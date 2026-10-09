<?php

namespace Tests\Unit;

use App\Services\Scheduling\Support\SchedulingPolicy;
use PHPUnit\Framework\TestCase;

class BalancedSplitDayPairsTest extends TestCase
{
    public function test_standard_pairs_take_precedence_and_friday_saturday_is_opt_in(): void
    {
        $days = SchedulingPolicy::teachingDays(true);
        $standard = [['Monday', 'Wednesday'], ['Tuesday', 'Thursday']];
        $this->assertSame($standard, SchedulingPolicy::balancedSplitDayPairs($days));
        $this->assertSame($standard, SchedulingPolicy::balancedSplitDayPairs($days, allowFallback: true));
        $this->assertSame([...$standard, ['Friday', 'Saturday']], SchedulingPolicy::balancedSplitDayPairs($days, true, true));
    }

    public function test_fallback_requires_explicit_day_restrictions_and_prefers_spaced_pairs(): void
    {
        $days = ['Monday', 'Tuesday', 'Friday'];
        $this->assertSame([], SchedulingPolicy::balancedSplitDayPairs($days));
        $this->assertSame([['Monday', 'Friday'], ['Tuesday', 'Friday'], ['Monday', 'Tuesday']], SchedulingPolicy::balancedSplitDayPairs($days, allowFallback: true));
        $this->assertSame([], SchedulingPolicy::balancedSplitDayPairs(['Monday'], allowFallback: true));
    }
}
