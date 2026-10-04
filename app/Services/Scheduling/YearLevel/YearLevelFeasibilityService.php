<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

class YearLevelFeasibilityService
{
    private const REPLACEABLE_STATUSES = ['draft', 'completed', 'revision'];

    private ?int $semesterId = null;

    /**
     * @var list<string>|null
     */
    private ?array $allowedDays = null;

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<array<string, mixed>>  blocking constraints, empty when feasible enough to try
     */
    public function check(array $sections, array $configsBySectionId): array
    {
        if ($sections === []) {
            return [];
        }

        $department = $this->resolveDepartment($sections);
        $this->semesterId = (int) $sections[array_key_first($sections)]->semester_id ?: null;
        $this->allowedDays = SchedulingPolicy::normalizeAllowedDays(
            $configsBySectionId[(int) $sections[array_key_first($sections)]->id]['allowed_days'] ?? null,
        );
        $courses = $this->courses($configsBySectionId);
        $slotsPerDay = SchedulingPolicy::totalSlots();

        $blocking = [];
        $blocking = [...$blocking, ...$this->checkPhysicalRoomCapacity($sections, $configsBySectionId, $courses, $department, $slotsPerDay)];
        $blocking = [...$blocking, ...$this->checkFixedPatternCapacity($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkForcedDayCapacity($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkComponentDurationsFitTheDay($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkPreferredDays($sections, $configsBySectionId, $courses, $department)];

        return $blocking;
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkComponentDurationsFitTheDay(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $splitEnabled = true;
        $blocking = [];
        $reported = [];
        $forcedDays = SchedulingPolicy::forcedCourseDayMap((int) $department->id);

        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
            $balancedSplitIds = array_map('intval', $config['balanced_split_course_ids'] ?? []);

            foreach ($this->configuredCourses($config, $courses) as $course) {
                $courseId = (int) $course->id;
                if (isset($reported[$courseId])) {
                    continue;
                }

                $lectureUnits = (int) ($course->lecture_hours ?? 0);
                $laboratoryUnits = (int) ($course->lab_hours ?? 0);
                $isSplit = $splitEnabled
                    && in_array($courseId, $splitIds, true)
                    && $lectureUnits > 0
                    && $laboratoryUnits > 0;

                $components = $isSplit
                    ? $this->integratedHybridSlots($course, $department, $config)
                    : ['meeting' => $this->configuredSlots($course, $config)];

                foreach ($components as $label => $slots) {
                    if ($slots <= 0 || SchedulingPolicy::generatedStartSlotsForDuration($slots) !== []) {
                        continue;
                    }

                    $reported[$courseId] = true;
                    $blocking[] = [
                        'code' => 'component_duration_exceeds_day',
                        'message' => sprintf(
                            '%s needs a %s block of %s, but the teaching day is only %s long, so it has no possible start time.',
                            (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}"),
                            $label,
                            $this->describeHours($slots),
                            $this->describeHours(SchedulingPolicy::totalSlots()),
                        ),
                        'suggested_action' => $isSplit
                            ? sprintf(
                                'Split %s across more than one weekly session, reduce its laboratory units, '
                                .'or extend the operating hours so a block of %s fits.',
                                (string) ($course->course_code ?? ''),
                                $this->describeHours($slots),
                            )
                            : sprintf(
                                'Reduce the units on %s or extend the operating hours so a block of %s fits.',
                                (string) ($course->course_code ?? ''),
                                $this->describeHours($slots),
                            ),
                        'context' => [
                            'course_id' => $courseId,
                            'course_code' => (string) ($course->course_code ?? ''),
                            'component' => $label,
                            'required_slots' => $slots,
                            'available_slots_per_day' => SchedulingPolicy::totalSlots(),
                            'lecture_units' => $lectureUnits,
                            'laboratory_units' => $laboratoryUnits,
                        ],
                    ];

                    break;
                }
            }
        }

        return $blocking;
    }

    private function describeHours(int $slots): string
    {
        $hours = ($slots * SchedulingPolicy::SLOT_MINUTES) / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').' hours';
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkForcedDayCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $forcedDays = SchedulingPolicy::forcedCourseDayMap((int) $department->id);
        if ($forcedDays === []) {
            return [];
        }

        $blocking = [];

        foreach ($forcedDays as $courseId => $day) {
            $course = $courses->get((int) $courseId);
            if ($course === null) {
                continue;
            }

            $demand = 0;
            foreach ($sections as $section) {
                $config = $configsBySectionId[(int) $section->id] ?? [];
                if (in_array((int) $courseId, array_map('intval', $config['course_ids'] ?? []), true)) {
                    $demand++;
                }
            }

            if ($demand === 0) {
                continue;
            }

            $durationSlots = $this->courseSlots($course);
            $startCount = count($this->legalStartSlots($course, $department, $durationSlots));
            if ($startCount === 0) {
                continue;
            }

            $capacity = $this->forcedDayConcurrency($course, $department);
            if ($capacity === null || $capacity <= 0) {
                continue;
            }

            $supply = ($startCount * $capacity) - $this->occupiedForcedDaySlots($sections, (int) $courseId, $day);
            if ($demand <= $supply) {
                continue;
            }

            $needed = (int) ceil($demand / max(1, $startCount));

            $blocking[] = [
                'code' => 'forced_day_capacity_exceeded',
                'message' => sprintf(
                    '%s is pinned to %s and needs %d placements, but %s offers only %d: %d start %s at a concurrency of %d.',
                    (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}"),
                    $day,
                    $demand,
                    $day,
                    max(0, $supply),
                    $startCount,
                    $startCount === 1 ? 'time' : 'times',
                    $capacity,
                ),
                'suggested_action' => sprintf(
                    'Make at least %d rooms of that type available, '
                    .'or release the %s pin so the course can spread across more days.',
                    $needed,
                    $day,
                ),
                'context' => [
                    'course_id' => (int) $courseId,
                    'course_code' => (string) ($course->course_code ?? ''),
                    'forced_day' => $day,
                    'required_placements' => $demand,
                    'available_placements' => max(0, $supply),
                    'start_times' => $startCount,
                    'concurrency' => $capacity,
                    'required_concurrency' => $needed,
                ],
            ];
        }

        return $blocking;
    }

    /**
     * @return list<int>
     */
    private function legalStartSlots(Course $course, Departments $department, int $durationSlots): array
    {
        $startSlots = SchedulingPolicy::generatedStartSlotsForDuration($durationSlots);

        if (! SchedulingPolicy::isFieldCourse($course, (int) $department->id)) {
            return $startSlots;
        }

        $fieldEnd = SchedulingPolicy::fieldDayEndTime();

        return array_values(array_filter(
            $startSlots,
            static fn (int $slot): bool => SchedulingPolicy::slotToTime($slot + $durationSlots) <= $fieldEnd,
        ));
    }

    private function forcedDayConcurrency(Course $course, Departments $department): ?int
    {
        if (SchedulingPolicy::isFieldCourse($course, (int) $department->id)) {
            return null;
        }

        $roomType = SchedulingPolicy::isLaboratoryCourse($course) ? 'laboratory' : 'lecture';

        return $this->usableRooms($department, [$roomType])->count();
    }

    /**
     * @param  list<Sections>  $sections
     */
    private function occupiedForcedDaySlots(array $sections, int $courseId, string $day): int
    {
        $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);
        $semesterId = (int) ($sections[array_key_first($sections)]->semester_id ?? 0);

        return Schedule::query()
            ->where('semester_id', $semesterId)
            ->where('course_id', $courseId)
            ->where('day', $day)
            ->whereNotIn('section_id', $sectionIds)
            ->whereNotIn('status', self::REPLACEABLE_STATUSES)
            ->distinct()
            ->count('section_id');
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkPhysicalRoomCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
        int $slotsPerDay,
    ): array {
        $demand = 0;
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $demand += $this->onSiteSlotDemand(
                $config,
                $courses,
                (int) $section->department_id,
            );
        }

        if ($demand === 0) {
            return [];
        }

        $rooms = $this->usableRooms($department, ['lecture', 'laboratory']);
        if ($rooms->isEmpty()) {
            return [[
                'code' => 'no_physical_rooms',
                'message' => 'The department has no available lecture or laboratory room.',
                'suggested_action' => 'Add or re-enable a physical room, or set the affected courses to Online.',
                'context' => ['required_room_types' => ['lecture', 'laboratory']],
            ]];
        }

        $lectureRooms = $rooms
            ->filter(static fn (Rooms $room): bool => $room->room_type === 'lecture' || (bool) $room->allow_lecture_usage)
            ->values();
        $supply = $this->weeklyRoomSlotSupply($lectureRooms, $slotsPerDay, $department)
            - $this->occupiedRoomSlots($sections, $lectureRooms->pluck('id')->map('intval')->all());

        if ($demand <= $supply) {
            return [];
        }

        $shortfall = $demand - max(0, $supply);

        return [[
            'code' => 'insufficient_room_slots',
            'message' => sprintf(
                'On-site placements need %d room-slots but only %d are free across %d room%s.',
                $demand,
                max(0, $supply),
                $lectureRooms->count(),
                $lectureRooms->count() === 1 ? '' : 's',
            ),
            'suggested_action' => 'Apply one of the options above, borrow a room from another department with a room request, or free up existing draft schedules.',
            'context' => [
                'required_slots' => $demand,
                'available_slots' => max(0, $supply),
                'room_count' => $lectureRooms->count(),
                'shortfall_slots' => $shortfall,
                'options' => $this->roomSlotReliefOptions($sections, $configsBySectionId, $courses, $shortfall),
            ],
        ]];
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array{kind: string, frees: int, course_codes: list<string>, targets: list<array<string, mixed>>}>
     */
    private function roomSlotReliefOptions(array $sections, array $configsBySectionId, Collection $courses, int $shortfall): array
    {
        $candidates = [];
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $modes = (array) ($config['delivery_modes_by_course_id'] ?? []);
            $splitIds = array_map('intval', [
                ...($config['balanced_split_course_ids'] ?? []),
                ...($config['hybrid_split_course_ids'] ?? []),
            ]);

            foreach ($this->configuredCourses($config, $courses) as $course) {
                $courseId = (int) $course->id;
                $mode = $modes[$courseId] ?? $modes[(string) $courseId] ?? null;
                if (($mode !== null && $mode !== 'automatic')
                    || in_array($courseId, $splitIds, true)
                    || SchedulingPolicy::isLaboratoryCourse($course)
                    || SchedulingPolicy::isFieldCourse($course, (int) $section->department_id)) {
                    continue;
                }

                $slots = $this->configuredSlots($course, $config);
                $candidates[$courseId] ??= ['course' => $course, 'online' => 0, 'hybrid' => 0, 'targets' => []];
                $candidates[$courseId]['online'] += $slots;
                $candidates[$courseId]['hybrid'] += max(0, $slots - SchedulingPolicy::hybridSplitMeetingSlots());
                $candidates[$courseId]['targets'][] = [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->section_name,
                    'course_id' => $courseId,
                    'course_code' => (string) $course->course_code,
                ];
            }
        }

        $options = [];
        foreach (['hybrid_split' => 'hybrid', 'online' => 'online'] as $kind => $saving) {
            $pool = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate[$saving] > 0
                    && ($saving !== 'hybrid' || SchedulingPolicy::hybridSplitEligible($candidate['course'])),
            ));
            usort($pool, static fn (array $left, array $right): int => [
                $left['course']->course_category === 'major',
                -$left[$saving],
                (string) $left['course']->course_code,
            ] <=> [
                $right['course']->course_category === 'major',
                -$right[$saving],
                (string) $right['course']->course_code,
            ]);

            $chosen = [];
            $frees = 0;
            foreach ($pool as $candidate) {
                if ($frees >= $shortfall) {
                    break;
                }
                $chosen[] = $candidate;
                $frees += $candidate[$saving];
            }
            if ($chosen === [] || $frees < $shortfall) {
                continue;
            }

            $targets = [];
            foreach ($chosen as $candidate) {
                foreach ($candidate['targets'] as $target) {
                    $targets[] = [
                        ...$target,
                        'adjustment_type' => $kind === 'online' ? 'set_delivery_mode' : 'set_hybrid_split',
                        'value' => $kind === 'online' ? 'online' : null,
                    ];
                }
            }

            $options[] = [
                'kind' => $kind,
                'frees' => $frees,
                'course_codes' => array_map(static fn (array $candidate): string => (string) $candidate['course']->course_code, $chosen),
                'targets' => $targets,
            ];
        }

