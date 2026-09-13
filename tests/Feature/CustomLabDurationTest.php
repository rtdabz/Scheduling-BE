<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom Lab Duration is an option on top of the lecture/laboratory split: the
 * lecture half keeps following the curriculum's lecture units while the
 * laboratory half meets for the length the department entered.
 *
 * These settings used to be stored, echoed back to the Settings page and
 * audited without a single line of generation code ever reading them, so the
 * assertions below are about the generated rows, not the saved flags.
 */
class CustomLabDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generated_split_uses_the_configured_laboratory_length(): void
    {
        [$section, $course] = $this->scenario([
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_5_hours_enabled' => true,
        ]);

        $rows = $this->generate($section, $course);

        $this->assertSame(120, $this->minutesFor($rows, 'lecture'), 'two lecture units stay at two hours');
        $this->assertSame(300, $this->minutesFor($rows, 'laboratory'), 'the laboratory half uses the configured five hours');
    }

    public function test_an_entered_number_of_hours_reaches_the_generated_rows(): void
    {
        [$section, $course] = $this->scenario([
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_other_enabled' => true,
            'custom_lab_duration_minutes' => 240,
        ]);

        $this->assertSame(240, $this->minutesFor($this->generate($section, $course), 'laboratory'));
    }

    public function test_without_the_override_the_laboratory_half_stays_at_three_hours_per_unit(): void
    {
        [$section, $course] = $this->scenario([]);

        $this->assertSame(180, $this->minutesFor($this->generate($section, $course), 'laboratory'));
    }

    /**
     * The generator and the validator have to agree, or generation produces a
     * preview that cannot be saved.
     */
    public function test_the_rule_engine_accepts_a_split_generated_at_the_configured_length(): void
    {
        [$section, $course] = $this->scenario([
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_6_hours_enabled' => true,
        ]);

        $rows = $this->generate($section, $course);
        $laboratory = $this->rowFor($rows, 'laboratory');
        $this->assertSame(360, $this->minutesFor($rows, 'laboratory'));

        $rules = array_column(app(RuleEngine::class)->validate([
            'term_id' => (int) $section->term_id,
            'section_id' => (int) $section->id,
            'course_id' => (int) $course->id,
            'room_id' => $laboratory['room_id'] ?? null,
            'day' => $laboratory['day'],
            'start_time' => $laboratory['start_time'],
            'end_time' => $laboratory['end_time'],
            'mode' => 'on-site',
            'meeting_type' => 'laboratory',
            'is_hybrid' => true,
        ]), 'rule');

        $this->assertNotContains('hybrid_component_shape', $rules, 'the validator must accept the length the generator produced');
    }

    /**
     * The other half of the same agreement: once a department configures six
     * hours, the old unit-derived three-hour block is no longer a valid
     * laboratory meeting for it.
     */
    public function test_the_rule_engine_rejects_the_unit_derived_length_once_a_custom_one_is_set(): void
    {
        [$section, $course] = $this->scenario([
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_6_hours_enabled' => true,
        ]);

        $rules = array_column(app(RuleEngine::class)->validate([
            'term_id' => (int) $section->term_id,
            'section_id' => (int) $section->id,
            'course_id' => (int) $course->id,
            'room_id' => (int) Rooms::query()->value('id'),
            'day' => 'Monday',
            'start_time' => '08:00:00',
            'end_time' => '11:00:00',
            'mode' => 'on-site',
            'meeting_type' => 'laboratory',
            'is_hybrid' => true,
        ]), 'rule');

        $this->assertContains('hybrid_component_shape', $rules);
    }

    /** @return array<int, mixed> */
    private function scenario(array $settings): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create(array_merge([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
            'lecture_lab_schedule_override_enabled' => true,
        ], $settings));
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Computer Programming',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        Rooms::create([
            'room_code' => 'IT LAB 1',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $section->setRelation('department', $department);

        return [$section, $course];
    }

    /** @return list<array<string, mixed>> */
    private function generate(Sections $section, Course $course): array
    {
        $options = ['selected_split_session_course_ids' => [(int) $course->id]];
        $requirements = app(ScheduleRequirementBuilderResolver::class)
            ->build($section, [(int) $course->id], $options);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            isHybrid: true,
            selectedLectureLabCourseIds: [(int) $course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            seed: 4321,
        );

        $this->assertNotEmpty($solutions, 'the split produced no solution at all');

        return $solutions[0]['schedules'];
    }

    /** @param list<array<string, mixed>> $rows */
    private function rowFor(array $rows, string $meetingType): array
    {
        foreach ($rows as $row) {
            if (($row['meeting_type'] ?? null) === $meetingType) {
                return $row;
            }
        }

        $this->fail("no {$meetingType} meeting was generated");
    }

    /** @param list<array<string, mixed>> $rows */
    private function minutesFor(array $rows, string $meetingType): int
    {
        $row = $this->rowFor($rows, $meetingType);

        return SchedulingPolicy::timeToMinutes((string) $row['end_time'])
            - SchedulingPolicy::timeToMinutes((string) $row['start_time']);
    }
}
