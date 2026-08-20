<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The band boundaries the overload confirmation is built on. They are asserted at
 * the exact edges because an off-by-one here either prompts for a load that is
 * still inside Basic Load, or lets a real overload through unasked.
 *
 * The instructor below has a 21-unit maximum with 6 units deloaded — 15 units of
 * Basic Load — plus 3 units of overload allowance and 3 of pro bono, so the bands
 * are 0–15 basic, 16–18 overload, 19–21 pro bono, 22+ beyond the ceiling.
 */
class FacultyLoadTierTest extends TestCase
{
    public function test_basic_load_is_the_maximum_less_the_deload(): void
    {
        $this->assertSame(15, SchedulingPolicy::facultyBasicLoad($this->instructor()));
        $this->assertSame(21, SchedulingPolicy::facultyUnitCeiling($this->instructor()));
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

    public function test_the_overload_allowance_gives_way_to_pro_bono(): void
    {
        $this->assertSame(SchedulingPolicy::LOAD_TIER_PROBONO, $this->tierFor(19));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_PROBONO, $this->tierFor(21));
    }

    public function test_a_load_past_the_ceiling_is_named_as_such(): void
    {
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, $this->tierFor(22));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, $this->tierFor(60));
    }

    public function test_an_ungranted_band_is_skipped_rather_than_widened(): void
    {
        // No allowances granted, so there is no overload band to climb: the unit
        // after Basic Load is already past the ceiling.
        $none = $this->instructor(['overload_units' => 0, 'probono_units' => 0]);

        $this->assertSame(SchedulingPolicy::LOAD_TIER_BASIC, SchedulingPolicy::facultyLoadTier($none, 15));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, SchedulingPolicy::facultyLoadTier($none, 16));

        // Overload granted but no pro bono: the pro bono band collapses instead of
        // absorbing the overflow.
        $noProbono = $this->instructor(['probono_units' => 0]);

        $this->assertSame(SchedulingPolicy::LOAD_TIER_OVERLOAD, SchedulingPolicy::facultyLoadTier($noProbono, 18));
        $this->assertSame(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING, SchedulingPolicy::facultyLoadTier($noProbono, 19));
    }

    public function test_an_instructor_with_nothing_configured_has_no_basic_load(): void
    {
        // Every band is empty, so any load reads as beyond the ceiling. It is
        // FacultyLoadService::projectLoad() that spares these instructors, by
        // requiring a Basic Load above zero before it asks anything — the tier
        // alone is not what decides whether the user is prompted.
        $unconfigured = $this->instructor(['max_units' => 0, 'deload_units' => 0, 'overload_units' => 0, 'probono_units' => 0]);

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
        $this->assertSame('Pro-bono', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_PROBONO));
        $this->assertSame('Beyond ceiling', SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_BEYOND_CEILING));
        $this->assertSame('unknown', SchedulingPolicy::loadTierLabel('unknown'));
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
            'probono_units' => 3,
        ], $overrides);
    }
}
