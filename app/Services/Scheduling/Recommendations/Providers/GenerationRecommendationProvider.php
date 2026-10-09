<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations\Providers;

use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Recommendations\GenerationRecommendationPolicy;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use InvalidArgumentException;

final readonly class GenerationRecommendationProvider implements RecommendationProvider
{
    public function __construct(private YearLevelGenerationDiagnostics $diagnostics) {}

    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $arguments = $context->inputs;
        $snapshot = $arguments['snapshot'] ?? null;
        $probeDraft = $arguments['probeDraft'] ?? null;
        $probeDeadline = $arguments['probeDeadline'] ?? null;
        unset($arguments['snapshot'], $arguments['probeDraft'], $arguments['probeDeadline']);
        // Preserve an orchestrator's explicitly supplied diagnostics instance.
        $diagnostics = $arguments['diagnostics'] ?? $this->diagnostics;
        unset($arguments['diagnostics']);

        $options = match ($context->source) {
            RecommendationSource::Configuration => array_map(
                static fn (GenerationConfigurationRecommendation $option): array => $option->toArray(),
                $arguments['validation']->recommendations,
            ),
            RecommendationSource::Feasibility => $diagnostics->feasibilityRecommendations($arguments['blockingConstraints']),
            RecommendationSource::Search => $diagnostics->searchRecommendations(...$arguments),
            RecommendationSource::PreferredDays => $diagnostics->preferredDayRecommendation(...$arguments),
            default => throw new InvalidArgumentException('Unsupported generation recommendation source.'),
        };
        if (in_array($context->source, [RecommendationSource::Search, RecommendationSource::Feasibility], true) && $snapshot instanceof SchedulingSnapshot) {
            $options = [...$options, ...$this->sessionEnhancements($snapshot, $arguments['configsBySectionId'] ?? [], $arguments['bottleneck'] ?? null, $probeDraft, $probeDeadline, $context->source === RecommendationSource::Feasibility)];
        }

        if (($context->metadata['selection_contract'] ?? null) === 1) {
            $options = GenerationRecommendationPolicy::withSelectionMetadata($options, $context->metadata['attempts'] ?? []);
        }

        $sessions = [];
        $configs = $context->source === RecommendationSource::Configuration
            ? [$arguments['validation']->configuration->sectionId => $arguments['validation']->configuration->toArray()]
            : ($arguments['configsBySectionId'] ?? []);
        foreach ($configs as $sectionId => $config) {
            foreach ($config['course_ids'] ?? [] as $courseId) {
                $sessions[$sectionId.':'.$courseId] = SessionInterpreter::fromConfiguration(
                    (int) $courseId, $config, $snapshot?->consecutiveDayRulesFor((int) $sectionId)[(int) $courseId] ?? null,
                )->toArray();
            }
        }

        return RecommendationResult::adapt(
            $context, $options, $options, 'configuration_adjustments',
            metadata: [
                'search_incomplete' => (bool) ($context->metadata['search_incomplete'] ?? false),
                'sessions' => $sessions,
                'evidence_scope' => 'configuration_or_resource_signal',
                'complete_group_verified' => false,
            ],
        );
    }

    private function sessionEnhancements(SchedulingSnapshot $snapshot, array $configs, ?array $bottleneck, ?array $draft, ?float $probeDeadline, bool $capacityOnly): array
    {
        $placements = app(PlacementRecommendationProvider::class);
        $deadline = min($probeDeadline ?? INF, microtime(true) + 2.0);
        $checked = 0;
        $options = [];
        foreach ($configs as $sectionId => $config) {
            foreach ($config['course_ids'] ?? [] as $courseId) {
                if ($draft !== null && ! array_filter($draft['unplaced_courses'] ?? [], static fn (array $entry): bool => (int) $entry['section_id'] === (int) $sectionId && (int) $entry['course_id'] === (int) $courseId)) {
                    continue;
                }
                if ($draft === null && isset($bottleneck['course_id']) && (int) $bottleneck['course_id'] > 0
                    && ((int) $bottleneck['course_id'] !== (int) $courseId || (int) ($bottleneck['section_id'] ?? 0) !== (int) $sectionId)) {
                    continue;
                }
                if ($checked >= 4 || microtime(true) >= $deadline) {
                    return $options;
                }
                $session = SessionInterpreter::fromConfiguration((int) $courseId, $config, $snapshot->consecutiveDayRulesFor((int) $sectionId)[(int) $courseId] ?? null);
                if (! in_array($session->kind, $capacityOnly ? ['integrated'] : ['regular', 'integrated'], true)) {
                    continue;
                }
                if (! empty($config['anchored_schedules'][$courseId])) {
                    continue;
                }
                $checked++;
                $meetings = array_map(static fn (array $meeting): array => [...$meeting, 'current' => null,
                    'modes' => $session->delivery === 'online' ? ['online'] : ($session->delivery === 'on-site' ? ['on-site'] : $meeting['modes'])], $session->meetings);
                $days = SchedulingPolicy::teachingDays((bool) ($snapshot->departmentSettings['sunday_classes_enabled'] ?? false));
                $allowedDays = $config['allowed_days'] ?? null;
                if ($allowedDays !== null) {
                    $days = array_values(array_intersect($days, $allowedDays));
                }
                $candidates = $placements->sessionEnhancements($snapshot, [
                    'section_id' => (int) $sectionId, 'course_id' => (int) $courseId, 'shape' => $session->legacyShape,
                    'meetings' => $meetings, 'replaces' => [], 'keeps' => [],
                    'probe_deadline' => $deadline,
                    'allowed_pairs' => SchedulingPolicy::balancedSplitDayPairs($days, (bool) ($config['allow_friday_saturday_split'] ?? false), $allowedDays !== null),
                ], [...($config['tentative_schedules'] ?? []), ...($draft['schedules'] ?? [])], $config['allowed_days'] ?? null);
                if ($candidates === []) {
                    continue;
                }
                $candidate = $candidates[0];
                $sectionName = (string) ($snapshot->sectionsById[$sectionId]['section_name'] ?? '');
                $courseCode = (string) ($snapshot->coursesById[$courseId]['course_code'] ?? '');
                $options[] = [
                    'id' => 'session-enhancement-'.$sectionId.'-'.$courseId,
                    'title' => $candidate['label'], 'detected_cause' => $candidate['reasons'][0],
                    'suggested_adjustment' => 'A complete course group fits the current bookings. Apply this option alone and generate again to check the whole timetable.',
                    'section_id' => (int) $sectionId, 'section_name' => $sectionName,
                    'course_id' => (int) $courseId, 'course_code' => $courseCode,
                    'impact' => 'medium', 'status' => 'active', 'resolved' => false,
                    'group_witness' => $candidate['rows'], 'apply_individually' => true,
                    'adjustments' => [['type' => $candidate['adjustment_type'], 'section_id' => (int) $sectionId,
                        'course_id' => (int) $courseId, 'value' => null, 'section_name' => $sectionName, 'course_code' => $courseCode]],
                ];
            }
        }

        return $options;
    }
}
