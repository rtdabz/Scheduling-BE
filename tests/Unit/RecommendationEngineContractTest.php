<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

class RecommendationEngineContractTest extends TestCase
{
    public function test_serialization_excludes_trusted_inputs_and_preserves_scope(): void
    {
        $context = new RecommendationContext(
            RecommendationSource::ManualPlacement,
            ['snapshot' => new \stdClass, 'tentativeSchedules' => [['private' => 'input']]],
            ['semester_id' => 8, 'section_id' => 10],
            'snapshot-123',
        );

        $this->assertSame([
            'source' => 'manual_placement',
            'scope' => ['semester_id' => 8, 'section_id' => 10],
            'snapshot_fingerprint' => 'snapshot-123',
            'metadata' => [],
        ], json_decode(json_encode($context, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_normalization_preserves_original_order_identity_and_null_empty_values(): void
    {
        $options = [
            ['id' => '10:100:2', 'rank' => 2, 'room_id' => null, 'meetings' => [], 'score' => 9.5],
            ['id' => '10:100:1', 'rank' => 1, 'meetings' => [['room_id' => 3, 'delivery_mode' => 'On-site']]],
        ];
        $payload = ['issues' => [['id' => '10:100', 'options' => $options]], 'checked_rows' => 2];
        $result = RecommendationResult::adapt(
            new RecommendationContext(RecommendationSource::DraftReview, []),
            $payload, $options, 'draft_course_options', metadata: ['checked_rows' => 2],
        );

        $this->assertSame($payload, $result->legacyPayload);
        $this->assertSame($options, array_column($result->toArray()['options'], 'payload'));
        $this->assertSame(1, $result->toArray()['schema_version']);
        $this->assertSame(['checked_rows' => 2], $result->toArray()['metadata']);
        foreach ($result->toArray()['options'] as $option) {
            $this->assertSame([
                'status' => 'legacy_checks_only',
                'scope' => 'draft_course_options',
                'complete_timetable_verified' => false,
                'requires_application_validation' => true,
            ], $option['verification']);
        }
    }

    public function test_request_results_do_not_reuse_another_context_or_payload(): void
    {
        $provider = new class implements RecommendationProvider
        {
            public function recommend(RecommendationContext $context): RecommendationResult
            {
                return RecommendationResult::adapt($context, $context->inputs['payload'], [], 'legacy_split_preview');
            }
        };
        $engine = new RecommendationEngine(['legacy_split_validation' => static fn () => $provider]);
        $first = new RecommendationContext(RecommendationSource::LegacySplit, ['payload' => ['operations' => [['id' => 3]]]]);
        $second = new RecommendationContext(RecommendationSource::LegacySplit, ['payload' => ['operations' => []]]);

        $this->assertSame($first->inputs['payload'], $engine->recommend($first)->legacyPayload);
        $this->assertSame($second, $engine->recommend($second)->context);
        $this->assertSame(['operations' => []], $engine->recommend($second)->legacyPayload);
    }

    public function test_missing_provider_fails_instead_of_returning_empty_success(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RecommendationEngine([]))->recommend(new RecommendationContext(RecommendationSource::Conflict, []));
    }

    public function test_provider_cannot_return_results_for_a_different_context(): void
    {
        $provider = new class implements RecommendationProvider
        {
            public function recommend(RecommendationContext $context): RecommendationResult
            {
                return RecommendationResult::adapt(new RecommendationContext($context->source, []), [], [], 'conflict_candidates');
            }
        };
        $engine = new RecommendationEngine(['conflict_resolution' => static fn () => $provider]);

        $this->expectException(LogicException::class);
        $engine->recommend(new RecommendationContext(RecommendationSource::Conflict, []));
    }
}
