<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Models\Course;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class SessionAlternativePolicy
{
    /**
     * Explicit enhancements, separate from the legacy fallback catalog. The caller
     * must first establish that the configured shape has no placement, then verify
     * the returned shape as a complete group. Delivery changes are named explicitly.
     */
    public static function enhancement(array $course, array $meetings, array $settings, array $fieldCodes): ?array
    {
        if (SchedulingPolicy::isFieldCourse($course, null, $fieldCodes)) {
            return null;
        }
        if (count($meetings) === 1 && (int) ($course['lab_hours'] ?? 0) === 0
            && SchedulingPolicy::balancedSplitEligible($course, $settings)
            && (int) ($course['lecture_hours'] ?? 0) > 0
            && ($total = (int) $meetings[0]['duration_slots']) >= 2 && $total % 2 === 0
            && $total * SchedulingPolicy::SLOT_MINUTES <= SchedulingPolicy::unitMinutes($course['units'] ?? 0)) {
            $half = intdiv($total, 2);
            $modes = $meetings[0]['modes'];
            $mode = in_array('on-site', $modes, true) ? 'on-site' : (in_array('online', $modes, true) ? 'online' : null);
            if ($mode === null) {
                return null;
            }
            $meeting = ['meeting_type' => 'lecture', 'duration_slots' => $half, 'modes' => [$mode],
                'current' => null, 'template' => ['faculty_id' => $meetings[0]['current']['faculty_id'] ?? null]];

            return ['shape' => 'split', 'label' => $mode === 'online' ? 'Online (All)' : 'Split',
                'label_reason' => 'No full-length placement was found; two matching shorter meetings fit.',
                'adjustment_type' => 'enable_balanced_split', 'meetings' => [$meeting, $meeting]];
        }
        $types = array_column($meetings, 'meeting_type');
        sort($types);
        if ($types !== ['laboratory', 'lecture'] || (int) ($course['lecture_hours'] ?? 0) <= 0
            || (int) ($course['lab_hours'] ?? 0) <= 0 || ! SchedulingPolicy::isMajorCourse($course)
            || array_filter($meetings, static fn (array $meeting): bool => $meeting['modes'] !== ['on-site']) !== []) {
            return null;
        }

        return ['shape' => 'integrated_hybrid', 'label' => 'Integrated Hybrid',
            'label_reason' => 'Change the lecture from on-site to online; keep the laboratory on-site. The complete group fits.',
            'adjustment_type' => 'set_integrated_hybrid',
            'meetings' => array_map(static fn (array $meeting): array => [...$meeting,
                'modes' => [$meeting['meeting_type'] === 'lecture' ? 'online' : 'on-site']], $meetings)];
    }

    /** @return list<array<string, mixed>> */
    public static function hybridSplitMeetings(): array
    {
        $slots = SchedulingPolicy::hybridSplitMeetingSlots();

        return [
            ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['on-site']],
            ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['online']],
        ];
    }

    public static function draftHybridEligible(array $course, ?string $shape): bool
    {
        return $shape !== 'online_split' && SchedulingPolicy::hybridSplitEligible($course);
    }

    public static function draftOnlineMeetingSlots(array $course): ?int
    {
        if ((int) ($course['lab_hours'] ?? 0) !== 0 || (int) ($course['lecture_hours'] ?? 0) <= 0) {
            return null;
        }
        $slots = intdiv(SchedulingPolicy::unitMinutes($course['units'] ?? 0), SchedulingPolicy::SLOT_MINUTES);

        return $slots >= 2 && $slots % 2 === 0 ? intdiv($slots, 2) : null;
    }

    public static function generationHybridEligible(Course $course, bool $balanced, bool $hybrid, bool $physicalIntervalAvailable): bool
    {
        return SchedulingPolicy::hybridSplitEligible($course) && $balanced && ! $hybrid && $physicalIntervalAvailable;
    }

    public static function generationOnlineEligible(Course $course, bool $balanced, bool $hybrid, ?string $mode): bool
    {
        return $balanced && ! $hybrid && $mode !== 'online' && SchedulingPolicy::allowsOnlineRoomFallback($course, null, null, []);
    }
}
