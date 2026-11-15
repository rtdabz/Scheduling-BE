<?php

namespace Tests\Feature;

use App\Http\Controllers\Faculty\CourseTeachingAssignmentController;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Program Heads submit only their own program's sections, so Course Assignment opens
 * for a Program Head as soon as the VPAA approves *their program's* schedule. A sibling
 * program that is still in Draft or review no longer keeps it closed.
 *
 * Opening per program needs a matching write boundary: a Program Head manages only
 * their own program's courses (and courses shared across the department), never a
 * sibling program's — otherwise an approved program could bypass a sibling's gate.
 */
class ProgramHeadCourseAssignmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_approved_program_opens_course_assignment_while_a_sibling_is_still_pending(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'faculty_assignment');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'draft');

        $this->actingAs($f['itHead'])
            ->getJson("/api/departments/{$f['dept']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', true);

        $this->actingAs($f['itHead'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', true);

        $this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", ['teaching_department_id' => $f['cas']->id])
            ->assertOk();

        $this->assertSame($f['cas']->id, (int) $f['itCourse']->fresh()->teaching_department_id);
    }

    public function test_the_pending_program_head_stays_locked(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'approved');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'approved_by_dean');

        $this->actingAs($f['csHead'])
            ->getJson("/api/departments/{$f['dept']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', false);

        $this->assertLocked($this->actingAs($f['csHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['csCourse']->id}", ['teaching_department_id' => $f['cas']->id]));

        $this->assertNull($f['csCourse']->fresh()->teaching_department_id);
    }

    public function test_one_pending_section_of_the_own_program_keeps_it_locked(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'approved');
        $this->section($f, $f['bsit'], 'BSIT 1B', 'submitted');

        $this->assertLocked($this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", ['teaching_department_id' => $f['cas']->id]));
    }

    public function test_the_program_gate_closes_again_when_its_schedule_is_returned(): void
    {
        $f = $this->fixture();
        $own = $this->section($f, $f['bsit'], 'BSIT 1A', 'faculty_assignment');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'draft');
        $statusUrl = "/api/departments/{$f['dept']->id}/schedule-status";

        $this->actingAs($f['itHead'])->getJson($statusUrl)->assertJsonPath('cross_department_unlocked', true);

        $own->update(['status' => 'revision']);

        $this->actingAs($f['itHead'])->getJson($statusUrl)->assertJsonPath('cross_department_unlocked', false);
        $this->assertLocked($this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", ['teaching_department_id' => $f['cas']->id]));
    }

    public function test_a_program_with_no_sections_follows_the_department_rule(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'submitted');

        // BSCS has no sections this semester: nothing of its own to approve, so the
        // department-wide rule applies, as before this change.
        $this->actingAs($f['csHead'])
            ->getJson("/api/departments/{$f['dept']->id}/schedule-status")
            ->assertJsonPath('cross_department_unlocked', false);
    }

    public function test_the_department_office_keeps_the_department_wide_rule(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'approved');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'draft');

        $this->actingAs($f['secretary'])
            ->getJson("/api/departments/{$f['dept']->id}/schedule-status")
            ->assertJsonPath('cross_department_unlocked', false);

        $this->assertLocked($this->actingAs($f['secretary'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", ['teaching_department_id' => $f['cas']->id]));

        $this->assertFalse(app(CrossDepartmentApprovalGate::class)->isOpen((int) $f['dept']->id));
    }

    public function test_a_program_head_cannot_manage_a_sibling_programs_course(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'approved');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'approved');
        $f['csCourse']->update(['teaching_department_id' => $f['cas']->id]);
        $head = $f['itHead'];
        $id = $f['csCourse']->id;

        $this->assertOutsideProgram($this->actingAs($head)
            ->patchJson("/api/course-teaching-assignments/{$id}", ['teaching_department_id' => null]));
        $this->assertOutsideProgram($this->actingAs($head)
            ->postJson('/api/course-teaching-assignments/batch', ['course_ids' => [$id], 'teaching_department_id' => $f['dept']->id, 'teaching_program_id' => $f['bsit']->id]));
        $this->assertOutsideProgram($this->actingAs($head)
            ->deleteJson("/api/course-teaching-assignments/{$id}"));
        $this->assertOutsideProgram($this->actingAs($head)
            ->postJson('/api/course-teaching-assignments/batch-remove', ['course_ids' => [$id]]));

        $this->assertSame($f['cas']->id, (int) $f['csCourse']->fresh()->teaching_department_id);
    }

    public function test_a_program_head_still_manages_a_course_shared_across_the_department(): void
    {
        $f = $this->fixture();
        $this->section($f, $f['bsit'], 'BSIT 1A', 'approved');
        $this->section($f, $f['bscs'], 'BSCS 1A', 'draft');

        $this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", ['teaching_department_id' => $f['cas']->id])
            ->assertOk();
    }

    private function assertLocked(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('error_code', CrossDepartmentApprovalGate::ERROR_CODE);
    }

    private function assertOutsideProgram(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertJsonPath('message', CourseTeachingAssignmentController::OUTSIDE_PROGRAM_MESSAGE);
    }

    /** @param  array<string, mixed>  $f */
    private function section(array $f, Program $program, string $name, string $status): Schedule
    {
        $section = Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $f['dept']->id,
            'program_id' => $program->id,
            'semester_id' => $f['semester']->id,
            'status' => 'active',
        ]);
        static $slot = 7;
        $slot++;

        return Schedule::create([
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $program->is($f['bsit']) ? $f['itCourse']->id : $f['csCourse']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['dept']->id,
            'program_id' => $program->id,
            'faculty_id' => null,
            'day' => 'Monday',
            'start_time' => sprintf('%02d:00', $slot % 20),
            'end_time' => sprintf('%02d:00', ($slot % 20) + 1),
            'mode' => 'on-site',
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $dept = Departments::create(['department_name' => 'College of Computing', 'department_code' => 'CCS']);
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        Program::create(['department_id' => $cas->id, 'code' => 'BACAS', 'name' => 'Arts and Sciences']);
        $bsit = Program::create(['department_id' => $dept->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $bscs = Program::create(['department_id' => $dept->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);

        $course = fn (string $code) => Course::create([
            'course_code' => $code,
            'course_name' => "{$code} Course",
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);
        $itCourse = $course('IT 110');
        $csCourse = $course('CS 110');
        $sharedCourse = $course('GEC 101');

        $curriculum = function (?Program $program, array $courses) use ($dept): void {
            $curriculum = Curriculum::create([
                'name' => 'Curriculum '.($program?->code ?? 'shared'),
                'code' => 'CURR-'.($program?->code ?? 'SHARED'),
                'department_id' => $dept->id,
                'program_id' => $program?->id,
                'effective_school_year' => '2026-2027',
                'status' => 'active',
            ]);
            foreach ($courses as $c) {
                DB::table('curriculum_course')->insert([
                    'curriculum_id' => $curriculum->id, 'course_id' => $c->id, 'year_level' => '1', 'semester' => 1,
                ]);
            }
        };
        $curriculum($bsit, [$itCourse]);
        $curriculum($bscs, [$csCourse]);
        $curriculum(null, [$sharedCourse]);

        $head = fn (Program $program) => $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'department_id' => $dept->id, 'program_id' => $program->id,
        ]));

        return [
            'dept' => $dept,
            'cas' => $cas,
            'bsit' => $bsit,
            'bscs' => $bscs,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'CCS 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $dept->id]),
            'itCourse' => $itCourse,
            'csCourse' => $csCourse,
            'sharedCourse' => $sharedCourse,
            'itHead' => $head($bsit),
            'csHead' => $head($bscs),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id])),
        ];
    }
}
