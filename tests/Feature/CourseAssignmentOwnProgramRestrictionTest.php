<?php

namespace Tests\Feature;

use App\Http\Controllers\Faculty\CourseTeachingAssignmentController;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Course Assignment hands a department's courses to *someone else* to teach. A Program
 * Head or the department office (Secretary, Director) may therefore never assign one of
 * their department's courses to their own program:
 *
 * - a department with one program cannot be its own target at all — its courses are
 *   managed inside the department;
 * - with two or more programs the user's own program is refused however many there
 *   are, while sibling programs and other colleges stay open.
 *
 * "Own program" is resolved per request: the Program Head's `program_id`, and for the
 * office every program without an active Program Head.
 */
class CourseAssignmentOwnProgramRestrictionTest extends TestCase
{
    use RefreshDatabase;

    // ---- One program -------------------------------------------------------------

    public function test_a_single_program_departments_head_cannot_assign_to_their_own_department(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['soloHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['soloCourse']->id}", ['teaching_department_id' => $f['solo']->id])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department')
            ->assertJsonPath('message', CourseTeachingAssignmentController::OWN_DEPARTMENT_MESSAGE);

        $this->actingAs($f['soloHead'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$f['soloCourse']->id],
                'teaching_department_id' => $f['cas']->id,
                // The program wins over the department, so this still targets SOLO.
                'teaching_program_id' => $f['bsn']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department');

        $this->assertNull($f['soloCourse']->fresh()->teaching_department_id);
    }

    public function test_a_single_program_departments_secretary_cannot_assign_to_their_own_department(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['soloSecretary'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$f['soloCourse']->id],
                'teaching_department_id' => $f['solo']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department');

        $this->assertNull($f['soloCourse']->fresh()->teaching_department_id);
    }

    public function test_a_single_program_department_may_still_assign_to_another_college(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['soloHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['soloCourse']->id}", ['teaching_department_id' => $f['cas']->id])
            ->assertOk();

        $this->assertSame($f['cas']->id, (int) $f['soloCourse']->fresh()->teaching_department_id);
    }

    /** Decision 3 of CourseTeachingAssignmentTest: a college may claim another college's course. */
    public function test_another_colleges_course_may_still_be_claimed_by_a_single_program_department(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['casSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$f['soloCourse']->id}", ['teaching_department_id' => $f['cas']->id])
            ->assertOk();
    }

    // ---- Two or more programs ----------------------------------------------------

    public function test_a_program_head_cannot_assign_to_their_own_program_but_may_assign_to_a_sibling(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsit']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program')
            ->assertJsonPath('message', CourseTeachingAssignmentController::OWN_PROGRAM_MESSAGE);

        $this->actingAs($f['itHead'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$f['itCourse']->id, $f['sharedCourse']->id],
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsit']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program');

        $this->assertNull($f['itCourse']->fresh()->teaching_department_id);

        $this->actingAs($f['itHead'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$f['itCourse']->id, $f['sharedCourse']->id],
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bscs']->id,
            ])
            ->assertOk();

        $this->assertSame($f['bscs']->id, (int) $f['itCourse']->fresh()->teaching_program_id);
    }

    public function test_more_programs_do_not_weaken_the_own_program_restriction(): void
    {
        $f = $this->fixture();
        Program::create(['department_id' => $f['ccs']->id, 'code' => 'BSEMC', 'name' => 'Entertainment and Multimedia']);
        Program::create(['department_id' => $f['ccs']->id, 'code' => 'BLIS', 'name' => 'Library Science']);
        $this->assertSame(5, Program::query()->where('department_id', $f['ccs']->id)->count());

        $this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['itCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsit']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program');
    }

    public function test_own_department_with_any_program_is_refused_because_it_includes_the_own_program(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['itHead'])
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", ['teaching_department_id' => $f['ccs']->id])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department_program_required');

        $this->actingAs($f['ccsSecretary'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$f['sharedCourse']->id],
                'teaching_department_id' => $f['ccs']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department_program_required');
    }

    public function test_the_secretary_cannot_assign_to_a_program_the_office_manages(): void
    {
        $f = $this->fixture();

        // BSIS has no Program Head, so it is the office's own program.
        $this->actingAs($f['ccsSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsis']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program');

        // BSIT has its own Program Head, so it is another eligible program.
        $this->actingAs($f['ccsSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsit']->id,
            ])
            ->assertOk();
    }

    public function test_the_director_follows_the_same_rule_as_the_secretary(): void
    {
        $f = $this->fixture();
        // One department office slot: the Director replaces the Secretary.
        $f['ccsSecretary']->delete();
        $director = $this->grantCapabilities(User::factory()->create(['role' => 'director', 'department_id' => $f['ccs']->id]));

        $this->actingAs($director)
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bsis']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program');
    }

