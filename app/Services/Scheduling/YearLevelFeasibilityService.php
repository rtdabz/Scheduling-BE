<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Support\Collection;

/**
 * Capacity feasibility pre-check for a whole year-level generation run.
 *
 * ScheduleGenerationPreflightService already validates one section's *data* —
 * course status, curriculum period, whether a room of the required type exists at
 * all. This answers the different question the year-level flow needs before it
 * spends two minutes searching: across every section in the run, is there
 * physically enough room-time for what has been asked for?
 *
 * Only provable shortfalls are reported. Demand must exceed supply arithmetically
 * for a constraint to block; anything that merely looks tight is left to the
 * solver, because refusing a feasible run is worse than searching and failing.
 */
class YearLevelFeasibilityService
{
    /** Statuses whose existing schedules will be replaced by this run. */
    private const REPLACEABLE_STATUSES = ['draft', 'completed', 'revision'];

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
        $courses = $this->courses($configsBySectionId);
        $slotsPerDay = SchedulingPolicy::totalSlots();

        $blocking = [];
        $blocking = [...$blocking, ...$this->checkPhysicalRoomCapacity($sections, $configsBySectionId, $courses, $department, $slotsPerDay)];
        $blocking = [...$blocking, ...$this->checkLaboratoryCapacity($sections, $configsBySectionId, $courses, $department, $slotsPerDay)];
        $blocking = [...$blocking, ...$this->checkFixedPatternCapacity($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkOnlineCapacity($sections, $configsBySectionId, $courses, $department, $slotsPerDay)];

        return $blocking;
    }

    /**
     * Total on-site slot demand against the department's usable lecture and
     * laboratory rooms for the week.
     *
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
            $demand += $this->onSiteSlotDemand($config, $courses);
        }

        if ($demand === 0) {
            // Nothing needs a physical room, so the absence of one is not a
            // shortfall — a fully online year level is a valid request.
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

        $supply = $this->weeklyRoomSlotSupply($rooms, $slotsPerDay)
            - $this->occupiedRoomSlots($sections, $rooms->pluck('id')->map('intval')->all());

        if ($demand <= $supply) {
            return [];
        }

        return [[
            'code' => 'insufficient_room_slots',
            'message' => sprintf(
                'On-site placements need %d room-slots but only %d are free across %d room%s.',
                $demand,
                max(0, $supply),
                $rooms->count(),
                $rooms->count() === 1 ? '' : 's',
            ),
            'suggested_action' => 'Set some courses to Online, reduce lecture/lab splitting, or free up existing draft schedules.',
            'context' => [
                'required_slots' => $demand,
                'available_slots' => max(0, $supply),
                'room_count' => $rooms->count(),
            ],
        ]];
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkLaboratoryCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
        int $slotsPerDay,
    ): array {
        $labDemandBySection = [];
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $slots = 0;
            foreach ($this->configuredCourses($config, $courses) as $course) {
                if (SchedulingPolicy::isLaboratoryCourse($course)) {
                    $slots += $this->courseSlots($course);
                }
            }
            if ($slots > 0) {
                $labDemandBySection[(int) $section->id] = $slots;
            }
        }

        $demand = array_sum($labDemandBySection);
        if ($demand === 0) {
            return [];
        }

        $labRooms = $this->usableRooms($department, ['laboratory']);
        if ($labRooms->isEmpty()) {
            $firstSectionId = (int) array_key_first($labDemandBySection);

            return [[
                'code' => 'no_laboratory_room',
                'message' => 'Courses with a laboratory component are selected but the department has no available laboratory room.',
                'suggested_action' => 'Add a laboratory room, or remove the laboratory courses from this run.',
                'section_id' => $firstSectionId,
                'context' => ['required_laboratory_slots' => $demand],
            ]];
        }

        $supply = $this->weeklyRoomSlotSupply($labRooms, $slotsPerDay)
            - $this->occupiedRoomSlots($sections, $labRooms->pluck('id')->map('intval')->all());

        if ($demand <= $supply) {
            return [];
        }

        return [[
            'code' => 'insufficient_laboratory_slots',
            'message' => sprintf(
                'Laboratory placements need %d room-slots but only %d are free across %d laboratory room%s.',
                $demand,
                max(0, $supply),
                $labRooms->count(),
                $labRooms->count() === 1 ? '' : 's',
            ),
            'suggested_action' => 'Add a laboratory room, or spread the laboratory courses across more than one year level run.',
            'context' => [
                'required_slots' => $demand,
                'available_slots' => max(0, $supply),
                'room_count' => $labRooms->count(),
            ],
        ]];
    }

    /**
     * Fixed MW/TTh patterns concentrate demand onto two days, which is where a
     * year-level run most often becomes infeasible.
     *
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
                // A pattern splits the course across its days, so each day carries
                // roughly half the course's slots.
                $demandByPattern[$key] = ($demandByPattern[$key] ?? 0)
                    + (int) ceil($this->courseSlots($course) / count($days));
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

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkOnlineCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
        int $slotsPerDay,
    ): array {
        $demand = 0;
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            foreach ($this->configuredCourses($config, $courses) as $course) {
                if (($config['delivery_modes_by_course_id'][(int) $course->id] ?? null) === 'online') {
                    $demand += $this->courseSlots($course);
                }
            }
        }

        if ($demand === 0) {
            return [];
        }

        $limit = app(DepartmentResourceSlotLimitService::class)->online((int) $department->id);
        $supply = $limit * $slotsPerDay * count(SchedulingPolicy::DAYS);

        if ($demand <= $supply) {
            return [];
        }

        return [[
            'code' => 'insufficient_online_slots',
            'message' => sprintf(
                'Courses forced Online need %d slots but the department online limit of %d allows only %d per week.',
                $demand,
                $limit,
                $supply,
            ),
            'suggested_action' => sprintf(
                'Raise the department online slot limit above %d, or set some of those courses back to Automatic.',
                $limit,
            ),
            'context' => [
                'required_slots' => $demand,
                'available_slots' => $supply,
                'online_slot_limit' => $limit,
            ],
        ]];
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
            ->with('categories')
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
    private function onSiteSlotDemand(array $config, Collection $courses): int
    {
        $slots = 0;
        foreach ($this->configuredCourses($config, $courses) as $course) {
            $mode = $config['delivery_modes_by_course_id'][(int) $course->id] ?? null;
            if ($mode === 'online') {
                continue;
            }
            if ($mode === 'field' || SchedulingPolicy::isFieldCourse($course)) {
                continue;
            }

            $slots += $this->courseSlots($course);
        }

        return $slots;
    }

    /** Slot count a course occupies for the week, derived from its contact hours. */
    private function courseSlots(Course $course): int
    {
        $hours = (float) ($course->lecture_hours ?? 0) + (float) ($course->lab_hours ?? 0);
        if ($hours <= 0) {
            $hours = (float) ($course->units ?? 3);
        }

        return max(1, (int) ceil(($hours * 60) / SchedulingPolicy::SLOT_MINUTES));
    }

