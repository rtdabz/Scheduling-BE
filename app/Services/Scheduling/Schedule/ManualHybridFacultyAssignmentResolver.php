<?php

namespace App\Services\Scheduling\Schedule;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Collection;

class ManualHybridFacultyAssignmentResolver
{
    /** @return Collection<int, Schedule> */
    public function resolve(Schedule $schedule): Collection
    {
        $schedule->loadMissing('split');

        $hasComponentGroup = $schedule->split_group_id !== null
            && in_array($schedule->meeting_type, ['lecture', 'laboratory'], true);

        if (! $hasComponentGroup && ! (bool) $schedule->is_hybrid) {
            return new Collection([$schedule]);
        }

        $query = Schedule::query()
            ->where('term_id', $schedule->term_id)
            ->where('section_id', $schedule->section_id)
            ->where('course_id', $schedule->course_id)
            ->where('department_id', $schedule->department_id);

        if ($hasComponentGroup) {
            $query->whereHas(
                'split',
                fn ($split) => $split
                    ->where('split_group_id', $schedule->split_group_id)
                    ->whereIn('meeting_type', ['lecture', 'laboratory']),
            );
        } else {
            $query->where('is_hybrid', true);
        }

        $components = $query->get();

        return $components->contains('id', $schedule->id)
            ? $components
            : $components->push($schedule);
    }
}
