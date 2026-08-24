<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A course is owned by one college and may be *taught* by another: Information
 * Technology owns GEC 101, the College of Arts and Sciences teaches it. That
 * decision is `courses.teaching_department_id`, and this covers who may record it
 * and which courses accept it.
 *
 * Any secretary or program head may decide it for any delegable course — the
 * colleges settle between themselves who teaches what, and the system does not
 * pick a side. What it does refuse is delegating a **major**, which belongs to the
 * department and program that offers it.
 *
 * The listing is a separate question from the decision, and its source is the acting
 * department's own **curriculum**: like the Auto-Assign Instructor workspace it
 * answers "what does my curriculum offer", by year level. Ownership does not put a
 * course on the list and neither does teaching it for someone else — a course
 * delegated *in* is reported separately as incoming.
 */
class CourseTeachingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const MAJOR_REFUSAL = 'A major course is taught by the department that offers it and cannot be assigned to another college.';

    private int $curriculaCreated = 0;

    public function test_a_secretary_assigns_a_service_course_to_another_college(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk()
            ->assertJsonPath('course.teaching_department_id', $fixture['cas']->id)
            ->assertJsonPath('course.teaching_department_code', 'CAS')
            ->assertJsonPath('course.effective_teaching_department_id', $fixture['cas']->id)
            // The owner is unchanged: IT still offers the course, CAS just teaches it.
            ->assertJsonPath('course.department_id', $fixture['it']->id);

        $this->assertDatabaseHas('courses', [
            'id' => $fixture['gec']->id,
            'department_id' => $fixture['it']->id,
            'teaching_department_id' => $fixture['cas']->id,
        ]);
    }

    public function test_a_program_head_may_manage_the_same_assignment(): void
    {
        $fixture = $this->fixture();
        $programHead = User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['it']->id,
        ]);

        $this->actingAs($programHead)
            ->getJson('/api/course-teaching-assignments')
            ->assertOk();

        $this->actingAs($programHead)
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk();

        $this->assertSame($fixture['cas']->id, $fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_the_listing_includes_programs_from_receiving_departments(): void
    {
        $fixture = $this->fixture();
        $casProgram = Program::create([
            'department_id' => $fixture['cas']->id,
            'code' => 'BSED',
            'name' => 'Secondary Education',
        ]);

        $response = $this->actingAs($fixture['itSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk();

        $programs = collect($response->json('programs'));

        $this->assertTrue($programs->contains(fn (array $program): bool => $program['id'] === $casProgram->id));
        $this->assertFalse($programs->contains(fn (array $program): bool => $program['id'] === $fixture['program']->id));
    }

    public function test_no_other_role_may_manage_teaching_assignments(): void
    {
        $fixture = $this->fixture();

        foreach (['vpaa', 'dean'] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $fixture['it']->id]);

            $this->actingAs($user)->getJson('/api/course-teaching-assignments')->assertStatus(403);
            $this->actingAs($user)
                ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                    'teaching_department_id' => $fixture['cas']->id,
                ])
                ->assertStatus(403);
        }

        $this->assertNull($fixture['gec']->refresh()->teaching_department_id);
    }

    /**
     * The point of decision 3: the override is not scoped to the acting user's own
     * department, so CAS can record that it teaches IT's GEC 101 without waiting for
     * the IT secretary to do it.
     */
    public function test_any_secretary_may_decide_who_teaches_a_course_another_college_owns(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk();

        $this->assertSame($fixture['cas']->id, $fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_a_major_cannot_be_handed_to_another_college(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['major']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MAJOR_REFUSAL);

        $this->assertNull($fixture['major']->refresh()->teaching_department_id);
    }

    public function test_a_minor_that_no_college_teaches_by_default_can_be_delegated(): void
    {
        $fixture = $this->fixture();

        // PATH FIT is not a GEC subject, so nothing derives a teaching college for
        // it and it is open to every department until a secretary decides otherwise.
        $this->assertNull(SchedulingPolicy::assignedTeachingDepartmentId($fixture['minor']));

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['minor']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk()
            ->assertJsonPath('course.effective_teaching_department_id', $fixture['cas']->id);
    }

    public function test_removing_the_assignment_hands_the_course_back_to_its_owner(): void
    {
        $fixture = $this->fixture();
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);

        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertOk()
            ->assertJsonPath('course.teaching_department_id', null)
            ->assertJsonPath('course.teaching_department_code', null)
            // No override means the derived rule again: a GEC subject is taught by
            // the college that offers it.
            ->assertJsonPath('course.effective_teaching_department_id', $fixture['it']->id);

        $this->assertNull($fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_a_null_teaching_department_clears_the_assignment(): void
    {
        $fixture = $this->fixture();
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);

        // The management page has one Save button for both, so the update route has
        // to accept the cleared select as well.
        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('course.teaching_department_id', null);

        $this->assertNull($fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_an_unknown_college_is_refused(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => 99999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('teaching_department_id');
    }

    public function test_the_listing_names_the_colleges_and_flags_what_may_be_delegated(): void
    {
        $fixture = $this->fixture();
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);

        $response = $this->actingAs($fixture['itSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk();

        $courses = collect($response->json('courses'));

        $gec = $courses->firstWhere('id', $fixture['gec']->id);
        $this->assertTrue($gec['delegable']);
        $this->assertSame($fixture['cas']->id, $gec['teaching_department_id']);
        $this->assertSame('CAS', $gec['teaching_department_code']);

        // Majors are listed rather than hidden, so the page can show why one cannot
        // be delegated instead of leaving the user hunting for a missing course.
        $major = $courses->firstWhere('id', $fixture['major']->id);
        $this->assertFalse($major['delegable']);
        $this->assertSame('BSIT', $major['program_code']);

        $this->assertEqualsCanonicalizing(
            ['CIT', 'CAS'],
            collect($response->json('departments'))->pluck('department_code')->all(),
        );

        // Every college is offered as a target — the decision is not scoped — but the
        // courses on offer are the acting department's own.
        $this->assertSame($fixture['it']->id, $response->json('current_department_id'));
    }

    /**
     * The listing is the acting department's, not the institution's. An IT secretary
     * managing IT's minors should not have to scroll past another college's courses
     * to find them.
     */
    public function test_the_listing_leaves_out_another_colleges_courses(): void
    {
        $fixture = $this->fixture();
        $this->course('LIT 101', 'minor', $fixture['cas']->id);

        $codes = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->pluck('course_code')->all();

        $this->assertContains('GEC 101', $codes);
        $this->assertNotContains('LIT 101', $codes);
    }

    /**
     * With a published curriculum the listing narrows to what that curriculum
     * places, and the year level shown is the curriculum's — not the default stored
     * on the course. The same shared minor sits in different years for different
     * colleges, and the page's year tabs have to agree with the curriculum the
     * department actually runs.
     */
    public function test_an_active_curriculum_scopes_the_listing_and_sets_each_year_level(): void
    {
        $fixture = $this->fixture();
        $shared = $this->course('GEC 102', 'minor', null);
        $this->course('GEC 103', 'minor', null);

        $curriculum = $this->curriculum($fixture['it']->id, 'active');
        $this->place($curriculum, $fixture['gec'], '3');
        $this->place($curriculum, $shared, '2');

        $courses = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->keyBy('course_code');

        // Both courses record year_level '1'; the curriculum placement overrides it.
        $this->assertSame(3, $courses['GEC 101']['year_level']);
        $this->assertSame(2, $courses['GEC 102']['year_level']);

        // Not placed by the curriculum, so not something this department offers —
        // including IT's own PATH FIT 1 and its major.
        $this->assertEqualsCanonicalizing(['GEC 101', 'GEC 102'], $courses->keys()->all());
    }

    /**
     * An archived curriculum is not what the department is running, so it neither
     * scopes the listing nor places a year level — the active one still decides both,
     * and the archived placement at 4th year is ignored.
     */
    public function test_an_archived_curriculum_does_not_scope_the_listing(): void
    {
        $fixture = $this->fixture();
        $this->place($this->curriculum($fixture['it']->id, 'archived'), $fixture['gec'], '4');

        $courses = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->keyBy('course_code');

        $this->assertEqualsCanonicalizing(['GEC 101', 'PATH FIT 1', 'IT 101'], $courses->keys()->all());
        $this->assertSame(1, $courses['GEC 101']['year_level']);
    }

    /**
     * CAS teaches IT's GEC 101, and no CAS curriculum places it. That does not put it
     * in the CAS course list — the list is CAS's curriculum, and the course is IT's.
     * It is reported as an incoming cross-department course instead, which is where
     * the college answerable for teaching it goes looking.
     */
    public function test_a_course_delegated_to_the_department_is_reported_as_incoming_not_offered(): void
    {
        $fixture = $this->fixture();
        $this->curriculum($fixture['cas']->id, 'active');
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);

        $response = $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk();

        $this->assertSame([], $response->json('courses'));
        $this->assertSame(
            ['GEC 101'],
            collect($response->json('incoming_cross_department_courses'))->pluck('course_code')->all(),
        );
        $this->assertSame('CIT', $response->json('incoming_cross_department_courses.0.source_department_code'));
    }

    /**
     * A course the curriculum does not carry is not on offer, even when the department
     * owns it. Ownership is not the question the page asks.
     */
    public function test_a_course_outside_the_curriculum_is_not_listed(): void
    {
        $fixture = $this->fixture();
        $this->course('IT 999', 'minor', $fixture['it']->id);

        $codes = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->pluck('course_code')->all();

        $this->assertContains('GEC 101', $codes);
        $this->assertNotContains('IT 999', $codes);
    }

    /**
     * Without a published curriculum there is nothing to offer. The old fallback to
     * ownership was worse than an empty list: a college that owns no minors of its own
     * got handed every shared GEC and GEE subject in the institution.
     */
    public function test_a_department_with_no_active_curriculum_is_offered_nothing(): void
    {
        $fixture = $this->fixture();
        $this->course('GEC 102', 'minor', null);

        $response = $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk();

        $this->assertSame([], $response->json('courses'));
        $this->assertFalse($response->json('has_active_curriculum'));
    }

    public function test_an_account_without_a_department_cannot_list_courses(): void
    {
        $this->fixture();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => null]);

        $this->actingAs($user)
            ->getJson('/api/course-teaching-assignments')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your account must belong to a department.');
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $it = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
        ]);
        $cas = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
        ]);
        $program = Program::create([
            'department_id' => $it->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        $gec = $this->course('GEC 101', 'minor', $it->id);
        $minor = $this->course('PATH FIT 1', 'minor', $it->id);
        $major = $this->course('IT 101', 'major', $it->id, $program->id);

        // The listing is the curriculum's, so a department with nothing published has
        // nothing to manage. IT publishes one carrying all three, which is what makes
        // them visible at all.
        $curriculum = $this->curriculum($it->id, 'active');
        foreach ([$gec, $minor, $major] as $course) {
            $this->place($curriculum, $course, '1');
        }

        return [
            'it' => $it,
            'cas' => $cas,
            'program' => $program,
            'curriculum' => $curriculum,
            'gec' => $gec,
            'minor' => $minor,
            'major' => $major,
            'itSecretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $it->id]),
            'casSecretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id]),
        ];
    }

    /**
     * A department may be given more than one curriculum in a single test — the
     * fixture publishes one and a test then publishes another to supersede it — so the
     * code, which is unique, is sequenced rather than derived from the department.
     */
    private function curriculum(int $departmentId, string $status): Curriculum
    {
        $sequence = ++$this->curriculaCreated;

        return Curriculum::create([
            'name' => "Curriculum {$sequence} for department {$departmentId} ({$status})",
            'code' => "CURR{$sequence}",
            'department_id' => $departmentId,
            'effective_school_year' => '2026-2027',
            'status' => $status,
        ]);
    }

    private function place(Curriculum $curriculum, Course $course, string $yearLevel): void
    {
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => $yearLevel,
            'semester' => '1',
        ]);
    }

    private function course(string $code, string $category, ?int $departmentId, ?int $programId = null): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => $category,
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $departmentId,
            'program_id' => $programId,
            'status' => 'active',
        ]);
    }
}
