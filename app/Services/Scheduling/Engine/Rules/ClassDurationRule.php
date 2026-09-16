<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Departments;
use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * class_duration: a section's meetings for one course may not add up to more
 * weekly time than the course carries.
 *
 * Meetings are judged together, not one at a time. A course may legitimately be
 * one block, an MWF set of shorter meetings, a Split Session pair or a
 * lecture/laboratory split, so no single meeting length is "the" right one; and
 * meetings are placed one by one, so a total still short of the course is
 * normal while a timetable is being built. What is never valid is going over:
 * that is a duplicated placement or a stretched class.
 *
 * Meetings in the same batch are not counted against each other here, the same
 * as every other RuleEngine rule; each is judged against what is persisted.
 *
 * The ceiling is the larger of the Schedule Generator's two shapes — one block
 * of `units × 2` slots, or a lecture/laboratory split of `lecture_hours × 2`
 * slots plus the department's laboratory length — so anything the Generator
 * can produce always fits. (`lecture_hours`/`lab_hours` hold units.)
 */
final class ClassDurationRule
{
    /** Rows that no longer occupy the timetable; counting them would double count. */
    private const INACTIVE_STATUSES = ['rejected', 'revision'];

    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function check(array $attempt, AttemptRecords $records): ?array
    {
        $course = $records->course;
        $allowedMinutes = $this->allowedWeeklyMinutes($records);
        if ($allowedMinutes <= 0) {
            return null;
        }

        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);
        $rows = Schedule::query()
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('section_id', (int) $records->section->id)
            ->where('course_id', (int) $course->id)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->get(['id', 'start_time', 'end_time']);
        $minutesOf = static fn (Schedule $row): int => max(0, RuleSupport::durationMinutes((string) $row->start_time, (string) $row->end_time));

        // Before this save: every live meeting, including the ones being edited
        // or replaced. After: the untouched ones plus this attempt.
        $minutesBefore = $rows->sum($minutesOf);
        $totalMinutes = $rows->reject(static fn (Schedule $row): bool => in_array((int) $row->id, $ignoreIds, true))->sum($minutesOf)
            + max(0, RuleSupport::durationMinutes((string) $attempt['start_time'], (string) $attempt['end_time']));

        // Only a save that adds time past the ceiling is refused. Data that was
        // already over must not block unrelated edits such as assigning an
        // instructor or moving a meeting without lengthening it.
        if ($totalMinutes <= $allowedMinutes || $totalMinutes <= $minutesBefore) {
            return null;
        }

        return [
            'rule' => 'class_duration',
            'message' => sprintf(
                '%s would meet %s a week for this section, but the course carries at most %s.',
                (string) $course->course_code,
                $this->hours($totalMinutes),
                $this->hours($allowedMinutes),
            ),
            'scheduled_minutes' => $totalMinutes,
            'allowed_minutes' => $allowedMinutes,
        ];
    }

    private function allowedWeeklyMinutes(AttemptRecords $records): int
    {
        $course = $records->course;
        $singleBlock = (int) round((float) ($course->units ?? 0) * 60);

        $lectureMinutes = max(0, (int) ($course->lecture_hours ?? 0)) * SchedulingPolicy::LECTURE_SLOTS_PER_UNIT * SchedulingPolicy::SLOT_MINUTES;
        $laboratoryMinutes = (int) ($course->lab_hours ?? 0) > 0
            ? SchedulingPolicy::laboratoryComponentMinutes($course, $this->labDurationSettings($records->departmentId()))
            : 0;

        return max($singleBlock, $lectureMinutes + $laboratoryMinutes);
    }

    /**
     * Only the Custom Lab Duration columns: a department row also carries its
     * logo inline as base64, which a validation pass has no use for.
     */
    private function labDurationSettings(int $departmentId): ?Departments
    {
        return $this->lookups->remember('labDurationSettings:'.$departmentId, fn () => Departments::query()
            ->select([
                'id',
                'custom_lab_duration_override_enabled',
                'custom_lab_duration_6_hours_enabled',
                'custom_lab_duration_5_hours_enabled',
                'custom_lab_duration_other_enabled',
                'custom_lab_duration_minutes',
            ])
            ->find($departmentId));
    }

    private function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').($hours === 1.0 ? ' hour' : ' hours');
    }
}
