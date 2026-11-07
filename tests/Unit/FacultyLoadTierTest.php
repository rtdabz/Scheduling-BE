<?php

namespace Tests\Unit;

use App\Services\Scheduling\Support\SchedulingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The band boundaries the unit ceiling is built on, asserted at the exact edges.
 *
 * The instructor below has a 21-unit maximum with 6 units deloaded (15 units of
 * Basic Load) plus 3 units of overload allowance, so the bands are 0-15 basic,
 * 16-18 overload, and everything from 19 up beyond the ceiling.
 */
class FacultyLoadTierTest extends TestCase
{
    public function test_basic_load_is_the_maximum_less_the_deload(): void
    {
        $this->assertSame(15, SchedulingPolicy::facultyBasicLoad($this->instructor()));
        $this->assertSame(18, SchedulingPolicy::facultyUnitCeiling($this->instructor()));
    }

    public function test_a_load_up_to_the_basic_load_is_basic(): void
    {
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, $this->tierFor(0));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, $this->tierFor(14));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, $this->tierFor(15));
    }

    public function test_the_first_unit_past_the_basic_load_is_an_overload(): void
    {
        $this->assertSame(SchedulingPolicy::LOAD_TIER_OVERLOAD, $this->tierFor(16));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_OVERLOAD, $this->tierFor(18));
    }

    public function test_past_the_overload_allowance_is_beyond_the_ceiling(): void
    {
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, $this->tierFor(19));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, $this->tierFor(60));
    }

    public function test_an_ungranted_overload_band_is_skipped_rather_than_widened(): void
    {
        $none = $this->instructor(['overload_units' => 0]);

        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, SchedulingPolicy::facultyLoadTier($none, 15));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, SchedulingPolicy::facultyLoadTier($none, 16));
    }

    public function test_an_instructor_with_nothing_configured_has_no_basic_load(): void
    {
        // Any load reads as beyond the ceiling. FacultyLoadService::projectLoad()
        // is what spares these instructors, by only enforcing a ceiling above zero.
        $unconfigured = $this->instructor(['max_units' => 0, 'deload_units' => 0, 'overload_units' => 0]);

        $this->assertSame(0, SchedulingPolicy::facultyBasicLoad($unconfigured));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, SchedulingPolicy::facultyLoadTier($unconfigured, 0));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, SchedulingPolicy::facultyLoadTier($unconfigured, 1));
    }

    public function test_a_deload_larger_than_the_maximum_cannot_go_negative(): void
    {
        $overDeloaded = $this->instructor(['max_units' => 6, 'deload_units' => 9]);

        $this->assertSame(0, SchedulingPolicy::facultyBasicLoad($overDeloaded));
    }

    public function test_every_band_has_the_label_the_scheduling_staff_use(): void
    {
        $this->assertSame('Basic Load', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_BASIC));
        $this->assertSame('Overload', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_OVERLOAD));
        $this->assertSame('Over limit', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING));
        $this->assertSame('unknown', SchedulingPolicy::loadTierLabel('unknown'));
    }

    public function test_pro_bono_follows_the_specification_examples(): void
    {
        // 34/36 + 3 = 37/36: capacity remained, one class crosses.
        $this->assertSame(['allowed' => true, 'reason' => null], SchedulingPolicy::proBonoDecision(36, 34, 37, 3));
        // 37/36 + 3: the limit has already been exceeded once.
        $this->assertSame(SchedulingPolicy::PRO_BONO_REFUSED_ALREADY_USED, SchedulingPolicy::proBonoDecision(36, 37, 40, 3)['reason']);
        // 36/36 + 3: no remaining capacity.
        $this->assertSame(SchedulingPolicy::PRO_BONO_REFUSED_LIMIT_REACHED, SchedulingPolicy::proBonoDecision(36, 36, 39, 3)['reason']);
    }

    public function test_pro_bono_lets_only_one_class_of_a_batch_cross_the_ceiling(): void
    {
        // 30 + 3 + 3 = 36 stays at the limit, then 3 more crosses it: two classes too many.
        $this->assertSame(SchedulingPolicy::PRO_BONO_REFUSED_MORE_THAN_ONE_CLASS, SchedulingPolicy::proBonoDecision(36, 30, 39, 3)['reason']);
        // 32 + 3 = 35 is still below, so the next 3 is the single crossing class.
        $this->assertTrue(SchedulingPolicy::proBonoDecision(36, 32, 38, 3)['allowed']);
    }

    public function test_pro_bono_does_not_apply_within_the_ceiling_or_without_one(): void
    {
        $this->assertSame(['allowed' => false, 'reason' => null], SchedulingPolicy::proBonoDecision(36, 33, 36, 3));
        $this->assertSame(['allowed' => false, 'reason' => null], SchedulingPolicy::proBonoDecision(0, 40, 43, 3));
        $this->assertSame('Pro-Bono', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_PRO_BONO));
    }

    private function tierFor(int $units): string
    {
        return SchedulingPolicy::facultyLoadTier($this->instructor(), $units);
    }

    /** @param array<string, int> $overrides */
    private function instructor(array $overrides = []): object
    {
        return (object) array_merge([
            'max_units' => 21,
            'deload_units' => 6,
            'overload_units' => 3,
        ], $overrides);
    }
}
