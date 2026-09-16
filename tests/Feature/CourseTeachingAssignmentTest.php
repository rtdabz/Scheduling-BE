<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $programHead = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['it']->id,
        ]));

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

    /**
     * A dean holds only schedule.view and schedule.approve_dean, and a director
     * holds nothing, so neither reaches the endpoint.
     *
     * The VPAA is deliberately not in this list. config/capabilities.php gives
     * the role the full permission set as the break-glass account, so it does
     * reach the endpoint -- see the test below.
     */
    public function test_a_role_without_the_capability_may_not_manage_teaching_assignments(): void
    {
        $fixture = $this->fixture();

        foreach (['dean', 'director'] as $role) {
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

    public function test_the_vpaa_holds_the_cross_department_capability_by_role(): void
    {
        $fixture = $this->fixture();
        // The controller answers from the acting account's department, so the
        // capability alone is not enough to produce a listing.
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => $fixture['it']->id]);

        $this->actingAs($vpaa)->getJson('/api/course-teaching-assignments')->assertOk();
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
     * The listing is what the department's active curricula place, and the year
     * level shown is the curriculum's — not the default stored on the course. The
     * same shared minor sits in different years for different colleges, and the
     * page's year tabs have to agree with the curriculum the department runs.
     *
     * A department mid-transition runs several curricula at once, so the listing
     * is their union: a cohort still on the old curriculum is still taking its
     * courses, and those courses still need delegating. Where the two place the
     * same course differently, the newest curriculum's placement is shown — this
     * page's year tabs follow the curriculum the department is moving to.
     * Nothing schedules from this scalar; a cohort's placements are resolved
     * through its own section's curriculum.
     */
    public function test_active_curricula_scope_the_listing_and_set_each_year_level(): void
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

        // Both courses record year_level '1'; the curriculum placement overrides
        // it. GEC 101 sits at year 1 in the fixture's curriculum and year 3 in
        // the newer one, and the newer placement is the one reported.
        $this->assertSame(3, $courses['GEC 101']['year_level']);
        $this->assertSame(2, $courses['GEC 102']['year_level']);

        // GEC 103 is placed by neither curriculum, so the department does not
        // offer it. PATH FIT 1 and IT 101 are placed by the older curriculum,
        // which is still active and still teaching upper years.
        $this->assertEqualsCanonicalizing(
            ['GEC 101', 'GEC 102', 'IT 101', 'PATH FIT 1'],
            $courses->keys()->all(),
        );
    }

    /**
     * Retiring the older curriculum narrows the listing to the survivor, which is
     * the pre-transition behaviour: what a department offers is what its active
     * curricula place, no more.
     */
    public function test_the_listing_narrows_once_the_superseded_curriculum_is_retired(): void
    {
        $fixture = $this->fixture();
        $shared = $this->course('GEC 102', 'minor', null);

        $curriculum = $this->curriculum($fixture['it']->id, 'active');
        $this->place($curriculum, $fixture['gec'], '3');
        $this->place($curriculum, $shared, '2');
        $fixture['curriculum']->update(['status' => 'archived']);

        $codes = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->pluck('course_code')->all();

        $this->assertEqualsCanonicalizing(['GEC 101', 'GEC 102'], $codes);
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
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => null]));

        $this->actingAs($user)
            ->getJson('/api/course-teaching-assignments')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your account must belong to a department.');
    }

    /**
     * Delegation is decided one semester at a time, so the listing is the active
     * semester's, not the whole curriculum's. A course the curriculum places in the
     * second semester is not this semester's work and is not offered while the first
     * semester runs.
     */
    public function test_the_listing_offers_only_the_active_semester_period(): void
    {
        $fixture = $this->fixture();
        $this->activateSemester('1st');

        $secondSemester = $this->course('GEC 201', 'minor', $fixture['it']->id);
        $this->place($fixture['curriculum'], $secondSemester, '1', 2);

        $courses = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->keyBy('course_code');

        $this->assertEqualsCanonicalizing(['GEC 101', 'PATH FIT 1', 'IT 101'], $courses->keys()->all());
        $this->assertArrayNotHasKey('GEC 201', $courses->all());
    }

    /**
     * The page has to name the semester it narrowed to; an empty year level is otherwise
     * indistinguishable from a curriculum missing its courses.
     */
    public function test_the_listing_reports_the_semester_it_is_scoped_to(): void
    {
        $fixture = $this->fixture();
        $this->activateSemester('1st');

        $this->actingAs($fixture['itSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('active_semester.semester', '1st')
            ->assertJsonPath('active_semester.academic_year', '2026-2027');
    }

    /**
     * Switching the active semester switches the list, rather than the second semester's
     * courses being permanently invisible.
     */
    public function test_the_second_semester_is_offered_once_its_semester_is_active(): void
    {
        $fixture = $this->fixture();
        $this->activateSemester('2nd');

        $secondSemester = $this->course('GEC 201', 'minor', $fixture['it']->id);
        $this->place($fixture['curriculum'], $secondSemester, '3', 2);

        $courses = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('courses'),
        )->keyBy('course_code');

        // The fixture's three are all first-semester placements, so only the second
        // semester's course survives — at the year level that placement gives it.
        $this->assertSame(['GEC 201'], $courses->keys()->all());
        $this->assertSame(3, $courses['GEC 201']['year_level']);
    }

    /**
     * With no semester active there is nothing to narrow to, and the whole curriculum is
     * offered rather than nothing at all — a blank page would be the worse failure.
     */
    public function test_no_active_semester_leaves_the_listing_unnarrowed(): void
    {
        $fixture = $this->fixture();
        $secondSemester = $this->course('GEC 201', 'minor', $fixture['it']->id);
        $this->place($fixture['curriculum'], $secondSemester, '1', 2);

        $response = $this->actingAs($fixture['itSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('active_semester', null);

        $this->assertEqualsCanonicalizing(
            ['GEC 101', 'PATH FIT 1', 'IT 101', 'GEC 201'],
            collect($response->json('courses'))->pluck('course_code')->all(),
        );
    }

    /**
     * The incoming list is scoped the same way, against whichever curriculum places
     * the course — its owner's, not the receiving college's.
     */
    public function test_an_incoming_course_from_another_semester_is_not_reported(): void
    {
        $fixture = $this->fixture();
        $this->activateSemester('1st');

        $secondSemester = $this->course('GEC 201', 'minor', $fixture['it']->id);
        $this->place($fixture['curriculum'], $secondSemester, '1', 2);
        $secondSemester->update(['teaching_department_id' => $fixture['cas']->id]);
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);

        $incoming = collect(
            $this->actingAs($fixture['casSecretary'])
                ->getJson('/api/course-teaching-assignments')
                ->assertOk()
                ->json('incoming_cross_department_courses'),
        )->pluck('course_code');

        $this->assertSame(['GEC 101'], $incoming->all());
    }

    public function test_a_course_that_already_has_an_instructor_cannot_be_given_to_another_college(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $this->assignInstructor($fixture, $semester, $fixture['gec']);

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('instructor_assigned_classes', 1)
            ->assertJsonPath('message', 'GEC 101 already has an instructor assigned in 1 class this semester, so another department cannot be assigned to teach it. Remove those instructor assignments first.');

        $this->assertNull($fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_the_batch_refuses_every_course_that_already_has_an_instructor(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $this->assignInstructor($fixture, $semester, $fixture['gec']);

        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$fixture['gec']->id, $fixture['minor']->id],
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('locked_course_ids', [$fixture['gec']->id]);

        // All or nothing: the free course is not delegated behind the refusal.
        $this->assertNull($fixture['gec']->refresh()->teaching_department_id);
        $this->assertNull($fixture['minor']->refresh()->teaching_department_id);
    }

    public function test_a_delegated_course_with_an_instructor_cannot_be_handed_back(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);
        $this->assignInstructor($fixture, $semester, $fixture['gec'], $fixture['cas']);

        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertStatus(422);

        $this->assertSame($fixture['cas']->id, (int) $fixture['gec']->refresh()->teaching_department_id);
    }

    public function test_saving_the_same_college_again_is_not_refused(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $fixture['gec']->update(['teaching_department_id' => $fixture['cas']->id]);
        $this->assignInstructor($fixture, $semester, $fixture['gec'], $fixture['cas']);

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk()
            ->assertJsonPath('course.instructor_assigned_classes', 1);
    }

    public function test_a_course_whose_classes_have_no_instructor_can_still_be_delegated(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $this->assignInstructor($fixture, $semester, $fixture['gec'], null, withInstructor: false);

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/course-teaching-assignments/{$fixture['gec']->id}", [
                'teaching_department_id' => $fixture['cas']->id,
            ])
            ->assertOk();
    }

    public function test_the_listing_reports_how_many_classes_already_have_an_instructor(): void
    {
        $fixture = $this->fixture();
        $semester = $this->activateSemester('1st');
        $this->assignInstructor($fixture, $semester, $fixture['gec']);

        $courses = collect($this->actingAs($fixture['itSecretary'])
            ->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->json('courses'))->keyBy('course_code');

        $this->assertSame(1, $courses['GEC 101']['instructor_assigned_classes']);
        $this->assertSame(0, $courses['PATH FIT 1']['instructor_assigned_classes']);
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
        // CapabilityMiddleware withholds every schedule capability except
        // schedule.view from a department that owns no program, so the CAS
        // secretary needs one before it can act on anything.
        Program::create([
            'department_id' => $cas->id,
            'code' => 'BACAS',
            'name' => 'Arts and Sciences',
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
            'itSecretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $it->id])),
            'casSecretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id])),
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

    /**
     * `curriculum_course.semester` is the tinyint 1|2|3, not the '1st'|'2nd'|'summer'
     * enum that `semesters` and `courses` carry. Placements default to the first
     * semester, which is what the fixture's semester runs.
     */
    private function place(Curriculum $curriculum, Course $course, string $yearLevel, int $period = 1): void
    {
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => $yearLevel,
            'semester' => $period,
        ]);
    }

    /**
     * The listing reads the active semester through a cache the semester endpoints normally
     * clear, so a test that switches semesters has to clear it too.
     */
    private function activateSemester(string $period): Semester
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => $period,
            'is_active' => true,
        ]);
        Cache::flush();

        return $semester;
    }

    /**
     * One class of the course, with an instructor from the given college (the
     * owner by default) unless `withInstructor` is false.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function assignInstructor(array $fixture, Semester $semester, Course $course, ?Departments $teachingCollege = null, bool $withInstructor = true): Schedule
    {
        $college = $teachingCollege ?? $fixture['it'];
        $section = Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['it']->id,
            'program_id' => $fixture['program']->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $room = Rooms::create(['room_code' => 'RM101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $fixture['it']->id]);
        $faculty = $withInstructor ? Faculty::create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'employment_type' => 'full-time',
            'department_id' => $college->id,
            'status' => 'active',
        ]) : null;

        return Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $fixture['it']->id,
            'faculty_id' => $faculty?->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
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
