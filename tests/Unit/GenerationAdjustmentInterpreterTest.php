<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Recommendations\GenerationAdjustmentInterpreter;
use App\Services\Scheduling\Recommendations\GenerationRecommendationPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationAdjustmentInterpreterTest extends TestCase
{
    public static function cases(): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/GenerationAdjustmentCases.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures['cases'] as $case) {
            $cases[$case['name']] = [$fixtures['base'], $case];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_fixed_server_and_frontend_preview_cases(array $base, array $case): void
    {
        $config = array_replace($base, $case['initial']);
        $input = [5 => $config];
        $original = $input;
        $interpreter = new GenerationAdjustmentInterpreter;
        if ($case['error']) {
            try {
                $interpreter->apply($input, $case['adjustments']);
                $this->fail('An invalid selection must reject the whole batch.');
            } catch (InvalidArgumentException) {
                $this->assertSame($original, $input);
            }

            return;
        }
        $result = $interpreter->apply($input, $case['adjustments']);
        $this->assertEquals(array_replace($config, $case['expected']), $result['configs'][5]);
        $this->assertCount($case['applied_count'], $result['applied']);
        $this->assertSame($original, $input);
    }

    public function test_year_level_changes_require_all_targets_and_leave_other_preferences_intact(): void
    {
        $configs = [5 => ['course_ids' => [11], 'allowed_days' => ['Monday']], 6 => ['course_ids' => [11], 'allowed_days' => ['Monday']]];
        $operation = ['type' => 'add_preferred_day', 'section_id' => 5, 'course_id' => 0, 'value' => 'Tuesday'];
        $interpreter = new GenerationAdjustmentInterpreter;
        $result = $interpreter->apply($configs, [$operation, [...$operation, 'section_id' => 6]]);
        $this->assertSame(['Monday', 'Tuesday'], $result['configs'][5]['allowed_days']);
        $this->assertSame(['Monday', 'Tuesday'], $result['configs'][6]['allowed_days']);
        $this->assertCount(2, $result['applied']);
        $this->expectException(InvalidArgumentException::class);
        $interpreter->apply($configs, [$operation]);
    }

    public function test_incomplete_generic_search_is_not_proof_of_infeasibility(): void
    {
        $options = (new GenerationRecommendationPolicy)->searchRecommendations(null, [], searchIncomplete: true);
        $this->assertSame('search-generic', $options[0]['id']);
        $this->assertStringContainsString('does not prove', $options[0]['detected_cause']);
        $this->assertSame([], $options[0]['adjustments']);
    }
}
