<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use App\Services\Scheduling\YearLevel\YearLevelRetryStrategyPlanner;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class YearLevelSplitSessionRecommendationTest extends TestCase
{
    private const SECTION_ID = 10;

    private const COURSE_ID = 100;

    public function test_a_split_session_bottleneck_plans_a_one_meeting_retry(): void
    {
        $strategies = (new YearLevelRetryStrategyPlanner)->plan(
            [$this->section()],
            $this->configs(),
            $this->courses(),
            $this->bottleneck(),
        );

        $keys = array_column($strategies, 'key');
        $this->assertContains('clear_bottleneck_balanced_split', $keys);

        $strategy = $strategies[array_search('clear_bottleneck_balanced_split', $keys, true)];
        $this->assertSame([[
            'type' => 'disable_minor_split',
            'section_id' => self::SECTION_ID,
            'course_id' => self::COURSE_ID,
            'value' => null,
            'section_name' => 'BSIT 1-A',
            'course_code' => 'GEC 101',
        ]], $strategy['adjustments']);
    }

    public function test_split_session_recommendations_are_applicable_and_not_duplicated(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $strategies = (new YearLevelRetryStrategyPlanner)->plan(
            [$this->section()],
            $this->configs(),
            $this->courses(),
            $this->bottleneck(),
        );

        $recommendations = collect($diagnostics->searchRecommendations(
            $this->bottleneck(),
            $strategies,
            $this->courses(),
            $this->configs(),
        ))->keyBy('id');

        $hybrid = $recommendations->get('recommend-hybrid-split-10-100');
        $this->assertNotNull($hybrid);
        $this->assertSame('enable_hybrid_split', $hybrid['adjustments'][0]['type']);

        // The ladder already offers "one meeting", so the advisory copy is dropped.
        $this->assertTrue($recommendations->has('strategy-clear_bottleneck_balanced_split'));
        $this->assertFalse($recommendations->has('recommend-regular-meeting-10-100'));

        // A meeting-shape bottleneck gets no room-time advice.
        $this->assertFalse($recommendations->has('advisory-resources'));
    }

    public function test_regular_meeting_is_applicable_when_the_ladder_did_not_offer_it(): void
    {
        $recommendations = collect((new YearLevelGenerationDiagnostics)->searchRecommendations(
            $this->bottleneck(),
            [],
            $this->courses(),
            $this->configs(),
        ))->keyBy('id');

        $regular = $recommendations->get('recommend-regular-meeting-10-100');
        $this->assertNotNull($regular);
        $this->assertSame('disable_minor_split', $regular['adjustments'][0]['type']);
    }

    public function test_room_bottlenecks_still_get_room_time_advice(): void
    {
        $recommendations = (new YearLevelGenerationDiagnostics)->searchRecommendations(
            ['type' => YearLevelGenerationDiagnostics::TYPE_LIMITED_ROOMS, 'section_id' => self::SECTION_ID, 'section_name' => 'BSIT 1-A', 'course_id' => null, 'course_code' => null, 'detected_cause' => 'rooms'],
            [],
        );

        $this->assertContains('advisory-resources', array_column($recommendations, 'id'));
    }

    public function test_feasibility_blocks_get_specific_titles_and_preferred_day_fixes(): void
    {
        $recommendations = (new YearLevelGenerationDiagnostics)->feasibilityRecommendations([
            [
                'code' => 'preferred_days_too_few_for_hybrid',
                'message' => 'GEC 101 is Hybrid',
                'suggested_action' => 'Add a day',
                'context' => [
                    'course_id' => self::COURSE_ID,
                    'course_code' => 'GEC 101',
                    'targets' => [
                        ['section_id' => 10, 'section_name' => 'BSIT 1-A', 'course_id' => 100, 'course_code' => 'GEC 101', 'adjustment_type' => 'disable_hybrid_split'],
                        ['section_id' => 11, 'section_name' => 'BSIT 1-B', 'course_id' => 100, 'course_code' => 'GEC 101', 'adjustment_type' => 'disable_lecture_lab_split'],
                    ],
                ],
            ],
            ['code' => 'component_duration_exceeds_day', 'message' => 'too long', 'suggested_action' => 'shorten', 'context' => ['course_code' => 'IT 101']],
            ['code' => 'forced_day_capacity_exceeded', 'message' => 'full', 'suggested_action' => 'release', 'context' => ['forced_day' => 'Monday']],
        ]);

        $this->assertSame(['disable_hybrid_split', 'disable_lecture_lab_split'], array_column($recommendations[0]['adjustments'], 'type'));
        $this->assertSame('medium', $recommendations[0]['impact']);
        $this->assertSame('Shorten the IT 101 block or extend operating hours', $recommendations[1]['title']);
        $this->assertSame('Release the Monday Required Day or add rooms', $recommendations[2]['title']);
        // Department data (course units, Required Days) is advice, never an automatic change.
        $this->assertSame([], $recommendations[1]['adjustments']);
        $this->assertSame([], $recommendations[2]['adjustments']);
    }

    private function section(): Sections
    {
        return (new Sections)->forceFill(['id' => self::SECTION_ID, 'section_name' => 'BSIT 1-A']);
    }

    /** @return Collection<int, Course> */
    private function courses(): Collection
    {
        return collect([self::COURSE_ID => (new Course)->forceFill([
            'id' => self::COURSE_ID,
            'course_code' => 'GEC 101',
            'units' => 3,
            'lecture_hours' => 3,
            'lab_hours' => 0,
        ])]);
    }

    /** @return array<int, array<string, mixed>> */
    private function configs(): array
    {
        return [self::SECTION_ID => [
            'course_ids' => [self::COURSE_ID],
            'balanced_split_course_ids' => [self::COURSE_ID],
            'hybrid_split_course_ids' => [],
            'preferred_patterns' => [self::COURSE_ID => null],
        ]];
    }

    /** @return array<string, mixed> */
    private function bottleneck(): array
    {
        return [
            'type' => YearLevelGenerationDiagnostics::TYPE_BALANCED_SPLIT,
            'section_id' => self::SECTION_ID,
            'section_name' => 'BSIT 1-A',
            'course_id' => self::COURSE_ID,
            'course_code' => 'GEC 101',
            'detected_cause' => 'no pair',
            'hybrid_split_slot_available' => true,
        ];
    }
}