    /**
     * Room-slots already committed by schedules this run will not replace.
     *
     * @param  list<Sections>  $sections
     * @param  list<int>  $roomIds
     */
    private function occupiedRoomSlots(array $sections, array $roomIds): int
    {
        if ($roomIds === []) {
            return 0;
        }

        $termIds = array_values(array_unique(array_map(
            static fn (Sections $section): int => (int) $section->term_id,
            $sections,
        )));
        $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);

        $minutes = Schedule::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('term_id', $termIds)
            ->where(function ($query) use ($sectionIds): void {
                $query->whereNotIn('section_id', $sectionIds)
                    ->orWhereNotIn('status', self::REPLACEABLE_STATUSES);
            })
            ->get(['start_time', 'end_time'])
            ->sum(function (Schedule $schedule): int {
                $start = SchedulingPolicy::timeToMinutes((string) $schedule->start_time);
                $end = SchedulingPolicy::timeToMinutes((string) $schedule->end_time);

                return max(0, $end - $start);
            });

        return (int) floor($minutes / SchedulingPolicy::SLOT_MINUTES);
    }

    /**
     * Weekly room-slot supply, counting a room's configured concurrency so a
     * shared room is not undercounted into a false shortfall.
     *
     * @param  Collection<int, Rooms>  $rooms
     */
    private function weeklyRoomSlotSupply(Collection $rooms, int $slotsPerDay): int
    {
        return $this->concurrentRoomCapacity($rooms)
            * $slotsPerDay
            * count(SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
    }

    /** @param  Collection<int, Rooms>  $rooms */
    private function concurrentRoomCapacity(Collection $rooms): int
    {
        return (int) $rooms->sum(
            static fn (Rooms $room): int => max(1, (int) ($room->max_concurrent_classes ?? 1)),
        );
    }

    /**
     * @param  list<string>  $roomTypes
     * @return Collection<int, Rooms>
     */
    private function usableRooms(Departments $department, array $roomTypes): Collection
    {
        return Rooms::query()
            ->whereIn('room_type', $roomTypes)
            ->where(function ($query) use ($department): void {
                $query->whereNull('department_id')
                    ->orWhere('department_id', (int) $department->id);
            })
            ->where(function ($query): void {
                $query->where('status', 'available')->orWhereNull('status');
            })
            ->get(['id', 'room_code', 'room_type', 'max_concurrent_classes']);
    }
}
