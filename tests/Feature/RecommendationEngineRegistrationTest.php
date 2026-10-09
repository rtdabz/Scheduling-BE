<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Scheduling\Recommendations\Providers\PlacementRecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use Tests\TestCase;

class RecommendationEngineRegistrationTest extends TestCase
{
    public function test_registry_is_complete_and_legacy_response_is_unchanged_without_resolving_placement(): void
    {
        $this->app->bind(PlacementRecommendationProvider::class, static function (): never {
            throw new \LogicException('Unrelated placement provider must remain lazy.');
        });
        $engine = $this->app->make(RecommendationEngine::class);
        $this->assertEqualsCanonicalizing(array_column(RecommendationSource::cases(), 'value'), $engine->sources());
        $this->assertNotContains('instructor_assignment', $engine->sources());
        $this->assertNull(RecommendationSource::tryFrom('instructor_assignment'));
        $payload = [
            'status' => 'conflict', 'operations' => [['section_id' => 10, 'room_id' => null]],
            'conflicts' => [['code' => 'room_overlap']], 'adjustments' => [],
        ];
        $result = $engine->recommend(new RecommendationContext(RecommendationSource::LegacySplit, ['payload' => $payload]));

        $this->assertSame($payload, $result->legacyPayload);
        $this->assertSame($payload['operations'], array_column($result->toArray()['options'], 'payload'));
        $this->assertFalse($result->options[0]->toArray()['verification']['complete_timetable_verified']);
        $this->assertNotSame($engine, $this->app->make(RecommendationEngine::class));
    }

    public function test_container_routes_generation_guidance_to_the_registered_provider(): void
    {
        $result = $this->app->make(RecommendationEngine::class)->recommend(new RecommendationContext(
            RecommendationSource::Search, ['bottleneck' => null, 'strategies' => []],
        ));

        $this->assertSame(['search-generic'], array_column($result->legacyPayload, 'id'));
        $this->assertSame('guidance', $result->options[0]->verificationStatus);
        $this->assertSame('configuration_adjustments', $result->options[0]->verificationScope);
    }
}
