<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Recommendations\SessionAlternativePolicy;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use PHPUnit\Framework\TestCase;

class SessionInterpretationTest extends TestCase
{
    public function test_enhancement_eligibility_retains_duration_and_excludes_field_lab_and_odd_halves(): void
    {
        $course = ['course_code' => 'LECT', 'course_category' => 'major', 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'room_type_required' => 'lecture'];
        $meeting = ['meeting_type' => null, 'duration_slots' => 4, 'modes' => ['online']];
        $option = SessionAlternativePolicy::enhancement($course, [$meeting], [], []);
        $this->assertSame('Online (All)', $option['label']);
        $this->assertSame([2, 2], array_column($option['meetings'], 'duration_slots'));
        foreach ([1, 3, 5, 7, 8] as $length) {
            $this->assertNull(SessionAlternativePolicy::enhancement($course, [[...$meeting, 'duration_slots' => $length]], [], []));
        }
        $this->assertNull(SessionAlternativePolicy::enhancement([...$course, 'lab_hours' => 1], [$meeting], [], []));
        $this->assertNull(SessionAlternativePolicy::enhancement($course, [$meeting], [], ['LECT']));
        $this->assertNull(SessionAlternativePolicy::enhancement($course, [[...$meeting, 'modes' => ['field']]], [], []));
        $integrated = [['meeting_type' => 'lecture', 'duration_slots' => 4, 'modes' => ['on-site']], ['meeting_type' => 'laboratory', 'duration_slots' => 6, 'modes' => ['on-site']]];
        $labCourse = [...$course, 'lecture_hours' => 2, 'lab_hours' => 1];
        $this->assertSame([4, 6], array_column(SessionAlternativePolicy::enhancement($labCourse, $integrated, [], [])['meetings'], 'duration_slots'));
        $this->assertNull(SessionAlternativePolicy::enhancement([...$labCourse, 'course_category' => 'minor'], $integrated, [], []));
        $integrated[0]['modes'] = ['online'];
        $this->assertNull(SessionAlternativePolicy::enhancement($labCourse, $integrated, [], []));
    }

    private function row(string $day = 'Monday', string $mode = 'on-site', ?string $type = 'lecture', string $start = '08:00', string $end = '09:30'): array
    {
        return ['day' => $day, 'mode' => $mode, 'meeting_type' => $type, 'start_time' => $start, 'end_time' => $end, 'split_group_id' => 'group'];
    }

    public function test_regular_field_and_three_split_deliveries_are_distinct_without_renaming_old_shapes(): void
    {
        $this->assertSame('regular', SessionInterpreter::fromRows([$this->row()])->kind);
        $this->assertSame('field', SessionInterpreter::fromRows([$this->row(mode: 'field')])->kind);
        foreach ([['on-site', 'Split On-site'], ['online', 'Online (All)']] as [$mode, $label]) {
            $description = SessionInterpreter::fromRows([$this->row(mode: $mode), $this->row('Wednesday', $mode)]);
            $this->assertSame('balanced_split', $description->kind);
            $this->assertSame($label, $description->label());
            $this->assertSame('split', $description->legacyShape);
            $this->assertTrue($description->sameTimeRequired);
        }
        $rows = [$this->row(), $this->row('Wednesday', 'online')];
        $rows = array_map(static fn (array $row): array => [...$row, 'is_hybrid' => true], $rows);
        $description = SessionInterpreter::fromRows($rows);
        $this->assertSame('hybrid_split', $description->kind);
        $this->assertSame('Hybrid Split', $description->label());
        $this->assertSame('online_split', $description->legacyShape);
        $this->assertSame([3, 3], array_column($description->meetings, 'duration_slots'));
    }

    public function test_integrated_components_retain_independent_durations_and_times_for_both_deliveries(): void
    {
        $course = ['units' => 4, 'lecture_hours' => 3, 'lab_hours' => 1];
        foreach (['on-site', 'online'] as $lectureMode) {
            $rows = [$this->row(mode: $lectureMode, end: '09:00'), $this->row('Thursday', 'on-site', 'laboratory', '13:00', '16:00')];
            $description = SessionInterpreter::fromRows($rows);
            $this->assertSame('integrated', $description->kind);
            $this->assertSame($lectureMode === 'online' ? 'Integrated Hybrid' : 'Integrated On-site', $description->label());
            $this->assertFalse($description->sameTimeRequired);
            $this->assertNull($description->legacyShape);
            $this->assertSame([2, 6], array_column($description->meetings, 'duration_slots'));
            $this->assertSame([], MeetingGroupRule::groupMismatches($lectureMode === 'online' ? 'hybrid' : 'linked', $course, $rows, null));
        }
    }

