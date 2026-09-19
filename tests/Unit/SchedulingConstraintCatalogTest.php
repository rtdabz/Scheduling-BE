<?php

namespace Tests\Unit;

use App\Services\Scheduling\Schedule\BatchConflict;
use App\Services\Scheduling\Support\SchedulingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 0 characterization guard for the current scheduling rule namespace.
 *
 * This intentionally scans the existing implementations. It can be removed once
 * Phase 3 replaces string rule IDs with executable constraint specifications.
 */
class SchedulingConstraintCatalogTest extends TestCase
{
    public function test_every_catalog_entry_has_the_required_phase_zero_metadata(): void
    {
        foreach (SchedulingPolicy::catalog() as $id => $constraint) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $id);
            $this->assertContains($constraint['severity'] ?? null, ['hard', 'warning', 'soft'], "{$id} has an invalid severity.");
            $this->assertNotSame('', trim((string) ($constraint['category'] ?? '')), "{$id} has no category.");
            $this->assertNotSame('', trim((string) ($constraint['description'] ?? '')), "{$id} has no description.");
            $this->assertNotEmpty($constraint['enforced_by'] ?? [], "{$id} has no current enforcement owner.");
        }
    }

    public function test_literal_runtime_rule_ids_are_registered_in_the_catalog(): void
    {
        $paths = [
            app_path('Services/Scheduling/Engine/RuleEngine.php'),
            ...glob(app_path('Services/Scheduling/Engine/Rules/*.php')),
            app_path('Http/Controllers/ScheduleController.php'),
            app_path('Http/Controllers/ScheduleRecommendationController.php'),
            app_path('Http/Controllers/InstructorAssignmentController.php'),
            app_path('Http/Controllers/Concerns/ConfirmsFacultyOverload.php'),
        ];

        $emitted = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "Unable to read {$path}.");

            preg_match_all("/['\"]rule['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", (string) $source, $matches);
            foreach ($matches[1] as $id) {
                $emitted[$id] = $id;
            }
        }

        // These group rules are selected by expressions rather than literal rule
        // assignments, so the source regex cannot discover them automatically.
        $emitted['hybrid_component_count'] = 'hybrid_component_count';
        $emitted['minor_split_component_count'] = 'minor_split_component_count';

        $missing = array_values(array_diff(array_values($emitted), array_keys(SchedulingPolicy::catalog())));
        sort($missing);

        $this->assertSame([], $missing, 'Runtime scheduling rules missing from SchedulingPolicy: '.implode(', ', $missing));
    }

    #[DataProvider('batchConflictRuleProvider')]
    public function test_batch_conflict_rule_ids_are_registered(string $rule): void
    {
        $this->assertArrayHasKey($rule, SchedulingPolicy::catalog());
    }

    public static function batchConflictRuleProvider(): array
    {
        return [
            'section' => [BatchConflict::RULE_SECTION],
            'subject section time' => [BatchConflict::RULE_SUBJECT_SECTION_TIME],
            'room' => [BatchConflict::RULE_ROOM],
            'faculty' => [BatchConflict::RULE_FACULTY],
        ];
    }
}
