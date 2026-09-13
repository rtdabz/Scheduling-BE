<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CSPSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Sunday is a fallback day by default: its candidates sit in a search tier the
 * solver only opens once Monday-Saturday has failed, and the day-balance ranking
 * charges a flat penalty for using it. A department that turned Sunday Online
 * Only off teaches on Sunday, so for that department Sunday must rank as an
 * ordinary teaching day instead.
 */
class CspSundayTeachingDayTest extends TestCase
{
    private function solver(bool $sundayIsRegularTeachingDay): CSPSolver
    {
        $solver = new CSPSolver;
        $flag = new ReflectionProperty($solver, 'sundayIsRegularTeachingDay');
        $flag->setValue($solver, $sundayIsRegularTeachingDay);

        return $solver;
    }

    public function test_sunday_is_a_fallback_search_tier_while_sunday_online_only_is_on(): void
    {
        $solver = $this->solver(false);
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');

        $this->assertSame(2, $dayTier->invoke($solver, $this->candidate('Sunday')));
        $this->assertSame(0, $dayTier->invoke($solver, $this->candidate('Saturday')));
    }

    public function test_sunday_joins_the_normal_search_tier_once_the_department_teaches_on_it(): void
    {
        $solver = $this->solver(true);
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');

        $this->assertSame(
            $dayTier->invoke($solver, $this->candidate('Saturday')),
            $dayTier->invoke($solver, $this->candidate('Sunday')),
            'A department that teaches on Sunday should not have Sunday gated behind a fallback tier.',
        );
    }

    public function test_the_flat_sunday_penalty_applies_only_while_sunday_online_only_is_on(): void
    {
        $gated = $this->solver(false);
        $open = $this->solver(true);
        $gatedPenalty = new ReflectionMethod($gated, 'candidateDayBalancePenalty');
        $openPenalty = new ReflectionMethod($open, 'candidateDayBalancePenalty');
        $sunday = $this->candidate('Sunday');

        $this->assertGreaterThanOrEqual(5000, $gatedPenalty->invoke($gated, $sunday, [], 42));
        $this->assertLessThan(
            $gatedPenalty->invoke($gated, $sunday, [], 42),
            $openPenalty->invoke($open, $sunday, [], 42),
            'Opening Sunday must drop the fallback-day penalty, not merely allow the placement.',
        );
    }

    public function test_an_open_sunday_is_day_balanced_like_any_other_teaching_day(): void
    {
        $solver = $this->solver(true);
        $penalty = new ReflectionMethod($solver, 'candidateDayBalancePenalty');

        // Monday already carries a class; an empty Sunday is the better spread.
        $this->assertLessThan(
            $penalty->invoke($solver, $this->candidate('Monday'), ['Monday' => 2], 42),
            $penalty->invoke($solver, $this->candidate('Sunday'), ['Monday' => 2], 42),
        );
    }

    public function test_an_online_sunday_loses_its_surcharge_once_sunday_is_a_teaching_day(): void
    {
        $gated = $this->solver(false);
        $open = $this->solver(true);
        $gatedPriority = new ReflectionMethod($gated, 'candidateAllocationPriority');
        $openPriority = new ReflectionMethod($open, 'candidateAllocationPriority');
        $saturday = $this->candidate('Saturday', 'online', null);
        $sunday = $this->candidate('Sunday', 'online', null);

        $this->assertGreaterThan(
            $gatedPriority->invoke($gated, $saturday, 1),
            $gatedPriority->invoke($gated, $sunday, 1),
        );
        $this->assertSame(
            $openPriority->invoke($open, $saturday, 1),
            $openPriority->invoke($open, $sunday, 1),
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
