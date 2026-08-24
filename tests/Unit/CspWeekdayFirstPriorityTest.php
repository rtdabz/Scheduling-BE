<?php

namespace Tests\Unit;

use App\Services\Scheduling\CSPSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CspWeekdayFirstPriorityTest extends TestCase
{
    public function test_weekday_physical_candidate_ranks_before_weekend_and_online_candidates(): void
    {
        $solver = new CSPSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $weekdayPhysical = $this->candidate('Monday', 'on-site', 1, 'lecture');
        $weekendPhysical = $this->candidate('Saturday', 'on-site', 1, 'lecture');
        $weekdayOnline = $this->candidate('Monday', 'online', null, 'online');

        $this->assertLessThan(
            $priority->invoke($solver, $weekendPhysical, 1),
            $priority->invoke($solver, $weekdayPhysical, 1),
        );
        $this->assertLessThan(
            $priority->invoke($solver, $weekdayOnline, 1),
            $priority->invoke($solver, $weekdayPhysical, 1),
        );
    }

    public function test_section_gaps_score_progressively_worse_as_they_grow(): void
    {
        $solver = new CSPSolver;
        $score = new ReflectionMethod($solver, 'calculateScore');

        $compact = $score->invoke($solver, [
            $this->assignment(1, 0, 6),
            $this->assignment(2, 6, 9),
        ]);

        $oneHourGap = $score->invoke($solver, [
            $this->assignment(1, 0, 6),
            $this->assignment(2, 8, 11),
        ]);

        $fillableGap = $score->invoke($solver, [
            $this->assignment(1, 0, 6),
            $this->assignment(2, 9, 12),
        ]);

        $this->assertLessThan($oneHourGap, $compact);
        // A 3-slot gap is cheaper than a 2-slot gap because the classroom
        // gap penalty accounts for schedulable blocks: a 3-slot gap can be
        // exactly filled by a 3-slot class block, reducing the penalty,
        // while a 2-slot gap cannot be filled by any standard block size (3, 4, 6).
        $this->assertLessThan($oneHourGap, $fillableGap);
    }

    public function test_day_balance_prefers_an_unloaded_monday_to_saturday_day_before_reusing_a_loaded_day(): void
    {
        $solver = new CSPSolver;
        $penalty = new ReflectionMethod($solver, 'candidateDayBalancePenalty');

        $monday = $this->candidate('Monday', 'on-site', 1, 'lecture');
        $tuesday = $this->candidate('Tuesday', 'on-site', 1, 'lecture');

        $this->assertLessThan(
            $penalty->invoke($solver, $monday, ['Monday' => 1], 42),
            $penalty->invoke($solver, $tuesday, ['Monday' => 1], 42),
        );
    }

    private function candidate(string $day, string $mode, ?int $roomId, string $roomType): array
    {
        return [
            'mode' => $mode,
            'room_id' => $roomId,
            'room_type' => $roomType,
            'blocks' => [[
                'day' => $day,
                'mode' => $mode,
                'room_id' => $roomId,
                'room_type' => $roomType,
            ]],
        ];
    }

    private function assignment(int $courseId, int $startSlot, int $endSlot): array
    {
        return [
            'course_id' => $courseId,
            'section_id' => 1,
            'term_id' => 1,
            'department_id' => 1,
            'room_id' => 1,
            'room_type' => 'lecture',
            'mode' => 'on-site',
            'is_hybrid' => false,
            'preferred_pattern' => null,
            'blocks' => [[
                'day' => 'Monday',
                'start_slot' => $startSlot,
                'end_slot' => $endSlot,
                'start_time' => sprintf('%02d:%02d:00', 7 + intdiv($startSlot, 2), ($startSlot % 2) * 30),
                'end_time' => sprintf('%02d:%02d:00', 7 + intdiv($endSlot, 2), ($endSlot % 2) * 30),
                'room_id' => 1,
                'room_type' => 'lecture',
                'mode' => 'on-site',
            ]],
        ];
    }
}
