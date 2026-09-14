<?php

namespace App\Services\Scheduling\Engine;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Department\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RuleEngine
{
    private DepartmentResourceSlotLimitService $resourceLimits;

    /**
     * Per-instance memo for reference-entity lookups.
     *
     * `validate()` is called once per operation in a batch save, and a batch
     * almost always reuses the same semester, section, room and department. Without
     * this, a 40-operation batch re-ran the same handful of primary-key lookups
     * 40 times (audit finding #8). The engine is resolved per request, so the
     * memo cannot outlive the data it caches.
     *
     * @var array<string, mixed>
     */
    private array $entityCache = [];

    public function __construct(?DepartmentResourceSlotLimitService $resourceLimits = null)
    {
        $this->resourceLimits = $resourceLimits ?? new DepartmentResourceSlotLimitService;
    }

    /**
     * Memoized primary-key lookup.
     *
     * @template TValue
     * @param  callable(): TValue  $resolver
     * @return TValue
     */
    private function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->entityCache)) {
            $this->entityCache[$key] = $resolver();
        }

        return $this->entityCache[$key];
    }

    public function checkRoomConflict(
        int $roomId,
        int $semesterId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null,
        ?int $departmentId = null,
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);
        $room = $this->remember('room:'.$roomId, fn () => Rooms::query()->find($roomId));
        $capacity = $this->effectiveRoomCapacity($room, $departmentId);

        if (($room?->room_type ?? null) === 'field' && $capacity > 1) {
            $overlaps = Schedule::where('room_id', $roomId)
                ->where('semester_id', $semesterId)
                ->where('day', $day)
                ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
                ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->with(['course', 'section'])
                ->get();

            if ($this->exceedsRoomCapacity($overlaps, $startTime, $endTime, $capacity)) {
                $firstConflict = $overlaps->first();

                return [
                    'rule' => 'room_capacity_conflict',
                    'message' => "{$room->room_code} capacity is full for this department on {$day} from {$startTime} to {$endTime}. "
                        ."Maximum concurrent classes: {$capacity}.",
                    'conflicting_schedule_id' => $firstConflict?->id,
                ];
            }

            return null;
        }

        $conflict = Schedule::where('room_id', $roomId)
            ->where('semester_id', $semesterId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['course', 'section'])
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'room_conflict',
            'message' => "Room is already booked on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->course?->course_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    private function exceedsRoomCapacity(
        Collection $overlaps,
        string $startTime,
        string $endTime,
        int $capacity,
    ): bool {
        $events = [
            [$this->timeToMinutes($startTime), 1],
            [$this->timeToMinutes($endTime), -1],
        ];

        foreach ($overlaps as $schedule) {
            $events[] = [$this->timeToMinutes((string) $schedule->start_time), 1];
            $events[] = [$this->timeToMinutes((string) $schedule->end_time), -1];
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]),
        );

        $active = 0;
        foreach ($events as [$minute, $delta]) {
            if ($minute === null) {
                continue;
            }

            $active += $delta;
            if ($active > $capacity) {
                return true;
            }
        }

        return false;
    }

    private function effectiveRoomCapacity(?Rooms $room, ?int $departmentId = null): int
    {
        if (($room?->room_type ?? null) === 'field') {
            return $departmentId !== null
                ? $this->resourceLimits->field($departmentId)
                : (int) ($room?->max_concurrent_classes ?? 1);
        }

        if (($room?->room_type ?? null) === 'online' && $departmentId !== null) {
            return $this->resourceLimits->online($departmentId);
        }

        return max(1, (int) ($room?->max_concurrent_classes ?? 1));
    }

    public function checkFacultyConflict(
        int $facultyId,
        int $semesterId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflicts = Schedule::where('faculty_id', $facultyId)
            ->where('semester_id', $semesterId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['course', 'section'])
            ->orderBy('start_time')
            ->get();

        if ($conflicts->isEmpty()) {
            return null;
        }

        $conflict = $conflicts->first();

        return [
            'rule' => 'faculty_conflict',
            'message' => "Faculty is already teaching on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->course?->course_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
            // Every clashing meeting, so an override can mark all of them.
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }

    public function checkSectionConflict(
        int $sectionId,
        int $semesterId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflict = Schedule::where('section_id', $sectionId)
            ->where('semester_id', $semesterId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with('course')
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'section_conflict',
            'message' => "Section already has a class on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."({$conflict->course?->course_code}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    /**
     * Online sections taking the same subject must use different overlapping
     * time windows in a semester. Physical and field delivery remain governed by
     * their room, capacity, section, and faculty constraints.
     */
    public function checkSubjectSectionConflict(
        int $courseId,
        int $sectionId,
        int $semesterId,
        string $day,
        string $startTime,
        string $endTime,
        string $deliveryMode = 'on-site',
        int|array|null $ignoreScheduleId = null,
    ): ?array {
        if ($deliveryMode !== 'online') {
            return null;
        }

        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflict = Schedule::query()
            ->where('course_id', $courseId)
            ->where('section_id', '!=', $sectionId)
            ->where('semester_id', $semesterId)
            ->where('mode', 'online')
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['course', 'section'])
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'subject_section_time_conflict',
            'message' => "{$conflict->course?->course_code} is already scheduled for another section ({$conflict->section?->section_name}) on {$day} from {$conflict->start_time} to {$conflict->end_time}.",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    public function checkPreferredPattern(string $day, ?string $preferredPattern): ?array
    {
        if (empty($preferredPattern)) {
            return null;
        }

        try {
            $allowedDays = SchedulingPolicy::allowedDaysForPattern($preferredPattern);
        } catch (InvalidArgumentException $exception) {
            return [
                'rule' => 'preferred_pattern',
                'message' => $exception->getMessage(),
            ];
        }

        if ($allowedDays !== null && ! in_array($day, $allowedDays, true)) {
            return [
                'rule' => 'preferred_pattern',
                'message' => "Preferred pattern conflict: '{$preferredPattern}' courses can only be scheduled on "
                    .implode(' or ', $allowedDays).", not {$day}.",
            ];
        }

        return null;
    }

    public function checkOperatingHours(string $startTime, string $endTime): ?array
    {
        $start = SchedulingPolicy::normalizeTime($startTime);
        $end = SchedulingPolicy::normalizeTime($endTime);

        if ($start < SchedulingPolicy::openingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule starts at {$startTime}, which is before operating hours begin ("
                    .date('g:i A', strtotime(SchedulingPolicy::openingTime())).').',
            ];
        }

        if ($end > SchedulingPolicy::closingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule ends at {$endTime}, which exceeds operating hours ("
                    .date('g:i A', strtotime(SchedulingPolicy::closingTime())).' cutoff).',
            ];
        }

        return null;
    }

    /**
     * $departmentId is the scheduling department, not the course's owner. Field
     * course codes are configured per department and shared minors carry no
     * department of their own, so the room-type rule must be asked in the same
     * semesters as the day rules below or one course gets two answers in one run.
     */
    public function checkRoomTypeMatch(
        int $courseId,
        ?int $roomId,
        string $deliveryMode = 'on-site',
        ?string $meetingType = null,
        ?int $departmentId = null
    ): ?array {
        $course = $this->remember('course:'.$courseId, fn () => Course::find($courseId));

        if (! $course) {
            return [
                'rule' => 'room_type_match',
                'message' => 'Course not found for room-type validation.',
            ];
        }

        $room = $roomId !== null ? $this->remember('room:'.$roomId, fn () => Rooms::find($roomId)) : null;
        $requiredRoomType = SchedulingPolicy::effectiveRoomType($course, $departmentId, $meetingType);

        if ($deliveryMode === 'online') {
            return SchedulingPolicy::allowsOnlineRoomFallback($course, $departmentId, $meetingType)
                ? null
                : [
                    'rule' => 'room_type_match',
                    'message' => "Course {$course->course_code} cannot use online delivery for its {$requiredRoomType} requirement.",
                ];
        }

        if (! $room) {
            if ($deliveryMode === 'on-site' && SchedulingPolicy::allowsRoomTbaFallback($course, $departmentId, $meetingType)) {
                return null;
            }
            return [
                'rule' => 'room_type_match',
                'message' => 'A physical room is required for this schedule.',
            ];
        }

        if ($deliveryMode === 'field') {
            return $room->room_type === 'field'
                ? null
                : [
                    'rule' => 'room_type_match',
                    'message' => 'Field schedules must use a field room assignment.',
                ];
        }

        // On-site: reject virtual (online/field) rooms for physical delivery.
        if (in_array($room->room_type, ['online', 'field'], true)) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a physical room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        if ($requiredRoomType === 'laboratory' && $room->room_type !== 'laboratory') {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a laboratory room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        if (
            $requiredRoomType === 'lecture'
            && $room->room_type === 'laboratory'
            && ! $this->canUseLaboratoryForLecture($course, $room)
        ) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} can only use lecture-capable laboratory rooms as a fallback.",
            ];
        }

        if (
            $requiredRoomType === 'lecture'
            && in_array($room->room_type, ['lecture', 'laboratory'], true)
        ) {
            return null;
        }

        if ($requiredRoomType === 'laboratory' && $room->room_type === 'laboratory') {
            return null;
        }

        if ($requiredRoomType !== $room->room_type) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a '{$requiredRoomType}' room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        return null;
    }

    private function canUseLaboratoryForLecture(Course $course, Rooms $room): bool
    {
        $courseCategory = $course->course_category ?? $course->subject_category ?? 'major';

        return $courseCategory === 'major'
            && (int) ($course->lecture_hours ?? 0) > 0
            && (int) ($course->lab_hours ?? 0) === 0
            && (string) ($course->room_type_required ?? 'lecture') === 'lecture'
            && $room->room_type === 'laboratory'
            && (bool) $room->allow_lecture_usage;
    }

    public function checkTimeSlotGrid(string $startTime, string $endTime): ?array
    {
        $startMinutes = $this->timeToMinutes($startTime);
        $endMinutes = $this->timeToMinutes($endTime);

        if ($startMinutes === null || $endMinutes === null) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule start and end times must be valid time values.',
            ];
        }

        if ($endMinutes <= $startMinutes) {
            return [
                'rule' => 'operating_hours',
                'message' => 'Schedule end time must be after start time.',
            ];
        }

        if (
            ($startMinutes - SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime())) % SchedulingPolicy::SLOT_MINUTES !== 0
            || ($endMinutes - SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime())) % SchedulingPolicy::SLOT_MINUTES !== 0
        ) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule times must align to 30-minute scheduling slots.',
            ];
        }

        return null;
    }

    public function checkRelationalIntegrity(array $attempt): array
    {
        $violations = [];

        $semester = $this->remember('semester:'.$attempt['semester_id'], fn () => Semester::find($attempt['semester_id']));
        $section = $this->remember('section:'.$attempt['section_id'], fn () => Sections::find($attempt['section_id']));
        $courseId = $attempt['course_id'] ?? $attempt['subject_id'] ?? null;
        $course = $courseId ? $this->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
        $mode = (string) ($attempt['mode'] ?? 'on-site');
        $roomId = $attempt['room_id'] ?? null;
        $room = $roomId !== null ? $this->remember('room:'.$roomId, fn () => Rooms::find($roomId)) : null;
        $allowsLabTba = $course !== null
            && $mode === 'on-site'
            && $room === null
            && SchedulingPolicy::allowsRoomTbaFallback(
                $course,
                $section?->department_id === null ? null : (int) $section->department_id,
                $attempt['meeting_type'] ?? null,
            );
        $faculty = ! empty($attempt['faculty_id'])
            ? $this->remember('faculty:'.$attempt['faculty_id'], fn () => Faculty::find($attempt['faculty_id']))
            : null;

        if (! $semester) {
            $violations[] = [
                'rule' => 'semester_exists',
                'message' => 'Selected academic semester does not exist.',
            ];
        }

        if (! $section) {
            $violations[] = [
                'rule' => 'section_exists',
                'message' => 'Selected section does not exist.',
            ];
        }

        if (! $course) {
            $violations[] = [
                'rule' => 'subject_exists',
                'message' => 'Selected course does not exist.',
            ];
        }

        if (! $room && $mode !== 'online' && ! $allowsLabTba) {
            $violations[] = [
                'rule' => 'room_exists',
                'message' => 'Selected room does not exist.',
            ];
        }

        if (! empty($attempt['faculty_id']) && ! $faculty) {
            $violations[] = [
                'rule' => 'faculty_exists',
                'message' => 'Selected faculty member does not exist.',
            ];
        }

        if (! $semester || ! $section || ! $course || (! $room && $mode !== 'online' && ! $allowsLabTba) || (! empty($attempt['faculty_id']) && ! $faculty)) {
            return $violations;
        }

        $activeCurriculum = $this->remember('curriculum:'.$section->department_id, fn () => Curriculum::query()
            ->where('department_id', $section->department_id)
            ->where('status', 'active')
            ->first());

        if ($activeCurriculum) {
            $pivot = $this->remember('curriculum_course:'.$activeCurriculum->id.':'.$course->id, fn () => DB::table('curriculum_course')
                ->where('curriculum_id', $activeCurriculum->id)
                ->where('course_id', $course->id)
                ->first());

            if ($pivot) {
                $course->year_level = (string) $pivot->year_level;
                $course->semester = (string) $pivot->semester === '1' ? '1st' : ((string) $pivot->semester === '2' ? '2nd' : 'summer');
            }
        }

        if (! (bool) ($semester->is_enabled ?? true)) {
            $violations[] = [
                'rule' => 'semester_enabled',
                'message' => 'Selected academic semester is disabled for scheduling.',
            ];
        }

        if ((int) $section->semester_id !== (int) $semester->id) {
            $violations[] = [
                'rule' => 'section_semester_alignment',
                'message' => 'Selected section does not belong to the selected academic semester.',
            ];
        }

        if ((string) $section->semester !== (string) $semester->semester) {
            $violations[] = [
                'rule' => 'section_semester_period_alignment',
                'message' => 'Section semester does not match the academic semester.',
            ];
        }

        if ((string) $course->semester !== (string) $section->semester) {
            $violations[] = [
                'rule' => 'subject_section_semester_alignment',
                'message' => 'Course semester does not match the selected section semester.',
            ];
        }

        if ((string) $course->year_level !== (string) $section->year_level) {
            $violations[] = [
                'rule' => 'subject_section_year_alignment',
                'message' => 'Course year level does not match the selected section year level.',
            ];
        }

        $attemptDepartmentId = $attempt['department_id'] ?? $section->department_id;
        if ((int) $attemptDepartmentId !== (int) $section->department_id) {
            $violations[] = [
                'rule' => 'schedule_department_alignment',
                'message' => 'Schedule department must match the selected section department.',
            ];
        }

        if (($section->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'section_active',
                'message' => 'Selected section is inactive and cannot be scheduled.',
            ];
        }

        if (($course->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'subject_active',
                'message' => 'Selected course is inactive and cannot be scheduled.',
            ];
        }

        if ($room) {
            if (($room->status ?? 'available') !== 'available') {
                $violations[] = [
                    'rule' => 'room_availability',
                    'message' => "Room {$room->room_code} is not available for scheduling.",
                ];
            }

            if ($room->department_id !== null && (int) $room->department_id !== (int) $section->department_id) {
                // Another department's room is reachable only through an
                // approved room request, and only inside its granted windows.
                $grantWindows = $this->remember(
                    'room-grants:'.$section->department_id.':'.$semester->id,
                    fn () => app(RoomAccessPolicy::class)->grantWindowsFor((int) $section->department_id, (int) $semester->id),
                )[(int) $room->id] ?? null;

                if ($grantWindows === null) {
                    $violations[] = [
                        'rule' => 'room_department_alignment',
                        'message' => 'Selected room is not shared and does not belong to the selected section department.',
                    ];
                } elseif (! RoomAccessPolicy::fitsWindows(
                    $grantWindows,
                    (string) ($attempt['day'] ?? ''),
                    (string) ($attempt['start_time'] ?? '00:00'),
                    (string) ($attempt['end_time'] ?? '00:00'),
                )) {
                    $violations[] = [
                        'rule' => 'room_department_alignment',
                        'message' => "Room {$room->room_code} is granted to your department only on "
                            .RoomAccessPolicy::describe($grantWindows).'.',
                    ];
                }
            }
        }

        $courseCategory = $course->course_category ?? $course->subject_category ?? 'major';
        if (
            $courseCategory === 'major'
            && $course->department_id !== null
            && (int) $course->department_id !== (int) $section->department_id
        ) {
            $violations[] = [
                'rule' => 'major_department_alignment',
                'message' => 'Major course department does not match the selected section department.',
            ];
        }

        if ($faculty) {
            if (($faculty->status ?? 'active') !== 'active') {
                $violations[] = [
                    'rule' => 'faculty_active',
                    'message' => 'Selected faculty member is inactive and cannot be assigned.',
                ];
            }

            $dayIndexMap = [
                'Monday' => 0,
                'Tuesday' => 1,
                'Wednesday' => 2,
                'Thursday' => 3,
                'Friday' => 4,
                'Saturday' => 5,
                'Sunday' => 6,
            ];
            $attemptDay = (string) ($attempt['day'] ?? '');
            $attemptDayIndex = $dayIndexMap[$attemptDay] ?? null;

            if ($faculty->employment_type === 'part-time' && $attemptDayIndex !== null) {
                // Fetch the availability windows for this day
                $dayAvailabilities = $faculty->availabilities()
                    ->where('day_index', $attemptDayIndex)
                    ->get();

                $attemptStart = SchedulingPolicy::normalizeTime((string) ($attempt['start_time'] ?? '00:00'));
                $attemptEnd = SchedulingPolicy::normalizeTime((string) ($attempt['end_time'] ?? '00:00'));

                // Verify if the attempt fits completely inside at least one availability window
                $fits = false;
                foreach ($dayAvailabilities as $window) {
                    $windowStart = SchedulingPolicy::normalizeTime($window->start_time);
                    $windowEnd = SchedulingPolicy::normalizeTime($window->end_time);
                    if ($attemptStart >= $windowStart && $attemptEnd <= $windowEnd) {
                        $fits = true;
                        break;
                    }
                }

                if (! $fits) {
                    $violations[] = [
                        'rule' => 'part_time_faculty_availability',
                        'message' => 'The selected assignment falls outside the instructor\'s availability window for '.$attemptDay.'.',
                    ];
                }
            }

            $assignedTeachingDepartmentId = SchedulingPolicy::assignedTeachingDepartmentId($course);

            if (SchedulingPolicy::isMajorCourse($course)) {
                // A major belongs to the department — and, when recorded, the
                // program — that offers it, so it is taught from inside that
                // program rather than delegated like a service course.
                $majorDepartmentId = SchedulingPolicy::majorTeachingDepartmentId(
                    $course,
                    (int) $section->department_id,
                );

                if ($majorDepartmentId !== null && (int) $faculty->department_id !== $majorDepartmentId) {
                    $violations[] = [
                        'rule' => 'major_faculty_department_alignment',
                        'message' => 'A major course must be assigned to an instructor from the department that offers it.',
                    ];
                }

                $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);
                if ($requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
                    $program = $this->remember(
                        'program:'.$requiredProgramId,
                        fn () => Program::find($requiredProgramId),
                    );
                    $programLabel = $program?->code ?? $program?->name;

                    $violations[] = [
                        'rule' => 'major_faculty_program_alignment',
                        'message' => $programLabel !== null
                            ? "This major course belongs to the {$programLabel} program, so the selected instructor must belong to that program."
                            : 'This major course belongs to a program the selected instructor is not assigned to.',
                    ];
                }
            } elseif ($assignedTeachingDepartmentId !== null) {
                if ((int) $faculty->department_id !== $assignedTeachingDepartmentId) {
                    $violations[] = [
                        'rule' => 'service_subject_faculty_department_alignment',
                        'message' => 'This service course must be assigned to an instructor from the college that offers it.',
                    ];
                }
            }

            if (! SchedulingPolicy::isMajorCourse($course)) {
                $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);
                if ($requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
                    $program = $this->remember('program:'.$requiredProgramId, fn () => Program::find($requiredProgramId));
                    $programLabel = $program?->code ?? $program?->name;
                    $violations[] = [
                        'rule' => 'service_subject_faculty_program_alignment',
                        'message' => $programLabel !== null
                            ? "This course is assigned to the {$programLabel} program, so the selected instructor must belong to that program."
                            : 'This course is assigned to a program the selected instructor is not assigned to.',
                    ];
                }
            }
            // A minor or service course with no assigned teaching department is open
            // to any department: shared minors such as PATH FIT are taught by
            // instructors from outside the section's department, which is what the
            // external-instructor assignment path is for. Majors are restricted by
            // their own department and program rules above.
        }

        $mode = (string) ($attempt['mode'] ?? 'on-site');
        if ($mode === 'field' && $room?->room_type !== 'field') {
            $violations[] = [
                'rule' => 'delivery_room_alignment',
                'message' => 'Field schedules must use a field room assignment.',
            ];
        }

        if ($mode === 'on-site' && ((! $room && ! $allowsLabTba) || ($room && in_array($room->room_type, ['online', 'field'], true)))) {
            $violations[] = [
                'rule' => 'delivery_room_alignment',
                'message' => 'On-site schedules must use a lecture or laboratory room assignment.',
            ];
        }

        // Day-category constraint: enforce which days each course type may use.
        $dayCategoryViolation = $this->checkDayCategoryConstraint(
            course: $course,
            day: (string) ($attempt['day'] ?? ''),
            mode: $mode,
            section: $section,
        );
        if ($dayCategoryViolation !== null) {
            $violations[] = $dayCategoryViolation;
        }

        $fieldEveningViolation = $this->checkFieldEveningWindow(
            course: $course,
            endTime: (string) ($attempt['end_time'] ?? ''),
            mode: $mode,
            section: $section,
        );
        if ($fieldEveningViolation !== null) {
            $violations[] = $fieldEveningViolation;
        }

        $forcedDayViolation = $this->checkForcedCourseDay(
            courseId: (int) $course->id,
            departmentId: (int) $section->department_id,
            day: (string) ($attempt['day'] ?? ''),
        );
        if ($forcedDayViolation !== null) {
            $violations[] = $forcedDayViolation;
        }

        return $violations;
    }

    public function validate(array $attempt): array
    {
        $violations = [];
        $ignoreId = $attempt['ignore_schedule_id'] ?? null;

        foreach (['semester_id', 'section_id', 'day', 'start_time', 'end_time'] as $field) {
            if (! array_key_exists($field, $attempt) || $attempt[$field] === null || $attempt[$field] === '') {
                $violations[] = [
                    'rule' => 'required_field',
                    'message' => "Schedule attempt is missing required field '{$field}'.",
                ];
            }
        }

        if (! isset($attempt['course_id']) && ! isset($attempt['subject_id'])) {
            $violations[] = [
                'rule' => 'required_field',
                'message' => "Schedule attempt is missing required field 'course_id'.",
            ];
        }

        if ($violations !== []) {
            return $violations;
        }

        if (! in_array($attempt['day'], SchedulingPolicy::PERSISTABLE_DAYS, true)) {
            $violations[] = [
                'rule' => 'valid_day',
                'message' => "Unsupported schedule day '{$attempt['day']}'.",
            ];
        }

        if (
            isset($attempt['mode'])
            && ! SchedulingPolicy::isValidDeliveryMode((string) $attempt['mode'])
        ) {
            $violations[] = [
                'rule' => 'delivery_mode',
                'message' => "Unsupported delivery mode '{$attempt['mode']}'.",
            ];
        }

        if (($attempt['mode'] ?? null) === 'field' && ! empty($attempt['is_hybrid'])) {
            $violations[] = [
                'rule' => 'hybrid_mode',
                'message' => 'Field schedules cannot be marked as hybrid.',
            ];
        }

        $slotGrid = $this->checkTimeSlotGrid(
            $attempt['start_time'],
            $attempt['end_time']
        );
        if ($slotGrid) {
            $violations[] = $slotGrid;
        }

        $violations = array_merge(
            $violations,
            $this->checkRelationalIntegrity($attempt)
        );

        $configuredShapeViolation = $this->checkConfiguredMeetingShape($attempt);
        if ($configuredShapeViolation !== null) {
            $violations[] = $configuredShapeViolation;
        }

        $mode = (string) ($attempt['mode'] ?? 'on-site');

        // FIELD uses department-scoped shared room capacity. ONLINE is checked
        // separately because it does not use a physical room assignment.
        if ($mode !== 'online' && ($attempt['room_id'] ?? null) !== null) {
            $roomConflict = $this->checkRoomConflict(
                (int) $attempt['room_id'],
                $attempt['semester_id'],
                $attempt['day'],
                $attempt['start_time'],
                $attempt['end_time'],
                $ignoreId,
                isset($attempt['department_id']) ? (int) $attempt['department_id'] : null,
            );
            if ($roomConflict) {
                $violations[] = $roomConflict;
            }
        }

        if (! empty($attempt['faculty_id'])) {
            $facultyConflict = $this->checkFacultyConflict(
                $attempt['faculty_id'],
                $attempt['semester_id'],
                $attempt['day'],
                $attempt['start_time'],
                $attempt['end_time'],
                $ignoreId
            );
            if ($facultyConflict) {
                $violations[] = $facultyConflict;
            }
        }

        $sectionConflict = $this->checkSectionConflict(
            $attempt['section_id'],
            $attempt['semester_id'],
            $attempt['day'],
            $attempt['start_time'],
            $attempt['end_time'],
            $ignoreId
        );
        if ($sectionConflict) {
            $violations[] = $sectionConflict;
        }

        $subjectSectionConflict = $this->checkSubjectSectionConflict(
            (int) ($attempt['course_id'] ?? $attempt['subject_id'] ?? 0),
            (int) $attempt['section_id'],
            (int) $attempt['semester_id'],
            (string) $attempt['day'],
            (string) $attempt['start_time'],
            (string) $attempt['end_time'],
            (string) ($attempt['mode'] ?? 'on-site'),
            $ignoreId,
        );
        if ($subjectSectionConflict) {
            $violations[] = $subjectSectionConflict;
        }

        $courseId = $attempt['course_id'] ?? $attempt['subject_id'] ?? 0;
        $roomTypeSection = isset($attempt['section_id'])
            ? $this->remember('section:'.$attempt['section_id'], fn () => Sections::find($attempt['section_id']))
            : null;
        $roomTypeMatch = $this->checkRoomTypeMatch(
            $courseId,
            isset($attempt['room_id']) ? (int) $attempt['room_id'] : null,
            (string) ($attempt['mode'] ?? 'on-site'),
            $attempt['meeting_type'] ?? null,
            $roomTypeSection?->department_id === null ? null : (int) $roomTypeSection->department_id,
        );
        if ($roomTypeMatch) {
            $violations[] = $roomTypeMatch;
        }

        $patternCheck = $this->checkPreferredPattern(
            $attempt['day'],
            $attempt['preferred_pattern'] ?? null
        );
        if ($patternCheck) {
            $violations[] = $patternCheck;
        }

        $hoursCheck = $this->checkOperatingHours(
            $attempt['start_time'],
            $attempt['end_time']
        );
        if ($hoursCheck) {
            $violations[] = $hoursCheck;
        }

        if (($attempt['mode'] ?? 'on-site') === 'online') {
            $onlineCapacityViolation = $this->checkOnlineCapacity(
                (int) $attempt['semester_id'],
                (string) $attempt['day'],
                (string) $attempt['start_time'],
                (string) $attempt['end_time'],
                $ignoreId,
                isset($attempt['department_id']) ? (int) $attempt['department_id'] : null,
            );
            if ($onlineCapacityViolation) {
                $violations[] = $onlineCapacityViolation;
            }

            $onlineLimitViolation = $this->checkSectionOnlineLimit(
                (int) $attempt['section_id'],
                (int) $attempt['semester_id'],
                $ignoreId
            );
            if ($onlineLimitViolation) {
                $violations[] = $onlineLimitViolation;
            }
        }

        // An instructor conflict someone already chose to override does not come
        // back on the next save of the same meeting.
        return FacultyConflictOverride::withoutStanding($attempt, $violations);
    }

    /**
     * Validate linked meeting shapes that cannot be judged one row at a time.
     * Legacy custom `days:x-y` groups are intentionally left alone; only the
     * explicit Generator configurations are covered here.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function validateConfiguredMeetingGroups(array $operations): array
    {
        $violations = [];
        $groups = collect($operations)
            ->filter(static fn (array $operation): bool => ! empty($operation['split_group_id']))
            ->groupBy(static fn (array $operation): string => (string) $operation['split_group_id']);

        foreach ($groups as $groupId => $group) {
            $rows = $group->values();
            $first = $rows->first();
            if (! is_array($first)) {
                continue;
            }

            $isHybrid = $rows->contains(static fn (array $row): bool => (bool) ($row['is_hybrid'] ?? false));
            $pattern = SchedulingPolicy::normalizePreferredPattern($first['preferred_pattern'] ?? null);
            $isMinorSplit = ! $isHybrid && in_array($pattern, ['MW', 'TTh'], true);

            if (! $isHybrid && ! $isMinorSplit) {
                continue;
            }

            if ($rows->count() !== 2) {
                $violations[] = [
                    'rule' => $isHybrid ? 'hybrid_component_count' : 'minor_split_component_count',
                    'message' => $isHybrid
                        ? 'Hybrid scheduling requires exactly one online lecture and one on-site laboratory meeting.'
                        : 'Split Session scheduling requires exactly two linked meetings.',
                    'split_group_id' => $groupId,
                ];
                continue;
            }

            $courseId = (int) ($first['course_id'] ?? $first['subject_id'] ?? 0);
            $course = $courseId > 0 ? $this->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
            if ($course === null) {
                continue;
            }

            if ($isHybrid) {
                $types = $rows->pluck('meeting_type')->sort()->values()->all();
                if ($types !== ['laboratory', 'lecture']) {
                    $violations[] = [
                        'rule' => 'hybrid_components',
                        'message' => 'Hybrid scheduling requires one lecture component and one laboratory component.',
                        'split_group_id' => $groupId,
                    ];
                }
                continue;
            }

            $sectionId = (int) ($first['section_id'] ?? 0);
            $section = $sectionId > 0
                ? $this->remember('section:'.$sectionId, fn () => Sections::with('department')->find($sectionId))
                : null;
            if (! SchedulingPolicy::balancedSplitEligible(
                $course,
                SchedulingPolicy::balancedSplitSettings($section?->department),
            )) {
                $violations[] = [
                    'rule' => 'minor_split_eligibility',
                    'message' => 'Split Session is available only for minor courses, or lecture-only majors once Major Lecture Split Sessions is enabled.',
                    'split_group_id' => $groupId,
                ];
                continue;
            }

            $expectedDays = $pattern === 'MW' ? ['Monday', 'Wednesday'] : ['Tuesday', 'Thursday'];
            $actualDays = $rows->pluck('day')->sort()->values()->all();
            sort($expectedDays);
            if ($actualDays !== $expectedDays) {
                $violations[] = [
                    'rule' => 'minor_split_pattern',
                    'message' => "Split Session {$pattern} meetings must use the configured day pair.",
                    'split_group_id' => $groupId,
                ];
            }

            $totalMinutes = $rows->sum(fn (array $row): int => max(0,
                (int) ($this->timeToMinutes((string) ($row['end_time'] ?? '')) ?? 0)
                - (int) ($this->timeToMinutes((string) ($row['start_time'] ?? '')) ?? 0)
            ));
            $expectedMinutes = max(1, (int) round((float) ($course->units ?? 0) * 60));
            if ($totalMinutes !== $expectedMinutes) {
                $violations[] = [
                    'rule' => 'minor_split_duration',
                    'message' => 'Split Session meeting durations must add up to the course contact hours used by the Schedule Generator.',
                    'split_group_id' => $groupId,
                ];
            }
        }

        return $violations;
    }

    private function checkConfiguredMeetingShape(array $attempt): ?array
    {
        if (! (bool) ($attempt['is_hybrid'] ?? false)) {
            return null;
        }

        $courseId = (int) ($attempt['course_id'] ?? $attempt['subject_id'] ?? 0);
        $sectionId = (int) ($attempt['section_id'] ?? 0);
        $course = $courseId > 0 ? $this->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
        $section = $sectionId > 0
            ? $this->remember('section:'.$sectionId, fn () => Sections::with('department')->find($sectionId))
            : null;
        if ($course === null || $section === null) {
            return null;
        }

        if (! $section->department?->lecture_lab_schedule_override_enabled
            || ! SchedulingPolicy::isMajorCourse($course)
            || (int) ($course->lecture_hours ?? 0) <= 0
            || (int) ($course->lab_hours ?? 0) <= 0) {
            return [
                'rule' => 'hybrid_eligibility',
                'message' => 'Hybrid scheduling is available only for major courses with both lecture and laboratory hours when the department setting is enabled.',
            ];
        }

        $meetingType = $attempt['meeting_type'] ?? null;
        $expected = match ($meetingType) {
            'lecture' => ['mode' => 'online', 'minutes' => (int) $course->lecture_hours * 60],
            'laboratory' => ['mode' => 'on-site', 'minutes' => SchedulingPolicy::laboratoryComponentMinutes($course, $section->department)],
            default => null,
        };
        if ($expected === null) {
            return [
                'rule' => 'hybrid_component_type',
                'message' => 'Hybrid schedules must identify each meeting as lecture or laboratory.',
            ];
        }

        $durationMinutes = (int) ($this->timeToMinutes((string) ($attempt['end_time'] ?? '')) ?? 0)
            - (int) ($this->timeToMinutes((string) ($attempt['start_time'] ?? '')) ?? 0);
        if (($attempt['mode'] ?? 'on-site') !== $expected['mode'] || $durationMinutes !== $expected['minutes']) {
            return [
                'rule' => 'hybrid_component_shape',
                'message' => $meetingType === 'lecture'
                    ? 'The Hybrid lecture must be online and use the Generator lecture duration.'
                    : 'The Hybrid laboratory must be on-site and use the Generator laboratory duration.',
            ];
        }

        return null;
    }

    private function checkForcedCourseDay(int $courseId, int $departmentId, string $day): ?array
    {
        $forcedDay = $this->remember(
            "forcedDay:{$departmentId}:{$courseId}",
            fn () => DB::table('department_forced_course_days')
                ->where('department_id', $departmentId)
                ->where('course_id', $courseId)
                ->value('day'),
        );

        if ($forcedDay === null || $forcedDay === $day) {
            return null;
        }

        return [
            'rule' => 'forced_course_day',
            'message' => "This course is configured to meet on {$forcedDay}.",
            'required_day' => $forcedDay,
        ];
    }

    private function checkOnlineCapacity(
        int $semesterId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null,
        ?int $departmentId = null,
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);
        $overlapCount = Schedule::query()
            ->where('semester_id', $semesterId)
            ->where('mode', 'online')
            ->where('day', $day)
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->count();

        $onlineLimit = $departmentId !== null
            ? $this->resourceLimits->online($departmentId)
            : 1;

        if ($overlapCount >= $onlineLimit) {
            return [
                'rule' => 'online_capacity_conflict',
                'message' => "Online capacity is full for this department on {$day} from {$startTime} to {$endTime}. Configured concurrent online classes: {$onlineLimit}.",
            ];
        }

        return null;
    }

    private function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', SchedulingPolicy::normalizeTime($time));

        if (count($parts) < 2) {
            return null;
        }

        if (! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function normalizeIgnoreScheduleIds(int|array|null $ignoreScheduleId): array
    {
        if ($ignoreScheduleId === null) {
            return [];
        }

        $ids = is_array($ignoreScheduleId) ? $ignoreScheduleId : [$ignoreScheduleId];

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * Enforces which days each course type may be scheduled on:
     *
     *  - NSTP (ROTC/CWTS/LTS)       : Monday-Sunday.
     *  - PATHFIT / other field (non-NSTP): Monday–Friday only.
     *  - Minor non-field (GEC, GEE): Monday-Saturday.
     *  - Major on Sunday            : online mode only (no room assignment).
     */
    private function checkDayCategoryConstraint(
        Course $course,
        string $day,
        string $mode,
        Sections $section,
    ): ?array {
        if ($this->isNstpCourse($course)) {
            if (! in_array($day, SchedulingPolicy::DAYS, true)) {
                return [
                    'rule' => 'nstp_day_constraint',
                    'message' => 'NSTP/ROTC/CWTS/LTS courses must be scheduled Monday through Sunday.',
                ];
            }

            return null;
        }

        if (SchedulingPolicy::isFieldCourse($course, (int) $section->department_id)) {
            // Non-NSTP field courses (PATHFIT, etc.): Mon–Fri only.
            if (! in_array($day, SchedulingPolicy::WEEKDAYS, true)) {
                return [
                    'rule' => 'field_day_constraint',
                    'message' => 'PATHFIT and other field courses must be scheduled Monday through Friday.',
                ];
            }

            return null;
        }

        $category = strtolower((string) ($course->course_category ?? 'major'));

        if ($category === 'minor') {
            if (! in_array($day, SchedulingPolicy::WEEKDAYS_AND_SATURDAY, true)) {
                return [
                    'rule' => 'minor_day_constraint',
                    'message' => 'Minor courses (GEC, GEE, and similar) must be scheduled Monday through Saturday.',
                ];
            }

            return null;
        }

        // Major courses: any day Mon–Sat; Sunday requires online mode.
        $sundayOnlineOnlyEnabled = (bool) $this->remember(
            'sundayOnlineOnly:'.(int) $section->department_id,
            fn () => Departments::query()
                ->whereKey((int) $section->department_id)
                ->value('sunday_online_only_enabled') ?? true,
        );

        if ($sundayOnlineOnlyEnabled && $day === 'Sunday' && $mode !== 'online') {
            return [
                'rule' => 'major_sunday_mode_constraint',
                'message' => 'Major courses scheduled on Sunday must use online delivery mode.',
            ];
        }

        return null;
    }

    /**
     * Returns true when the course is an NSTP-type course (ROTC, CWTS, or LTS).
     */
    private function isNstpCourse(Course $course): bool
    {
        return SchedulingPolicy::isNstpCourse($course);
    }

    /** End of the ordinary field-course day, mirroring CspSolver::FIELD_DAY_END_TIME. */

    /**
     * Field courses stop at 17:00 unless the department opts into evening use.
     *
     * The setting was previously read only by CspSolver, so it steered generation
     * but not manual placement — the Settings page promised a limit that a
     * drag-and-drop could ignore (audit finding #41).
     */
    private function checkFieldEveningWindow(
        Course $course,
        string $endTime,
        string $mode,
        Sections $section,
    ): ?array {
        $isFieldPlacement = $mode === 'field' || SchedulingPolicy::isFieldCourse($course, (int) $section->department_id);
        if (! $isFieldPlacement) {
            return null;
        }

        $eveningEnabled = (bool) $this->remember(
            'fieldEvening:'.(int) $section->department_id,
            fn () => Departments::query()
                ->whereKey((int) $section->department_id)
                ->value('field_evening_schedule_enabled') ?? false,
        );
        if ($eveningEnabled) {
            return null;
        }

        if (SchedulingPolicy::normalizeTime($endTime) <= SchedulingPolicy::FIELD_DAY_END_TIME) {
            return null;
        }

        return [
            'rule' => 'field_evening_window',
            'message' => 'Field courses must end by 5:00 PM unless evening field scheduling is enabled for this department.',
        ];
    }
    /**
     * Enforces that a single section does not exceed 5 online classes.
     */
    private function checkSectionOnlineLimit(
        int $sectionId,
        int $semesterId,
        int|array|null $ignoreId
    ): ?array {
        $ignoreIds = $this->normalizeIgnoreScheduleIds($ignoreId);

        // If updating an existing schedule that is ALREADY online in the database,
        // it is not adding a new online class to the section.
        if (! empty($ignoreIds)) {
            $alreadyOnline = Schedule::whereIn('id', $ignoreIds)
                ->where('mode', 'online')
                ->exists();
            if ($alreadyOnline) {
                return null;
            }
        }

        $query = Schedule::where('section_id', $sectionId)
            ->where('semester_id', $semesterId)
            ->where('mode', 'online');

        if (! empty($ignoreIds)) {
            $query->whereNotIn('id', $ignoreIds);
        }

        if ($query->distinct('course_id')->count('course_id') >= 5) {
            return [
                'rule' => 'section_online_limit',
                'message' => 'A section cannot have more than 5 online classes.',
            ];
        }

        return null;
    }

}
