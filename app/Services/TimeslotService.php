<?php

namespace App\Services;

use App\Models\ScheduleSetting;
use App\Models\TimeslotOverride;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimeslotService
{
    /**
     * Return available start times for a class duration.
     *
     * Active overrides for the requested duration take priority. When no
     * override exists, slots are generated from institution settings.
     *
     * @return array<int, string>
     */
    public function generateStartTimes(int $durationMinutes): array
    {
        $overrides = TimeslotOverride::query()
            ->where('duration_minutes', $durationMinutes)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->pluck('start_time');

        if ($overrides->isNotEmpty()) {
            return $this->formatTimes($overrides);
        }

        $settings = $this->settings();
        $start = Carbon::parse($settings->opening_time);
        $end = Carbon::parse($settings->closing_time);

        $times = [];

        while ($start->copy()->addMinutes($durationMinutes)->lessThanOrEqualTo($end)) {
            $times[] = $start->format('g:i A');
            $start->addMinutes($durationMinutes);
        }

        return $times;
    }

    public function settings(): ScheduleSetting
    {
        return ScheduleSetting::query()->firstOrCreate([], [
            'opening_time' => '07:00:00',
            'closing_time' => '19:00:00',
            'slot_interval' => 30,
        ]);
    }

    public function hasActiveOverridesForDuration(int $durationMinutes): bool
    {
        return TimeslotOverride::query()
            ->where('duration_minutes', $durationMinutes)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @param  Collection<int, string>  $times
     * @return array<int, string>
     */
    private function formatTimes(Collection $times): array
    {
        return $times
            ->map(fn (string $time): string => Carbon::parse($time)->format('g:i A'))
            ->values()
            ->all();
    }
}
