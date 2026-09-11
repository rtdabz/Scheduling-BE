<?php

namespace Tests\Unit;

use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The gate that stops the retry ladder from re-running an over-constrained
 * search. Every strategy in the ladder relaxes a preference, so none of them
 * can widen a section's hard teaching period; without this gate a year level
 * squeezed into one period spent the whole time budget before reporting.
 */
class YearLevelPreferredPeriodBlockerTest extends TestCase
{
    public function test_a_restricted_blocking_section_stops_the_ladder(): void
    {
        $blocker = $this->detect(
            bottleneck: ['section_id' => 10, 'section_name' => 'BSIT 1A'],
            configs: [10 => ['preferred_period' => 'morning']],
        );

        $this->assertSame(10, $blocker['section_id']);
        $this->assertSame('BSIT 1A', $blocker['section_name']);
        $this->assertSame('Morning (7:00 AM - 11:30 AM)', $blocker['period_label']);
    }

    public function test_an_unrestricted_blocking_section_keeps_the_ladder(): void
    {
        $this->assertNull($this->detect(
            bottleneck: ['section_id' => 10, 'section_name' => 'BSIT 1A'],
            configs: [10 => ['preferred_period' => null]],
        ));
    }

    /**
     * A period on some other section must not disable the retries: the
     * strategies can still fix a pattern or split problem in the section that
     * actually failed.
     */
    public function test_a_period_on_another_section_is_ignored(): void
    {
        $this->assertNull($this->detect(
            bottleneck: ['section_id' => 10, 'section_name' => 'BSIT 1A'],
            configs: [
                10 => [],
                11 => ['preferred_period' => 'evening'],
            ],
        ));
    }

    public function test_no_identified_bottleneck_keeps_the_ladder(): void
    {
        $this->assertNull($this->detect(
            bottleneck: null,
            configs: [10 => ['preferred_period' => 'morning']],
        ));
    }

    public function test_the_section_name_falls_back_to_the_section_record(): void
    {
        $section = new class
        {
            public int $id = 10;

            public string $section_name = 'BSIT 1C';
        };

        $blocker = $this->detect(
            bottleneck: ['section_id' => 10],
            configs: [10 => ['preferred_period' => 'afternoon']],
            sections: [$section],
        );

        $this->assertSame('BSIT 1C', $blocker['section_name']);
        $this->assertSame('Afternoon (11:30 AM - 4:00 PM)', $blocker['period_label']);
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  array<int, array<string, mixed>>  $configs
     * @param  list<object>  $sections
     * @return array<string, mixed>|null
     */
    private function detect(?array $bottleneck, array $configs, array $sections = []): ?array
    {
        $service = (new ReflectionClass(YearLevelScheduleGenerationService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'preferredPeriodBlocker');

        return $method->invoke($service, $bottleneck, $configs, $sections);
    }
}
