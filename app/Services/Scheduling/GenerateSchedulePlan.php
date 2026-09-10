<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Services\Scheduling\Constraints\ValidateScheduleCandidate;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Solver\SchedulingSolver;
use Illuminate\Support\Str;

final class GenerateSchedulePlan
{
    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly ValidateGenerationConfiguration $configurationValidator,
        private readonly SchedulingSolver $solver,
        private readonly ValidateScheduleCandidate $candidateValidator,
    ) {}

    /** @return list<SchedulePlan> */
    public function generate(
        int $termId,
        int $departmentId,
        GenerationConfiguration $configuration,
        bool $configurationWarningsConfirmed = false,
    ): array {
        $snapshot = $this->snapshots->captureForConfiguration($termId, $departmentId, $configuration);
        $validation = $this->configurationValidator->validateSnapshot($configuration, $snapshot);

        if (! $validation->canGenerate()) {
            return [$this->statePlan($validation, SchedulePlanStatus::Invalid)];
        }

        if ($validation->requiresConfirmation() && ! $configurationWarningsConfirmed) {
            return [$this->statePlan($validation, SchedulePlanStatus::ConfigurationValid)];
        }

        $candidates = $this->solveWithPhysicalRoomsFirst($configuration, $snapshot);
        if ($candidates === []) {
            return [$this->noSolutionPlan($validation, $configurationWarningsConfirmed)];
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
     * Room TBA and a lecture pushed online are fallbacks, not peers of a real
     * room. Year-level generation already exhausts real rooms before opening
     * TBA (see YearLevelScheduleGenerationService::generateForOrder), but
     * single section generation used to offer both from the first attempt, so a
     * laboratory could land on Room TBA -- or a lecture go online -- while a
     * real room was still free at another feasible time. Solve with both
     * fallbacks closed first, and only reopen them when that finds nothing.
     *
     * Doing this here rather than inside the search matters: tier ordering only
     * ranks candidates within a single variable, so an earlier course holding a
     * room can still strand a later one on a fallback without ever being
     * reconsidered. Removing fallbacks from every domain makes the physical
     * search exhaustive across variables.
     *
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
            // Under the strict pass an empty domain only means "no all-physical
            // placement exists", which must not abort generation before the
            // fallback pass runs. The throw is deferred to that pass.
            'throw_on_empty_domain' => false,
            // The strict domain is a subset of the full domain, so it searches
            // faster. Capping its budget bounds the added cost of the extra
            // pass, while the fallback pass keeps the full configured budget so
            // nothing that generates successfully today starts timing out.
            'timeout_seconds' => max(1.0, $configuration->timeoutSeconds / 2),
        ]);
    }

    private function statePlan(
        GenerationConfigurationValidationResult $validation,
        SchedulePlanStatus $status,
    ): SchedulePlan {
        return new SchedulePlan(
            planId: (string) Str::uuid(),
            configuration: $validation->configuration,
            snapshotFingerprint: $validation->snapshotFingerprint,
            status: $status,
            violations: $validation->violations,
            recommendations: $this->recommendations($validation),
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
            recommendations: $this->recommendations($validation),
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
            recommendations: $this->recommendations($validation),
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
    private function recommendations(GenerationConfigurationValidationResult $validation): array
    {
        return array_map(
            static fn (GenerationConfigurationRecommendation $recommendation): array => $recommendation->toArray(),
            $validation->recommendations,
        );
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