    public function test_the_offices_own_programs_follow_program_head_changes(): void
    {
        $f = $this->fixture();
        $f['csHead']->update(['is_active' => false]);

        // BSCS lost its Program Head, so the office now manages it.
        $this->actingAs($f['ccsSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bscs']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_program');
    }

    public function test_a_program_head_without_a_program_cannot_target_their_own_department(): void
    {
        $f = $this->fixture();
        $orphan = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'department_id' => $f['ccs']->id, 'program_id' => null,
        ]));

        $this->actingAs($orphan)
            ->patchJson("/api/course-teaching-assignments/{$f['sharedCourse']->id}", [
                'teaching_department_id' => $f['ccs']->id,
                'teaching_program_id' => $f['bscs']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'own_department');
    }

    public function test_removing_an_existing_own_department_assignment_still_works(): void
    {
        $f = $this->fixture();
        $f['soloCourse']->update(['teaching_department_id' => $f['solo']->id]);

        $this->actingAs($f['soloHead'])
            ->deleteJson("/api/course-teaching-assignments/{$f['soloCourse']->id}")
            ->assertOk();

        $this->assertNull($f['soloCourse']->fresh()->teaching_department_id);
    }

    // ---- What the frontend is told -------------------------------------------------

    public function test_the_listing_reports_each_users_restrictions(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['itHead'])->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('assignment_restrictions.department_id', $f['ccs']->id)
            ->assertJsonPath('assignment_restrictions.own_program_ids', [$f['bsit']->id])
            ->assertJsonPath('assignment_restrictions.own_department_excluded', false)
            ->assertJsonPath('assignment_restrictions.requires_program', true);

        $this->actingAs($f['ccsSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('assignment_restrictions.own_program_ids', [$f['bsis']->id])
            ->assertJsonPath('assignment_restrictions.own_department_excluded', false);

        $this->actingAs($f['soloHead'])->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('assignment_restrictions.own_program_ids', [$f['bsn']->id])
            ->assertJsonPath('assignment_restrictions.own_department_excluded', true);

        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => $f['ccs']->id]);
        $this->actingAs($vpaa)->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('assignment_restrictions', null);
    }

    /**
     * CCS: BSIT (head), BSCS (head), BSIS (no head — the office's own program).
     * SOLO: BSN only. CAS: BACAS only, the "other college".
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $ccs = Departments::create(['department_name' => 'College of Computing', 'department_code' => 'CCS']);
        $solo = Departments::create(['department_name' => 'College of Nursing', 'department_code' => 'CON']);
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        Program::create(['department_id' => $cas->id, 'code' => 'BACAS', 'name' => 'Arts and Sciences']);
        $bsit = Program::create(['department_id' => $ccs->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $bscs = Program::create(['department_id' => $ccs->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $bsis = Program::create(['department_id' => $ccs->id, 'code' => 'BSIS', 'name' => 'Information Systems']);
        $bsn = Program::create(['department_id' => $solo->id, 'code' => 'BSN', 'name' => 'Nursing']);

        $course = fn (string $code, Departments $dept) => Course::create([
            'course_code' => $code, 'course_name' => "{$code} Course", 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $dept->id, 'status' => 'active',
        ]);
        $itCourse = $course('IT 110', $ccs);
        $sharedCourse = $course('GEC 101', $ccs);
        $soloCourse = $course('GEC 102', $solo);

        $curriculum = function (Departments $dept, ?Program $program, array $courses): void {
            $curriculum = Curriculum::create([
                'name' => 'Curriculum '.($program?->code ?? $dept->department_code),
                'code' => 'CURR-'.($program?->code ?? $dept->department_code),
                'department_id' => $dept->id, 'program_id' => $program?->id,
                'effective_school_year' => '2026-2027', 'status' => 'active',
            ]);
            foreach ($courses as $c) {
                DB::table('curriculum_course')->insert(['curriculum_id' => $curriculum->id, 'course_id' => $c->id, 'year_level' => '1', 'semester' => 1]);
            }
        };
        $curriculum($ccs, $bsit, [$itCourse]);
        $curriculum($ccs, null, [$sharedCourse]);
        $curriculum($solo, $bsn, [$soloCourse]);

        $user = fn (string $role, Departments $dept, ?Program $program = null) => $this->grantCapabilities(User::factory()->create([
            'role' => $role, 'department_id' => $dept->id, 'program_id' => $program?->id,
        ]));

        return [
            'ccs' => $ccs, 'solo' => $solo, 'cas' => $cas,
            'bsit' => $bsit, 'bscs' => $bscs, 'bsis' => $bsis, 'bsn' => $bsn,
            'itCourse' => $itCourse, 'sharedCourse' => $sharedCourse, 'soloCourse' => $soloCourse,
            'itHead' => $user('program_head', $ccs, $bsit),
            'csHead' => $user('program_head', $ccs, $bscs),
            'ccsSecretary' => $user('secretary', $ccs),
            'soloHead' => $user('program_head', $solo, $bsn),
            'soloSecretary' => $user('secretary', $solo),
            'casSecretary' => $user('secretary', $cas),
        ];
    }
}
