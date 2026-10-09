<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Recommendations\GenerationRecommendationPolicy;
use App\Services\Scheduling\Recommendations\Providers\GenerationRecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class GenerationRecommendationCompatibilityTest extends TestCase
{
    public function test_selection_metadata_matches_shared_ui_fixtures_without_rewriting_options(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/GenerationSelectionCases.json'), true, flags: JSON_THROW_ON_ERROR);
        $options = array_map(static function (array $case): array {
            unset($case['expected']);

            return $case;
        }, $fixture['cases']);
        $decorated = GenerationRecommendationPolicy::withSelectionMetadata($options, $fixture['attempts']);
        foreach ($decorated as $index => $option) {
            $this->assertSame($fixture['cases'][$index]['expected'], $option['selection']);
            unset($option['selection']);
            $this->assertSame($options[$index], $option);
        }
        $active = array_values(array_filter($decorated, static fn (array $option): bool => $option['selection']['applicable']));
        usort($active, static fn (array $left, array $right): int => $left['selection']['priority'] <=> $right['selection']['priority']);
        $this->assertSame($fixture['active_order'], array_column($active, 'id'));
    }

    public function test_generation_provider_exposes_selection_only_when_the_consumer_opts_in(): void
    {
        $provider = new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics);
        $inputs = ['blockingConstraints' => [['code' => 'no_physical_rooms']]];
        $legacy = $provider->recommend(new RecommendationContext(RecommendationSource::Feasibility, $inputs))->legacyPayload;
        $current = $provider->recommend(new RecommendationContext(RecommendationSource::Feasibility, $inputs,
            metadata: ['selection_contract' => 1]))->legacyPayload;
        $this->assertFalse($current[0]['selection']['applicable']);
        $this->assertSame(1, $current[0]['selection']['contract_version']);
        unset($current[0]['selection']);
        $this->assertSame($legacy, $current);
    }

    public function test_split_alternatives_keep_existing_order_and_require_regeneration(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $inputs = [
            'bottleneck' => [
                'type' => YearLevelGenerationDiagnostics::TYPE_BALANCED_SPLIT,
                'section_id' => 10, 'section_name' => 'BSIT 1-A',
                'course_id' => 100, 'course_code' => 'GEC 101',
                'detected_cause' => 'no pair', 'hybrid_split_slot_available' => true,
            ],
            'strategies' => [],
            'courses' => collect([100 => (new Course)->forceFill([
                'id' => 100, 'course_code' => 'GEC 101', 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0,
            ])]),
            'configsBySectionId' => [10 => [
                'course_ids' => [100], 'balanced_split_course_ids' => [100],
                'hybrid_split_course_ids' => [], 'preferred_patterns' => [100 => null],
            ]],
            'searchIncomplete' => true,
        ];
        $result = (new GenerationRecommendationProvider($diagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Search, $inputs, metadata: ['search_incomplete' => true],
        ));

        $this->assertSame($diagnostics->searchRecommendations(...$inputs), $result->legacyPayload);
        $this->assertSame([
            'recommend-hybrid-split-10-100', 'recommend-online-split-10-100', 'recommend-regular-meeting-10-100',
        ], array_column($result->legacyPayload, 'id'));
        $this->assertTrue($result->metadata['search_incomplete']);
        foreach ($result->toArray()['options'] as $option) {
            $this->assertSame('requires_regeneration', $option['verification']['status']);
            $this->assertFalse($option['verification']['complete_timetable_verified']);
        }
    }

    public function test_resource_guidance_and_targeted_adjustments_keep_distinct_evidence(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $constraints = [
            ['code' => 'no_physical_rooms', 'message' => 'No usable room.', 'suggested_action' => 'Add a room.'],
            [
                'code' => 'preferred_days_too_few_for_hybrid', 'message' => 'GEC 101 needs two days.',
                'suggested_action' => 'Add a day or use on-site.',
                'context' => ['course_code' => 'GEC 101', 'targets' => [[
                    'section_id' => 10, 'course_id' => 100, 'adjustment_type' => 'disable_hybrid_split',
                ]]],
            ],
        ];
        $result = (new GenerationRecommendationProvider($diagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Feasibility, ['blockingConstraints' => $constraints],
        ));

        $this->assertSame($diagnostics->feasibilityRecommendations($constraints), $result->legacyPayload);
        $this->assertSame(['guidance', 'requires_regeneration'], array_column($result->options, 'verificationStatus'));
        $this->assertSame('disable_hybrid_split', $result->legacyPayload[1]['adjustments'][0]['type']);
        $this->assertSame(10, $result->legacyPayload[1]['adjustments'][0]['section_id']);
    }

    public function test_configuration_findings_reuse_existing_contract_without_changing_adjustments(): void
    {
        $finding = new GenerationConfigurationRecommendation(
            id: 'clear-forced-day-monday-100', title: 'Reduce Monday concentration',
            detectedCause: 'Courses use Monday.', suggestedAdjustment: 'Clear one Required Day.', impact: 'medium',
            adjustments: [['type' => 'clear_forced_day', 'course_id' => 100, 'value' => null]], sectionId: 10, courseId: 100,
        );
        $validation = new GenerationConfigurationValidationResult(
            configuration: GenerationConfiguration::fromArray(['section_id' => 10, 'course_ids' => [100]]),
            snapshotFingerprint: 'configuration-snapshot', recommendations: [$finding],
        );
        $result = (new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Configuration, ['validation' => $validation], snapshotFingerprint: $validation->snapshotFingerprint,
        ));

        $this->assertSame([$finding->toArray()], $result->legacyPayload);
        $this->assertSame('guidance', $result->options[0]->verificationStatus);
        $this->assertSame('configuration-snapshot', $result->toArray()['context']['snapshot_fingerprint']);
    }

    public function test_unsupported_and_mixed_adjustments_remain_guidance_with_original_payloads(): void
    {
        $unsupported = ['clear_forced_day', 'remove_course', 'remove_configuration_reference', 'split_session_single_meeting_fallback', 'unknown_future_adjustment'];
        $supported = ['type' => 'set_delivery_mode', 'course_id' => 100, 'value' => 'online'];
        $findings = [];
        foreach ($unsupported as $type) {
            foreach ([false, true] as $mixed) {
                $findings[] = new GenerationConfigurationRecommendation(
                    id: $type.($mixed ? '-mixed' : ''), title: 'Configuration guidance',
                    detectedCause: 'Configuration needs review.', suggestedAdjustment: 'Review configuration.', impact: 'medium',
                    adjustments: $mixed ? [$supported, ['type' => $type]] : [['type' => $type]],
                    sectionId: 10, courseId: 100,
                );
            }
        }
        $validation = new GenerationConfigurationValidationResult(
            configuration: GenerationConfiguration::fromArray(['section_id' => 10, 'course_ids' => [100]]),
            snapshotFingerprint: 'guidance-snapshot', recommendations: $findings,
        );
        $result = (new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Configuration, ['validation' => $validation],
        ));

        $this->assertSame(array_map(static fn ($finding): array => $finding->toArray(), $findings), $result->legacyPayload);
        foreach ($result->options as $option) {
            $this->assertSame('guidance', $option->verificationStatus, $option->payload['id']);
        }
    }

    public function test_provisional_metadata_is_independent_of_legacy_search_arguments(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $inputs = ['bottleneck' => ['type' => YearLevelGenerationDiagnostics::TYPE_LIMITED_ROOMS], 'strategies' => []];
        $provider = new GenerationRecommendationProvider($diagnostics);
        $result = $provider->recommend(new RecommendationContext(
            RecommendationSource::Search, $inputs, metadata: ['search_incomplete' => true],
        ));

        $this->assertSame($diagnostics->searchRecommendations(...$inputs), $result->legacyPayload);
        $this->assertSame(['advisory-resources'], array_column($result->legacyPayload, 'id'));
        $this->assertTrue($result->metadata['search_incomplete']);
        $this->assertFalse($provider->recommend(new RecommendationContext(RecommendationSource::Search, $inputs))->metadata['search_incomplete']);
    }

    public function test_success_advisory_does_not_verify_the_adjusted_timetable(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $inputs = ['day' => 'Tuesday', 'configsBySectionId' => [10 => ['allowed_days' => ['Monday', 'Wednesday']]], 'timetableFits' => true];
        $result = (new GenerationRecommendationProvider($diagnostics))->recommend(new RecommendationContext(RecommendationSource::PreferredDays, $inputs));

        $this->assertSame($diagnostics->preferredDayRecommendation(...$inputs), $result->legacyPayload);
        $this->assertSame('add-preferred-day-tuesday', $result->legacyPayload[0]['id']);
        $this->assertSame('low', $result->legacyPayload[0]['impact']);
        $this->assertSame('requires_regeneration', $result->options[0]->verificationStatus);
        $this->assertFalse($result->options[0]->toArray()['verification']['complete_timetable_verified']);
    }

    public function test_limited_room_failure_uses_readable_utf8_dashes(): void
    {
        $bottleneck = (new YearLevelGenerationDiagnostics)->detectBottleneck([[
            'section_id' => 10, 'course_count' => 2,
            'forced_on_site_courses' => [['course_id' => 100, 'course_code' => 'GEC 100']],
        ]], new Collection);

        $this->assertSame(YearLevelGenerationDiagnostics::TYPE_LIMITED_ROOMS, $bottleneck['type']);
        $this->assertSame(
            "Courses pinned to a physical room \u{2014} starting with GEC 100 \u{2014} ran out of eligible room-time.",
            $bottleneck['detected_cause'],
        );
    }

    public function test_explicit_diagnostics_dependency_is_preserved(): void
    {
        $custom = $this->createMock(YearLevelGenerationDiagnostics::class);
        $custom->expects($this->once())->method('feasibilityRecommendations')->with([])->willReturn([
            ['id' => 'custom-guidance', 'adjustments' => []],
        ]);
        $result = (new GenerationRecommendationProvider(new YearLevelGenerationDiagnostics))->recommend(new RecommendationContext(
            RecommendationSource::Feasibility, ['blockingConstraints' => [], 'diagnostics' => $custom],
        ));

        $this->assertSame('custom-guidance', $result->legacyPayload[0]['id']);
        $this->assertSame('guidance', $result->options[0]->verificationStatus);
    }
}
