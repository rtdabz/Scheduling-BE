<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\ValidateScheduleCandidate;
use App\Services\Scheduling\Engine\Solver\SchedulingSolver;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use Illuminate\Support\Str;

final class GenerateSchedulePlan
{
    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly ValidateGenerationConfiguration $configurationValidator,
        private readonly SchedulingSolver $solver,
        private readonly ValidateScheduleCandidate $candidateValidator,
        private readonly RecommendationEngine $recommendationEngine,
    ) {}

    /** @return list<SchedulePlan> */
    public function generate(
        int $semesterId,
        int $departmentId,
        GenerationConfiguration $configuration,
        bool $configurationWarningsConfirmed = false,
    ): array {
        $snapshot = $this->snapshots->captureForConfiguration($semesterId, $departmentId, $configuration);
        $validation = $this->configurationValidator->validateSnapshot($configuration, $snapshot);

        if (! $validation->canGenerate()) {
            return [$this->statePlan($validation, SchedulePlanStatus::Invalid, $snapshot)];
        }

        if ($validation->requiresConfirmation() && ! $configurationWarningsConfirmed) {
            return [$this->statePlan($validation, SchedulePlanStatus::ConfigurationValid, $snapshot)];
        }

        $candidates = $this->solveWithPhysicalRoomsFirst($configuration, $snapshot);
        if ($candidates === []) {
            return [$this->noSolutionPlan($validation, $configurationWarningsConfirmed, $snapshot)];
        }

        return array_map(
            fn (ScheduleCandidate $candidate): SchedulePlan => $this->candidatePlan(
                $candidate,
                $configuration,
                $snapshot,
                $validation,
                $configurationWarningsConfirmed,
            ),
            $candidates,
        );
    }

    /**
     * @return list<ScheduleCandidate>
     */
    private function solveWithPhysicalRoomsFirst(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): array {
        if (! $configuration->allowRoomTbaFallback && ! $configuration->allowOnlineFallback) {
            return $this->solver->solve($configuration, $snapshot);
        }

        $candidates = $this->solver->solve(
            $this->strictRoomConfiguration($configuration),
            $snapshot,
        );

        return $candidates !== []
            ? $candidates
            : $this->solver->solve($configuration, $snapshot);
    }

    private function strictRoomConfiguration(GenerationConfiguration $configuration): GenerationConfiguration
    {
        return GenerationConfiguration::fromArray([
            ...$configuration->toArray(),
            'allow_room_tba_fallback' => false,
            'allow_online_fallback' => false,
            'throw_on_empty_domain' => false,
            'timeout_seconds' => max(1.0, $configuration->timeoutSeconds / 2),
        ]);
    }

    private function statePlan(
        GenerationConfigurationValidationResult $validation,
        SchedulePlanStatus $status,
        SchedulingSnapshot $snapshot,
    ): SchedulePlan {
        return new SchedulePlan(
            planId: (string) Str::uuid(),
            configuration: $validation->configuration,
            snapshotFingerprint: $validation->snapshotFingerprint,
            status: $status,
            violations: $validation->violations,
            recommendations: $this->recommendations($validation, $snapshot),
            metadata: [
                ...$validation->metadata,
                'configuration_validation_status' => $validation->status(),
                'generation_deferred' => $status === SchedulePlanStatus::ConfigurationValid,
            ],
        );
    }

    private function noSolutionPlan(
        GenerationConfigurationValidationResult $validation,
        bool $warningsConfirmed,
        SchedulingSnapshot $snapshot,
    ): SchedulePlan {
        return new SchedulePlan(
            planId: (string) Str::uuid(),
            configuration: $validation->configuration,
            snapshotFingerprint: $validation->snapshotFingerprint,
            status: SchedulePlanStatus::Invalid,
            violations: [
                ...$validation->violations,
                new ConstraintViolation(
                    ruleId: 'no_feasible_schedule',
                    message: 'No schedule candidate satisfies all hard constraints for this configuration.',
                    scope: 'schedule_plan',
                    context: ['section_id' => $validation->configuration->sectionId],
                ),
            ],
            recommendations: $this->recommendations($validation, $snapshot),
            metadata: $this->solverMetadata($validation, $warningsConfirmed),
        );
    }

    private function candidatePlan(
        ScheduleCandidate $candidate,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        GenerationConfigurationValidationResult $validation,
        bool $warningsConfirmed,
    ): SchedulePlan {
        $violations = [
            ...$validation->violations,
            ...$this->candidateValidator->validate($candidate, $configuration, $snapshot),
        ];
        $unresolvedResources = $this->unresolvedResources($candidate->rows);
        $hasHardViolations = array_filter(
            $violations,
            static fn (ConstraintViolation $violation): bool => $violation->severity === 'hard',
        ) !== [];
        $status = match (true) {
            $hasHardViolations => SchedulePlanStatus::Invalid,
            $unresolvedResources !== [] => SchedulePlanStatus::RoomAssignmentUnresolved,
            default => SchedulePlanStatus::RoomAssignmentComplete,
        };

        return new SchedulePlan(
            planId: (string) Str::uuid(),
            configuration: $configuration,
            snapshotFingerprint: $snapshot->fingerprint,
            status: $status,
            rows: $candidate->rows,
            violations: $violations,
            recommendations: $this->recommendations($validation, $snapshot),
            unresolvedResources: $unresolvedResources,
            scores: $this->scores($candidate),
            metadata: [
                ...$candidate->metadata,
                ...$this->solverMetadata($validation, $warningsConfirmed),
                'score_breakdown' => $candidate->scoreBreakdown,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function unresolvedResources(array $rows): array
    {
        $resources = [];
        foreach ($rows as $row) {
            if ($row->isRoomResolved()) {
                continue;
            }

            $resources[] = [
                'type' => 'room',
                'section_id' => $row->sectionId,
                'course_id' => $row->courseId,
                'meeting_type' => $row->meetingType,
                'day' => $row->day,
                'start_time' => $row->startTime,
                'end_time' => $row->endTime,
            ];
        }

        return $resources;
    }

    /** @return array<string, int|float> */
    private function scores(ScheduleCandidate $candidate): array
    {
        $scores = $candidate->scoreBreakdown;
        if ($candidate->qualityScore !== null) {
            $scores['quality_score'] = $candidate->qualityScore;
        }
        if ($candidate->penaltyScore !== null) {
            $scores['penalty_score'] = $candidate->penaltyScore;
        }

        return $scores;
    }

    /** @return list<array<string, mixed>> */
    private function recommendations(GenerationConfigurationValidationResult $validation, SchedulingSnapshot $snapshot): array
    {
        return $this->recommendationEngine->recommend(new RecommendationContext(
            RecommendationSource::Configuration,
            ['validation' => $validation, 'snapshot' => $snapshot],
            scope: $validation->metadata,
            snapshotFingerprint: $validation->snapshotFingerprint,
        ))->legacyPayload;
    }

    /** @return array<string, mixed> */
    private function solverMetadata(
        GenerationConfigurationValidationResult $validation,
        bool $warningsConfirmed,
    ): array {
        $confirmedRuleIds = $warningsConfirmed
            ? array_values(array_unique(array_map(
                static fn (ConstraintViolation $violation): string => $violation->ruleId,
                array_values(array_filter(
                    $validation->violations,
                    static fn (ConstraintViolation $violation): bool => $violation->severity === 'warning',
                )),
            )))
            : [];

        return [
            ...$validation->metadata,
            'configuration_validation_status' => $validation->status(),
            'configuration_warnings_confirmed' => $warningsConfirmed,
            'confirmed_violation_rule_ids' => $confirmedRuleIds,
            'iterations_used' => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
            'generation_metrics' => $this->generationMetrics($validation)->toArray(),
        ];
    }

    private function generationMetrics(
        GenerationConfigurationValidationResult $validation,
    ): SchedulingGenerationMetrics {
        $solver = $this->solver->generationMetrics();
        $snapshotElapsedMs = (float) ($validation->metadata['snapshot_elapsed_ms'] ?? 0.0);

        return new SchedulingGenerationMetrics(
            operation: 'generate_schedule_plan',
            snapshotQueryCount: (int) ($validation->metadata['snapshot_query_count'] ?? 0),
            snapshotElapsedMs: $snapshotElapsedMs,
            variableCount: $solver->variableCount,
            candidateCountBefore: $solver->candidateCountBefore,
            candidateCountAfter: $solver->candidateCountAfter,
            prunedByConstraint: $solver->prunedByConstraint,
            iterations: $solver->iterations,
            searchLimitReached: $solver->searchLimitReached,
            solverAttempts: $solver->solverAttempts,
            elapsedMs: $solver->elapsedMs + $snapshotElapsedMs,
            fallbackUsage: $solver->fallbackUsage,
        );
    }
}
