<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Support;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SchedulingSnapshotRepository
{
    public function __construct(
        private readonly SchedulingSnapshotFingerprint $fingerprint,
        private readonly SchedulingQueryCounter $queryCounter,
        private readonly SectionCurriculumResolver $curricula,
    ) {}

    public function captureForConfiguration(int $semesterId, int $departmentId, GenerationConfiguration $configuration): SchedulingSnapshot
    {
        return $this->capture(
            semesterId: $semesterId,
            departmentId: $departmentId,
            sectionIds: [$configuration->sectionId],
            courseIds: $configuration->courseIds,
        );
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  list<int>  $courseIds
     * @param  list<array{0: int, 1: int}>|null  $replacedClasses  the exact [section id, course id] pairs a
     *                                                             commit replaces; when given, only their rows are left out rather than
     *                                                             every target section's rows of every target course
     */
    public function capture(
        int $semesterId,
        int $departmentId,
        array $sectionIds = [],
        array $courseIds = [],
        bool $includeFaculties = true,
        ?array $replacedClasses = null,
    ): SchedulingSnapshot {
        $queryCountBefore = $this->queryCounter->total();
        $startedAt = microtime(true);

        /** @var Semester $semester */
        $semester = Semester::query()->findOrFail($semesterId);
        /** @var Departments $department */
        $department = Departments::query()->findOrFail($departmentId);

        $sectionIds = $this->positiveIds($sectionIds);
        $courseIds = $this->positiveIds($courseIds);

        $sectionsQuery = Sections::query()
            ->where('semester_id', $semesterId)
            ->where('department_id', $departmentId)
            ->orderBy('id');
        if ($sectionIds !== []) {
            $sectionsQuery->whereIn('id', $sectionIds);
        }
        $sections = $sectionsQuery->get();

        $curriculumIdBySectionId = [];
        foreach ($sections as $section) {
            try {
                $curriculumIdBySectionId[(int) $section->id] = (int) $this->curricula->forSection($section)->id;
            } catch (InvalidArgumentException) {
            }
        }

        $curriculumIds = array_values(array_unique($curriculumIdBySectionId));
        sort($curriculumIds);

        $curricula = $curriculumIds === []
            ? collect()
            : Curriculum::query()
                ->whereIn('id', $curriculumIds)
                ->orderBy('id')
                ->get(['id', 'name', 'department_id', 'program_id', 'code', 'effective_school_year', 'status']);

        $scopedPeriods = $this->curricula->periodsForMany($curriculumIds, $courseIds);

        $curriculumPeriods = $scopedPeriods
            ->keyBy(static fn (object $period): int => (int) $period->course_id);

        if ($courseIds === []) {
            $courseIds = $curriculumPeriods->keys()->map('intval')->values()->all();
        }

        $courses = $courseIds === []
            ? collect()
            : Course::query()
                ->whereIn('id', $courseIds)
                ->orderBy('id')
                ->get();

        $schedules = Schedule::query()
            ->with('split')
            ->where('semester_id', $semesterId)
            ->when($sectionIds !== [] && $replacedClasses !== [], function ($query) use ($sectionIds, $courseIds, $replacedClasses): void {
                $query->where(function ($scope) use ($sectionIds, $courseIds, $replacedClasses): void {
                    $scope
                        ->whereNotIn('section_id', $sectionIds)
                        ->orWhereNotIn('status', ['draft', 'completed', 'revision']);
                    if ($replacedClasses !== null) {
                        $scope->orWhere(function ($kept) use ($replacedClasses): void {
                            foreach ($replacedClasses as [$sectionId, $courseId]) {
                                $kept->where(static fn ($pair) => $pair
                                    ->where('section_id', '!=', (int) $sectionId)
                                    ->orWhere('course_id', '!=', (int) $courseId));
                            }
                        });
                    } elseif ($courseIds !== []) {
                        $scope->orWhereNotIn('course_id', $courseIds);
                    }
                });
            })
            ->orderBy('id')
            ->get([
                'id', 'semester_id', 'section_id', 'course_id', 'faculty_id', 'room_id',
                'department_id', 'day', 'start_time', 'end_time', 'mode', 'is_hybrid',
                'preferred_pattern', 'faculty_assignment_done', 'status',
            ]);

        $referencedRoomIds = $schedules->pluck('room_id')->filter()->map('intval')->unique()->values()->all();
        $grantWindows = app(RoomAccessPolicy::class)->grantWindowsFor($departmentId, $semesterId);
        $grantedRoomIds = array_keys($grantWindows);
        $rooms = Rooms::query()
            ->where(function ($query) use ($departmentId, $referencedRoomIds, $grantedRoomIds): void {
                $query->whereNull('department_id')->orWhere('department_id', $departmentId);
                if ($grantedRoomIds !== []) {
                    $query->orWhereIn('id', $grantedRoomIds);
                }
                if ($referencedRoomIds !== []) {
                    $query->orWhereIn('id', $referencedRoomIds);
                }
            })
            ->orderBy('id')
            ->get();

        $faculties = $includeFaculties
            ? Faculty::query()->with('availabilities')->orderBy('id')->get()
            : collect();

        $forcedDays = DepartmentCourseRules::query($departmentId)
            ->whereNotNull('forced_day')
            ->when($courseIds !== [], fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderBy('course_id')
            ->pluck('forced_day', 'course_id')
            ->mapWithKeys(static fn (string $day, int|string $courseId): array => [(int) $courseId => $day])
            ->all();

        $consecutiveDayRules = DepartmentCourseRules::query($departmentId)
            ->whereNotNull('consecutive_day_count')
            ->when($courseIds !== [], fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderBy('course_id')
            ->orderBy('section_id')
            ->get(['course_id', 'section_id', 'consecutive_day_count', 'preferred_start_day', 'meeting_days'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'section_id' => $rule->section_id === null ? null : (int) $rule->section_id,
                'day_count' => (int) $rule->consecutive_day_count,
                'preferred_start_day' => $rule->preferred_start_day === null ? null : (string) $rule->preferred_start_day,
                'meeting_days' => SchedulingPolicy::parseMeetingDays($rule->meeting_days),
            ])
            ->all();

        $fieldCourseCodes = DepartmentCourseRules::fieldCourseCodes($departmentId);

        $roomRecords = $this->withVirtualRooms($this->roomRecords($rooms, $grantWindows));
        foreach (app(ProgramRoomShares::class)->forDepartment($departmentId, $semesterId) as $roomId => $days) {
            if (isset($roomRecords[$roomId])) {
                $roomRecords[$roomId]['program_days'] = $days;
            }
        }
        foreach (app(RoomAccessPolicy::class)->lentWindowsFor($departmentId, $semesterId) as $roomId => $windows) {
            if (isset($roomRecords[$roomId])) {
                $roomRecords[$roomId]['lent_windows'] = array_map(static fn (array $window): array => [
                    'day' => $window['day'],
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                    'borrower' => $window['borrower'],
                ], $windows);
            }
        }

        $payload = [
            'schema_version' => SchedulingSnapshot::SCHEMA_VERSION,
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'sections' => $this->sectionRecords($sections),
            'courses' => $this->courseRecords($courses, $curriculumPeriods),
            'rooms' => $roomRecords,
            'persisted_schedules' => $this->scheduleRecords($schedules),
            'faculties' => $this->facultyRecords($faculties),
            'forced_days_by_course_id' => $forcedDays,
            'consecutive_day_rules' => $consecutiveDayRules,
            'field_course_codes' => $fieldCourseCodes,
            'curriculum_periods_by_course_id' => $this->curriculumPeriodRecords($curriculumPeriods),
            'curriculum_periods_by_curriculum_course' => $this->scopedCurriculumPeriodRecords($scopedPeriods),
            'curriculum_id_by_section_id' => $curriculumIdBySectionId,
            'operating_hours' => [
                'opening_time' => SchedulingPolicy::openingTime(),
                'closing_time' => SchedulingPolicy::closingTime(),
                'field_end_time' => SchedulingPolicy::fieldDayEndTime(),
                'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
            ],
            'department_settings' => $this->departmentSettings($department),
            'semester' => [
                'id' => (int) $semester->id,
                'academic_year' => (string) $semester->academic_year,
                'semester' => (string) $semester->semester,
                'is_active' => (bool) $semester->is_active,
                'is_enabled' => (bool) $semester->is_enabled,
            ],
            'metadata' => [
                'active_curriculum_id' => $curriculumIds === [] ? null : $curriculumIds[0],
                'active_curriculum_ids' => $curriculumIds,
                'curriculum_names_by_id' => $curricula
                    ->mapWithKeys(static fn ($curriculum): array => [(int) $curriculum->id => (string) $curriculum->name])
                    ->all(),
                'requested_section_ids' => $sectionIds,
                'requested_course_ids' => $courseIds,
                'includes_faculties' => $includeFaculties,
            ],
        ];

        $snapshotFingerprint = $this->fingerprint->calculate($payload);
        $payload['metadata']['snapshot_query_count'] = max(
            0,
            $this->queryCounter->total() - $queryCountBefore,
        );
        $payload['metadata']['snapshot_elapsed_ms'] = max(
            0.0,
            (microtime(true) - $startedAt) * 1000,
        );

        return new SchedulingSnapshot(
            fingerprint: $snapshotFingerprint,
            capturedAt: new DateTimeImmutable,
            semesterId: $payload['semester_id'],
            departmentId: $payload['department_id'],
            sectionsById: $payload['sections'],
            coursesById: $payload['courses'],
            roomsById: $payload['rooms'],
            persistedSchedules: $payload['persisted_schedules'],
            facultiesById: $payload['faculties'],
            forcedDaysByCourseId: $payload['forced_days_by_course_id'],
            fieldCourseCodes: $payload['field_course_codes'],
            curriculumPeriodsByCourseId: $payload['curriculum_periods_by_course_id'],
            curriculumPeriodsByCurriculumCourse: $payload['curriculum_periods_by_curriculum_course'],
            curriculumIdBySectionId: $payload['curriculum_id_by_section_id'],
            operatingHours: $payload['operating_hours'],
            departmentSettings: $payload['department_settings'],
            semester: $payload['semester'],
            metadata: $payload['metadata'],
            consecutiveDayRules: $payload['consecutive_day_rules'],
        );
    }

    /** @return list<int> */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /** @return array<int, array<string, mixed>> */
    private function sectionRecords(Collection $sections): array
    {
        return $sections->mapWithKeys(static fn (Sections $section): array => [(int) $section->id => [
            'id' => (int) $section->id,
            'section_name' => (string) $section->section_name,
            'year_level' => (string) $section->year_level,
            'semester' => (string) $section->semester,
            'department_id' => (int) $section->department_id,
            'semester_id' => (int) $section->semester_id,
            'curriculum_id' => $section->curriculum_id === null ? null : (int) $section->curriculum_id,
            'program_id' => $section->program_id === null ? null : (int) $section->program_id,
            'status' => (string) $section->status,
        ]])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function courseRecords(Collection $courses, Collection $periods): array
    {
        return $courses->mapWithKeys(static function (Course $course) use ($periods): array {
            $period = $periods->get((int) $course->id);

            return [(int) $course->id => [
                'id' => (int) $course->id,
                'course_code' => (string) $course->course_code,
                'course_name' => (string) $course->course_name,
                'lecture_hours' => (int) $course->lecture_hours,
                'lab_hours' => (int) $course->lab_hours,
                'units' => (float) $course->units,
                'course_category' => (string) $course->course_category,
                'room_type_required' => (string) $course->room_type_required,
                'year_level' => $period === null ? (string) $course->year_level : (string) $period->year_level,
                'semester' => $period === null
                    ? (string) $course->semester
                    : ((string) $period->semester === '1'
                        ? '1st'
                        : ((string) $period->semester === '2' ? '2nd' : 'summer')),
                'department_id' => $course->department_id === null ? null : (int) $course->department_id,
                'teaching_department_id' => $course->teaching_department_id === null ? null : (int) $course->teaching_department_id,
                'teaching_program_id' => $course->teaching_program_id === null ? null : (int) $course->teaching_program_id,
                'program_id' => $course->program_id === null ? null : (int) $course->program_id,
                'status' => (string) $course->status,
            ]];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function roomRecords(Collection $rooms, array $grantWindows = []): array
    {
        return $rooms->mapWithKeys(static fn (Rooms $room): array => [(int) $room->id => [
            ...(isset($grantWindows[(int) $room->id]) ? ['grant_windows' => array_map(
                static fn (array $window): array => [
                    'day' => $window['day'],
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                ],
                $grantWindows[(int) $room->id],
            )] : []),
            'id' => (int) $room->id,
            'room_code' => (string) $room->room_code,
            'building' => $room->building === null ? null : (string) $room->building,
            'room_type' => (string) $room->room_type,
            'allow_lecture_usage' => (bool) $room->allow_lecture_usage,
            'status' => (string) $room->status,
            'department_id' => $room->department_id === null ? null : (int) $room->department_id,
        ]])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rooms
     * @return array<int, array<string, mixed>>
     */
    private function withVirtualRooms(array $rooms): array
    {
        $types = array_column($rooms, 'room_type');
        foreach (['online' => 99998, 'field' => 99999] as $type => $id) {
            if (in_array($type, $types, true)) {
                continue;
            }

            $rooms[$id] = [
                'id' => $id,
                'room_code' => strtoupper($type),
                'building' => null,
                'room_type' => $type,
                'allow_lecture_usage' => false,
                'status' => 'available',
                'department_id' => null,
                'virtual' => true,
            ];
        }

        ksort($rooms);

        return $rooms;
    }

    /** @return list<array<string, mixed>> */
    private function scheduleRecords(Collection $schedules): array
    {
        return $schedules->map(static fn (Schedule $schedule): array => [
            'id' => (int) $schedule->id,
            'semester_id' => (int) $schedule->semester_id,
            'section_id' => (int) $schedule->section_id,
            'course_id' => (int) $schedule->course_id,
            'faculty_id' => $schedule->faculty_id === null ? null : (int) $schedule->faculty_id,
            'room_id' => $schedule->room_id === null ? null : (int) $schedule->room_id,
            'department_id' => (int) $schedule->department_id,
            'day' => (string) $schedule->day,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'mode' => (string) $schedule->mode,
            'is_hybrid' => (bool) $schedule->is_hybrid,
            'preferred_pattern' => $schedule->preferred_pattern === null ? null : (string) $schedule->preferred_pattern,
            'faculty_assignment_done' => (bool) $schedule->faculty_assignment_done,
            'split_group_id' => $schedule->split_group_id,
            'meeting_type' => $schedule->meeting_type,
            'meeting_index' => $schedule->meeting_index,
            'status' => (string) $schedule->status,
        ])
            ->unique(static fn (array $schedule): string => implode('|', [
                $schedule['semester_id'],
                $schedule['section_id'],
                $schedule['course_id'],
                $schedule['faculty_id'] ?? '',
                $schedule['room_id'] ?? '',
                $schedule['department_id'],
                $schedule['day'],
                $schedule['start_time'],
                $schedule['end_time'],
                $schedule['mode'],
                $schedule['is_hybrid'] ? '1' : '0',
                $schedule['preferred_pattern'] ?? '',
                $schedule['meeting_type'] ?? '',
                $schedule['status'],
            ]))->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function facultyRecords(Collection $faculties): array
    {
        return $faculties->mapWithKeys(static fn (Faculty $faculty): array => [(int) $faculty->id => [
            'id' => (int) $faculty->id,
            'first_name' => (string) $faculty->first_name,
            'last_name' => (string) $faculty->last_name,
            'employment_type' => (string) $faculty->employment_type,
            'max_units' => (int) $faculty->max_units,
            'overload_units' => (int) $faculty->overload_units,
            'deload_units' => (int) $faculty->deload_units,
            'probono_units' => (int) $faculty->probono_units,
            'department_id' => $faculty->department_id === null ? null : (int) $faculty->department_id,
            'program_id' => $faculty->program_id === null ? null : (int) $faculty->program_id,
            'status' => (string) $faculty->status,
            'availabilities' => $faculty->availabilities
                ->sortBy(static fn ($availability): string => sprintf(
                    '%d:%s',
                    (int) $availability->day_index,
                    (string) $availability->start_time,
                ))
                ->values()
                ->map(static fn ($availability): array => [
                    'day_index' => (int) $availability->day_index,
                    'start_time' => substr((string) $availability->start_time, 0, 5),
                    'end_time' => substr((string) $availability->end_time, 0, 5),
                ])->all(),
        ]])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function curriculumPeriodRecords(Collection $periods): array
    {
        return $periods->mapWithKeys(static fn (object $period): array => [(int) $period->course_id => [
            'course_id' => (int) $period->course_id,
            'year_level' => (int) $period->year_level,
            'semester' => (int) $period->semester,
        ]])->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scopedCurriculumPeriodRecords(Collection $periods): array
    {
        return $periods->mapWithKeys(static fn (object $period): array => [
            SectionCurriculumResolver::periodKey((int) $period->curriculum_id, (int) $period->course_id) => [
                'curriculum_id' => (int) $period->curriculum_id,
                'course_id' => (int) $period->course_id,
                'year_level' => (int) $period->year_level,
                'semester' => (int) $period->semester,
            ],
        ])->all();
    }

    /** @return array<string, mixed> */
    private function departmentSettings(Departments $department): array
    {
        return [
            'scheduling_profile' => (string) ($department->scheduling_profile ?? 'standard'),
            'lecture_lab_schedule_override_enabled' => (bool) $department->lecture_lab_schedule_override_enabled,
            'custom_lab_duration_override_enabled' => (bool) $department->custom_lab_duration_override_enabled,
            'custom_lab_duration_minutes' => $department->custom_lab_duration_minutes === null ? null : (int) $department->custom_lab_duration_minutes,
            'custom_lab_duration_6_hours_enabled' => (bool) $department->custom_lab_duration_6_hours_enabled,
            'custom_lab_duration_5_hours_enabled' => (bool) $department->custom_lab_duration_5_hours_enabled,
            'custom_lab_duration_other_enabled' => (bool) $department->custom_lab_duration_other_enabled,
            'gec_split_schedule_override_enabled' => (bool) $department->gec_split_schedule_override_enabled,
            'major_lecture_split_schedule_override_enabled' => (bool) $department->major_lecture_split_schedule_override_enabled,
            'sunday_classes_enabled' => (bool) $department->sunday_classes_enabled,
        ];
    }
}
