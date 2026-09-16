<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class CspTimePreferenceTest extends TestCase
{
    public function test_a_candidate_outside_the_requested_band_is_penalised(): void
    {
        $solver = $this->solverPreferring([7 => 'afternoon']);
        $penalty = new ReflectionMethod($solver, 'candidateTimePreferencePenalty');

        // 07:00 is slot 0; 13:00 is slot 12.
        $morning = $this->candidate(7, 0);
        $afternoon = $this->candidate(7, 12);

        $this->assertSame(0, $penalty->invoke($solver, $afternoon));
        $this->assertGreaterThan(0, $penalty->invoke($solver, $morning));
    }

    public function test_a_course_without_a_preference_is_never_penalised(): void
    {
        $solver = $this->solverPreferring([7 => 'afternoon']);
        $penalty = new ReflectionMethod($solver, 'candidateTimePreferencePenalty');

        $this->assertSame(0, $penalty->invoke($solver, $this->candidate(99, 0)));
    }

    public function test_each_off_band_meeting_adds_to_the_cost(): void
    {
        $solver = $this->solverPreferring([7 => 'evening']);
        $penalty = new ReflectionMethod($solver, 'candidateTimePreferencePenalty');

        $oneMeeting = $penalty->invoke($solver, $this->candidate(7, 0));
        $twoMeetings = $penalty->invoke($solver, $this->candidate(7, 0, 4));

        $this->assertSame($oneMeeting * 2, $twoMeetings);
    }

    /**
     * The preference must lose to keeping a section's week spread out: one
     * meeting already on a day costs 700, well above the preference weight.
     */
    public function test_the_preference_stays_weaker_than_day_balance(): void
    {
        $solver = $this->solverPreferring([7 => 'afternoon']);
        $penalty = new ReflectionMethod($solver, 'candidateTimePreferencePenalty');
        $dayBalance = new ReflectionMethod($solver, 'candidateDayBalancePenalty');

        $offBand = $penalty->invoke($solver, $this->candidate(7, 0));
        $loadedDay = $dayBalance->invoke($solver, $this->candidate(7, 0), ['Monday' => 1], 1);

        $this->assertLessThan($loadedDay, $offBand);
    }

    public function test_midday_satisfies_a_morning_request(): void
    {
        $solver = $this->solverPreferring([7 => 'morning']);
        $matches = new ReflectionMethod($solver, 'matchesTimePreference');

        // Slot 6 is 10:00 — the solver calls that band "midday", but it is
        // what a user asking for a morning schedule means.
        $this->assertTrue($matches->invoke($solver, 'morning', 6));
        $this->assertTrue($matches->invoke($solver, 'morning', 0));
        $this->assertFalse($matches->invoke($solver, 'morning', 12));
    }

    public function test_unknown_values_and_foreign_courses_are_dropped(): void
    {
        $solver = new CspSolver;
        $normalize = new ReflectionMethod($solver, 'normalizeTimePreferences');

        $normalized = $normalize->invoke(
            $solver,
            [7 => 'AFTERNOON', 8 => 'whenever', 9 => 'morning', 10 => null],
            [7, 8, 10],
        );

        // Case is normalised, unsupported values are dropped, and a course
        // outside the section's list never reaches the solver.
        $this->assertSame([7 => 'afternoon'], $normalized);
    }

    /** @param array<int, string> $preferences */
    private function solverPreferring(array $preferences): CspSolver
    {
        $solver = new CspSolver;
        $property = new ReflectionProperty($solver, 'timePreferencesByCourseId');
        $property->setValue($solver, $preferences);

        return $solver;
    }

    /** @return array<string, mixed> */
    private function candidate(int $courseId, int $startSlot, ?int $secondStartSlot = null): array
    {
        $blocks = [[
            'day' => 'Monday',
            'start_slot' => $startSlot,
            'end_slot' => $startSlot + 3,
        ]];

        if ($secondStartSlot !== null) {
            $blocks[] = [
                'day' => 'Wednesday',
                'start_slot' => $secondStartSlot,
                'end_slot' => $secondStartSlot + 3,
            ];
        }

        return [
            'course_id' => $courseId,
            'mode' => 'on-site',
            'room_id' => 1,
            'room_type' => 'lecture',
            'blocks' => $blocks,
        ];
    }
}
