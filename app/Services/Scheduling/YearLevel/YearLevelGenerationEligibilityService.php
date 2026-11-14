<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Support\Collection;

final class YearLevelGenerationEligibilityService
{
    public const BLOCKED_MESSAGE = 'This year level includes schedules under review or already approved. Only editable sections can be regenerated.';

    public const SECTIONS_BLOCKED_MESSAGE = 'The selected sections include schedules under review or already approved. Only editable sections can be regenerated.';

    /**
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
        return $statuses->every(
            static fn (string $status): bool => in_array($status, Schedule::PLOTTING_EDITABLE_STATUSES, true),
        );
    }
}
