<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cross-department scenario end to end: Information Technology owns GEC 101
 * and offers it to its own section, but the College of Arts and Sciences has been
 * assigned to teach it. From there on it is CAS, not IT, that assigns the
 * instructor, and only an active CAS instructor is accepted.
 *
 * The class meets Monday/Wednesday/Friday, which is three `schedules` rows — so
 * every assertion about "the class" groups the rows rather than counting them.
 */
class CrossDepartmentInstructorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_teaching_college_sees_the_offering_it_must_assign(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        $response = $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/instructor-assignments')
            ->assertOk();

        // IT's rows, in the CAS workspace, because CAS is who teaches them.
        $this->assertEqualsCanonicalizing(
            $blocks->pluck('id')->all(),
            collect($response->json('schedules'))->pluck('id')->all(),
        );

        // The picker offers CAS's own active instructors and nobody else — the IT
        // instructor and the inactive CAS one are both absent.
        $this->assertSame(
            [$fixture['casInstructor']->id],
            collect($response->json('faculties'))->pluck('id')->all(),
        );
    }

    public function test_program_head_assignment_payload_only_contains_their_program_faculty(): void
    {
        $fixture = $this->fixture();
        $bped = \App\Models\Program::create([
            'department_id' => $fixture['cas']->id,
            'code' => 'BPED',
            'name' => 'Physical Education',
        ]);
        $otherProgram = \App\Models\Program::create([
            'department_id' => $fixture['cas']->id,
            'code' => 'BEED',
            'name' => 'Elementary Education',
        ]);
        $head = User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['cas']->id,
            'program_id' => $bped->id,
        ]);
        $fixture['gec']->update(['teaching_program_id' => $bped->id]);
        $fixture['casInstructor']->update(['program_id' => $bped->id]);
        $otherInstructor = Faculty::create([
            'first_name' => 'Other',
            'last_name' => 'Program Instructor',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $fixture['cas']->id,
            'program_id' => $otherProgram->id,
            'status' => 'active',
        ]);
        $blocks = $this->meetingBlocks($fixture);

        $this->actingAs($head)->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'faculties')
            ->assertJsonPath('faculties.0.id', $fixture['casInstructor']->id);

        $this->actingAs($head)
            ->patchJson("/api/instructor-assignments/{$blocks->first()->id}", [
                'faculty_id' => $otherInstructor->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Program Heads can only assign instructors from their assigned program.');
    }

    public function test_the_teaching_college_cannot_create_a_duplicate_for_an_existing_source_schedule(): void
    {
        $fixture = $this->fixture();
        $this->meetingBlocks($fixture);

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/schedules', [
                'term_id' => $fixture['term']->id,
                'section_id' => $fixture['section']->id,
                'course_id' => $fixture['gec']->id,
                'department_id' => $fixture['cas']->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'room_id' => $fixture['casRoom']->id,
                'mode' => 'on-site',
                'status' => 'faculty_assignment',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'GEC 101 already has a schedule owned by the source department. Assign the instructor to the existing schedule; do not create another schedule.');

        $this->assertSame(3, Schedule::query()->where('course_id', $fixture['gec']->id)->count());
    }

    /**
     * The scheduler payload is what Auto-Assign works from, and it has to carry the
     * delegated course record as well as its rows. A missing course is worse than a
     * missing row: the client's course lookup misses, and a missed lookup reads as
     * "open to every department" rather than "CAS only", so the picker would quietly
     * offer the wrong staff.
     */
    public function test_the_scheduler_payload_carries_the_delegated_course_and_its_rows(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        $response = $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/initial-data')
            ->assertOk();

        $gec = collect($response->json('courses'))->firstWhere('id', $fixture['gec']->id);
        $this->assertNotNull($gec, 'The delegated course is missing from the CAS payload.');
        $this->assertSame($fixture['cas']->id, $gec['teaching_department_id']);
        $this->assertSame('CAS', $gec['teaching_department']['department_code']);

        $this->assertEqualsCanonicalizing(
            $blocks->pluck('id')->all(),
            collect($response->json('schedules'))->pluck('id')->all(),
        );
    }

    public function test_the_teaching_college_keeps_seeing_its_assignment_before_handoff_is_done(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $blocks->pluck('id')->all(),
                    'faculty_id' => $fixture['casInstructor']->id,
                ]],
            ])
            ->assertOk();

        $receivingSchedules = collect(
            $this->actingAs($fixture['casSecretary'])
                ->getJson('/api/initial-data')
                ->assertOk()
                ->json('schedules'),
        )->whereIn('id', $blocks->pluck('id'));

        $this->assertCount(3, $receivingSchedules);
        $this->assertTrue($receivingSchedules->every(
            fn (array $schedule): bool => (int) $schedule['faculty_id'] === $fixture['casInstructor']->id,
        ));

        // The source college must still wait for the teaching college to mark
        // the assignment batch done before those instructor details are exposed.
        $sourceSchedules = collect(
            $this->actingAs($fixture['itSecretary'])
                ->getJson('/api/initial-data')
                ->assertOk()
                ->json('schedules'),
        )->whereIn('id', $blocks->pluck('id'));

        $this->assertCount(3, $sourceSchedules);
        $this->assertTrue($sourceSchedules->every(
            fn (array $schedule): bool => $schedule['faculty_id'] === null,
        ));
    }

    public function test_the_delegated_course_survives_the_curriculum_filter(): void
    {
        $fixture = $this->fixture();
        $this->meetingBlocks($fixture);

        // With a curriculum of its own, CAS's course list is filtered to that
        // curriculum — and IT's GEC 101 is in neither it nor the CAS department, so
        // it has to be admitted on the delegation alone.
        Curriculum::create([
            'name' => 'BA Curriculum',
            'code' => 'CAS-2026',
            'effective_school_year' => '2026-2027',
            'department_id' => $fixture['cas']->id,
            'status' => 'active',
        ]);

        $courses = collect(
            $this->actingAs($fixture['casSecretary'])
                ->getJson('/api/initial-data')
                ->assertOk()
                ->json('courses'),
        );

        $this->assertNotNull(
            $courses->firstWhere('id', $fixture['gec']->id),
            'The delegated course was filtered out by the curriculum scope.',
        );
    }

    public function test_only_an_active_instructor_of_the_teaching_college_may_teach_it(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $monday = $blocks->firstWhere('day', 'Monday');

        $this->assertSame([], $this->violations($monday, $fixture['casInstructor']));

        // The owning college's own instructor is now the outsider.
        $this->assertContains(
            'service_subject_faculty_department_alignment',
            $this->violations($monday, $fixture['itInstructor']),
        );

        $this->assertContains(
            'faculty_active',
            $this->violations($monday, $fixture['casInactiveInstructor']),
        );
    }

    public function test_the_teaching_college_assigns_every_linked_meeting_block(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $monday = $blocks->firstWhere('day', 'Monday');

        // One block is picked; the whole class is assigned.
        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$monday->id}", [
                'faculty_id' => $fixture['casInstructor']->id,
            ])
            ->assertOk()
            ->assertJsonCount(3, 'schedules')
            ->assertJsonPath('load.tier', 'basic');

        $this->assertSame(
            ['Friday', 'Monday', 'Wednesday'],
            $this->daysTaughtBy($fixture, $fixture['casInstructor']),
        );

        // The log records who did it — CAS — next to the college that offers the
        // course, so the trail shows the delegation rather than hiding it.
        $this->assertDatabaseHas('scheduling_audit_logs', [
            'action' => 'instructor_assigned',
            'department_id' => $fixture['cas']->id,
        ]);
    }

    public function test_the_batch_route_assigns_the_whole_class_for_the_teaching_college(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $blocks->pluck('id')->all(),
                    'faculty_id' => $fixture['casInstructor']->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 3);

        $this->assertSame(
            ['Friday', 'Monday', 'Wednesday'],
            $this->daysTaughtBy($fixture, $fixture['casInstructor']),
        );
    }

    public function test_batch_assignment_refreshes_the_cached_workspace_with_the_instructor_name(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        // Prime the cached assignment payload before the write.
        $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/instructor-assignments')
            ->assertOk();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $blocks->pluck('id')->all(),
                    'faculty_id' => $fixture['casInstructor']->id,
                ]],
            ])
            ->assertOk();

        $schedules = collect(
            $this->actingAs($fixture['casSecretary'])
                ->getJson('/api/instructor-assignments')
                ->assertOk()
                ->json('schedules'),
        )->whereIn('id', $blocks->pluck('id'));

        $this->assertCount(3, $schedules);
        $this->assertSame(
            [$fixture['casInstructor']->id],
            $schedules->pluck('faculty_id')->map('intval')->unique()->values()->all(),
        );
        $this->assertSame(
            [$fixture['casInstructor']->first_name],
            $schedules->pluck('faculty.first_name')->unique()->values()->all(),
        );
        $this->assertSame(
            [$fixture['casInstructor']->last_name],
            $schedules->pluck('faculty.last_name')->unique()->values()->all(),
        );
    }

    public function test_the_owning_college_may_not_assign_a_course_it_delegated(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $monday = $blocks->firstWhere('day', 'Monday');

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/instructor-assignments/{$monday->id}", [
                'faculty_id' => $fixture['itInstructor']->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only the college that offers this course can assign its instructor.');

        // The same answer on the batch route, or Auto-Assign would be a way around
        // the single-row gate.
        $this->actingAs($fixture['itSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $blocks->pluck('id')->all(),
                    'faculty_id' => $fixture['itInstructor']->id,
                ]],
            ])
            ->assertStatus(403);

        $this->assertSame([], $this->daysTaughtBy($fixture, $fixture['itInstructor']));
    }

    public function test_a_college_with_no_claim_on_the_course_is_refused(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $outsider = User::factory()->create([
            'role' => 'secretary',
            'department_id' => Departments::create([
                'department_name' => 'Engineering',
                'department_code' => 'COE',
            ])->id,
        ]);

        $this->actingAs($outsider)
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $blocks->pluck('id')->all(),
                    'faculty_id' => $fixture['casInstructor']->id,
                ]],
            ])
            ->assertStatus(403);

        $this->assertSame([], $this->daysTaughtBy($fixture, $fixture['casInstructor']));
    }

    public function test_teaching_a_delegated_course_does_not_confer_status_control(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        // CAS assigns the instructor; moving IT's schedules through the approval
        // workflow is still IT's alone.
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-status', [
                'ids' => $blocks->pluck('id')->all(),
                'status' => 'finalized',
            ])
            ->assertStatus(403);

        $this->assertSame(
            ['faculty_assignment'],
            Schedule::query()->whereIn('id', $blocks->pluck('id'))->distinct()->pluck('status')->all(),
        );
    }

    public function test_an_overload_is_still_confirmed_before_the_batch_is_written(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        // 15 units of Basic Load already carried (21 maximum less 6 deload), so the
        // 3-unit class crosses it.
        $this->carriedLoad($fixture, 15);

        $assignment = [[
            'schedule_ids' => $blocks->pluck('id')->all(),
            'faculty_id' => $fixture['casInstructor']->id,
        ]];

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => $assignment])
            ->assertStatus(409)
            ->assertJsonPath('overload_confirmation.instructors.0.faculty_id', $fixture['casInstructor']->id)
            ->assertJsonPath('overload_confirmation.instructors.0.tier', 'overload')
            // Three meeting blocks are one class, so the load rises by the course's
            // units once rather than three times.
            ->assertJsonPath('overload_confirmation.instructors.0.added_units', 3)
            ->assertJsonPath('overload_confirmation.instructors.0.projected_units', 18)
            ->assertJsonPath('overload_confirmation.instructors.0.assignment_label', 'GEC 101 — BSIT 1A');

        $this->assertSame([], $this->daysTaughtBy($fixture, $fixture['casInstructor']));

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => $assignment,
                'confirm_overload' => true,
            ])
            ->assertOk();

        $this->assertSame(
            ['Friday', 'Monday', 'Wednesday'],
            $this->daysTaughtBy($fixture, $fixture['casInstructor']),
        );
    }

    public function test_a_conflict_on_one_block_rolls_the_whole_class_back(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        // A CAS class the instructor already teaches at the same hour as the Monday
        // block, so the last row of the batch cannot be written.
        $this->carriedLoad($fixture, 3, ['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00']);

        $ordered = $blocks->sortByDesc('day')->pluck('id')->values()->all();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => $ordered,
                    'faculty_id' => $fixture['casInstructor']->id,
                ]],
                'confirm_overload' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.schedule_id', $blocks->firstWhere('day', 'Monday')->id);

        // Wednesday and Friday were written before Monday failed; none may survive.
        $this->assertSame([], $this->daysTaughtBy($fixture, $fixture['casInstructor']));
    }

    public function test_the_delegation_can_be_handed_back(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $monday = $blocks->firstWhere('day', 'Monday');

        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertOk();

        // Back to the derived rule: a GEC subject is taught by the college that
        // offers it, so IT assigns it again and CAS no longer may.
        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$monday->id}", [
                'faculty_id' => $fixture['casInstructor']->id,
            ])
            ->assertStatus(403);

        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/instructor-assignments/{$monday->id}", [
                'faculty_id' => $fixture['itInstructor']->id,
            ])
            ->assertOk();

        $this->assertSame(
            ['Friday', 'Monday', 'Wednesday'],
            $this->daysTaughtBy($fixture, $fixture['itInstructor']),
        );
    }

    /**
     * The rules a save would apply, without saving.
     *
     * @return array<int, string>
     */
    private function violations(Schedule $schedule, Faculty $faculty): array
    {
        $violations = app(RuleEngine::class)->validate(array_merge($schedule->toArray(), [
            'faculty_id' => $faculty->id,
            'ignore_schedule_id' => $schedule->id,
        ]));

        return array_map(static fn (array $violation): string => (string) $violation['rule'], $violations);
    }

    /**
     * The days of the delegated class this instructor now holds, sorted so the
     * assertion does not depend on row order. An MWF class is three rows, so this
     * is what "the whole class was assigned" looks like.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<int, string>
     */
    private function daysTaughtBy(array $fixture, Faculty $faculty): array
    {
        return Schedule::query()
            ->where('course_id', $fixture['gec']->id)
            ->where('faculty_id', $faculty->id)
            ->orderBy('day')
            ->pluck('day')
            ->all();
    }

    /**
     * The three meeting blocks of IT's GEC 101 class, at the stage where instructor
     * assignment is legal — VPAA approval has already happened.
     *
     * @param  array<string, mixed>  $fixture
     * @return \Illuminate\Support\Collection<int, Schedule>
     */
    private function meetingBlocks(array $fixture)
    {
        return collect(['Monday', 'Wednesday', 'Friday'])->map(fn (string $day): Schedule => Schedule::create([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['gec']->id,
            'room_id' => $fixture['room']->id,
            // The offering is IT's, whoever teaches it.
            'department_id' => $fixture['it']->id,
            'faculty_id' => null,
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]));
    }

    /**
     * Load the CAS instructor already carries, on a CAS class of their own.
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function carriedLoad(array $fixture, int $units, array $overrides = []): Schedule
    {
        $course = Course::create([
            'course_code' => "CAS{$units}",
            'course_name' => "Arts Course {$units}",
            'lecture_hours' => $units,
            'lab_hours' => 0,
            'units' => $units,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['cas']->id,
            'status' => 'active',
        ]);

        $section = Sections::create([
            'section_name' => "AB-1{$units}",
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['cas']->id,
            'term_id' => $fixture['term']->id,
            'status' => 'active',
        ]);

        return Schedule::create(array_merge([
            'term_id' => $fixture['term']->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $fixture['casRoom']->id,
            'department_id' => $fixture['cas']->id,
            'faculty_id' => $fixture['casInstructor']->id,
            'day' => 'Tuesday',
            'start_time' => '13:00',
            'end_time' => '14:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ], $overrides));
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
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'it' => $it,
            'cas' => $cas,
            'term' => $term,
            'room' => Rooms::create([
                'room_code' => 'CIT 101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $it->id,
            ]),
            'casRoom' => Rooms::create([
                'room_code' => 'AB 201',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $cas->id,
            ]),
            'section' => Sections::create([
                'section_name' => 'BSIT 1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $it->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            // IT owns it; CAS teaches it.
            'gec' => Course::create([
                'course_code' => 'GEC 101',
                'course_name' => 'Understanding the Self',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'minor',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $it->id,
                'teaching_department_id' => $cas->id,
                'status' => 'active',
            ]),
            'casInstructor' => $this->instructor('Arts', $cas->id),
            'casInactiveInstructor' => $this->instructor('Retired', $cas->id, ['status' => 'inactive']),
            'itInstructor' => $this->instructor('Tech', $it->id),
            'itSecretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $it->id]),
            'casSecretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id]),
        ];
    }

    /**
     * 15 units of Basic Load — a 21-unit maximum less 6 of deload — with 3 units of
     * overload allowance and 3 of pro bono on top.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function instructor(string $firstName, int $departmentId, array $overrides = []): Faculty
    {
        return Faculty::create(array_merge([
            'first_name' => $firstName,
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $departmentId,
            'status' => 'active',
            'max_units' => 21,
            'deload_units' => 6,
            'overload_units' => 3,
            'probono_units' => 3,
        ], $overrides));
    }
}
