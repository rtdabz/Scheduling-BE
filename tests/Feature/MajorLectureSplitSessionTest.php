<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Major Lecture Split Sessions reuses the balanced two-day split the minor
 * setting already drives, so what these tests pin down is the eligibility
 * boundary: a lecture-only major splits, a major carrying laboratory units does
 * not, and the generator and the rule engine agree on both.
 */
class MajorLectureSplitSessionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Departments, 1: Sections, 2: Course, 3: User} */
    private function scenario(bool $majorLectureSplitEnabled, int $labHours = 0): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'scheduling_profile' => $labHours > 0 ? 'laboratory_enabled' : 'standard',
            'major_lecture_split_schedule_override_enabled' => $majorLectureSplitEnabled,
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'P'.$department->id,
            'name' => 'Program '.$department->id,
        ]);
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Computer Programming',
            'lecture_hours' => 3,
            'lab_hours' => $labHours,
            'units' => 3 + $labHours,
            'course_category' => 'major',
            'room_type_required' => $labHours > 0 ? 'laboratory' : 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        Rooms::create(['room_code' => 'IT 102', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        Rooms::create(['room_code' => 'LAB 101', 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        $user = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]));

        return [$department, $section, $course, $user];
    }

    private function preview(User $user, Departments $department, Sections $section, Course $course): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'semester_id' => (int) $section->semester_id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => (int) $section->id,
                'course_ids' => [(int) $course->id],
                'selected_gec_course_ids' => [(int) $course->id],
            ]],
        ]);
    }

    public function test_a_lecture_only_major_splits_into_two_balanced_meetings(): void
    {
        [$department, $section, $course, $user] = $this->scenario(majorLectureSplitEnabled: true);

        $response = $this->preview($user, $department, $section, $course);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'))->where('course_id', (int) $course->id)->values();
        $this->assertCount(2, $rows, 'A lecture-only major should split into two meetings.');
        $this->assertCount(2, $rows->pluck('day')->unique(), 'The two meetings must fall on different days.');
        $totalMinutes = $rows->sum(fn (array $row): int => (int) ((strtotime((string) $row['end_time']) - strtotime((string) $row['start_time'])) / 60));
        $this->assertSame(180, $totalMinutes, 'The split must still add up to the course contact hours.');
    }

    public function test_a_lecture_only_major_is_not_split_while_the_setting_is_off(): void
    {
        [$department, $section, $course, $user] = $this->scenario(majorLectureSplitEnabled: false);

        $response = $this->preview($user, $department, $section, $course);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'))->where('course_id', (int) $course->id)->values();
        $this->assertCount(1, $rows, 'The split request must be dropped when the department setting is off.');
    }

    public function test_a_major_carrying_laboratory_units_is_never_split_by_the_lecture_setting(): void
    {
        [$department, $section, $course, $user] = $this->scenario(majorLectureSplitEnabled: true, labHours: 1);

        $response = $this->preview($user, $department, $section, $course);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'))->where('course_id', (int) $course->id)->values();
        $this->assertCount(1, $rows, 'Only pure-lecture majors are eligible; a lab-bearing major belongs to the Lecture + Laboratory override.');
    }

    /**
     * The generator and the rule engine must answer the eligibility question the
     * same way. A split one accepts and the other refuses is not a candidate --
     * it is an unexplained generation failure.
     */
    public function test_the_rule_engine_accepts_the_same_major_lecture_split_the_generator_allows(): void
    {
        [$department, $section, $course, $user] = $this->scenario(majorLectureSplitEnabled: true);
        $room = Rooms::query()->where('room_code', 'IT 101')->firstOrFail();
        $operations = [
            [
                'split_group_id' => 'group-1',
                'section_id' => (int) $section->id,
                'course_id' => (int) $course->id,
                'room_id' => (int) $room->id,
                'semester_id' => (int) $section->semester_id,
                'day' => 'Monday',
                'start_time' => '08:00',
                'end_time' => '09:30',
                'preferred_pattern' => 'MW',
                'meeting_type' => 'lecture',
            ],
            [
                'split_group_id' => 'group-1',
                'section_id' => (int) $section->id,
                'course_id' => (int) $course->id,
                'room_id' => (int) $room->id,
                'semester_id' => (int) $section->semester_id,
                'day' => 'Wednesday',
                'start_time' => '08:00',
                'end_time' => '09:30',
                'preferred_pattern' => 'MW',
                'meeting_type' => 'lecture',
            ],
        ];

        $violations = app(RuleEngine::class)->validateConfiguredMeetingGroups($operations);
        $this->assertSame([], collect($violations)->pluck('rule')->all());

        $department->forceFill(['major_lecture_split_schedule_override_enabled' => false])->save();
        $violations = app(RuleEngine::class)->validateConfiguredMeetingGroups($operations);
        $this->assertContains('minor_split_eligibility', collect($violations)->pluck('rule')->all());
    }
}