        return $options;
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkFixedPatternCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $rooms = $this->usableRooms($department, ['lecture', 'laboratory']);
        if ($rooms->isEmpty()) {
            return [];
        }

        $slotsPerDay = SchedulingPolicy::totalSlots();
        $demandByPattern = [];
        $sectionsByPattern = [];
        $targetsByPattern = [];

        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            foreach (($config['preferred_patterns'] ?? []) as $courseId => $pattern) {
                $days = SchedulingPolicy::allowedDaysForPattern($pattern);
                if ($days === null || $days === []) {
                    continue;
                }

                $course = $courses->get((int) $courseId);
                if ($course === null || ($config['delivery_modes_by_course_id'][$courseId] ?? null) === 'online') {
                    continue;
                }

                $key = (string) $pattern;
                $demandByPattern[$key] = ($demandByPattern[$key] ?? 0)
                    + (int) ceil($this->configuredSlots($course, $config) / count($days));
                $sectionsByPattern[$key][(int) $section->id] = (string) $section->section_name;
                $targetsByPattern[$key][] = [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->section_name,
                    'course_id' => (int) $courseId,
                    'course_code' => (string) ($course->course_code ?? ('Course '.$courseId)),
                ];
            }
        }

        $blocking = [];
        foreach ($demandByPattern as $pattern => $perDayDemand) {
            $supplyPerDay = $this->concurrentRoomCapacity($rooms) * $slotsPerDay;
            if ($perDayDemand <= $supplyPerDay) {
                continue;
            }

            $blocking[] = [
                'code' => 'fixed_pattern_overloaded',
                'message' => sprintf(
                    'The %s pattern needs %d room-slots on each of its days, but only %d are available per day.',
                    $pattern,
                    $perDayDemand,
                    $supplyPerDay,
                ),
                'suggested_action' => sprintf(
                    'Move some %s courses to the other pattern, or let the generator choose the days automatically.',
                    $pattern,
                ),
                'context' => [
                    'pattern' => $pattern,
                    'required_slots_per_day' => $perDayDemand,
                    'available_slots_per_day' => $supplyPerDay,
                    'sections' => array_values($sectionsByPattern[$pattern] ?? []),
                    'section_ids' => array_map('intval', array_keys($sectionsByPattern[$pattern] ?? [])),
                    'targets' => array_values($targetsByPattern[$pattern] ?? []),
                ],
            ];
        }

        return $blocking;
    }

    /** @param  list<Sections>  $sections */
    private function resolveDepartment(array $sections): Departments
    {
        $section = $sections[array_key_first($sections)];

        return $section->department ?: Departments::query()->findOrFail((int) $section->department_id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return Collection<int, Course>
     */
    private function courses(array $configsBySectionId): Collection
    {
        $courseIds = [];
        foreach ($configsBySectionId as $config) {
            foreach (($config['course_ids'] ?? []) as $courseId) {
                $courseIds[(int) $courseId] = (int) $courseId;
            }
        }

        if ($courseIds === []) {
            return collect();
        }

        return Course::query()
            ->whereIn('id', array_values($courseIds))
            ->get()
            ->keyBy(static fn (Course $course): int => (int) $course->id);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  Collection<int, Course>  $courses
     * @return list<Course>
     */
    private function configuredCourses(array $config, Collection $courses): array
    {
        $selected = [];
        foreach (($config['course_ids'] ?? []) as $courseId) {
            $course = $courses->get((int) $courseId);
            if ($course !== null) {
                $selected[] = $course;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  Collection<int, Course>  $courses
     */
    private function onSiteSlotDemand(
        array $config,
        Collection $courses,
        int $departmentId,
    ): int
    {
        $slots = 0;
        $hybridSplitIds = array_map('intval', $config['hybrid_split_course_ids'] ?? []);
        foreach ($this->configuredCourses($config, $courses) as $course) {
            $mode = $config['delivery_modes_by_course_id'][(int) $course->id] ?? null;
            if ($mode === 'online') {
                continue;
            }
            if (in_array((int) $course->id, $hybridSplitIds, true)) {
                $slots += SchedulingPolicy::hybridSplitMeetingSlots();
                continue;
            }
            if (SchedulingPolicy::isLaboratoryCourse($course)) {
                continue;
            }
            if ($mode === 'field' || SchedulingPolicy::isFieldCourse($course, $departmentId)) {
                continue;
            }

            $slots += $this->configuredSlots($course, $config);
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configuredSlots(Course $course, array $config): int
    {
        return CourseSetupOverrides::durationSlots($config, (int) $course->id)
            ?? $this->courseSlots($course);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{lecture: int, laboratory: int}
     */
    private function integratedHybridSlots(Course $course, Departments $department, array $config): array
    {
        $courseId = (int) $course->id;

        return [
            'lecture' => CourseSetupOverrides::componentSlots($config, $courseId, 'lecture')
                ?? SchedulingPolicy::lectureComponentSlots($course),
            'laboratory' => CourseSetupOverrides::componentSlots($config, $courseId, 'laboratory')
                ?? SchedulingPolicy::laboratoryComponentSlots($course, $department),
        ];
    }

    private function courseSlots(Course $course): int
    {
        $units = (float) ($course->units ?? 0);
        if ($units <= 0) {
            $units = (float) ($course->lecture_hours ?? 0) + (float) ($course->lab_hours ?? 0);
        }

        return max(1, (int) ceil(SchedulingPolicy::unitMinutes($units) / SchedulingPolicy::SLOT_MINUTES));
    }

    /**
     * @param  list<Sections>  $sections
     * @param  list<int>  $roomIds
     */
    private function occupiedRoomSlots(array $sections, array $roomIds): int
    {
        if ($roomIds === []) {
            return 0;
        }

        $semesterIds = array_values(array_unique(array_map(
            static fn (Sections $section): int => (int) $section->semester_id,
            $sections,
        )));
        $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);

        $minutes = Schedule::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('semester_id', $semesterIds)
            ->where(function ($query) use ($sectionIds): void {
                $query->whereNotIn('section_id', $sectionIds)
                    ->orWhereNotIn('status', self::REPLACEABLE_STATUSES);
            })
            ->get(['section_id', 'course_id', 'room_id', 'day', 'start_time', 'end_time', 'mode', 'status'])
            ->unique(static fn (Schedule $schedule): string => implode('|', [
                (int) $schedule->section_id,
                (int) $schedule->course_id,
                (int) $schedule->room_id,
                (string) $schedule->day,
                (string) $schedule->start_time,
                (string) $schedule->end_time,
                (string) $schedule->mode,
                (string) $schedule->status,
            ]))
            ->sum(function (Schedule $schedule): int {
                $start = SchedulingPolicy::timeToMinutes((string) $schedule->start_time);
                $end = SchedulingPolicy::timeToMinutes((string) $schedule->end_time);

                return max(0, $end - $start);
            });

        return (int) floor($minutes / SchedulingPolicy::SLOT_MINUTES);
    }

    /**
     * @param  Collection<int, Rooms>  $rooms
     */
    private function weeklyRoomSlotSupply(Collection $rooms, int $slotsPerDay, Departments $department): int
    {
        return $this->concurrentRoomCapacity($rooms)
            * $slotsPerDay
            * SchedulingPolicy::countAllowedDays(
                SchedulingPolicy::teachingDays((bool) $department->sunday_classes_enabled),
                $this->allowedDays,
            );
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkPreferredDays(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        if ($this->allowedDays === null) {
            return [];
        }

        $blocking = [];
        $dayList = implode(', ', $this->allowedDays);
        if (count($this->allowedDays) >= 2) {
            return [];
        }

        $indexByCourseId = [];
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $lectureLabIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
            $hybridSplitIds = array_map('intval', $config['hybrid_split_course_ids'] ?? []);

            foreach ($this->configuredCourses($config, $courses) as $course) {
                $courseId = (int) $course->id;
                $isLectureLab = in_array($courseId, $lectureLabIds, true);
                if (! $isLectureLab && ! in_array($courseId, $hybridSplitIds, true)) {
                    continue;
                }

                $code = (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}");
                $target = [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->section_name,
                    'course_id' => $courseId,
                    'course_code' => $code,
                    'adjustment_type' => $isLectureLab ? 'disable_lecture_lab_split' : 'disable_hybrid_split',
                ];

                if (isset($indexByCourseId[$courseId])) {
                    $blocking[$indexByCourseId[$courseId]]['context']['targets'][] = $target;

                    continue;
                }

                $indexByCourseId[$courseId] = count($blocking);
                $blocking[] = [
                    'code' => 'preferred_days_too_few_for_hybrid',
                    'message' => sprintf(
                        '%s is Hybrid, which meets on two different days, but the Preferred Days allow only %s.',
                        $code,
                        $dayList,
                    ),
                    'suggested_action' => sprintf(
                        'Add another Preferred Day in Configuration, or set %s back to On-Site in Setup Courses.',
                        $code,
                    ),
                    'context' => [
                        'course_id' => $courseId,
                        'course_code' => $code,
                        'allowed_days' => $this->allowedDays,
                        'targets' => [$target],
                    ],
                ];
            }
        }

        return $blocking;
    }

    /**
     * @param  Collection<int, Rooms>  $rooms
     */
    private function concurrentRoomCapacity(Collection $rooms): int
    {
        return $rooms->count();
    }

    /**
     * @param  list<string>  $roomTypes
     * @return Collection<int, Rooms>
     */
    private function usableRooms(Departments $department, array $roomTypes): Collection
    {
        return Rooms::query()
            ->whereIn('room_type', $roomTypes)
            ->tap(fn ($query) => app(RoomAccessPolicy::class)->scopeReachableRooms($query, (int) $department->id, $this->semesterId))
            ->where(function ($query): void {
                $query->where('status', 'available')->orWhereNull('status');
            })
            ->get(['id', 'room_code', 'room_type', 'allow_lecture_usage']);
    }
}
