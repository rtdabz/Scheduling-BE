<?php

namespace App\Services;

use App\Models\InstitutionSetting;
use Carbon\Carbon;

class TimeslotService
{
    /**
     * Return available start times for a class duration: from opening time,
     * stepping by the duration, while the class still ends by closing time.
     *
     * @return array<int, string>
     */
    public function generateStartTimes(int $durationMinutes): array
    {
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

    public function settings(): InstitutionSetting
    {
        return InstitutionSetting::current();
    }
}
