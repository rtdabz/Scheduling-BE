<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Sunday is an ordinary teaching day. It used to sit in a search tier the
 * solver only opened once Monday-Saturday had failed, carry a flat 5000-point
 * day-balance penalty, and take an extra allocation step when online — all
 * gated by a per-department `sunday_online_only_enabled` switch. The switch and
 * every one of those handicaps are gone, so Sunday must now rank exactly as
 * Saturday does.
 */
class CspSundayTeachingDayTest extends TestCase
{
    public function test_sunday_is_searched_in_the_same_tier_as_saturday(): void
    {
        $solver = new CspSolver;
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');

        $this->assertSame(0, $dayTier->invoke($solver, $this->candidate('Sunday')));
        $this->assertSame(
            $dayTier->invoke($solver, $this->candidate('Saturday')),
            $dayTier->invoke($solver, $this->candidate('Sunday')),
        );
    }

    public function test_sunday_carries_no_flat_day_balance_penalty(): void
    {
        $solver = new CspSolver;
        $penalty = new ReflectionMethod($solver, 'candidateDayBalancePenalty');

        // The old gate added 5000 for any Sunday block, whatever the day loads.
        $this->assertLessThan(
            5000,
            $penalty->invoke($solver, $this->candidate('Sunday'), [], 42),
        );
    }

    public function test_sunday_is_day_balanced_like_any_other_teaching_day(): void
    {
        $solver = new CspSolver;
        $penalty = new ReflectionMethod($solver, 'candidateDayBalancePenalty');

        // Monday already carries a class; an empty Sunday is the better spread.
        $this->assertLessThan(
            $penalty->invoke($solver, $this->candidate('Monday'), ['Monday' => 2], 42),
            $penalty->invoke($solver, $this->candidate('Sunday'), ['Monday' => 2], 42),
        );
    }

    public function test_an_online_sunday_ranks_with_an_online_saturday(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $this->assertSame(
            $priority->invoke($solver, $this->candidate('Saturday', 'online', null), 1),
            $priority->invoke($solver, $this->candidate('Sunday', 'online', null), 1),
        );
    }

    private function candidate(string $day, string $mode = 'on-site', ?int $roomId = 1): array
    {
        return [
            'course_id' => 7,
            'mode' => $mode,
            'room_id' => $roomId,
            'room_type' => $mode === 'online' ? 'online' : 'lecture',
            'blocks' => [[
                'day' => $day,
                'mode' => $mode,
                'room_id' => $roomId,
                'room_type' => $mode === 'online' ? 'online' : 'lecture',
                'meeting_type' => $mode === 'online' ? 'lecture' : null,
            ]],
        ];
    }
}
