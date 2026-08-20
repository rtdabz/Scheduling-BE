<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the fixes for audit findings #34 and #35.
 *
 * `field_course_settings` had no `department_id` and a unique index on
 * `course_code`, so one department's save deleted another's selection for the same
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

        // Both departments keep their own row.
        $this->assertDatabaseHas('field_course_settings', ['department_id' => $deptA->id, 'course_code' => 'PATHFIT 1']);
        $this->assertDatabaseHas('field_course_settings', ['department_id' => $deptB->id, 'course_code' => 'PATHFIT 1']);

        // And department B clearing its list leaves department A's intact.
        $this->actingAs($userB)->patchJson('/api/scheduling-settings', [
            'field_course_codes' => [],
        ])->assertOk();

        $this->assertDatabaseHas('field_course_settings', ['department_id' => $deptA->id, 'course_code' => 'PATHFIT 1']);
        $this->assertDatabaseMissing('field_course_settings', ['department_id' => $deptB->id, 'course_code' => 'PATHFIT 1']);
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

    public function test_a_shared_course_with_no_department_still_applies_institution_wide(): void
    {
        [$deptA] = $this->department('AAA');
        $shared = Course::create([
            'course_code' => 'NSTP-SHARED',
            'course_name' => 'Shared Field Course',
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => null, 'status' => 'active',
        ]);

        DB::table('field_course_settings')->insert([
            'department_id' => null,
            'course_code' => 'NSTP-SHARED',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SchedulingPolicy::clearFieldCourseCache();
        $this->assertTrue(SchedulingPolicy::isFieldCourse($shared->fresh()));
        $this->assertTrue(SchedulingPolicy::fieldCourseSettingEnabled((int) $deptA->id));
    }

    /** @return array{0: Departments, 1: User} */
    private function department(string $code): array
    {
        $term = Terms::firstOrCreate(
            ['academic_year' => '2026-2027', 'semester' => '1st'],
            ['is_active' => true, 'is_enabled' => true],
        );
        $dept = Departments::create(['department_name' => "Dept {$code}", 'department_code' => $code]);
        Sections::create([
            'section_name' => "{$code}-1A", 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'term_id' => $term->id, 'status' => 'active',
        ]);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]);

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
