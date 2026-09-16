<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CspPreferredPeriodTest extends TestCase
{
    /**
     * Slots are 30 minutes from 07:00, so morning is [0, 9), afternoon
     * [9, 18) and evening [18, 27).
     */
    public function test_only_candidates_inside_the_window_survive(): void
    {
        $solver = new CspSolver;
        $filter = new ReflectionMethod($solver, 'filterDomainByWindow');

        $domain = [
            $this->candidate(0, 4),    // 07:00 - 09:00
            $this->candidate(6, 9),    // 10:00 - 11:30
            $this->candidate(9, 12),   // 11:30 - 13:00
            $this->candidate(18, 21),  // 16:00 - 17:30
        ];

        $morning = $filter->invoke($solver, $domain, 0, 9);
        $this->assertSame([[0, 4], [6, 9]], $this->windows($morning));

        $afternoon = $filter->invoke($solver, $domain, 9, 18);
        $this->assertSame([[9, 12]], $this->windows($afternoon));

        $evening = $filter->invoke($solver, $domain, 18, 27);
        $this->assertSame([[18, 21]], $this->windows($evening));
    }

    public function test_a_meeting_that_overruns_the_window_is_rejected(): void
    {
        $solver = new CspSolver;
        $filter = new ReflectionMethod($solver, 'filterDomainByWindow');

        // Starts at 10:00, inside morning, but runs to 12:00 — past 11:30. The
        // cohort must not be on campus outside its period at all.
        $overrunning = [$this->candidate(6, 10)];

        $this->assertSame([], $filter->invoke($solver, $overrunning, 0, 9));
    }

    public function test_every_meeting_of_a_split_candidate_must_fit(): void
    {
        $solver = new CspSolver;
        $filter = new ReflectionMethod($solver, 'filterDomainByWindow');

        $bothInside = ['blocks' => [
            ['day' => 'Monday', 'start_slot' => 0, 'end_slot' => 3],
            ['day' => 'Wednesday', 'start_slot' => 4, 'end_slot' => 7],
        ]];
        $oneOutside = ['blocks' => [
            ['day' => 'Monday', 'start_slot' => 0, 'end_slot' => 3],
            ['day' => 'Wednesday', 'start_slot' => 12, 'end_slot' => 15],
        ]];

        $this->assertCount(1, $filter->invoke($solver, [$bothInside], 0, 9));
        $this->assertSame([], $filter->invoke($solver, [$oneOutside], 0, 9));
    }

    public function test_unknown_periods_are_ignored_rather_than_restricting(): void
    {
        $solver = new CspSolver;
        $normalize = new ReflectionMethod($solver, 'normalizePreferredPeriod');

        $this->assertSame('morning', $normalize->invoke($solver, 'Morning'));
        $this->assertSame('evening', $normalize->invoke($solver, ' evening '));
        $this->assertNull($normalize->invoke($solver, 'midday'));
        $this->assertNull($normalize->invoke($solver, ''));
        $this->assertNull($normalize->invoke($solver, null));
    }

    public function test_the_window_is_named_in_full_for_failure_messages(): void
    {
        $solver = new CspSolver;
        $label = new ReflectionMethod($solver, 'preferredPeriodLabel');

        // One wording for the window everywhere it is shown: the solver's
        // failure message, the year-level recommendation and the Preferred
        // Meetings board used to print three different formats.
        $this->assertSame('Morning (7:00 AM - 11:30 AM)', $label->invoke($solver, 'morning'));
        $this->assertSame('Afternoon (11:30 AM - 4:00 PM)', $label->invoke($solver, 'afternoon'));
        $this->assertSame('Evening (4:00 PM - 8:30 PM)', $label->invoke($solver, 'evening'));
    }

    /** @return array<string, mixed> */
    private function candidate(int $startSlot, int $endSlot): array
    {
        return [
            'course_id' => 7,
            'mode' => 'on-site',
            'blocks' => [[
                'day' => 'Monday',
                'start_slot' => $startSlot,
                'end_slot' => $endSlot,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $domain
     * @return list<array{int, int}>
     */
    private function windows(array $domain): array
    {
        return array_map(
            static fn (array $candidate): array => [
                (int) $candidate['blocks'][0]['start_slot'],
                (int) $candidate['blocks'][0]['end_slot'],
            ],
            $domain,
        );
    }
}
