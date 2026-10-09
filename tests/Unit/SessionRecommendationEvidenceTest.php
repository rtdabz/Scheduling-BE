<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Recommendations\Providers\GenerationRecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use Tests\TestCase;

class SessionRecommendationEvidenceTest extends TestCase
{
    public function test_a_physical_interval_never_becomes_a_verified_hybrid_group_or_timetable(): void
    {
        $course = new Course(['id' => 100, 'course_code' => 'LECTURE', 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor']);
        $config = ['course_ids' => [100], 'balanced_split_course_ids' => [100], 'requirements_by_course_id' => [100 => [
            ['component_type' => 'lecture', 'duration_slots' => 6, 'allowed_delivery_modes' => ['on-site', 'online']],
        ]]];
        $inputs = [
            'bottleneck' => ['type' => YearLevelGenerationDiagnostics::TYPE_BALANCED_SPLIT, 'section_id' => 10, 'course_id' => 100, 'hybrid_split_slot_available' => true],
            'strategies' => [], 'courses' => collect([100 => $course]), 'configsBySectionId' => [10 => $config],
        ];
        $result = (new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics))->recommend(new RecommendationContext(RecommendationSource::Search, $inputs));
        $hybrid = collect($result->options)->first(fn ($option): bool => $option->payload['id'] === 'recommend-hybrid-split-10-100');
        $this->assertNotNull($hybrid);
        $this->assertSame('requires_regeneration', $hybrid->verificationStatus);
        $this->assertFalse($hybrid->toArray()['verification']['complete_timetable_verified']);
        $this->assertFalse($result->metadata['complete_group_verified']);
        $this->assertStringContainsString('matching online meeting', $hybrid->payload['suggested_adjustment']);
        $this->assertSame(['recommend-hybrid-split-10-100', 'recommend-online-split-10-100', 'recommend-regular-meeting-10-100'], array_column($result->legacyPayload, 'id'));
        $this->assertSame('Online (All)', $result->legacyPayload[1]['title']);
        $this->assertSame('set_delivery_mode', $result->legacyPayload[1]['adjustments'][0]['type']);
        $this->assertSame([3, 3], array_column($result->metadata['sessions']['10:100']['meetings'], 'duration_slots'));
    }

    public function test_room_capacity_arithmetic_explains_its_scope_without_promising_a_timetable(): void
    {
        $inputs = ['blockingConstraints' => [[
            'code' => 'insufficient_room_slots', 'context' => ['shortfall_slots' => 4, 'options' => [[
                'kind' => 'hybrid_split', 'frees' => 6, 'course_codes' => ['LECTURE'],
                'targets' => [['section_id' => 10, 'course_id' => 100, 'adjustment_type' => 'set_hybrid_split']],
            ]]],
        ]]];
        $result = (new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics))->recommend(new RecommendationContext(RecommendationSource::Feasibility, $inputs));
        $option = $result->legacyPayload[0];
        $this->assertSame('room-capacity-hybrid_split', $option['id']);
        $this->assertStringContainsString('estimated room-time shortfall', $option['detected_cause']);
        $this->assertStringContainsString('has not been verified', $option['detected_cause']);
        $this->assertStringContainsString('Generate again', $option['suggested_adjustment']);
        $this->assertFalse($result->metadata['complete_group_verified']);
    }

    public function test_generation_interpretation_uses_snapshot_section_rules_without_leaking_them_to_diagnostics(): void
    {
        $snapshot = SchedulingSnapshot::fromArray([
            'fingerprint' => 'session-snapshot', 'semester_id' => 1, 'department_id' => 2,
            'consecutive_day_rules' => [['course_id' => 100, 'section_id' => 10, 'day_count' => 2]],
        ]);
        $config = ['course_ids' => [100], 'requirements_by_course_id' => [100 => [
            ['component_type' => 'lecture', 'duration_slots' => 4, 'allowed_delivery_modes' => ['on-site']],
        ]]];
        $inputs = ['bottleneck' => null, 'strategies' => [], 'configsBySectionId' => [10 => $config, 11 => $config]];
        $diagnostics = new YearLevelGenerationDiagnostics;
        $result = (new GenerationRecommendationProvider($diagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Search, [...$inputs, 'snapshot' => $snapshot],
        ));
        $this->assertSame($diagnostics->searchRecommendations(...$inputs), $result->legacyPayload);
        $this->assertSame('consecutive', $result->metadata['sessions']['10:100']['kind']);
        $this->assertSame('regular', $result->metadata['sessions']['11:100']['kind']);
        $this->assertSame([4, 4], array_column($result->metadata['sessions']['10:100']['meetings'], 'duration_slots'));
        $this->assertArrayNotHasKey('snapshot', $result->toArray()['context']);
    }
}
