<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use Illuminate\Support\Collection;

/** Existing correction/relief selection; detection and generation stay with their owners. */
final class GenerationRecommendationPolicy
{
    /** Additive UI selection contract; accepted changes still require the interpreter and authorization. */
    public static function withSelectionMetadata(array $options, array $attempts = []): array
    {
        $failed = [];
        foreach ($attempts as $attempt) {
            if (($attempt['outcome'] ?? null) === 'failed') {
                $failed['strategy-'.($attempt['strategy'] ?? '')] = true;
            }
        }

        return array_map(static function (array $option) use ($failed): array {
            $tried = isset($failed[$option['id']]);
            $adjustments = $option['adjustments'] ?? [];

            return [...$option, 'selection' => [
                'contract_version' => 1,
                'priority' => ($tried ? 3 : 0) + (['low' => 0, 'medium' => 1, 'high' => 2][$option['impact'] ?? 'medium'] ?? 1),
                'tried_alone' => $tried,
                'applicable' => ! ($option['resolved'] ?? false) && ($option['status'] ?? 'active') !== 'resolved'
                    && $adjustments !== [] && ! array_filter($adjustments, static fn (array $adjustment): bool => ! GenerationAdjustmentInterpreter::supports($adjustment)),
            ]];
        }, $options);
    }

