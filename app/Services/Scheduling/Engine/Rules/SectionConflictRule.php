<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Schedule;
use App\Services\Scheduling\Support\ConflictMessage;

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
            'message' => "Section already has a class on {$day} from ".ConflictMessage::time((string) $conflict->start_time)
                .' to '.ConflictMessage::time((string) $conflict->end_time).' '
                ."({$conflict->course?->course_code}){$more}.",
            'conflicting_schedule_id' => $conflict->id,
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }
}
