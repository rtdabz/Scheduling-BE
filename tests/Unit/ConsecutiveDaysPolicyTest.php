<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\Rules\MeetingDayRule;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Support\SchedulingPolicy;
use PHPUnit\Framework\TestCase;

class ConsecutiveDaysPolicyTest extends TestCase
{
    public function test_runs_are_calendar_consecutive_teaching_days_in_week_order(): void
    {
        $this->assertSame([
            ['Monday', 'Tuesday', 'Wednesday'],
            ['Tuesday', 'Wednesday', 'Thursday'],
            ['Wednesday', 'Thursday', 'Friday'],
            ['Thursday', 'Friday', 'Saturday'],
        ], SchedulingPolicy::consecutiveDayRuns(3, false));

        $this->assertContains(
            ['Friday', 'Saturday', 'Sunday'],
            SchedulingPolicy::consecutiveDayRuns(3, true),
            'an open Sunday extends the week',
        );
    }

    public function test_the_week_does_not_wrap_and_a_closed_day_breaks_a_run(): void
    {
        $this->assertNotContains(['Sunday', 'Monday'], SchedulingPolicy::consecutiveDayRuns(2, true));
        $this->assertSame([], SchedulingPolicy::consecutiveDayRuns(7, false), 'seven days need Sunday');
        $this->assertSame([SchedulingPolicy::DAYS], SchedulingPolicy::consecutiveDayRuns(7, true));

        // Wednesday left out of the Preferred Days removes every run through it.
        $this->assertSame(
            [['Thursday', 'Friday', 'Saturday']],
            SchedulingPolicy::consecutiveDayRuns(3, false, ['Monday', 'Tuesday', 'Thursday', 'Friday', 'Saturday']),
        );
        $this->assertSame([], SchedulingPolicy::consecutiveDayRuns(1, false), 'one day is not a run');
    }

    public function test_a_day_set_is_consecutive_only_without_gaps_or_repeats(): void
    {
        $this->assertTrue(SchedulingPolicy::isConsecutiveDaySet(['Saturday', 'Thursday', 'Friday']));
        $this->assertTrue(SchedulingPolicy::isConsecutiveDaySet(['Saturday', 'Sunday']));
        $this->assertFalse(SchedulingPolicy::isConsecutiveDaySet(['Saturday', 'Monday']), 'Sunday in between');
        $this->assertFalse(SchedulingPolicy::isConsecutiveDaySet(['Sunday', 'Monday']), 'no wrap into the next week');
        $this->assertFalse(SchedulingPolicy::isConsecutiveDaySet(['Monday', 'Monday']));
    }

    public function test_the_consecutive_marker_is_a_row_pattern_but_never_a_generator_choice(): void
    {
        $this->assertSame(3, SchedulingPolicy::consecutiveDayCount('consecutive:3'));
        $this->assertNull(SchedulingPolicy::consecutiveDayCount('consecutive:1'));
        $this->assertNull(SchedulingPolicy::consecutiveDayCount('consecutive:8'));
        $this->assertNull(SchedulingPolicy::consecutiveDayCount('MW'));

        $this->assertTrue(SchedulingPolicy::isValidRowPattern('consecutive:3'));
        $this->assertTrue(SchedulingPolicy::isValidRowPattern('TTh'));
        $this->assertFalse(SchedulingPolicy::isValidPreferredPattern('consecutive:3'));

        $this->assertNull(MeetingDayRule::preferredPattern('Thursday', 'consecutive:3'), 'any day may start a run');
    }

    public function test_a_sections_own_rule_overrides_the_course_wide_rule(): void
    {
        $rules = [
            ['course_id' => 7, 'section_id' => null, 'day_count' => 2, 'preferred_start_day' => null],
            ['course_id' => 7, 'section_id' => 11, 'day_count' => 3, 'preferred_start_day' => 'Thursday'],
            ['course_id' => 8, 'section_id' => 12, 'day_count' => 4, 'preferred_start_day' => null],
        ];

        $this->assertSame(
            [7 => ['day_count' => 3, 'preferred_start_day' => 'Thursday']],
            SchedulingPolicy::resolveConsecutiveDayRules($rules, 11),
        );
        $this->assertSame(
            [7 => ['day_count' => 2, 'preferred_start_day' => null]],
            SchedulingPolicy::resolveConsecutiveDayRules($rules, 99),
            'another section only gets the course-wide rule',
        );
    }

    public function test_a_complete_run_at_one_time_passes_the_group_rule(): void
    {
        $this->assertSame([], $this->mismatches([
            $this->row('Thursday'), $this->row('Friday'), $this->row('Saturday'),
        ]));
    }

    public function test_a_run_missing_a_meeting_is_refused(): void
    {
        $this->assertSame(['consecutive_day_count'], $this->mismatches([
            $this->row('Thursday'), $this->row('Friday'),
        ]));
    }

    public function test_days_with_a_gap_are_refused(): void
    {
        $this->assertSame(['consecutive_days'], $this->mismatches([
            $this->row('Monday'), $this->row('Wednesday'), $this->row('Thursday'),
        ]));
    }

    public function test_every_meeting_keeps_one_time_and_one_mode(): void
    {
        $this->assertSame(['split_group_same_time'], $this->mismatches([
            $this->row('Thursday'), $this->row('Friday', '09:00:00', '13:00:00'), $this->row('Saturday'),
        ]));
        $this->assertSame(['consecutive_mode'], $this->mismatches([
            $this->row('Thursday'), $this->row('Friday', mode: 'online'), $this->row('Saturday'),
        ]));
    }

    /** @param list<array<string, mixed>> $rows */
    private function mismatches(array $rows): array
    {
        return array_column(MeetingGroupRule::groupMismatches('consecutive', null, $rows, 'consecutive:3'), 'rule');
    }

    private function row(string $day, string $start = '07:00:00', string $end = '11:00:00', string $mode = 'on-site'): array
    {
        return ['day' => $day, 'start_time' => $start, 'end_time' => $end, 'mode' => $mode, 'meeting_type' => null];
    }
}
