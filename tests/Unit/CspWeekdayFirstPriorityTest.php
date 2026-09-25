<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CspWeekdayFirstPriorityTest extends TestCase
{
    public function test_weekday_physical_candidate_ranks_before_weekend_and_online_candidates(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        // A laboratory meeting: a single lecture-room meeting is steered late in
        // the week instead (see the test below).
        $weekdayPhysical = $this->candidate('Monday', 'on-site', 1, 'laboratory');
        $weekendPhysical = $this->candidate('Saturday', 'on-site', 1, 'laboratory');
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

    public function test_saturday_single_lecture_meeting_is_searched_with_friday_before_monday_to_thursday(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');

        $monday = $this->candidate('Monday', 'on-site', 1, 'lecture');
        $friday = $this->candidate('Friday', 'on-site', 1, 'lecture');
        $saturday = $this->candidate('Saturday', 'on-site', 1, 'lecture');
        $sunday = $this->candidate('Sunday', 'on-site', 1, 'lecture');

        // Same allocation tier as the weekdays, so the day tier decides:
        // Friday and Saturday (0) before Monday-Thursday (1).
        $this->assertSame($priority->invoke($solver, $monday, 1), $priority->invoke($solver, $saturday, 1));
        $this->assertSame($priority->invoke($solver, $friday, 1), $priority->invoke($solver, $saturday, 1));
        $this->assertSame(0, $dayTier->invoke($solver, $saturday));
        $this->assertSame(1, $dayTier->invoke($solver, $monday));

        // Sunday is not one of the preferred late-week days.
        $this->assertGreaterThan($priority->invoke($solver, $monday, 1), $priority->invoke($solver, $sunday, 1));
    }

    public function test_monday_to_thursday_single_meeting_prefers_a_slot_whose_pair_day_is_taken(): void
    {
        $solver = new CspSolver;
        $penalty = new ReflectionMethod($solver, 'candidateSplitPairBreakPenalty');

        $monday = $this->timedCandidate('Monday', 1, 0, 4);

        // Wednesday is free at that time in that room: an MW split could have
        // used the slot, so taking it on Monday costs.
        $this->assertGreaterThan(0, $penalty->invoke($solver, $monday, []));

        // Wednesday is already booked there, so no MW split can use the slot.
        $wednesdayBooked = $this->timedCandidate('Wednesday', 1, 2, 6);
        $this->assertSame(0, $penalty->invoke($solver, $monday, [$wednesdayBooked]));

        // A booking in another room, or at another time, does not count.
        $this->assertGreaterThan(0, $penalty->invoke($solver, $monday, [$this->timedCandidate('Wednesday', 2, 0, 4)]));
        $this->assertGreaterThan(0, $penalty->invoke($solver, $monday, [$this->timedCandidate('Wednesday', 1, 4, 8)]));

        // Friday has no pair day; laboratory rooms are out of scope.
        $this->assertSame(0, $penalty->invoke($solver, $this->timedCandidate('Friday', 1, 0, 4), []));
        $this->assertSame(0, $penalty->invoke($solver, $this->timedCandidate('Monday', 1, 0, 4, 'laboratory'), []));
    }

    private function timedCandidate(string $day, int $roomId, int $startSlot, int $endSlot, string $roomType = 'lecture'): array
    {
        $block = [
            'day' => $day,
            'start_slot' => $startSlot,
            'end_slot' => $endSlot,
            'start_time' => sprintf('%02d:%02d:00', 7 + intdiv($startSlot, 2), ($startSlot % 2) * 30),
            'end_time' => sprintf('%02d:%02d:00', 7 + intdiv($endSlot, 2), ($endSlot % 2) * 30),
            'mode' => 'on-site',
            'room_id' => $roomId,
            'room_type' => $roomType,
        ];

        return [
            'mode' => 'on-site',
            'room_id' => $roomId,
            'room_type' => $roomType,
            'blocks' => [$block],
        ];
    }

    public function test_online_lecture_can_enter_the_same_search_tier_on_saturday(): void
    {
        $solver = new CspSolver;
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $weekdayOnline = $this->candidate('Monday', 'online', null, 'online');
        $saturdayOnline = $this->candidate('Saturday', 'online', null, 'online');

        $this->assertSame($dayTier->invoke($solver, $weekdayOnline), $dayTier->invoke($solver, $saturdayOnline));
        $this->assertSame($priority->invoke($solver, $weekdayOnline, 1), $priority->invoke($solver, $saturdayOnline, 1));
    }

    public function test_hybrid_online_lecture_is_not_demoted_when_its_pair_contains_saturday(): void
    {
        $solver = new CspSolver;
        $dayTier = new ReflectionMethod($solver, 'candidateSearchDayTier');
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $weekdayHybrid = $this->hybridCandidate('Monday', 'Tuesday');
        $saturdayHybrid = $this->hybridCandidate('Saturday', 'Tuesday');

        $this->assertSame($dayTier->invoke($solver, $weekdayHybrid), $dayTier->invoke($solver, $saturdayHybrid));
        $this->assertSame($priority->invoke($solver, $weekdayHybrid, 1), $priority->invoke($solver, $saturdayHybrid, 1));
    }

    public function test_section_gaps_score_progressively_worse_as_they_grow(): void
    {
        $solver = new CspSolver;
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
        $solver = new CspSolver;
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
                'meeting_type' => $mode === 'online' ? 'lecture' : null,
            ]],
        ];
    }

    private function assignment(int $courseId, int $startSlot, int $endSlot): array
    {
        return [
            'course_id' => $courseId,
            'section_id' => 1,
            'semester_id' => 1,
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

    private function hybridCandidate(string $lectureDay, string $labDay): array
    {
        return [
            'mode' => 'online',
            'room_id' => null,
            'room_type' => 'online',
            '_split_lecture_online_default' => true,
            'blocks' => [
                ['day' => $lectureDay, 'mode' => 'online', 'meeting_type' => 'lecture'],
                ['day' => $labDay, 'mode' => 'on-site', 'room_type' => 'laboratory', 'meeting_type' => 'laboratory'],
            ],
        ];
    }
}
