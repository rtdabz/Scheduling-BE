<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\ScheduleRecommendation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Domain\PreparedGenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\ScheduleRecommendationPayload;
use App\Services\Scheduling\Domain\ScheduleRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecommendationPlanBackfillService
{
    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly GenerationConfigurationFingerprint $fingerprints,
    ) {}

    /** @return array{updated:int, skipped:int, failed:list<int>} */
    public function backfill(?int $recommendationId = null): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'failed' => []];
        ScheduleRecommendation::query()
            ->when($recommendationId !== null, fn ($query) => $query->whereKey($recommendationId))
            ->whereNotNull('recommended_schedules')
            ->orderBy('id')
            ->each(function (ScheduleRecommendation $recommendation) use (&$result): void {
                try {
                    $payload = is_array($recommendation->input_payload) ? $recommendation->input_payload : [];
                    if (ScheduleRecommendationPayload::isVersioned($payload)) {
                        $versioned = ScheduleRecommendationPayload::fromArray($payload);
                        if ($versioned->schedulePlan !== null) {
                            $result['skipped']++;

                            return;
                        }
                        $configuration = $versioned->configuration;
                    } else {
                        $rows = $this->rows($recommendation);
                        $configuration = GenerationConfiguration::fromArray([
                            ...$payload,
                            'section_id' => $recommendation->section_id,
                            'course_ids' => array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['course_id'] ?? $row['subject_id'] ?? 0), $rows))),
                        ]);
                    }
                    $rows = $this->rows($recommendation);
                    $snapshot = $this->snapshots->captureForConfiguration((int) $recommendation->term_id, (int) $recommendation->department_id, $configuration);
                    $plan = new SchedulePlan(
                        planId: (string) Str::uuid(), configuration: $configuration,
                        snapshotFingerprint: $snapshot->fingerprint,
                        status: SchedulePlanStatus::RoomAssignmentComplete,
                        rows: array_map(static fn (array $row): ScheduleRow => ScheduleRow::fromArray($row), $rows),
                        scores: ['quality_score' => (int) $recommendation->score],
                        metadata: ['backfilled_from_recommendation_id' => (int) $recommendation->id],
                    );
                    $legacyPayload = $payload;
                    unset($legacyPayload[ScheduleRecommendationPayload::ENVELOPE_KEY]);
                    $envelope = ScheduleRecommendationPayload::fromPrepared(
                        [...$legacyPayload, 'section_id' => $recommendation->section_id, 'course_ids' => $configuration->courseIds],
                        new PreparedGenerationConfiguration(
                            configuration: $configuration,
                            validation: new GenerationConfigurationValidationResult(
                                configuration: $configuration,
                                snapshotFingerprint: $snapshot->fingerprint,
                            ),
                            configurationFingerprint: $this->fingerprints->calculate($configuration),
                        ),
                        $plan,
                    )->toArray();
                    DB::transaction(fn () => $recommendation->update(['input_payload' => $envelope]));
                    $result['updated']++;
                } catch (\Throwable) {
                    $result['failed'][] = (int) $recommendation->id;
                }
            });

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(ScheduleRecommendation $recommendation): array
    {
        return array_map(function (array $row) use ($recommendation): array {
            return [...$row, 'term_id' => $row['term_id'] ?? $recommendation->term_id, 'section_id' => $row['section_id'] ?? $recommendation->section_id, 'department_id' => $row['department_id'] ?? $recommendation->department_id];
        }, is_array($recommendation->recommended_schedules) ? $recommendation->recommended_schedules : []);
    }
}
