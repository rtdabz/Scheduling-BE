<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;

final class ScheduleConflictScanner
{
    public function __construct(private readonly BatchConflictValidator $batchConflicts) {}

    /**
     * @param  list<int>  $onlyScheduleIds  when set, only conflicts touching one of these rows
     * @return list<ScheduleConflictCase>
     */
    public function scan(
        int $semesterId,
        ?int $departmentId = null,
        ?int $sectionId = null,
        array $onlyScheduleIds = [],
    ): array {
        if ($semesterId <= 0) {
            return [];
        }

        $rowsById = $this->rows($semesterId);
        if (count($rowsById) < 2) {
            return [];
        }

        $cases = [];
        foreach ($this->groupByDay($rowsById) as $dayRows) {
            if (count($dayRows) < 2) {
                continue;
            }

            foreach ($this->batchConflicts->validate($dayRows) as $conflict) {
                $case = ScheduleConflictCase::fromBatchConflict($conflict, $rowsById);
                $cases[$case->id()] = $case;
            }
        }

        $cases = array_values($cases);
        usort($cases, static fn (ScheduleConflictCase $a, ScheduleConflictCase $b): int => [$a->scheduleId, $a->rule] <=> [$b->scheduleId, $b->rule]);

        return array_values(array_filter(
            $cases,
            fn (ScheduleConflictCase $case): bool => $this->inScope($case, $departmentId, $sectionId, $onlyScheduleIds),
        ));
    }

    /**
     * @param  list<ScheduleConflictCase>  $before
     * @param  list<ScheduleConflictCase>  $after
     * @return list<ScheduleConflictCase>
     */
    public static function introduced(array $before, array $after): array
    {
        $known = array_flip(array_map(static fn (ScheduleConflictCase $case): string => $case->id(), $before));

        return array_values(array_filter(
            $after,
            static fn (ScheduleConflictCase $case): bool => ! isset($known[$case->id()]),
        ));
    }

