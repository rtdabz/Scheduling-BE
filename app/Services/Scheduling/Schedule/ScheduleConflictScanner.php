<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use Illuminate\Support\Facades\DB;

/**
 * Derives the conflicts that currently exist between *persisted* meetings.
 *
 * This is not a second conflict engine. BatchConflictValidator already decides
 * what makes two rows clash -- same section, same exclusive room, same
 * instructor, one online course serving two sections -- and it is the same
 * class the batch save path uses. All this adds is the scope query and the
 * pairing of results back to schedule ids, so a saved timetable can be asked
 * the question the save path asks of candidate rows.
 *
 * A scan is the only thing that can declare a conflict resolved: the client
 * clicking "Apply" proves nothing, and `schedules.status` is an operational
 * workflow field that must never carry a conflict lifecycle.
 */
final class ScheduleConflictScanner
{
    public function __construct(private readonly BatchConflictValidator $batchConflicts) {}

    /**
     * Every conflict in a semester that touches the given scope.
     *
     * The *comparison* is always semester-wide even when a department is given:
     * a room clash with another college's class is exactly the kind the owning
     * department cannot see on its own timetable, and narrowing the query would
     * hide it. Narrowing happens after pairing, on the conflicts themselves.
     *
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
        // Two rows can only clash on the same day, and the validator's pass is
        // O(n^2) over whatever it is handed. Feeding it one day at a time keeps
        // a semester-wide scan from comparing Monday against Friday.
        foreach ($this->groupByDay($rowsById) as $dayRows) {
            if (count($dayRows) < 2) {
                continue;
            }

            foreach ($this->batchConflicts->validate($dayRows) as $conflict) {
                $case = ScheduleConflictCase::fromBatchConflict($conflict, $rowsById);
                // One pair can break several rules at once; each is its own case
                // with its own id, but the same rule must not appear twice.
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
     * The conflicts a resolution introduced: present after the change and not
     * before it. Used to refuse a "fix" that only moved the problem, without
     * refusing it over a pre-existing conflict elsewhere that the user was
     * never asked about.
     *
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
     * Read with the query builder and explicit columns. Hydrating models here
     * would pull the `split` relation on every row for a scan that never reads
     * it, and joining labels is cheaper than a second pass of lookups.
     *
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
            // The query builder does not carry the model's SoftDeletes scope,
            // so an archived meeting would be scanned as if it were still on
            // the timetable -- and reported as a conflict against a class the
            // grid does not even show.
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