    public function test_two_day_consecutive_runs_are_never_interpreted_as_balanced_split(): void
    {
        $rows = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'consecutive:2'], [$this->row(), $this->row('Thursday')]);
        $description = SessionInterpreter::fromRows($rows);
        $this->assertSame('consecutive', $description->kind);
        $this->assertSame(2, $description->expectedMeetings);
        $this->assertTrue($description->sameTimeRequired);
        $this->assertNull($description->legacyShape);
        $this->assertSame([], MeetingGroupRule::groupMismatches('consecutive', null, $rows, 'consecutive:2'));
    }

    public function test_interpretation_does_not_claim_that_incomplete_or_mismatched_groups_are_valid(): void
    {
        $course = ['units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor'];
        $rows = [$this->row(), $this->row('Wednesday', start: '08:30', end: '10:00')];
        $description = SessionInterpreter::fromRows($rows)->toArray();
        $this->assertTrue($description['same_time_required']);
        $this->assertArrayNotHasKey('verified', $description);
        $this->assertContains('split_group_same_time', array_column(MeetingGroupRule::groupMismatches('minor_split', $course, $rows, 'MW', []), 'rule'));
        $partial = SessionInterpreter::fromRows([[...$this->row(), 'is_hybrid' => true]]);
        $this->assertSame(2, $partial->expectedMeetings);
        $this->assertCount(1, $partial->meetings);
        $this->assertContains('hybrid_component_count', array_column(MeetingGroupRule::groupMismatches('hybrid', $course, [$this->row()], null), 'rule'));
    }

    public function test_configuration_uses_built_component_requirements_and_explicit_integrated_delivery(): void
    {
        $config = ['selected_split_session_course_ids' => [100], 'requirements_by_course_id' => [100 => [
            ['component_type' => 'lecture', 'duration_slots' => 2, 'allowed_delivery_modes' => ['online']],
            ['component_type' => 'laboratory', 'duration_slots' => 8, 'allowed_delivery_modes' => ['on-site']],
        ]]];
        $description = SessionInterpreter::fromConfiguration(100, $config);
        $this->assertSame('Integrated Hybrid', $description->label());
        $this->assertSame([2, 8], array_column($description->meetings, 'duration_slots'));
        $config['delivery_modes_by_course_id'][100] = 'on-site';
        $config['requirements_by_course_id'][100][0]['allowed_delivery_modes'] = ['on-site'];
        $description = SessionInterpreter::fromConfiguration(100, $config);
        $this->assertSame('Integrated On-site', $description->label());
        $this->assertFalse($description->sameTimeRequired);
        $this->assertSame([2, 8], array_column($description->meetings, 'duration_slots'));
    }

    public function test_configuration_keeps_split_hybrid_field_and_section_specific_runs_distinct(): void
    {
        $config = ['balanced_split_course_ids' => [100], 'requirements_by_course_id' => [100 => [
            ['component_type' => 'lecture', 'duration_slots' => 6, 'allowed_delivery_modes' => ['on-site', 'online']],
        ]]];
        $this->assertSame([3, 3], array_column(SessionInterpreter::fromConfiguration(100, $config)->meetings, 'duration_slots'));
        $config['hybrid_split_course_ids'] = [100];
        $description = SessionInterpreter::fromConfiguration(100, $config);
        $this->assertSame('online_split', $description->legacyShape);
        $this->assertSame(SessionAlternativePolicy::hybridSplitMeetings(), $description->meetings);
        $description = SessionInterpreter::fromConfiguration(100, $config, ['day_count' => 3]);
        $this->assertSame('consecutive', $description->kind);
        $this->assertSame([6, 6, 6], array_column($description->meetings, 'duration_slots'));
        $config['requirements_by_course_id'][100][0]['component_type'] = 'field';
        $this->assertSame('field', SessionInterpreter::fromConfiguration(100, $config)->kind);
    }

    public function test_existing_draft_eligibility_and_durations_are_preserved(): void
    {
        $course = ['units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0];
        $this->assertTrue(SessionAlternativePolicy::draftHybridEligible($course, 'split'));
        $this->assertFalse(SessionAlternativePolicy::draftHybridEligible($course, 'online_split'));
        $this->assertSame(3, SessionAlternativePolicy::draftOnlineMeetingSlots($course));
        $this->assertFalse(SessionAlternativePolicy::draftHybridEligible([...$course, 'units' => 2], null));
        $this->assertFalse(SessionAlternativePolicy::draftHybridEligible([...$course, 'lab_hours' => 1], null));
        $this->assertNull(SessionAlternativePolicy::draftOnlineMeetingSlots([...$course, 'lab_hours' => 1]));
        $this->assertNull(SessionAlternativePolicy::draftOnlineMeetingSlots([...$course, 'units' => 1.5]));
    }

    public function test_odd_configured_split_duration_is_not_silently_shortened(): void
    {
        $description = SessionInterpreter::fromConfiguration(100, [
            'balanced_split_course_ids' => [100], 'requirements_by_course_id' => [100 => [
                ['component_type' => 'lecture', 'duration_slots' => 5, 'allowed_delivery_modes' => ['on-site']],
            ]],
        ]);
        $this->assertSame(2, $description->expectedMeetings);
        $this->assertCount(1, $description->meetings);
        $this->assertSame([5], array_column($description->meetings, 'duration_slots'));
    }

    public function test_an_explicit_balanced_pattern_retains_its_save_rule_even_on_component_rows(): void
    {
        $rows = [$this->row(end: '09:00'), $this->row('Wednesday', 'on-site', 'laboratory', '13:00', '16:00')];
        $rows = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'MW'], $rows);
        $this->assertTrue(SessionInterpreter::fromRows($rows)->sameTimeRequired);
        $course = ['units' => 4, 'lecture_hours' => 3, 'lab_hours' => 1, 'course_category' => 'major'];
        $this->assertContains('split_group_same_time', array_column(MeetingGroupRule::groupMismatches('minor_split', $course, $rows, 'MW', []), 'rule'));
    }
}
