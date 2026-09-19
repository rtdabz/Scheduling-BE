<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Schedule;

/**
 * section_conflict, subject_section_time_conflict.
 *
 * A section cannot attend two classes at once, and online sections taking the
 * same course must use different time windows. Physical and field delivery of
 * the same course are governed by their room and capacity rules instead.
 */
final class SectionConflictRule
{
    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function check(array $attempt): array
    {
        return array_values(array_filter([
            $this->sectionClash($attempt),
            $this->sameOnlineCourseClash($attempt),
        ]));
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    private function sectionClash(array $attempt): ?array
    {
        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflicts = Schedule::where('section_id', $attempt['section_id'])
            ->where('semester_id', $attempt['semester_id'])
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', $attempt['end_time'])
            ->where('end_time', '>', $attempt['start_time'])
            ->with('course')
            ->orderBy('start_time')
            ->get();

        if ($conflicts->isEmpty()) {
            return null;
        }

        $conflict = $conflicts->first();
        $more = $conflicts->count() > 1 ? ' and '.($conflicts->count() - 1).' more' : '';

        return [
            'rule' => 'section_conflict',
            'message' => "Section already has a class on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."({$conflict->course?->course_code}){$more}.",
            'conflicting_schedule_id' => $conflict->id,
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    private function sameOnlineCourseClash(array $attempt): ?array
    {
        if ((string) ($attempt['mode'] ?? 'on-site') !== 'online') {
            return null;
        }

        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflict = Schedule::query()
            ->where('course_id', RuleSupport::courseId($attempt))
            ->where('section_id', '!=', (int) $attempt['section_id'])
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('mode', 'online')
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', (string) $attempt['end_time'])
            ->where('end_time', '>', (string) $attempt['start_time'])
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
}
