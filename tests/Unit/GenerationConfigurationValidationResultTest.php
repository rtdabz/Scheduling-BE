<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use PHPUnit\Framework\TestCase;

class GenerationConfigurationValidationResultTest extends TestCase
{
    public function test_warning_result_requires_confirmation_and_round_trips(): void
    {
        $result = new GenerationConfigurationValidationResult(
            configuration: GenerationConfiguration::fromArray(['section_id' => 2, 'course_ids' => [3, 4]]),
            snapshotFingerprint: 'snapshot-sha256',
            violations: [new ConstraintViolation(
                'same_day_concentration',
                'Review the configured day.',
                'warning',
                'generation_configuration',
            )],
            recommendations: [new GenerationConfigurationRecommendation(
                id: 'clear-forced-day-monday-4',
                title: 'Reduce the Monday concentration',
                detectedCause: 'Both courses are forced to Monday.',
                suggestedAdjustment: 'Clear one forced-day rule.',
                impact: 'medium',
                adjustments: [['type' => 'clear_forced_day', 'course_id' => 4, 'value' => null]],
                sectionId: 2,
                courseId: 4,
            )],
        );

        $restored = GenerationConfigurationValidationResult::fromArray($result->toArray());

        $this->assertTrue($result->canGenerate());
        $this->assertTrue($result->requiresConfirmation());
        $this->assertSame('confirmation_required', $result->status());
        $this->assertSame($result->toArray(), $restored->toArray());
    }

    public function test_hard_violation_blocks_generation_without_confirmation(): void
    {
        $result = new GenerationConfigurationValidationResult(
            configuration: GenerationConfiguration::fromArray(['section_id' => 2, 'course_ids' => [3]]),
            snapshotFingerprint: 'snapshot-sha256',
            violations: [new ConstraintViolation('subject_active', 'Course is inactive.')],
        );

        $this->assertFalse($result->canGenerate());
        $this->assertFalse($result->requiresConfirmation());
        $this->assertSame('invalid', $result->status());
    }
}
