<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Support\SchedulingPolicy;

final class SessionInterpreter
{
    /** @param list<array<string, mixed>> $rows A single affected course/group, including kept meetings. */
    public static function fromRows(array $rows): SessionDescription
    {
        $count = count($rows);
        $consecutiveCount = SchedulingPolicy::consecutiveDayCount($rows[0]['preferred_pattern'] ?? null);
        $types = array_column($rows, 'meeting_type');
        $modes = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['mode'] ?? 'on-site'), $rows)));
        sort($modes);
        $hybrid = in_array(true, array_map(static fn (array $row): bool => (bool) ($row['is_hybrid'] ?? false), $rows), true);
        $lectureOnly = count(array_filter($rows, static fn (array $row): bool => ! in_array($row['meeting_type'] ?? null, [null, 'lecture'], true))) === 0;
        $integrated = in_array('laboratory', $types, true) && in_array('lecture', $types, true);
        $kind = match (true) {
            $consecutiveCount !== null => 'consecutive',
            $integrated => 'integrated',
            $hybrid && $lectureOnly => 'hybrid_split',
            $modes === ['field'] => 'field',
            $count === 2 && ($lectureOnly || SchedulingPolicy::isFixedMeetingPattern($rows[0]['preferred_pattern'] ?? null)) => 'balanced_split',
            $count <= 1 => 'regular',
            default => 'linked',
        };
        $delivery = count($modes) === 1 ? $modes[0] : ($modes === ['on-site', 'online'] ? 'hybrid' : 'mixed');
        $legacyShape = match (true) {
            $kind === 'hybrid_split' && $count === 2 && $delivery === 'hybrid' => 'online_split',
            $count === 2 && $lectureOnly && $consecutiveCount === null => 'split',
            default => null,
        };
        $meetings = array_map(static fn (array $row): array => [
            'meeting_type' => $row['meeting_type'] ?? null,
            'duration_slots' => max(0, SchedulingPolicy::timeToMinutes((string) ($row['end_time'] ?? '00:00'))
                - SchedulingPolicy::timeToMinutes((string) ($row['start_time'] ?? '00:00'))) / SchedulingPolicy::SLOT_MINUTES,
            'modes' => [(string) ($row['mode'] ?? 'on-site')],
        ], $rows);

        return new SessionDescription(
            $kind, $delivery,
            $consecutiveCount ?? (in_array($kind, ['balanced_split', 'hybrid_split', 'integrated'], true) ? 2 : max(1, $count)),
            in_array($kind, ['balanced_split', 'hybrid_split', 'consecutive'], true)
                || ($count === 2 && ! $hybrid && SchedulingPolicy::isFixedMeetingPattern($rows[0]['preferred_pattern'] ?? null)),
            $meetings, $legacyShape,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration  Existing section configuration, with built requirements.
     * @param  array<string, mixed>|null  $consecutiveRule  From the authorized snapshot's section-specific rule map.
     */
    public static function fromConfiguration(int $courseId, array $configuration, ?array $consecutiveRule = null): SessionDescription
    {
        $requirements = array_values((array) ($configuration['requirements_by_course_id'][$courseId] ?? []));
        $meetings = array_map(static fn (array $requirement): array => [
            'meeting_type' => count($requirements) > 1 ? ($requirement['component_type'] ?? null) : null,
            'duration_slots' => (int) ($requirement['duration_slots'] ?? 0),
            'modes' => array_values((array) ($requirement['allowed_delivery_modes'] ?? [])),
        ], $requirements);
        $selected = static fn (string $key): bool => in_array($courseId, array_map('intval', (array) ($configuration[$key] ?? [])), true);
        $mode = (string) ($configuration['delivery_modes_by_course_id'][$courseId] ?? $configuration['mode'] ?? $configuration['delivery_mode'] ?? 'on-site');
        $field = in_array('field', array_column($requirements, 'component_type'), true) || $mode === 'field';
        $kind = match (true) {
            $consecutiveRule !== null => 'consecutive',
            $field => 'field',
            $selected('selected_split_session_course_ids') => 'integrated',
            $selected('hybrid_split_course_ids') => 'hybrid_split',
            $selected('balanced_split_course_ids') => 'balanced_split',
            default => 'regular',
        };
        $expected = match ($kind) {
            'consecutive' => (int) $consecutiveRule['day_count'],
            'integrated', 'hybrid_split', 'balanced_split' => 2,
            default => 1,
        };
        if ($kind === 'hybrid_split') {
            $meetings = SessionAlternativePolicy::hybridSplitMeetings();
            $mode = 'hybrid';
        } elseif ($kind === 'integrated') {
            $mode = SchedulingPolicy::isIntegratedOnSite($configuration['delivery_modes_by_course_id'] ?? [], $courseId) ? 'on-site' : 'hybrid';
        } elseif ($kind === 'balanced_split' && count($meetings) === 1 && $meetings[0]['duration_slots'] >= 2 && $meetings[0]['duration_slots'] % 2 === 0) {
            // Equal meetings must retain the supplied total; an odd total stays unresolved.
            $meeting = [...$meetings[0], 'meeting_type' => 'lecture', 'duration_slots' => intdiv($meetings[0]['duration_slots'], 2)];
            $meetings = [$meeting, $meeting];
        } elseif ($kind === 'consecutive' && count($meetings) === 1) {
            $meetings = array_fill(0, $expected, $meetings[0]);
        }

        return new SessionDescription(
            $kind, $field ? 'field' : $mode, $expected,
            in_array($kind, ['balanced_split', 'hybrid_split', 'consecutive'], true), $meetings,
            match ($kind) {
                'hybrid_split' => 'online_split', 'balanced_split' => 'split', default => null
            },
        );
    }
}
