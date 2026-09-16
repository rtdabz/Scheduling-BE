<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Schedule;

/**
 * faculty_conflict: the instructor already teaches an overlapping class.
 *
 * Every clashing meeting is reported, so an override can mark all of them; a
 * clash someone chose to override is filtered out afterwards by
 * FacultyConflictOverride.
 */
final class InstructorConflictRule
{
    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function check(array $attempt): ?array
    {
        if (empty($attempt['faculty_id'])) {
            return null;
        }

        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflicts = Schedule::where('faculty_id', $attempt['faculty_id'])
            ->where('semester_id', $attempt['semester_id'])
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', $attempt['end_time'])
            ->where('end_time', '>', $attempt['start_time'])
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
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }
}
