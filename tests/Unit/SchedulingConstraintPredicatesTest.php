<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintParityReporter;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Support\SchedulingPolicy;
use PHPUnit\Framework\TestCase;

class SchedulingConstraintPredicatesTest extends TestCase
{
    public function test_overlap_uses_half_open_time_ranges(): void
    {
        $this->assertTrue(SchedulingConstraintPredicates::overlaps('08:00', '10:00', '09:30', '11:00'));
        $this->assertFalse(SchedulingConstraintPredicates::overlaps('08:00', '10:00', '10:00', '11:00'));
        $this->assertFalse(SchedulingConstraintPredicates::overlaps('10:00', '11:00', '08:00', '10:00'));
    }

    public function test_capacity_sweep_treats_end_events_before_start_events(): void
    {
        $row = ScheduleRow::fromArray([
            'semester_id' => 1,
            'section_id' => 1,
            'course_id' => 1,
            'department_id' => 1,
            'day' => 'Monday',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'mode' => 'online',
        ]);

        $this->assertFalse(SchedulingConstraintPredicates::concurrencyExceeds($row, [[
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]], 1));
        $this->assertTrue(SchedulingConstraintPredicates::concurrencyExceeds($row, [[
            'start_time' => '09:30',
            'end_time' => '10:30',
        ]], 1));
    }

    public function test_parity_reporter_exposes_both_mismatch_directions(): void
    {
        $report = (new SchedulingConstraintParityReporter)->compare(
            [
                ['rule' => 'section_conflict'],
                ['rule' => 'legacy_only'],
            ],
            [
                new ConstraintViolation('section_conflict', 'Conflict.'),
                new ConstraintViolation('kernel_only', 'Conflict.'),
            ],
        );

        $this->assertFalse($report['matches']);
        $this->assertSame(['legacy_only'], $report['legacy_only']);
        $this->assertSame(['kernel_only'], $report['kernel_only']);
    }

    public function test_every_executable_kernel_rule_is_cataloged_with_a_unique_priority(): void
    {
        foreach (array_keys(SchedulingConstraintKernel::RULE_PRIORITY) as $ruleId) {
            $this->assertArrayHasKey($ruleId, SchedulingPolicy::CONSTRAINT_CATALOG);
        }

        $priorities = array_values(SchedulingConstraintKernel::RULE_PRIORITY);
        $this->assertCount(count($priorities), array_unique($priorities));
    }

    public function test_course_classification_does_not_promote_missing_category_to_major(): void
    {
        $this->assertFalse(SchedulingConstraintPredicates::isMajorCourse([
            'course_category' => 'minor',
        ]));
        $this->assertFalse(SchedulingConstraintPredicates::isMajorCourse([]));
        $this->assertTrue(SchedulingConstraintPredicates::isMajorCourse([
            'subject_category' => 'major',
        ]));
    }
}
