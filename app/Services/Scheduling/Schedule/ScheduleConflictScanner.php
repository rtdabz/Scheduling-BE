<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

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

        if ($departmentId !== null
            && (int) ($case->schedule['department_id'] ?? 0) !== $departmentId
            && (int) ($case->otherSchedule['department_id'] ?? 0) !== $departmentId) {
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
        return DB::table('schedules')
            ->leftJoin('courses', 'courses.id', '=', 'schedules.course_id')
            ->leftJoin('sections', 'sections.id', '=', 'schedules.section_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'schedules.room_id')
            ->leftJoin('faculties', 'faculties.id', '=', 'schedules.faculty_id')
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
            ->all();
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
