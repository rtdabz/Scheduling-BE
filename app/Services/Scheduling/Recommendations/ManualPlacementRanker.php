<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class ManualPlacementRanker
{
    /** Preserve the manual Best Match order; browsing order remains the finder's. */
    public function bestMatches(array $slots, SchedulingSnapshot $snapshot, array $current, array $tentative): array
    {
        $daySlots = array_values(array_filter($slots, static fn (array $slot): bool => $slot['day'] === $current['day']));
        $required = SchedulingPolicy::effectiveRoomType($snapshot->coursesById[$current['course_id']], $snapshot->departmentId, $current['meeting_type'] ?? null, $snapshot->fieldCourseCodes);
        $fits = static fn (array $slot): bool => $required === 'laboratory'
            ? in_array($slot['room_type'], SchedulingPolicy::labRoomTypes($snapshot->departmentId), true)
            : $slot['room_type'] === $required;
        $modeRank = ['on-site' => 0, 'online' => 1, 'field' => 2];
        $start = SchedulingPolicy::timeToMinutes($current['start_time']);
        foreach ($daySlots as &$slot) {
            $slot['_penalty'] = $this->penalty($slot, $snapshot, $current, $tentative, $slots);
        }
        unset($slot);
        usort($daySlots, static fn (array $left, array $right): int => [
            $left['_penalty'], abs(SchedulingPolicy::timeToMinutes($left['start_time']) - $start),
            (int) ! $fits($left), (int) ($left['room_id'] !== ($current['room_id'] ?? null)),
            $modeRank[$left['mode']], $left['start_slot'], $left['room_code'],
        ] <=> [
            $right['_penalty'], abs(SchedulingPolicy::timeToMinutes($right['start_time']) - $start),
            (int) ! $fits($right), (int) ($right['room_id'] !== ($current['room_id'] ?? null)),
            $modeRank[$right['mode']], $right['start_slot'], $right['room_code'],
        ]);
        $best = [];
        $seen = [];
        foreach ($daySlots as $slot) {
            $fit = $fits($slot);
            if (! $fit && $slot['mode'] !== 'on-site' && collect($daySlots)->contains(static fn (array $other): bool => $other['start_slot'] === $slot['start_slot'] && $fits($other))) {
                continue;
            }
            $key = $slot['start_slot'].':'.(int) $fit;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            unset($slot['_penalty']);
            $best[] = $slot;
            if (count($best) === 5) {
                break;
            }
        }

        return $best;
    }

    private function penalty(array $slot, SchedulingSnapshot $snapshot, array $current, array $tentative, array $slots): int
    {
        $notes = [];
        $sectionRows = array_values(array_filter([...$snapshot->persistedSchedules, ...$tentative], static fn (array $row): bool => (int) $row['section_id'] === (int) $current['section_id']));
        $start = SchedulingPolicy::timeToMinutes($slot['start_time']);
        $end = SchedulingPolicy::timeToMinutes($slot['end_time']);
        $blocks = [['start' => $start, 'end' => $end, 'candidate' => true]];
        foreach ($sectionRows as $row) {
            if ($row['day'] === $slot['day']) {
                $blocks[] = ['start' => SchedulingPolicy::timeToMinutes($row['start_time']), 'end' => SchedulingPolicy::timeToMinutes($row['end_time']), 'candidate' => false];
            }
        }
        usort($blocks, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        for ($index = 1; $index < count($blocks); $index++) {
            if (! $blocks[$index - 1]['candidate'] && ! $blocks[$index]['candidate']) {
                continue;
            }
            $gap = $blocks[$index]['start'] - $blocks[$index - 1]['end'];
            if ($gap > 0 && $gap < 90) {
                $notes['awkward_gap'] = 1;
            } elseif ($gap >= 180) {
                $notes['idle_gap'] = 3;
            }
        }
        $forced = isset($snapshot->forcedDaysByCourseId[$current['course_id']]);
        if (! $forced && in_array($slot['day'], ['Saturday', 'Sunday'], true)) {
            $notes['weekend_day'] = $slot['day'] === 'Sunday' ? 3 : 1;
        }
        if (! $forced && ! in_array($slot['day'], ['Saturday', 'Sunday'], true) && $start > 13 * 60) {
            $notes['late_weekday_start'] = 1;
        }
        $existingDays = array_unique(array_column($sectionRows, 'day'));
        $total = $end - $start + array_sum(array_map(static fn (array $row): int => SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']), $sectionRows));
        if ($existingDays !== [] && ! in_array($slot['day'], $existingDays, true)
            && count($existingDays) + 1 > max(1, (int) ceil($total / (SchedulingPolicy::totalSlots() * SchedulingPolicy::SLOT_MINUTES)))) {
            $notes['extra_day'] = 1;
        }
        if ($slot['mode'] === 'online' && ! (($current['is_hybrid'] ?? false) && ($current['meeting_type'] ?? null) === 'lecture')
            && collect($slots)->contains(static fn (array $other): bool => $other['day'] === $slot['day'] && $other['start_slot'] === $slot['start_slot'] && $other['mode'] === 'on-site')) {
            $notes['online_with_free_room'] = 1;
        }
        if (($current['meeting_type'] ?? null) === 'laboratory' && $slot['mode'] === 'on-site' && $slot['room_type'] !== 'laboratory') {
            $notes['laboratory_room_mismatch'] = 3;
        }

        return array_sum($notes);
    }
}