    /**
     * @param  list<array<string, mixed>>  $blockingConstraints
     * @return list<array<string, mixed>>
     */
    public function feasibilityRecommendations(array $blockingConstraints): array
    {
        $recommendations = [];

        foreach ($blockingConstraints as $index => $constraint) {
            $code = (string) ($constraint['code'] ?? 'blocking_constraint');
            $context = (array) ($constraint['context'] ?? []);
            if ($code === 'insufficient_room_slots') {
                array_push($recommendations, ...$this->roomSlotReliefRecommendations($constraint));
            }
            $adjustments = [];
            $courseCode = (string) ($context['course_code'] ?? '');
            $title = match ($code) {
                'no_physical_rooms' => 'Add a usable lecture or laboratory room',
                'insufficient_room_slots' => 'Reduce on-site demand for this year level',
                'fixed_pattern_overloaded' => sprintf(
                    'Let the generator choose days for %s courses',
                    (string) ($context['pattern'] ?? 'fixed-pattern'),
                ),
                'preferred_days_too_few_for_hybrid' => sprintf(
                    'Add a Preferred Day, or schedule %s on-site',
                    $courseCode !== '' ? $courseCode : 'the course',
                ),
                'component_duration_exceeds_day' => sprintf(
                    'Shorten the %s block or extend operating hours',
                    $courseCode !== '' ? $courseCode : 'course',
                ),
                'forced_day_capacity_exceeded' => sprintf(
                    'Release the %s Required Day or add rooms',
                    (string) ($context['forced_day'] ?? ''),
                ),
                default => 'Adjust the generation scope',
            };

            $adjustmentType = match ($code) {
                'fixed_pattern_overloaded' => 'clear_pattern',
                'preferred_days_too_few_for_hybrid' => null,
                default => false,
            };
            if ($adjustmentType !== false) {
                foreach (($context['targets'] ?? []) as $target) {
                    $adjustments[] = [
                        'type' => $adjustmentType ?? (string) ($target['adjustment_type'] ?? ''),
                        'section_id' => (int) ($target['section_id'] ?? 0),
                        'course_id' => (int) ($target['course_id'] ?? 0),
                        'value' => $target['value'] ?? null,
                        'section_name' => (string) ($target['section_name'] ?? ''),
                        'course_code' => (string) ($target['course_code'] ?? ''),
                    ];
                }
            }

            $recommendations[] = [
                'id' => sprintf('feasibility-%s-%d', $code, $index),
                'title' => $title,
                'detected_cause' => (string) ($constraint['message'] ?? ''),
                'suggested_adjustment' => (string) ($constraint['suggested_action'] ?? ''),
                'section_id' => isset($constraint['section_id']) ? (int) $constraint['section_id'] : null,
                'section_name' => isset($constraint['section_name']) ? (string) $constraint['section_name'] : null,
                'course_id' => null,
                'course_code' => null,
                'impact' => $adjustments === [] ? 'high' : 'medium',
                'adjustments' => $adjustments,
                'status' => 'active',
                'resolved' => false,
            ];
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $constraint
     * @return list<array<string, mixed>>
     */
    private function roomSlotReliefRecommendations(array $constraint): array
    {
        $context = (array) ($constraint['context'] ?? []);
        $shortfall = (int) ($context['shortfall_slots'] ?? 0);
        $recommendations = [];

        foreach ((array) ($context['options'] ?? []) as $option) {
            $kind = (string) ($option['kind'] ?? '');
            $recommendations[] = [
                'id' => 'room-capacity-'.$kind,
                'title' => $kind === 'online' ? 'Online' : 'Hybrid Split',
                'detected_cause' => 'These options reduce the estimated room-time shortfall. A complete timetable has not been verified. On-site Split still needs physical room time on both days.',
                'suggested_adjustment' => sprintf(
                    '%s. This frees %d room slots, while %d are needed. Generate again to check the complete timetable.',
                    implode(', ', (array) ($option['course_codes'] ?? [])),
                    (int) ($option['frees'] ?? 0),
                    $shortfall,
                ),
                'section_id' => null,
                'section_name' => null,
                'course_id' => null,
                'course_code' => null,
                'impact' => 'medium',
                'adjustments' => array_map(static fn (array $target): array => [
                    'type' => (string) ($target['adjustment_type'] ?? ''),
                    'section_id' => (int) ($target['section_id'] ?? 0),
                    'course_id' => (int) ($target['course_id'] ?? 0),
                    'value' => $target['value'] ?? null,
                    'section_name' => (string) ($target['section_name'] ?? ''),
                    'course_code' => (string) ($target['course_code'] ?? ''),
                ], (array) ($option['targets'] ?? [])),
                'status' => 'active',
                'resolved' => false,
            ];
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $strategies  retry strategies that were planned
     * @return list<array<string, mixed>>
     */
    public function searchRecommendations(
        ?array $bottleneck,
        array $strategies,
        ?Collection $courses = null,
        array $configsBySectionId = [],
        ?string $suggestedPreferredDay = null,
        bool $searchIncomplete = false,
    ): array {
        $preferredDay = $this->preferredDayRecommendation($suggestedPreferredDay, $configsBySectionId);

        if ($bottleneck === null) {
            return [...$preferredDay, [
                'id' => 'search-generic',
                'title' => 'Reduce the constraints on this year level',
                'detected_cause' => $searchIncomplete
                    ? 'The search stopped before all candidates were checked. No complete timetable was found; this does not prove the configuration cannot fit.'
                    : 'The generator explored every ordering it could within the time budget without finding a conflict-free timetable.',
                'suggested_adjustment' => 'Clear old draft schedules for this year level, or relax fixed patterns and forced delivery modes, then generate again.',
                'section_id' => null,
                'section_name' => null,
                'course_id' => null,
                'course_code' => null,
                'impact' => 'medium',
                'adjustments' => [],
                'status' => 'active',
                'resolved' => false,
            ]];
        }

        $recommendations = [];

        foreach ($strategies as $strategy) {
            $adjustments = array_values((array) ($strategy['adjustments'] ?? []));
            if ($adjustments === []) {
                continue;
            }

            $first = $adjustments[0];
            $recommendations[] = [
                'id' => 'strategy-'.(string) ($strategy['key'] ?? count($recommendations)),
                'title' => (string) ($strategy['label'] ?? 'Adjust the configuration'),
                'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
                'suggested_adjustment' => (string) ($strategy['description'] ?? ''),
                'section_id' => isset($first['section_id']) ? (int) $first['section_id'] : null,
                'section_name' => isset($first['section_name']) && $first['section_name'] !== ''
                    ? (string) $first['section_name']
                    : null,
                'course_id' => isset($first['course_id']) ? (int) $first['course_id'] : null,
                'course_code' => isset($first['course_code']) && $first['course_code'] !== ''
                    ? (string) $first['course_code']
                    : null,
                'impact' => (string) ($strategy['impact'] ?? 'medium'),
                'adjustments' => $adjustments,
                'status' => 'active',
                'resolved' => false,
            ];
        }

        if (($bottleneck['type'] ?? null) === YearLevelGenerationDiagnostics::TYPE_BALANCED_SPLIT) {
            $courseId = (int) ($bottleneck['course_id'] ?? 0);
            $course = $courses?->get($courseId);
            $sectionId = (int) ($bottleneck['section_id'] ?? 0);
            $sectionName = (string) ($bottleneck['section_name'] ?? 'the section');
            $courseCode = (string) ($bottleneck['course_code'] ?? ($course?->course_code ?? 'the course'));
            $splitIds = array_map('intval', $configsBySectionId[$sectionId]['balanced_split_course_ids'] ?? []);
            $hybridIds = array_map('intval', $configsBySectionId[$sectionId]['hybrid_split_course_ids'] ?? []);
            $mode = $configsBySectionId[$sectionId]['delivery_modes_by_course_id'][$courseId] ?? null;
            $splitCause = sprintf('%s has no two free on-site slots for its Split Session.', $courseCode);

            if ($course !== null && SessionAlternativePolicy::generationHybridEligible(
                $course, in_array($courseId, $splitIds, true), in_array($courseId, $hybridIds, true),
                (bool) ($bottleneck['hybrid_split_slot_available'] ?? false),
            )) {
                $recommendations[] = [
                    'id' => 'recommend-hybrid-split-'.$sectionId.'-'.$courseId,
                    'title' => 'Hybrid Split',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Meet once online and once on campus: two 1.5-hour meetings for %s in %s. A physical interval was found; the matching online meeting and complete timetable still need generation.', $courseCode, $sectionName),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'medium',
                    'adjustments' => [[
                        'type' => 'enable_hybrid_split',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => null,
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }

            if ($course !== null && SessionAlternativePolicy::generationOnlineEligible(
                $course, in_array($courseId, $splitIds, true), in_array($courseId, $hybridIds, true), $mode,
            )) {
                $recommendations[] = [
                    'id' => 'recommend-online-split-'.$sectionId.'-'.$courseId,
                    'title' => 'Online (All)',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Hold both meetings of %s in %s online. They need free class time, not a room.', $courseCode, $sectionName),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'medium',
                    'adjustments' => [[
                        'type' => 'set_delivery_mode',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => 'online',
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }

            if (! $this->offersAdjustment($recommendations, 'disable_minor_split', $sectionId, $courseId)) {
                $recommendations[] = [
                    'id' => 'recommend-regular-meeting-'.$sectionId.'-'.$courseId,
                    'title' => 'Regular',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Meet once a week for the full length of %s instead of twice.', $courseCode),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'high',
                    'adjustments' => [[
                        'type' => 'disable_minor_split',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => null,
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }
        }

        if ($searchIncomplete || ! in_array($bottleneck['type'] ?? null, [
            YearLevelGenerationDiagnostics::TYPE_LABORATORY_ROOM,
            YearLevelGenerationDiagnostics::TYPE_FORCED_ON_SITE,
            YearLevelGenerationDiagnostics::TYPE_LIMITED_ROOMS,
            YearLevelGenerationDiagnostics::TYPE_SEARCH_EXHAUSTED,
        ], true)) {
            return [...$recommendations, ...$preferredDay];
        }

        $recommendations = [...$recommendations, ...$preferredDay];

        $recommendations[] = [
            'id' => 'advisory-resources',
            'title' => 'Free up room-time for this year level',
            'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
            'suggested_adjustment' => $bottleneck['type'] === YearLevelGenerationDiagnostics::TYPE_LABORATORY_ROOM
                ? 'Add or re-enable a laboratory room for the department, or move a laboratory course to a different year-level run.'
                : 'Delete stale draft schedules for other year levels in this semester, or add an available room, then generate again.',
            'section_id' => ($bottleneck['section_id'] ?? 0) > 0 ? (int) $bottleneck['section_id'] : null,
            'section_name' => ($bottleneck['section_name'] ?? '') !== '' ? (string) $bottleneck['section_name'] : null,
            'course_id' => $bottleneck['course_id'] ?? null,
            'course_code' => $bottleneck['course_code'] ?? null,
            'impact' => 'high',
            'adjustments' => [],
            'status' => 'active',
            'resolved' => false,
        ];

        return $recommendations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<array<string, mixed>>
     */
    public function preferredDayRecommendation(?string $day, array $configsBySectionId, bool $timetableFits = false): array
    {
        if ($day === null || $day === '' || $configsBySectionId === []) {
            return [];
        }

        $allowedDays = SchedulingPolicy::normalizeAllowedDays(
            $configsBySectionId[array_key_first($configsBySectionId)]['allowed_days'] ?? null,
        ) ?? [];

        return [[
            'id' => 'add-preferred-day-'.strtolower($day),
            'title' => sprintf('Add %s to the Preferred Days', $day),
            'detected_cause' => $timetableFits
                ? sprintf('The timetable fits, but only on %s, so room-time on other days goes unused.', implode(', ', $allowedDays))
                : sprintf('The Preferred Days limit every section to %s, and the timetable does not fit in them.', implode(', ', $allowedDays)),
            'suggested_adjustment' => sprintf(
                'Open %s as well. It is the least booked day the Preferred Days leave out, so it adds the most free room-time.',
                $day,
            ),
            'section_id' => null,
            'section_name' => null,
            'course_id' => null,
            'course_code' => null,
            'impact' => $timetableFits ? 'low' : 'medium',
            'adjustments' => array_map(static fn (int|string $sectionId): array => [
                'type' => 'add_preferred_day',
                'section_id' => (int) $sectionId,
                'course_id' => 0,
                'value' => $day,
                'section_name' => '',
                'course_code' => '',
            ], array_keys($configsBySectionId)),
            'status' => 'active',
            'resolved' => false,
        ]];
    }

    /** @param  list<array<string, mixed>>  $recommendations */
    private function offersAdjustment(array $recommendations, string $type, int $sectionId, int $courseId): bool
    {
        foreach ($recommendations as $recommendation) {
            foreach ((array) ($recommendation['adjustments'] ?? []) as $adjustment) {
                if (($adjustment['type'] ?? null) === $type
                    && (int) ($adjustment['section_id'] ?? 0) === $sectionId
                    && (int) ($adjustment['course_id'] ?? 0) === $courseId) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function configuration(
        string $id, string $title, string $cause, string $adjustment, string $impact,
        GenerationConfiguration $configuration, ?int $courseId = null, array $course = [], array $adjustments = [],
    ): GenerationConfigurationRecommendation {
        return new GenerationConfigurationRecommendation(
            id: $id, title: $title, detectedCause: $cause, suggestedAdjustment: $adjustment,
            impact: $impact, adjustments: $adjustments, sectionId: $configuration->sectionId,
            sectionName: null, courseId: $courseId,
            courseCode: isset($course['course_code']) ? (string) $course['course_code'] : null,
        );
    }
}
