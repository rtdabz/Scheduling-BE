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
 * Guards the fixes for audit findings #34 and #35.
 *
 * The field-course table (now `department_course_rules.is_field`) had no
 * `department_id` and a unique index on `course_code`, so one department's save deleted another's selection for the same
 * code, and a single marker row enabled the feature institution-wide. The
 * "enabled" flag was also write-once: nothing ever set it back to false.
 */
class FieldCourseSettingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SchedulingPolicy::clearFieldCourseCache();
    }

    public function test_one_department_configuring_a_code_does_not_clear_another(): void
    {
        [$deptA, $userA] = $this->department('AAA');
        [$deptB, $userB] = $this->department('BBB');
        $this->course('PATHFIT 1', $deptA);
        $this->course('PATHFIT 1', $deptB);

        $this->actingAs($userA)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => ['PATHFIT 1'],
        ])->assertOk();

        $this->actingAs($userB)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => ['PATHFIT 1'],
        ])->assertOk();

        // Both departments keep their own setting.
        $this->assertSame(['PATHFIT 1'], DepartmentCourseRules::fieldCourseCodes((int) $deptA->id));
        $this->assertSame(['PATHFIT 1'], DepartmentCourseRules::fieldCourseCodes((int) $deptB->id));

        // And department B clearing its list leaves department A's intact.
        $this->actingAs($userB)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => [],
        ])->assertOk();

        $this->assertSame(['PATHFIT 1'], DepartmentCourseRules::fieldCourseCodes((int) $deptA->id));
        $this->assertSame([], DepartmentCourseRules::fieldCourseCodes((int) $deptB->id));
        // The emptied row is removed, not left behind.
        $this->assertDatabaseMissing(DepartmentCourseRules::TABLE, ['department_id' => $deptB->id]);
    }

    public function test_configured_codes_do_not_leak_across_departments(): void
    {
        [$deptA, $userA] = $this->department('AAA');
        [$deptB, $userB] = $this->department('BBB');
        $courseA = $this->course('PATHFIT 1', $deptA);
        $courseB = $this->course('PATHFIT 1', $deptB);

        $this->actingAs($userA)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => ['PATHFIT 1'],
        ])->assertOk();

        SchedulingPolicy::clearFieldCourseCache();
        $this->assertTrue(SchedulingPolicy::isFieldCourse($courseA->fresh()));
        $this->assertFalse(
            SchedulingPolicy::isFieldCourse($courseB->fresh()),
            "Department B's course must not become a field course because department A configured the same code.",
        );

        // The scheduler payload each department receives is scoped too.
        $this->assertSame(['PATHFIT 1'], $this->actingAs($userA)->getJson('/api/initial-data')->json('field_course_codes'));
        $this->assertSame([], $this->actingAs($userB)->getJson('/api/initial-data')->json('field_course_codes'));
    }

    public function test_enabled_is_derived_so_clearing_the_list_turns_it_off(): void
    {
        [$dept, $user] = $this->department('AAA');
        $this->course('PATHFIT 1', $dept);

        $this->assertFalse($this->actingAs($user)->getJson('/api/scheduling-settings')->json('field_course_assignment_enabled'));

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => ['PATHFIT 1'],
        ])->assertOk();
        SchedulingPolicy::clearFieldCourseCache();
        $this->assertTrue($this->actingAs($user)->getJson('/api/scheduling-settings')->json('field_course_assignment_enabled'));

        // Previously this stayed true forever: nothing ever wrote false.
        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => [],
        ])->assertOk();
        SchedulingPolicy::clearFieldCourseCache();
        $this->assertFalse($this->actingAs($user)->getJson('/api/scheduling-settings')->json('field_course_assignment_enabled'));
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

    public function test_a_shared_course_is_a_field_course_only_where_a_department_set_it(): void
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

        DepartmentCourseRules::put((int) $deptA->id, (int) $shared->id, null, ['is_field' => true]);

        SchedulingPolicy::clearFieldCourseCache();
        $this->assertTrue(SchedulingPolicy::isFieldCourse($shared->fresh(), (int) $deptA->id));
        $this->assertFalse(SchedulingPolicy::isFieldCourse($shared->fresh(), (int) $deptB->id));
        $this->assertTrue(SchedulingPolicy::fieldCourseSettingEnabled((int) $deptA->id));
        $this->assertFalse(SchedulingPolicy::fieldCourseSettingEnabled((int) $deptB->id));
    }

    public function test_renaming_a_course_code_carries_its_field_setting_over(): void
    {
        [$deptA] = $this->department('AAA');
        [$deptB] = $this->department('BBB');
        $courseA = $this->course('PATHFIT 1', $deptA);
        $courseB = $this->course('PATHFIT 1', $deptB);
        DepartmentCourseRules::put((int) $deptA->id, (int) $courseA->id, null, ['is_field' => true]);
        DepartmentCourseRules::put((int) $deptB->id, (int) $courseB->id, null, ['is_field' => true]);
        SchedulingPolicy::fieldCourseCodeMap((int) $deptA->id); // warm the cache

        $courseA->update(['course_code' => 'PE 101']);

        $this->assertSame(['PE 101'], DepartmentCourseRules::fieldCourseCodes((int) $deptA->id));
        $this->assertSame(['PE 101' => true], SchedulingPolicy::fieldCourseCodeMap((int) $deptA->id));
        // Another department's course under the old code keeps its setting.
        $this->assertSame(['PATHFIT 1'], DepartmentCourseRules::fieldCourseCodes((int) $deptB->id));
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
