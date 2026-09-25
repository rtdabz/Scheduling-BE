<?php

namespace Tests\Unit;

use App\Services\Scheduling\YearLevel\YearLevelGenerationChangeReport;
use PHPUnit\Framework\TestCase;

class YearLevelGenerationChangeReportTest extends TestCase
{
    private const SECTIONS = [10 => 'BSIT 1-A', 11 => 'BSIT 1-B'];

    private const COURSES = [100 => 'GEC 101', 200 => 'IT 101'];

    public function test_a_run_generated_as_configured_reports_no_changes(): void
    {
        $changes = (new YearLevelGenerationChangeReport)->build(null, [], [
            $this->row(10, 100, 'Monday', ['room_id' => 5]),
            $this->row(10, 100, 'Wednesday', ['room_id' => 5]),
            $this->row(11, 200, 'Tuesday', ['mode' => 'online', 'room_id' => null]),
        ], self::SECTIONS, self::COURSES);

        $this->assertSame([], $changes);
    }

    public function test_a_retry_that_only_reordered_the_search_is_not_a_change(): void
    {
        $changes = (new YearLevelGenerationChangeReport)->build(
            ['key' => 'alternate_ordering', 'label' => 'Alternate ordering', 'adjustments' => []],
            [],
            [$this->row(10, 100, 'Monday', ['room_id' => 5])],
            self::SECTIONS,
            self::COURSES,
        );

        $this->assertSame([], $changes);
    }

    public function test_a_relaxed_preference_is_reported_with_resolved_names(): void
    {
        $changes = (new YearLevelGenerationChangeReport)->build(
            [
                'key' => 'alternate_pattern',
                'label' => 'Alternate pattern',
                'description' => 'Tried the other pattern.',
                'adjustments' => [['type' => 'set_pattern', 'section_id' => 10, 'course_id' => 100, 'value' => 'TTh']],
            ],
            [],
            [$this->row(10, 100, 'Tuesday', ['room_id' => 5])],
            self::SECTIONS,
            self::COURSES,
            [
                'type' => 'fixed_pattern',
                'section_name' => 'BSIT 1-A',
                'course_code' => 'GEC 101',
                'detected_cause' => 'The fixed MW pattern on GEC 101 has no valid placement.',
            ],
            [
                ['strategy' => 'baseline', 'outcome' => 'failed'],
                ['strategy' => 'alternate_ordering', 'outcome' => 'failed'],
                ['strategy' => 'alternate_pattern', 'outcome' => 'succeeded'],
            ],
        );

        $this->assertCount(1, $changes);
        $this->assertSame(YearLevelGenerationChangeReport::KIND_PREFERENCE_RELAXED, $changes[0]['kind']);
        $this->assertSame([
            'section_id' => 10,
            'section_name' => 'BSIT 1-A',
            'course_id' => 100,
            'course_code' => 'GEC 101',
            'detail' => 'Meeting pattern changed to TTh',
            'adjustment_type' => 'set_pattern',
            'adjustment_value' => 'TTh',
        ], $changes[0]['items'][0]);
        $this->assertSame('fixed_pattern', $changes[0]['detected_issue']['type']);
        $this->assertSame('GEC 101', $changes[0]['detected_issue']['course_code']);
        $this->assertSame(2, $changes[0]['failed_attempts']);
    }

    public function test_online_fallback_and_room_tba_are_reported_once_per_class(): void
    {
        $changes = (new YearLevelGenerationChangeReport)->build(null, [], [
            $this->row(10, 100, 'Monday', ['mode' => 'online', 'room_id' => null, 'lecture_online_fallback' => true]),
            $this->row(10, 100, 'Wednesday', ['mode' => 'online', 'room_id' => null, 'lecture_online_fallback' => true]),
            $this->row(11, 200, 'Friday', ['room_id' => null, 'meeting_type' => 'laboratory']),
            // A field meeting has no room by design.
            $this->row(11, 100, 'Saturday', ['mode' => 'field', 'room_id' => null]),
        ], self::SECTIONS, self::COURSES);

        $this->assertSame(
            [YearLevelGenerationChangeReport::KIND_MOVED_ONLINE, YearLevelGenerationChangeReport::KIND_ROOM_TBA],
            array_column($changes, 'kind'),
        );
        $this->assertCount(1, $changes[0]['items']);
        $this->assertSame('Scheduled online: no lecture room was available (Monday, Wednesday)', $changes[0]['items'][0]['detail']);
        $this->assertSame('critical', $changes[1]['severity']);
        $this->assertSame('active', $changes[1]['status']);
        $this->assertFalse($changes[1]['resolved']);
        $this->assertSame('BSIT 1-B', $changes[1]['items'][0]['section_name']);
        $this->assertSame('IT 101', $changes[1]['items'][0]['course_code']);
    }

    public function test_split_session_fallbacks_keep_their_own_names(): void
    {
        $changes = (new YearLevelGenerationChangeReport)->build(null, [[
            'type' => 'split_session_single_meeting_fallback',
            'section_id' => 10,
            'course_id' => 100,
            'section_name' => 'BSIT 1-A',
            'course_code' => 'GEC 101',
        ]], [$this->row(10, 100, 'Friday', ['room_id' => 5, 'split_session_fallback' => true])], self::SECTIONS, self::COURSES);

        $this->assertSame(YearLevelGenerationChangeReport::KIND_SINGLE_MEETING, $changes[0]['kind']);
        $this->assertSame('Meets once a week instead of twice', $changes[0]['items'][0]['detail']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $sectionId, int $courseId, string $day, array $overrides = []): array
    {
        return [
            'section_id' => $sectionId,
            'course_id' => $courseId,
            'day' => $day,
            'start_time' => '07:30:00',
            'end_time' => '09:00:00',
            'mode' => 'on-site',
            ...$overrides,
        ];
    }
}
