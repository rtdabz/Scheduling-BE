<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Field Course is a per-run choice, never a saved department setting.
 *
 * It used to be saved per department (audit findings #34 and #35 scoped that
 * list). A saved list let one placement mark a course "field" for every
 * section, so classes already in lecture rooms -- PATH-FIT and NSTP in BA 202
 * to 204 -- turned into room-type conflicts with no way to turn it off. Now a
 * course needs a field room only when the course itself says so, one class
 * meets in the field by its own Field delivery mode, and a generation run
 * passes its own field courses for that run alone.
 */
class FieldCourseSettingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SchedulingPolicy::clearFieldCourseCache();
    }

    public function test_the_settings_endpoint_no_longer_saves_field_courses(): void
    {
        [$dept, $user] = $this->department('AAA');
        $this->course('PATHFIT 1', $dept);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => ['PATHFIT 1'],
        ])->assertOk();

        $this->assertDatabaseMissing(DepartmentCourseRules::TABLE, ['department_id' => $dept->id]);
        $this->assertSame([], DepartmentCourseRules::fieldCourseCodes((int) $dept->id));
        $this->assertSame([], $this->actingAs($user)->getJson('/api/initial-data')->json('field_course_codes'));
        $this->assertFalse($this->actingAs($user)->getJson('/api/scheduling-settings')->json('field_course_assignment_enabled'));
    }

    public function test_a_run_field_course_applies_to_that_run_and_department_only(): void
    {
        [$deptA] = $this->department('AAA');
        [$deptB] = $this->department('BBB');
        $shared = Course::create([
            'course_code' => 'NSTP-SHARED',
            'course_name' => 'Shared Field Course',
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => null, 'status' => 'active',
        ]);

        DepartmentCourseRules::withOverride((int) $deptA->id, [
            'field_course_codes' => ['NSTP-SHARED'],
            'scope_course_ids' => [(int) $shared->id],
        ], function () use ($shared, $deptA, $deptB): void {
            $this->assertTrue(SchedulingPolicy::isFieldCourse($shared->fresh(), (int) $deptA->id));
            $this->assertFalse(SchedulingPolicy::isFieldCourse($shared->fresh(), (int) $deptB->id));
            $this->assertTrue(SchedulingPolicy::fieldCourseSettingEnabled((int) $deptA->id));
            $this->assertFalse(SchedulingPolicy::fieldCourseSettingEnabled((int) $deptB->id));
        });

        // Nothing outlives the run.
        $this->assertFalse(SchedulingPolicy::isFieldCourse($shared->fresh(), (int) $deptA->id));
        $this->assertDatabaseMissing(DepartmentCourseRules::TABLE, ['course_id' => $shared->id]);
    }

    public function test_a_course_set_to_need_a_field_room_is_always_a_field_course(): void
    {
        [$dept] = $this->department('AAA');
        $course = $this->course('PE 101', $dept);
        $course->update(['room_type_required' => 'field']);

        $this->assertTrue(SchedulingPolicy::isFieldCourse($course->fresh(), (int) $dept->id));
    }

    public function test_the_derived_flag_cannot_be_written_directly(): void
    {
        [$dept, $user] = $this->department('AAA');
        $this->course('PATHFIT 1', $dept);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'field_course_assignment_enabled' => true,
        ])->assertOk();

        SchedulingPolicy::clearFieldCourseCache();
        $this->assertFalse(
            $this->actingAs($user)->getJson('/api/scheduling-settings')->json('field_course_assignment_enabled'),
            'The flag is derived from the configured codes, so a direct write must not enable it.',
        );
    }

    /** @return array{0: Departments, 1: User} */
    private function department(string $code): array
    {
        $semester = Semester::firstOrCreate(
            ['academic_year' => '2026-2027', 'semester' => '1st'],
            ['is_active' => true, 'is_enabled' => true],
        );
        $dept = Departments::create(['department_name' => "Dept {$code}", 'department_code' => $code]);
        // Schedule capabilities and section scheduling both require the
        // department to own a program.
        $program = Program::create(['department_id' => $dept->id, 'code' => $code.'P', 'name' => "Program {$code}"]);
        Sections::create([
            'section_name' => "{$code}-1A", 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]));

        return [$dept, $user];
    }

    private function course(string $code, Departments $dept): Course
    {
        $curriculum = Curriculum::firstOrCreate(
            ['code' => "CURR-{$dept->department_code}"],
            [
                'name' => "Curriculum {$dept->department_code}",
                'department_id' => $dept->id,
                'effective_school_year' => '2026-2027',
                'status' => 'active',
            ],
        );

        $course = Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'status' => 'active',
        ]);

        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => '1',
            'semester' => '1',
        ]);

        return $course;
    }
}
