<?php

namespace Tests\Unit;

use App\Models\Sections;
use App\Services\Scheduling\ScheduleQualityEvaluator;
use PHPUnit\Framework\TestCase;

class ScheduleQualityEvaluatorTest extends TestCase
{
    private ScheduleQualityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ScheduleQualityEvaluator;
    }

    public function test_many_rooms_prefers_face_to_face_over_unnecessary_online_classes(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1')];
        $fairness = $this->fairness(
            physicalRooms: 6,
            regularTargets: [1 => 2, 2 => 2],
            labTargets: [1 => 0, 2 => 0],
            onlineTargets: [1 => 0, 2 => 0],
        );
        $physical = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '10:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '10:00', '12:00'),
            $this->row(2, 103, 11, 'on-site', 'lecture', '08:00', '10:00'),
            $this->row(2, 104, 12, 'on-site', 'lecture', '10:00', '12:00'),
        ];
        $online = array_map(static fn (array $row): array => array_merge($row, [
            'room_id' => null,
            'mode' => 'online',
        ]), $physical);

        $physicalScore = $this->evaluator->evaluate($physical, $sections, fairness: $fairness);
        $onlineScore = $this->evaluator->evaluate($online, $sections, fairness: $fairness);

        $this->assertGreaterThan($onlineScore['quality_score'], $physicalScore['quality_score']);
        $this->assertSame(0, $physicalScore['score_breakdown']['unnecessary_online']);
        $this->assertGreaterThan(0, $onlineScore['score_breakdown']['unnecessary_online']);
    }

    public function test_limited_rooms_prefers_balanced_physical_access_between_sections(): void
    {
        $sections = [$this->section(1, '2'), $this->section(2, '2')];
        $fairness = $this->fairness(
            physicalRooms: 1,
            regularTargets: [1 => 1, 2 => 1],
            labTargets: [1 => 0, 2 => 0],
            onlineTargets: [1 => 1, 2 => 1],
        );
        $balanced = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '10:00'),
            $this->row(1, null, 12, 'online', 'lecture', '10:00', '12:00'),
            $this->row(2, 101, 11, 'on-site', 'lecture', '13:00', '15:00'),
            $this->row(2, null, 12, 'online', 'lecture', '15:00', '17:00'),
        ];
        $dominated = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '10:00'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '10:00', '12:00'),
            $this->row(2, null, 11, 'online', 'lecture', '13:00', '15:00'),
            $this->row(2, null, 12, 'online', 'lecture', '15:00', '17:00'),
        ];

        $balancedScore = $this->evaluator->evaluate($balanced, $sections, fairness: $fairness);
        $dominatedScore = $this->evaluator->evaluate($dominated, $sections, fairness: $fairness);

        $this->assertGreaterThan($dominatedScore['quality_score'], $balancedScore['quality_score']);
        $this->assertSame(0, $balancedScore['score_breakdown']['physical_distribution']);
        $this->assertGreaterThan(0, $dominatedScore['score_breakdown']['physical_distribution']);
    }

    public function test_first_section_does_not_consume_physical_capacity_reserved_for_later_sections(): void
    {
        $sections = [$this->section(1, '2')];
        $fairness = $this->fairness(
            physicalRooms: 2,
            regularTargets: [1 => 3, 2 => 3, 3 => 3],
            labTargets: [1 => 0, 2 => 0, 3 => 0],
            onlineTargets: [1 => 2, 2 => 2, 3 => 2],
        );
        $allPhysical = $this->sectionRows(1, 5, 5, 100);
        $targeted = $this->sectionRows(1, 5, 3, 100);

        $ranked = $this->evaluator->rank(
            [
                ['score' => 1, 'schedules' => $allPhysical],
                ['score' => 2, 'schedules' => $targeted],
            ],
            $sections,
            fairness: $fairness,
        );

        $this->assertSame($targeted, $ranked[0]['schedules']);
        $this->assertGreaterThan(
            $ranked[0]['score_breakdown']['regular_physical_targets'],
            $ranked[1]['score_breakdown']['regular_physical_targets'],
        );
    }

    public function test_balanced_f2f_percentages_score_above_first_section_dominated_distribution(): void
    {
        $sections = [$this->section(1, '2'), $this->section(2, '2'), $this->section(3, '2')];
        $fairness = $this->fairness(
            physicalRooms: 9,
            regularTargets: [1 => 3, 2 => 3, 3 => 3],
            labTargets: [1 => 0, 2 => 0, 3 => 0],
            onlineTargets: [1 => 2, 2 => 2, 3 => 2],
        );
        $balanced = array_merge(
            $this->sectionRows(1, 5, 3, 100),
            $this->sectionRows(2, 5, 3, 200),
            $this->sectionRows(3, 5, 3, 300),
        );
        $firstSectionDominated = array_merge(
            $this->sectionRows(1, 5, 5, 100),
            $this->sectionRows(2, 5, 3, 200),
            $this->sectionRows(3, 5, 1, 300),
        );

        $balancedScore = $this->evaluator->evaluate($balanced, $sections, fairness: $fairness);
        $dominatedScore = $this->evaluator->evaluate($firstSectionDominated, $sections, fairness: $fairness);

        $this->assertGreaterThan($dominatedScore['quality_score'], $balancedScore['quality_score']);
        $this->assertSame(0, $balancedScore['score_breakdown']['physical_distribution']);
        $this->assertSame(0, $balancedScore['score_breakdown']['physical_rate_variance']);
        $this->assertSame(0, $balancedScore['score_breakdown']['first_section_physical_advantage']);
        $this->assertGreaterThan(0, $dominatedScore['score_breakdown']['physical_distribution']);
        $this->assertGreaterThan(0, $dominatedScore['score_breakdown']['physical_rate_variance']);
        $this->assertGreaterThan(0, $dominatedScore['score_breakdown']['first_section_physical_advantage']);
    }

    public function test_laboratory_meetings_receive_priority_for_laboratory_rooms(): void
    {
        $sections = [$this->section(1, '3')];
        $labRoom = [$this->row(1, 201, 31, 'on-site', 'laboratory', '08:00', '11:00')];
        $lectureRoom = [$this->row(1, 101, 31, 'on-site', 'laboratory', '08:00', '11:00')];
        $roomTypes = [101 => 'lecture', 201 => 'laboratory'];

        $preferred = $this->evaluator->evaluate($labRoom, $sections, roomTypesById: $roomTypes);
        $fallback = $this->evaluator->evaluate($lectureRoom, $sections, roomTypesById: $roomTypes);

        $this->assertGreaterThan($fallback['quality_score'], $preferred['quality_score']);
        $this->assertSame(0, $preferred['score_breakdown']['laboratory_room_mismatch']);
        $this->assertGreaterThan(0, $fallback['score_breakdown']['laboratory_room_mismatch']);
    }

    public function test_split_configuration_is_evaluated_per_section(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1')];
        $configs = [
            1 => ['selected_split_session_course_ids' => [101]],
            2 => ['selected_split_session_course_ids' => []],
        ];
        $compliant = [
            $this->row(1, 101, 101, 'on-site', 'lecture', '08:00', '09:00'),
            $this->row(1, 201, 101, 'on-site', 'laboratory', '09:00', '11:00'),
            $this->row(2, 102, 101, 'on-site', null, '08:00', '11:00'),
        ];
        $wrongSection = [
            $this->row(1, 101, 101, 'on-site', null, '08:00', '11:00'),
            $this->row(2, 102, 101, 'on-site', 'lecture', '08:00', '09:00'),
            $this->row(2, 202, 101, 'on-site', 'laboratory', '09:00', '11:00'),
        ];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $wrongSection], ['score' => 2, 'schedules' => $compliant]],
            $sections,
            $configs,
        );

        $this->assertSame($compliant, $ranked[0]['schedules']);
        $this->assertSame(0, $ranked[0]['score_breakdown']['configuration_violations']);
        $this->assertGreaterThan(0, $ranked[1]['score_breakdown']['configuration_violations']);
    }

    private function section(int $id, string $yearLevel): Sections
    {
        $section = new Sections;
        $section->id = $id;
        $section->year_level = $yearLevel;

        return $section;
    }

    private function row(
        int $sectionId,
        ?int $roomId,
        int $courseId,
        string $mode,
        ?string $meetingType,
        string $start,
        string $end,
    ): array {
        return [
            'section_id' => $sectionId,
            'course_id' => $courseId,
            'room_id' => $roomId,
            'mode' => $mode,
            'meeting_type' => $meetingType,
            'day' => 'Monday',
            'start_time' => $start,
            'end_time' => $end,
        ];
    }

    private function fairness(
        int $physicalRooms,
        array $regularTargets,
        array $labTargets,
        array $onlineTargets,
    ): array {
        return [
            'physical_rooms' => $physicalRooms,
            'section_regular_physical_targets' => $regularTargets,
            'section_lab_physical_targets' => $labTargets,
            'section_online_targets' => $onlineTargets,
        ];
    }

    private function sectionRows(int $sectionId, int $courseCount, int $physicalCount, int $roomBase): array
    {
        $rows = [];
        for ($index = 0; $index < $courseCount; $index++) {
            $isPhysical = $index < $physicalCount;
            $rows[] = $this->row(
                $sectionId,
                $isPhysical ? $roomBase + $index : null,
                ($sectionId * 100) + $index,
                $isPhysical ? 'on-site' : 'online',
                'lecture',
                sprintf('%02d:00', 8 + $index),
                sprintf('%02d:00', 9 + $index),
            );
        }

        return $rows;
    }
}
