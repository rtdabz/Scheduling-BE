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

    public function test_weekday_candidate_ranks_above_equivalent_optional_saturday_candidate(): void
    {
        $sections = [$this->section(1, '1')];
        $weekday = [$this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00', 'Monday')];
        $saturday = [$this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00', 'Saturday')];

        $ranked = $this->evaluator->rank(
            [['score' => 2, 'schedules' => $saturday], ['score' => 1, 'schedules' => $weekday]],
            $sections,
        );

        $this->assertSame($weekday, $ranked[0]['schedules']);
        $this->assertSame(0, $ranked[0]['score_breakdown']['weekend_usage']);
        $this->assertGreaterThan(0, $ranked[1]['score_breakdown']['weekend_usage']);
    }

    public function test_forced_saturday_placement_is_not_treated_as_unnecessary_weekend_usage(): void
    {
        $sections = [$this->section(1, '1')];
        $saturday = [$this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00', 'Saturday')];
        $configs = [1 => ['forced_days_by_course_id' => [11 => 'Saturday']]];
        $fairness = $this->fairness(
            physicalRooms: 1,
            regularTargets: [1 => 1],
            labTargets: [1 => 0],
            onlineTargets: [1 => 0],
        );

        $score = $this->evaluator->evaluate($saturday, $sections, $configs, $fairness);

        $this->assertSame(0, $score['score_breakdown']['weekend_usage']);
        $this->assertSame(0, $score['score_breakdown']['weekday_capacity_migration']);
    }



    public function test_earlier_weekday_slots_rank_above_equivalent_late_slots(): void
    {
        $sections = [$this->section(1, '1')];
        $early = [$this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00')];
        $late = [$this->row(1, 101, 11, 'on-site', 'lecture', '16:00', '19:00')];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $late], ['score' => 2, 'schedules' => $early]],
            $sections,
        );

        $this->assertSame($early, $ranked[0]['schedules']);
        $this->assertSame(0, $ranked[0]['score_breakdown']['late_weekday_starts']);
        $this->assertGreaterThan(0, $ranked[1]['score_breakdown']['late_weekday_starts']);
    }

    public function test_same_day_clustering_ranks_above_unnecessary_day_spread(): void
    {
        $sections = [$this->section(1, '1')];
        $clustered = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '10:00', 'Monday'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '10:00', '12:00', 'Monday'),
            $this->row(1, 101, 13, 'on-site', 'lecture', '13:00', '15:00', 'Tuesday'),
            $this->row(1, 101, 14, 'on-site', 'lecture', '15:00', '17:00', 'Tuesday'),
        ];
        $scattered = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '10:00', 'Monday'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '08:00', '10:00', 'Tuesday'),
            $this->row(1, 101, 13, 'on-site', 'lecture', '08:00', '10:00', 'Wednesday'),
            $this->row(1, 101, 14, 'on-site', 'lecture', '08:00', '10:00', 'Thursday'),
        ];

        $clusteredScore = $this->evaluator->evaluate($clustered, $sections);
        $scatteredScore = $this->evaluator->evaluate($scattered, $sections);

        $this->assertGreaterThan($scatteredScore['quality_score'], $clusteredScore['quality_score']);
        $this->assertSame(0, $clusteredScore['score_breakdown']['section_day_spread']);
        $this->assertGreaterThan(0, $scatteredScore['score_breakdown']['section_day_spread']);
    }

    public function test_short_section_gaps_are_penalized_heavily_to_prefer_consecutive_blocks(): void
    {
        $sections = [$this->section(1, '1')];
        $consecutive = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '10:00', '13:00'),
            $this->row(1, 103, 13, 'on-site', 'laboratory', '13:00', '16:00'),
        ];
        $thirtyMinuteGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '10:30', '13:30'),
            $this->row(1, 103, 13, 'on-site', 'laboratory', '13:30', '16:30'),
        ];
        $oneHourGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '11:00', '14:00'),
            $this->row(1, 103, 13, 'on-site', 'laboratory', '14:00', '17:00'),
        ];

        $compactScore = $this->evaluator->evaluate($consecutive, $sections);
        $thirtyMinuteScore = $this->evaluator->evaluate($thirtyMinuteGap, $sections);
        $oneHourScore = $this->evaluator->evaluate($oneHourGap, $sections);

        $this->assertSame(0, $compactScore['score_breakdown']['section_idle_gaps']);
        $this->assertGreaterThanOrEqual(90000, $thirtyMinuteScore['score_breakdown']['section_idle_gaps']);
        $this->assertGreaterThan(
            $thirtyMinuteScore['score_breakdown']['section_idle_gaps'],
            $oneHourScore['score_breakdown']['section_idle_gaps'],
        );
        $this->assertGreaterThan($thirtyMinuteScore['quality_score'], $compactScore['quality_score']);
        $this->assertGreaterThan($oneHourScore['quality_score'], $thirtyMinuteScore['quality_score']);
    }

    public function test_laboratory_rooms_prefer_consecutive_standard_blocks(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1'), $this->section(3, '1')];
        $roomTypes = [201 => 'laboratory'];
        $compact = [
            $this->row(1, 201, 11, 'on-site', 'laboratory', '07:00', '10:00'),
            $this->row(2, 201, 12, 'on-site', 'laboratory', '10:00', '13:00'),
            $this->row(3, 201, 13, 'on-site', 'laboratory', '13:00', '16:00'),
        ];
        $fragmented = [
            $this->row(1, 201, 11, 'on-site', 'laboratory', '07:00', '10:00'),
            $this->row(2, 201, 12, 'on-site', 'laboratory', '10:30', '13:30'),
            $this->row(3, 201, 13, 'on-site', 'laboratory', '14:00', '17:00'),
        ];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $fragmented], ['score' => 2, 'schedules' => $compact]],
            $sections,
            roomTypesById: $roomTypes,
        );

        $this->assertSame($compact, $ranked[0]['schedules']);
        $this->assertLessThanOrEqual(0, $ranked[0]['score_breakdown']['laboratory_room_compactness']);
        $this->assertGreaterThan(0, $ranked[1]['score_breakdown']['laboratory_room_compactness']);
    }

    public function test_laboratory_compactness_does_not_penalize_unused_labs(): void
    {
        $sections = [$this->section(1, '1')];
        $schedules = [
            $this->row(1, 201, 11, 'on-site', 'laboratory', '07:00', '10:00'),
        ];

        $score = $this->evaluator->evaluate(
            $schedules,
            $sections,
            roomTypesById: [201 => 'laboratory', 202 => 'laboratory'],
        );

        $this->assertSame(0, $score['score_breakdown']['laboratory_room_compactness']);
    }

    public function test_classroom_room_optimization_does_not_score_laboratory_rooms(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1'), $this->section(3, '1')];
        $schedules = [
            $this->row(1, 201, 11, 'on-site', 'laboratory', '07:00', '10:00'),
            $this->row(2, 201, 12, 'on-site', 'laboratory', '10:30', '13:30'),
            $this->row(3, 201, 13, 'on-site', 'laboratory', '14:30', '17:30'),
        ];

        $score = $this->evaluator->evaluate(
            $schedules,
            $sections,
            roomTypesById: [201 => 'laboratory'],
        );

        $this->assertSame(0, $score['score_breakdown']['room_idle_gaps']);
        $this->assertSame(0, $score['score_breakdown']['room_optimization']);
        $this->assertSame(0, $score['score_breakdown']['classroom_fragment_gaps']);
        $this->assertGreaterThan(0, $score['score_breakdown']['laboratory_room_compactness']);
    }

    public function test_room_optimization_prefers_continuous_room_usage(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1'), $this->section(3, '1')];
        $compact = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '10:00', '13:00'),
            $this->row(3, 101, 13, 'on-site', 'lecture', '13:00', '16:00'),
        ];
        $fragmented = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '11:00', '14:00'),
            $this->row(3, 101, 13, 'on-site', 'lecture', '15:00', '18:00'),
        ];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $fragmented], ['score' => 2, 'schedules' => $compact]],
            $sections,
        );

        $this->assertSame($compact, $ranked[0]['schedules']);
        $this->assertLessThanOrEqual(0, $ranked[0]['score_breakdown']['room_optimization']);
        $this->assertGreaterThan(0, $ranked[1]['score_breakdown']['room_optimization']);
    }

    public function test_classroom_fragment_gaps_are_penalized_when_they_could_be_merged_into_a_schedulable_block(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1'), $this->section(3, '1')];
        $mergedGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '10:00', '13:00'),
            $this->row(3, 101, 13, 'on-site', 'lecture', '14:30', '16:00'),
        ];
        $fragmentedGaps = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '10:30', '13:30'),
            $this->row(3, 101, 13, 'on-site', 'lecture', '14:30', '16:00'),
        ];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $fragmentedGaps], ['score' => 2, 'schedules' => $mergedGap]],
            $sections,
            roomTypesById: [101 => 'lecture'],
        );

        $this->assertSame($mergedGap, $ranked[0]['schedules']);
        $this->assertLessThan(
            $ranked[1]['score_breakdown']['classroom_fragment_gaps'],
            $ranked[0]['score_breakdown']['classroom_fragment_gaps'],
        );
        $this->assertGreaterThanOrEqual(185000, $ranked[1]['score_breakdown']['classroom_fragment_gaps']);
    }

    public function test_classroom_gaps_that_leave_unusable_remainders_rank_below_clean_schedulable_gaps(): void
    {
        $sections = [$this->section(1, '1'), $this->section(2, '1')];
        $cleanThreeHourGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '11:30', '13:00'),
        ];
        $awkwardTwoAndHalfHourGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(2, 101, 12, 'on-site', 'lecture', '11:00', '13:00'),
        ];

        $ranked = $this->evaluator->rank(
            [
                ['score' => 1, 'schedules' => $awkwardTwoAndHalfHourGap],
                ['score' => 2, 'schedules' => $cleanThreeHourGap],
            ],
            $sections,
            roomTypesById: [101 => 'lecture'],
        );

        $this->assertSame($cleanThreeHourGap, $ranked[0]['schedules']);
        $this->assertGreaterThan(
            $ranked[0]['score_breakdown']['classroom_fragment_gaps'],
            $ranked[1]['score_breakdown']['classroom_fragment_gaps'],
        );
    }

    public function test_five_slot_gap_is_penalized_as_an_awkward_classroom_and_section_gap(): void
    {
        $sections = [$this->section(1, '1')];
        $consecutive = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '08:30', '10:30'),
        ];
        $fiveSlotGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '11:00', '13:00'),
        ];

        $ranked = $this->evaluator->rank(
            [
                ['score' => 1, 'schedules' => $fiveSlotGap],
                ['score' => 2, 'schedules' => $consecutive],
            ],
            $sections,
            roomTypesById: [101 => 'lecture'],
        );

        $this->assertSame($consecutive, $ranked[0]['schedules']);
        $this->assertGreaterThanOrEqual(350000, $ranked[1]['score_breakdown']['classroom_fragment_gaps']);
        $this->assertGreaterThanOrEqual(250000, $ranked[1]['score_breakdown']['section_awkward_gaps']);
    }

    public function test_six_slot_gap_is_penalized_as_empty_full_lecture_capacity(): void
    {
        $sections = [$this->section(1, '1')];
        $consecutive = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '08:30', '10:30'),
        ];
        $sixSlotGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '08:30'),
            $this->row(1, 101, 12, 'on-site', 'lecture', '11:30', '13:30'),
        ];

        $ranked = $this->evaluator->rank(
            [
                ['score' => 1, 'schedules' => $sixSlotGap],
                ['score' => 2, 'schedules' => $consecutive],
            ],
            $sections,
            roomTypesById: [101 => 'lecture'],
        );

        $this->assertSame($consecutive, $ranked[0]['schedules']);
        $this->assertGreaterThanOrEqual(120000, $ranked[1]['score_breakdown']['classroom_fragment_gaps']);
        $this->assertGreaterThanOrEqual(250000, $ranked[1]['score_breakdown']['section_awkward_gaps']);
    }

    public function test_room_optimization_does_not_penalize_unused_rooms(): void
    {
        $sections = [$this->section(1, '1')];
        $schedules = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '07:00', '10:00'),
        ];

        $score = $this->evaluator->evaluate(
            $schedules,
            $sections,
            roomTypesById: [101 => 'lecture', 102 => 'lecture'],
        );

        $this->assertSame(0, $score['score_breakdown']['room_optimization']);
    }

    public function test_complete_timetable_refinements_are_deferred_during_partial_selection(): void
    {
        $sections = [$this->section(1, '1')];
        $compact = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '11:00', '14:00'),
        ];
        $lateWithGap = [
            $this->row(1, 101, 11, 'on-site', 'lecture', '08:00', '11:00'),
            $this->row(1, 102, 12, 'on-site', 'lecture', '16:00', '19:00'),
        ];

        $ranked = $this->evaluator->rank(
            [['score' => 1, 'schedules' => $lateWithGap], ['score' => 2, 'schedules' => $compact]],
            $sections,
            includeCompleteTimetableRefinements: false,
        );

        $this->assertSame($lateWithGap, $ranked[0]['schedules']);
        $this->assertSame(0, $ranked[0]['score_breakdown']['late_weekday_starts']);
        $this->assertSame(0, $ranked[0]['score_breakdown']['section_idle_gaps']);
        $this->assertSame(0, $ranked[1]['score_breakdown']['late_weekday_starts']);
        $this->assertSame(0, $ranked[1]['score_breakdown']['section_idle_gaps']);
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
        string $day = 'Monday',
    ): array {
        return [
            'section_id' => $sectionId,
            'course_id' => $courseId,
            'room_id' => $roomId,
            'mode' => $mode,
            'meeting_type' => $meetingType,
            'day' => $day,
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
