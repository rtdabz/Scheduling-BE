<?php

namespace Tests\Unit;

use App\Enums\DepartmentSchedulingProfile;
use App\Models\Sections;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Generation\ScheduleGenerationPreflightService;
use App\Services\Scheduling\Generation\ScheduleQualityEvaluator;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use App\Services\Scheduling\Engine\Solver\CspYearLevelSchedulingSolverAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class YearLevelScheduleFairnessScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_online_rows_are_counted_as_last_resort(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'unnecessaryOnlineCount');
        $method->setAccessible(true);

        $candidate = ['schedules' => [
            $this->row(1, null, 'online', 'lecture'),
            $this->row(1, null, 'online', 'lecture'),
        ]];

        $this->assertSame(2, $method->invoke($service, $candidate, [1 => [
            'mode' => 'on-site',
            'delivery_modes_by_course_id' => [],
        ]]));
        $this->assertSame(0, $method->invoke($service, $candidate, [1 => [
            'mode' => 'online',
            'delivery_modes_by_course_id' => [],
        ]]));
    }

    public function test_year_level_generation_orders_resource_heavy_sections_first(): void
    {
        $service = $this->service();
        $sections = [$this->section(1), $this->section(2), $this->section(3)];
        $configs = [
            1 => [
                'course_ids' => [101, 102, 103],
                'selected_split_session_course_ids' => [],
                'delivery_modes_by_course_id' => [],
            ],
            2 => [
                'course_ids' => [201, 202, 203],
                'selected_split_session_course_ids' => [201, 202],
                'delivery_modes_by_course_id' => [201 => 'on-site', 202 => 'on-site'],
            ],
            3 => [
                'course_ids' => [301, 302, 303, 304],
                'selected_split_session_course_ids' => [],
                'delivery_modes_by_course_id' => [301 => 'online'],
            ],
        ];

        $method = new ReflectionMethod($service, 'candidateOrders');
        $orders = $method->invoke($service, $sections, $configs);
        $firstOrderSectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $orders[0]);

        $this->assertSame([2, 3, 1], $firstOrderSectionIds);
    }

    public function test_section_hybrid_retry_can_be_applied_without_a_course_id(): void
    {
        $preflight = $this->createMock(ScheduleGenerationPreflightService::class);
        $preflight->expects($this->once())
            ->method('validate')
            ->willReturn(DepartmentSchedulingProfile::LABORATORY_ENABLED);
        $builders = $this->createMock(ScheduleRequirementBuilderResolver::class);
        $builders->expects($this->once())
            ->method('build')
            ->willReturn([]);
        $service = new YearLevelScheduleGenerationService(
            new CspYearLevelSchedulingSolverAdapter($this->solverWithFairnessTargets()),
            new ScheduleQualityEvaluator,
            app(SchedulingSnapshotRepository::class),
            requirementBuilders: $builders,
            preflight: $preflight,
        );
        $section = $this->section(7);
        $method = new ReflectionMethod($service, 'applyAdjustments');

        $updated = $method->invoke(
            $service,
            [$section],
            [7 => [
                'course_ids' => [301, 302],
                'department_profile' => 'laboratory_enabled',
                'is_hybrid' => true,
                'selected_split_session_course_ids' => [301, 302],
            ]],
            [[
                'type' => 'disable_section_hybrid',
                'section_id' => 7,
                'course_id' => 0,
                'value' => null,
            ]],
            new Collection,
        );

        $this->assertSame([], $updated[7]['selected_split_session_course_ids']);
        $this->assertFalse($updated[7]['is_hybrid']);
        $this->assertSame([], $updated[7]['requirements_by_course_id']);
    }

    private function solverWithFairnessTargets(): CspSolver
    {
        return new class extends CspSolver
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
            new CspYearLevelSchedulingSolverAdapter($this->solverWithFairnessTargets()),
            new ScheduleQualityEvaluator,
            app(SchedulingSnapshotRepository::class),
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
    ): array {
        return [
            'section_id' => $sectionId,
            'room_id' => $roomId,
            'mode' => $mode,
            'meeting_type' => $meetingType,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '09:00',
        ];
    }
}
