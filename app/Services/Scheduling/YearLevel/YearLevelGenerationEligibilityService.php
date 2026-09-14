<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Support\Collection;

final class YearLevelGenerationEligibilityService
{
    public const BLOCKED_MESSAGE = 'Schedules for this year level have already been plotted. Generation is unavailable unless the entire year level is recalled.';

    private const PLOTTING_STATUSES = ['draft', 'completed'];

    /**
     * Ordinary plotting rows remain replaceable. Once withdrawal has started,
     * regeneration requires every active section to be fully in revision.
     *
     * @param  Collection<int, Sections>  $sections
     */
    public function canGenerate(Collection $sections, int $semesterId): bool
    {
        $sectionIds = $sections->pluck('id')->map('intval')->values();
        if ($sectionIds->isEmpty()) {
            return false;
        }

        $statusesBySection = Schedule::query()
            ->where('semester_id', $semesterId)
            ->whereIn('section_id', $sectionIds)
            ->get(['section_id', 'status'])
            ->groupBy(fn (Schedule $schedule): int => (int) $schedule->section_id);

        if ($statusesBySection->isEmpty()) {
            return true;
        }

        $statuses = $statusesBySection->flatten()->pluck('status');
        if (! $statuses->contains('revision')) {
            return $statuses->every(
                static fn (string $status): bool => in_array($status, self::PLOTTING_STATUSES, true),
            );
        }

        return $sectionIds->every(function (int $sectionId) use ($statusesBySection): bool {
            $statuses = $statusesBySection->get($sectionId, collect())->pluck('status');

            return $statuses->isNotEmpty()
                && $statuses->every(static fn (string $status): bool => $status === 'revision');
        });
    }
}
