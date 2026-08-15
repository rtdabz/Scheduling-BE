<?php

namespace Tests\Unit;

use App\Models\Sections;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\ScheduleQualityEvaluator;
use App\Services\Scheduling\YearLevelScheduleGenerationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class YearLevelScheduleFairnessScoreTest extends TestCase
{
    public function test_balanced_candidate_scores_better_than_section_dominated_candidate(): void
    {
        $service = $this->service();
        $sections = [$this->section(1), $this->section(2)];

        $balanced = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 103, 'on-site', 'laboratory'),
            $this->row(1, null, 'online', 'lecture'),
            $this->row(2, 102, 'on-site', 'lecture'),
            $this->row(2, 104, 'on-site', 'laboratory'),
            $this->row(2, null, 'online', 'lecture'),
        ], $sections);

        $dominated = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 103, 'on-site', 'laboratory'),
            $this->row(2, null, 'online', 'lecture'),
            $this->row(2, null, 'online', 'lecture'),
            $this->row(2, null, 'online', 'laboratory'),
        ], $sections);

        $this->assertGreaterThan($dominated['score'], $balanced['score']);
        $this->assertSame(0, $balanced['score_breakdown']['physical_distribution']);
        $this->assertGreaterThan(0, $dominated['score_breakdown']['physical_distribution']);
        $this->assertGreaterThan(0, $dominated['score_breakdown']['laboratory_distribution']);
        $this->assertGreaterThan(0, $dominated['score_breakdown']['dominant_physical_share']);
    }

    public function test_room_concentration_is_penalized_when_other_rooms_are_available(): void
    {
        $service = $this->service();
        $sections = [$this->section(1), $this->section(2)];

        $distributed = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 103, 'on-site', 'laboratory'),
            $this->row(2, 102, 'on-site', 'lecture'),
            $this->row(2, 104, 'on-site', 'laboratory'),
        ], $sections);

        $concentrated = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 101, 'on-site', 'laboratory'),
            $this->row(2, 101, 'on-site', 'lecture'),
            $this->row(2, 101, 'on-site', 'laboratory'),
        ], $sections);

        $this->assertSame(0, $distributed['score_breakdown']['room_concentration']);
        $this->assertGreaterThan(0, $concentrated['score_breakdown']['room_concentration']);
        $this->assertGreaterThan($concentrated['score'], $distributed['score']);
    }

    public function test_weekday_physical_capacity_is_preferred_over_weekend_and_online_migration(): void
    {
        $service = $this->service();
        $sections = [$this->section(1), $this->section(2)];

        $weekdayPhysical = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture'),
            $this->row(1, 103, 'on-site', 'laboratory'),
            $this->row(2, 102, 'on-site', 'lecture'),
            $this->row(2, 104, 'on-site', 'laboratory'),
        ], $sections);

        $migrated = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Saturday'),
            $this->row(1, null, 'online', 'laboratory'),
            $this->row(2, 102, 'on-site', 'lecture'),
            $this->row(2, 104, 'on-site', 'laboratory'),
        ], $sections);

        $this->assertSame(0, $weekdayPhysical['score_breakdown']['weekday_capacity_migration']);
        $this->assertGreaterThan(0, $migrated['score_breakdown']['weekday_capacity_migration']);
        $this->assertGreaterThan($migrated['score'], $weekdayPhysical['score']);
    }

    public function test_room_idle_gaps_are_penalized_in_year_level_score(): void
    {
        $service = $this->service();
        $sections = [$this->section(1), $this->section(2)];

        $compact = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '07:00', '09:00'),
            $this->row(2, 101, 'on-site', 'lecture', 'Monday', '09:00', '11:00'),
        ], $sections);

        $withGap = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '07:00', '09:00'),
            $this->row(2, 101, 'on-site', 'lecture', 'Monday', '10:00', '12:00'),
        ], $sections);

        $this->assertSame(0, $compact['score_breakdown']['room_idle_gaps']);
        $this->assertGreaterThan(0, $withGap['score_breakdown']['room_idle_gaps']);
        $this->assertGreaterThan($withGap['score'], $compact['score']);
    }

    public function test_section_compactness_is_scored_separately_from_resource_fairness(): void
    {
        $service = $this->service();
        $sections = [$this->section(1)];

        $compact = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '08:00', '11:00'),
            $this->row(1, 102, 'on-site', 'lecture', 'Monday', '11:00', '13:00'),
            $this->row(1, 103, 'on-site', 'laboratory', 'Monday', '13:00', '16:00'),
        ], $sections);

        $scattered = $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '08:00', '11:00'),
            $this->row(1, 102, 'on-site', 'lecture', 'Monday', '13:00', '16:00'),
            $this->row(1, 103, 'on-site', 'laboratory', 'Monday', '17:00', '19:00'),
        ], $sections);

        $this->assertSame(250000, $compact['schedule_compactness_score']);
        $this->assertSame(0, $compact['score_breakdown']['section_idle_gaps']);
        $this->assertLessThan($compact['schedule_compactness_score'], $scattered['schedule_compactness_score']);
        $this->assertSame($compact['resource_fairness_score'], $scattered['resource_fairness_score']);
        $this->assertSame(
            array_sum($scattered['quality_breakdown']),
            $scattered['score'],
        );
        $this->assertGreaterThan($scattered['score'], $compact['score']);
    }

    public function test_longer_section_gaps_receive_progressively_larger_penalties(): void
    {
        $service = $this->service();
        $sections = [$this->section(1)];

        $scoreWithGap = fn (string $secondStart): array => $this->score($service, [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '08:00', '10:00'),
            $this->row(1, 102, 'on-site', 'lecture', 'Monday', $secondStart, '16:00'),
        ], $sections);

        $thirtyMinutes = $scoreWithGap('10:30');
        $oneHour = $scoreWithGap('11:00');
        $fourHours = $scoreWithGap('14:00');

        $this->assertGreaterThan(
            $oneHour['schedule_compactness_score'],
            $thirtyMinutes['schedule_compactness_score'],
        );
        $this->assertGreaterThan(
            $fourHours['schedule_compactness_score'],
            $oneHour['schedule_compactness_score'],
        );
    }

    public function test_instructor_data_does_not_affect_plotting_score(): void
    {
        $service = $this->service();
        $sections = [$this->section(1)];
        $rows = [
            $this->row(1, 101, 'on-site', 'lecture', 'Monday', '08:00', '11:00'),
            $this->row(1, 102, 'on-site', 'lecture', 'Monday', '11:00', '13:00'),
        ];
        $withInstructors = array_map(
            static fn (array $row): array => array_merge($row, ['faculty_id' => 999]),
            $rows,
        );

        $withoutInstructorScore = $this->score($service, $rows, $sections);
        $withInstructorScore = $this->score($service, $withInstructors, $sections);

        $this->assertSame($withoutInstructorScore['score'], $withInstructorScore['score']);
        $this->assertSame(
            $withoutInstructorScore['schedule_compactness_score'],
            $withInstructorScore['schedule_compactness_score'],
        );
    }

    private function solverWithFairnessTargets(): CSPSolver
    {
        return new class extends CSPSolver
        {
            public function departmentRoomFairness(): array
            {
                return [
                    'active_sections' => 2,
                    'physical_rooms' => 4,
                    'target_physical_ratio' => 2 / 3,
                    'scarcity_multiplier' => 1 / 3,
                    'section_regular_physical_targets' => [1 => 1, 2 => 1],
                    'section_lab_physical_targets' => [1 => 1, 2 => 1],
                    'section_online_targets' => [1 => 1, 2 => 1],
                ];
            }
        };
    }

    private function service(): YearLevelScheduleGenerationService
    {
        return new YearLevelScheduleGenerationService(
            $this->solverWithFairnessTargets(),
            new ScheduleQualityEvaluator,
        );
    }

    private function section(int $id): Sections
    {
        $section = new Sections;
        $section->id = $id;

        return $section;
    }

    private function row(
        int $sectionId,
        ?int $roomId,
        string $mode,
        string $meetingType,
        string $day = 'Monday',
        string $startTime = '07:00',
        string $endTime = '09:00',
    ): array {
        return [
            'section_id' => $sectionId,
            'room_id' => $roomId,
            'mode' => $mode,
            'meeting_type' => $meetingType,
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function score(YearLevelScheduleGenerationService $service, array $schedules, array $sections): array
    {
        $method = new ReflectionMethod($service, 'scoreCandidate');

        return $method->invoke($service, $schedules, $sections);
    }
}
