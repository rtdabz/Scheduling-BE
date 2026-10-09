<?php

namespace Tests\Unit;

use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Recommendations\ManualPlacementRanker;
use Tests\TestCase;

class ManualPlacementRankerTest extends TestCase
{
    public function test_best_matches_keep_the_requested_day_chosen_room_and_fallback_type_order(): void
    {
        $ranker = new ManualPlacementRanker;
        $slots = [$this->slot('Tuesday', 4), $this->slot('Monday', 4, null, 'online'),
            $this->slot('Monday', 4, 11), $this->slot('Monday', 4, 10),
            [...$this->slot('Monday', 4, 25), 'room_type' => 'laboratory']];
        $best = $ranker->bestMatches($slots, $this->snapshot(), $this->current(), []);
        $this->assertSame([10, 25], array_column($best, 'room_id'));
        $this->assertSame(['Monday'], array_unique(array_column($best, 'day')));
        $withoutChosenRoom = $ranker->bestMatches([
            $this->slot('Monday', 4, null, 'online'), $this->slot('Monday', 4, 11),
        ], $this->snapshot(), $this->current(), []);
        $this->assertSame([11], array_column($withoutChosenRoom, 'room_id'));
        $labBest = $ranker->bestMatches([
            $this->slot('Monday', 4, 10), [...$this->slot('Monday', 3, 25), 'room_type' => 'laboratory'],
        ], $this->snapshot(), [...$this->current(), 'meeting_type' => 'laboratory'], []);
        $this->assertSame([25, 10], array_column($labBest, 'room_id'), 'A laboratory fallback retains its existing quality warning penalty.');
    }

    public function test_soft_gap_notes_precede_start_distance_and_the_result_stops_at_five(): void
    {
        $snapshot = $this->snapshot([['section_id' => 1, 'day' => 'Monday', 'start_time' => '07:00', 'end_time' => '08:30']]);
        $best = (new ManualPlacementRanker)->bestMatches([
            $this->slot('Monday', 4), $this->slot('Monday', 9), $this->slot('Monday', 3),
        ], $snapshot, $this->current(), []);
        $this->assertSame([3, 4, 9], array_column($best, 'start_slot'));
        $this->assertCount(5, (new ManualPlacementRanker)->bestMatches(array_map(fn (int $start): array => $this->slot('Monday', $start), range(0, 9)), $this->snapshot(), $this->current(), []));
    }

    private function snapshot(array $rows = []): SchedulingSnapshot
    {
        return SchedulingSnapshot::fromArray(['fingerprint' => 'rank-fixture', 'semester_id' => 1, 'department_id' => 1,
            'courses' => [1 => ['id' => 1, 'course_code' => 'GEC 101', 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0,
                'room_type_required' => 'lecture', 'course_category' => 'minor']], 'persisted_schedules' => $rows]);
    }

    private function current(): array
    {
        return ['section_id' => 1, 'course_id' => 1, 'day' => 'Monday', 'start_time' => '09:00', 'room_id' => 10, 'meeting_type' => null];
    }

    private function slot(string $day, int $start, ?int $roomId = 10, string $mode = 'on-site'): array
    {
        $time = static fn (int $slot): string => sprintf('%02d:%02d', 7 + intdiv($slot, 2), $slot % 2 * 30);

        return ['day' => $day, 'start_slot' => $start, 'end_slot' => $start + 3,
            'start_time' => $time($start), 'end_time' => $time($start + 3), 'mode' => $mode, 'room_id' => $roomId,
            'room_code' => $roomId === null ? 'Online' : 'R'.$roomId, 'room_type' => $roomId === null ? 'online' : 'lecture'];
    }
}
