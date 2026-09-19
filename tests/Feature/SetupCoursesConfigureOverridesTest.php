<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Engine\Rules\RuleLookupCache;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The Setup Courses "Configure" panel's Custom Time Duration and Preferred
 * Room. Each case asserts on generated rows, and every generated length is
 * run back through the validator: a duration the Generator honours but the
 * save refuses is worse than one it ignores.
 */
class SetupCoursesConfigureOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_custom_single_block_duration_reaches_the_generated_row_and_saves(): void
    {
        [$section, $course] = $this->scenario();

        $rows = $this->generate($section, $course, [
            CourseSetupOverrides::DURATIONS_KEY => [(int) $course->id => 4],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(120, $this->minutes($rows[0]), 'a 3-unit course set to 2 hours meets for 2 hours');
        $this->assertNotContains('class_duration', $this->violations($section, $course, $rows[0]));
    }

    public function test_without_a_custom_duration_the_course_keeps_its_units(): void
    {
        [$section, $course] = $this->scenario();

        $rows = $this->generate($section, $course, []);

        $this->assertSame(180, $this->minutes($rows[0]));
    }

    public function test_a_custom_split_session_duration_is_halved_across_both_meetings(): void
    {
        [$section, $course] = $this->scenario();

        $rows = $this->generate($section, $course, [
            'balanced_split_course_ids' => [(int) $course->id],
            CourseSetupOverrides::DURATIONS_KEY => [(int) $course->id => 4],
        ], balancedSplit: true);

        $this->assertCount(2, $rows, 'the Split Session keeps its two meetings');
        $this->assertSame([60, 60], array_map(fn (array $row): int => $this->minutes($row), $rows));
    }

    public function test_a_shortened_split_session_passes_the_meeting_group_rule_and_a_longer_one_does_not(): void
    {
        [$section, $course] = $this->scenario();
        $rule = new MeetingGroupRule(new RuleLookupCache);
        $group = fn (string $end): array => array_map(static fn (string $day): array => [
            'split_group_id' => 'grp-1',
            'preferred_pattern' => 'MW',
            'course_id' => (int) $course->id,
            'section_id' => (int) $section->id,
            'day' => $day,
            'start_time' => '08:00:00',
            'end_time' => $end,
        ], ['Monday', 'Wednesday']);

        $this->assertNotContains('minor_split_duration', array_column($rule->check($group('09:00:00')), 'rule'), '2 h of a 3 h course');
        $this->assertNotContains('minor_split_duration', array_column($rule->check($group('09:30:00')), 'rule'), 'exactly the course hours');
        $this->assertContains('minor_split_duration', array_column($rule->check($group('10:00:00')), 'rule'), '4 h of a 3 h course');
    }

    public function test_the_preferred_room_is_chosen_when_it_is_free(): void
    {
        [$section, $course] = $this->scenario();

        // Each room in turn, so the choice is shown to follow the preference
        // rather than whatever the ranking would have picked anyway.
        foreach (['LEC 1', 'LEC 2'] as $code) {
            $preferred = Rooms::query()->where('room_code', $code)->firstOrFail();
            foreach ([11, 222, 3333] as $seed) {
                $rows = $this->generate($section, $course, [
                    CourseSetupOverrides::PREFERRED_ROOMS_KEY => [(int) $course->id => (int) $preferred->id],
                ], seed: $seed);

                $this->assertSame((int) $preferred->id, (int) $rows[0]['room_id'], "seed {$seed} ignored {$code}");
            }
        }
    }

    public function test_a_duration_longer_than_the_course_is_refused_before_generation(): void
    {
        [$section, $course] = $this->scenario();

        $this->expectException(ValidationException::class);

        CourseSetupOverrides::normalizeDurations($section, [(int) $course->id => 300], [(int) $course->id], []);
    }

    public function test_a_split_session_may_not_exceed_the_course_units(): void
    {
        [$section, $course] = $this->scenario();

        $this->expectException(ValidationException::class);

        CourseSetupOverrides::normalizeDurations(
            $section,
            [(int) $course->id => 240],
            [(int) $course->id],
            ['balanced_split_course_ids' => [(int) $course->id]],
        );
    }

    public function test_hybrid_shapes_keep_their_fixed_lengths(): void
    {
        [$section, $course] = $this->scenario();

        $this->assertSame([], CourseSetupOverrides::normalizeDurations(
            $section,
            [(int) $course->id => 120],
            [(int) $course->id],
            ['hybrid_split_course_ids' => [(int) $course->id]],
        ));
        $this->assertSame([(int) $course->id => 4], CourseSetupOverrides::normalizeDurations(
            $section,
            [(int) $course->id => 120],
            [(int) $course->id],
            [],
        ));
    }

    public function test_preferred_room_options_follow_room_access(): void
    {
        [$section] = $this->scenario();
        $department = $section->department;
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology']);
        $section->update(['program_id' => $program->id]);
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        Rooms::create(['room_code' => 'OTHER 1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $other->id]);
        Rooms::create(['room_code' => 'SHARED 1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => null]);
        Rooms::create(['room_code' => 'CLOSED 1', 'room_type' => 'lecture', 'status' => 'not available', 'department_id' => $department->id]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $codes = array_column(
            $this->actingAs($user)->getJson('/api/scheduling-settings?section_id='.$section->id)->assertOk()->json('preferred_room_options'),
            'room_code',
        );

        $this->assertSame(['LEC 1', 'LEC 2', 'SHARED 1'], $codes);
    }

    /** @return array{0: Sections, 1: Course} */
    private function scenario(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'GEC 1',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        foreach (['LEC 1', 'LEC 2'] as $code) {
            Rooms::create([
                'room_code' => $code,
                'building' => 'Building 1',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]);
        }

        $section->setRelation('department', $department);

        return [$section, $course];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function generate(Sections $section, Course $course, array $options, bool $balancedSplit = false, int $seed = 4321): array
    {
        $requirements = app(ScheduleRequirementBuilderResolver::class)
            ->build($section, [(int) $course->id], $options);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            balancedSplitCourseIds: $balancedSplit ? [(int) $course->id] : [],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            seed: $seed,
        );

        $this->assertNotEmpty($solutions, 'the generator produced no solution');

        return $solutions[0]['schedules'];
    }

    /** @param array<string, mixed> $row */
    private function minutes(array $row): int
    {
        return SchedulingPolicy::timeToMinutes((string) $row['end_time'])
            - SchedulingPolicy::timeToMinutes((string) $row['start_time']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function violations(Sections $section, Course $course, array $row): array
    {
        return array_column(app(RuleEngine::class)->validate([
            'semester_id' => (int) $section->semester_id,
            'section_id' => (int) $section->id,
            'course_id' => (int) $course->id,
            'room_id' => $row['room_id'] ?? null,
            'day' => $row['day'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'mode' => $row['mode'] ?? 'on-site',
        ]), 'rule');
    }
}
