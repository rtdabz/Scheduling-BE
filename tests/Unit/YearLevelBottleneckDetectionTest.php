<?php

namespace Tests\Unit;

use App\Services\Scheduling\YearLevel\YearLevelGenerationDiagnostics;
use PHPUnit\Framework\TestCase;

class YearLevelBottleneckDetectionTest extends TestCase
{
    public function test_the_course_the_solver_stalled_on_names_the_bottleneck(): void
    {
        $bottleneck = (new YearLevelGenerationDiagnostics)->detectBottleneck(
            [$this->failure(['course_id' => 5, 'course_code' => 'GEC 5', 'dead_ends' => 40])],
            collect(),
        );

        $this->assertSame('balanced_split', $bottleneck['type']);
        $this->assertSame(5, $bottleneck['course_id']);
        $this->assertSame('GEC 5', $bottleneck['course_code']);
    }

    public function test_without_an_observed_course_the_most_restrictive_setting_is_used(): void
    {
        $bottleneck = (new YearLevelGenerationDiagnostics)->detectBottleneck([$this->failure(null)], collect());

        $this->assertSame('lecture_lab_split', $bottleneck['type']);
        $this->assertSame(1, $bottleneck['course_id']);
    }

    public function test_a_stalled_course_without_its_own_restriction_falls_back_to_the_section(): void
    {
        $bottleneck = (new YearLevelGenerationDiagnostics)->detectBottleneck(
            [$this->failure(['course_id' => 9, 'course_code' => 'IT 109', 'dead_ends' => 12])],
            collect(),
        );

        $this->assertSame('lecture_lab_split', $bottleneck['type']);
        $this->assertSame(1, $bottleneck['course_id']);
    }

    public function test_a_search_cut_short_is_reported_as_unproven_not_as_a_conflict(): void
    {
        $diagnostics = new YearLevelGenerationDiagnostics;
        $bottleneck = $diagnostics->markSearchIncomplete($diagnostics->detectBottleneck(
            [[...$this->failure(null), 'split_courses' => [], 'balanced_split_courses' => []]],
            collect(),
        ));
        $attempts = [['strategy' => 'baseline', 'outcome' => 'failed']];

        $this->assertSame('laboratory_room', $bottleneck['type']);
        $this->assertTrue($bottleneck['search_incomplete']);
        $this->assertTrue($bottleneck['search_limit_reached']);
        $this->assertStringContainsString('ran out of time while placing IT 101', $bottleneck['detected_cause']);
        $this->assertStringNotContainsString('could not claim', $bottleneck['detected_cause']);

        $message = $diagnostics->searchMessage($bottleneck, $attempts, true);
        $this->assertStringContainsString('a timetable may still fit', $message);
        $this->assertStringContainsString('stuck on IT 101 in BSIT 3E', $message);

        // Rooms were never shown to run out, so no room-time advice.
        $ids = array_column($diagnostics->searchRecommendations($bottleneck, [], collect(), [], null, true), 'id');
        $this->assertNotContains('advisory-resources', $ids);
        $this->assertContains(
            'advisory-resources',
            array_column($diagnostics->searchRecommendations($bottleneck, [], collect()), 'id'),
        );
    }

    /**
     * BSIT 3E with a lecture/lab split (IT 101) placed first and a Split
     * Session (GEC 5) after it; IT 109 is a plain lecture.
     *
     * @param  array<string, mixed>|null  $blockingCourse
     * @return array<string, mixed>
     */
    private function failure(?array $blockingCourse): array
    {
        return [
            'section_id' => 30,
            'section_name' => 'BSIT 3E',
            'course_count' => 3,
            'pattern_courses' => [],
            'split_courses' => [['course_id' => 1, 'course_code' => 'IT 101']],
            'balanced_split_courses' => [['course_id' => 5, 'course_code' => 'GEC 5']],
            'hybrid_split_slot_available' => false,
            'laboratory_courses' => [['course_id' => 1, 'course_code' => 'IT 101']],
            'forced_on_site_courses' => [],
            'blocking_course' => $blockingCourse,
            'iterations' => 5000,
            'search_limit_reached' => true,
        ];
    }
}