    /**
     * @param  list<ScheduleConflictCase>  $before  conflicts touching the rows the write changed
     * @param  list<ScheduleConflictCase>  $after  conflicts touching the rows as they now are
     * @param  list<int>  $removedIds  rows the write deleted or replaced
     * @return list<ScheduleConflictCase>
     */
    public static function cleared(array $before, array $after, array $removedIds = []): array
    {
        $afterIds = array_flip(array_map(static fn (ScheduleConflictCase $case): string => $case->id(), $after));
        $new = self::introduced($before, $after);

        return array_values(array_filter($before, static function (ScheduleConflictCase $case) use ($afterIds, $new, $removedIds): bool {
            if (isset($afterIds[$case->id()])) {
                return false;
            }

            $stayed = array_values(array_diff($case->scheduleIds(), $removedIds));
            foreach ($new as $open) {
                foreach ($stayed as $stayedId) {
                    if ($open->rule === $case->rule && $open->involves($stayedId) && count($stayed) < 2) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * @param  list<ScheduleConflictCase>  $cases
     */
    public static function contains(array $cases, string $conflictId): bool
    {
        foreach ($cases as $case) {
            if ($case->id() === $conflictId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $onlyScheduleIds
     */
    private function inScope(ScheduleConflictCase $case, ?int $departmentId, ?int $sectionId, array $onlyScheduleIds): bool
    {
        if ($onlyScheduleIds !== []) {
            $touches = false;
            foreach ($onlyScheduleIds as $scheduleId) {
                $touches = $touches || $case->involves((int) $scheduleId);
            }
            if (! $touches) {
                return false;
            }
        }

        if ($departmentId !== null && ! in_array($departmentId, $case->owners()['department_ids'], true)) {
            return false;
        }

        return $sectionId === null
            || (int) ($case->schedule['section_id'] ?? 0) === $sectionId
            || (int) ($case->otherSchedule['section_id'] ?? 0) === $sectionId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $semesterId): array
    {
        return $this->withAssigningOwner(DB::table('schedules')
            ->leftJoin('courses', 'courses.id', '=', 'schedules.course_id')
            ->leftJoin('sections', 'sections.id', '=', 'schedules.section_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'schedules.room_id')
            ->leftJoin('faculties', 'faculties.id', '=', 'schedules.faculty_id')
            ->leftJoin('departments as class_departments', 'class_departments.id', '=', 'schedules.department_id')
            ->where('schedules.semester_id', $semesterId)
            ->whereNull('schedules.deleted_at')
            ->orderBy('schedules.id')
            ->get([
                'schedules.id',
                'schedules.semester_id',
                'schedules.section_id',
                'schedules.course_id',
                'schedules.faculty_id',
                'schedules.room_id',
                'schedules.department_id',
                'schedules.day',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.mode',
                'schedules.status',
                'courses.course_code',
                'courses.course_name',
                'sections.section_name',
                'rooms.room_code',
                'faculties.first_name as faculty_first_name',
                'faculties.last_name as faculty_last_name',
                'class_departments.department_code as department_code',
                'class_departments.department_name as department_name',
            ])
            ->mapWithKeys(static function (object $row): array {
                $schedule = (array) $row;
                $schedule['id'] = (int) $schedule['id'];
                $schedule['faculty_name'] = trim(implode(' ', array_filter([
                    $schedule['faculty_first_name'] ?? null,
                    $schedule['faculty_last_name'] ?? null,
                ]))) ?: null;
                unset($schedule['faculty_first_name'], $schedule['faculty_last_name']);

                return [$schedule['id'] => $schedule];
            })
            ->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withAssigningOwner(array $rows): array
    {
        $courseIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['course_id'] ?? 0),
            $rows,
        ))));
        $courses = $courseIds === [] ? collect() : Course::query()->whereIn('id', $courseIds)->get()->keyBy('id');

        $owners = [];
        foreach ($rows as $id => $row) {
            $course = $courses->get((int) ($row['course_id'] ?? 0));
            $classDepartmentId = $row['department_id'] !== null ? (int) $row['department_id'] : null;
            $owners[$id] = $course instanceof Course
                ? [
                    'department_id' => SchedulingPolicy::isMajorCourse($course)
                        ? SchedulingPolicy::majorTeachingDepartmentId($course, $classDepartmentId)
                        : SchedulingPolicy::assignedTeachingDepartmentId($course) ?? $classDepartmentId,
                    'program_id' => SchedulingPolicy::requiredTeachingProgramId($course),
                ]
                : ['department_id' => $classDepartmentId, 'program_id' => null];
        }

        $departmentIds = array_values(array_unique(array_filter(array_column($owners, 'department_id'))));
        $programIds = array_values(array_unique(array_filter(array_column($owners, 'program_id'))));
        $departments = $departmentIds === [] ? collect() : Departments::query()->whereIn('id', $departmentIds)->get(['id', 'department_code', 'department_name'])->keyBy('id');
        $programs = $programIds === [] ? collect() : Program::query()->whereIn('id', $programIds)->get(['id', 'code', 'name', 'major'])->keyBy('id');

        foreach ($rows as $id => $row) {
            $department = $departments->get($owners[$id]['department_id']);
            $program = $programs->get($owners[$id]['program_id']);
            $rows[$id]['assigning_department_id'] = $owners[$id]['department_id'];
            $rows[$id]['assigning_department_code'] = $department?->department_code;
            $rows[$id]['assigning_department_name'] = $department?->department_name;
            $rows[$id]['assigning_program_id'] = $owners[$id]['program_id'];
            $rows[$id]['assigning_program_code'] = $program === null
                ? null
                : trim($program->code.($program->major ? " {$program->major}" : ''));
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rowsById
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByDay(array $rowsById): array
    {
        $byDay = [];
        foreach ($rowsById as $id => $row) {
            $byDay[(string) ($row['day'] ?? '')][$id] = $row;
        }

        return $byDay;
    }
}
