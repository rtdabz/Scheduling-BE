<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('preApprovalStatuses')]
    public function test_classes_awaiting_vpaa_approval_stay_out_of_cross_department_even_with_a_retained_instructor(string $status): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))->update([
            'status' => $status,
            'faculty_id' => $fixture['casInstructor']->id,
            'faculty_assignment_done' => false,
        ]);

        $unassignedSection = $fixture['section']->replicate();
        $unassignedSection->section_name = 'BSIT 1B';
        $unassignedSection->save();
        $unassigned = $this->meetingBlocks(array_replace($fixture, ['section' => $unassignedSection]));
        Schedule::whereIn('id', $unassigned->pluck('id'))->update(['status' => $status]);

        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$blocks->first()->id}", ['faculty_id' => null])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Instructor assignment is available only after VPAA approval.');

        $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonCount(0, 'schedules')
            ->assertJsonCount(0, 'courses')
            ->assertJsonCount(0, 'departments')
            ->assertJsonMissingPath('incoming_courses');

        // The retained instructor is still recorded, just out of view.
        $this->assertSame($fixture['casInstructor']->id, (int) Schedule::findOrFail($blocks->first()->id)->faculty_id);
    }

    /**
     * A college whose timetable is only partly VPAA-approved stays out of Cross-Department; once
     * the VPAA approves the rest, every delegated class appears exactly once.
     */
    public function test_a_partly_approved_college_appears_only_after_full_vpaa_approval(): void
    {
        $fixture = $this->fixture();
        $approved = $this->meetingBlocks($fixture);
        $pendingSection = $fixture['section']->replicate();
        $pendingSection->section_name = 'BSIT 1B';
        $pendingSection->save();
        $pending = $this->meetingBlocks(array_replace($fixture, ['section' => $pendingSection]));
        Schedule::whereIn('id', $pending->pluck('id'))->update(['status' => 'approved_by_dean']);

        $schedules = fn (): array => collect(
            $this->actingAs($fixture['casSecretary'])->getJson('/api/instructor-assignments')->assertOk()->json('schedules'),
        )->pluck('id')->all();
        $pendingCount = fn (): int => (int) $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")->assertOk()->json('cross_department_pending');

        $this->assertSame([], $schedules());
        $this->assertSame(0, $pendingCount());

        Schedule::whereIn('id', $pending->pluck('id'))->update(['status' => 'faculty_assignment']);
        ApiCache::forgetGroups(['instructor_assignments.index', 'initial.data']);

        $visible = $schedules();
        $this->assertCount(count(array_unique($visible)), $visible);
        $this->assertEqualsCanonicalizing(
            [...$approved->pluck('id')->all(), ...$pending->pluck('id')->all()],
            $visible,
        );
        $this->assertSame(2, $pendingCount());
    }

    public static function preApprovalStatuses(): array
    {
        return array_map(static fn (string $status): array => [$status], [
            'draft', 'completed', 'submitted', 'approved_by_dean',
            'conditionally_approved', 'rejected_by_dean', 'rejected', 'revision',
        ]);
    }

    public function test_retained_instructors_do_not_bypass_semester_course_or_department_scope(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))->update([
            'faculty_id' => $fixture['casInstructor']->id,
        ]);
        $otherSemester = Semester::create([
            'academic_year' => '2025-2026',
            'semester' => '1st',
            'is_active' => false,
            'is_enabled' => false,
        ]);
        $inactiveCourse = $fixture['gec']->replicate()->fill(['course_code' => 'GEC 102', 'status' => 'inactive']);
        $inactiveCourse->save();
        $unrelatedCourse = $fixture['gec']->replicate()->fill([
            'course_code' => 'GEC 103',
            'teaching_department_id' => $fixture['it']->id,
        ]);
        $unrelatedCourse->save();

        foreach ([
            ['semester_id' => $otherSemester->id],
            ['course_id' => $inactiveCourse->id],
            ['course_id' => $unrelatedCourse->id],
        ] as $overrides) {
            $blocks->first()->refresh()->replicate()->fill($overrides)->save();
        }

        $response = $this->actingAs($fixture['casSecretary'])
            ->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonCount(3, 'schedules');

        $this->assertEqualsCanonicalizing(
            $blocks->pluck('id')->all(),
            collect($response->json('schedules'))->pluck('id')->all(),
        );
    }

    public function test_an_it_instructor_teaching_blis_within_the_same_college_is_returned_once_per_meeting(): void
    {
        $fixture = $this->fixture();
        $blis = Program::create([
            'department_id' => $fixture['it']->id,
            'code' => 'BLIS',
            'name' => 'Library and Information Science',
        ]);
        $unrelated = Program::create([
            'department_id' => $fixture['it']->id,
            'code' => 'BSCS',
            'name' => 'Computer Science',
        ]);
        $fixture['gec']->update([
            'program_id' => $blis->id,
            'teaching_department_id' => $fixture['it']->id,
            'teaching_program_id' => $fixture['itProgram']->id,
            'teaching_source_program_id' => $blis->id,
        ]);
        $fixture['section']->update(['section_name' => 'BLIS 1A', 'program_id' => $blis->id]);
        $fixture['itInstructor']->update(['program_id' => $fixture['itProgram']->id]);
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))->update(['faculty_id' => $fixture['itInstructor']->id]);
        $head = fn (int $programId) => $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['it']->id,
            'program_id' => $programId,
        ]));

        $response = $this->actingAs($head($fixture['itProgram']->id))
            ->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonCount(3, 'schedules')
            ->assertJsonCount(1, 'faculties')
            ->assertJsonPath('faculties.0.id', $fixture['itInstructor']->id);

        $this->assertEqualsCanonicalizing(
            $blocks->pluck('id')->all(),
            collect($response->json('schedules'))->pluck('id')->all(),
        );
        foreach ($response->json('schedules') as $schedule) {
            $this->assertSame($fixture['itInstructor']->id, $schedule['faculty']['id']);
            $this->assertSame($fixture['itProgram']->id, $schedule['course']['teaching_program_id']);
            $this->assertSame($blis->id, $schedule['course']['program_id']);
            $this->assertSame('BLIS 1A', $schedule['section']['section_name']);
            $this->assertSame($blis->id, $schedule['section']['program_id']);
        }

        foreach ([$blis->id, $unrelated->id] as $programId) {
            $this->actingAs($head($programId))->getJson('/api/instructor-assignments')
                ->assertOk()
                ->assertJsonMissingPath('incoming_courses');
        }
    }

    public function test_program_head_assignment_payload_only_contains_their_program_faculty(): void
    {
        $fixture = $this->fixture();
        $bped = Program::create([
            'department_id' => $fixture['cas']->id,
            'code' => 'BPED',
            'name' => 'Physical Education',
        ]);
        $otherProgram = Program::create([
            'department_id' => $fixture['cas']->id,
            'code' => 'BEED',
            'name' => 'Elementary Education',
        ]);
        $head = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['cas']->id,
            'program_id' => $bped->id,
        ]));
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
                'semester_id' => $fixture['semester']->id,
                'section_id' => $fixture['section']->id,
                'course_id' => $fixture['gec']->id,
                'department_id' => $fixture['cas']->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'room_id' => $fixture['casRoom']->id,
                'mode' => 'on-site',
                'status' => 'draft',
            ])
            ->assertStatus(422)
            // The section belongs to the source department, so the request is
            // refused before the duplicate check is ever reached.
            ->assertJsonPath('message', 'The schedule Department, Program, and Section must match.');

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

        $delegated = $courses->firstWhere('id', $fixture['gec']->id);
        $this->assertNotNull($delegated, 'The delegated course was filtered out by the curriculum scope.');
        $this->assertTrue($delegated['delegated_only']);
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

    public function test_the_teaching_college_can_remove_an_instructor_without_schedule_ownership(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();
        Schedule::whereIn('id', $ids)->update(['faculty_id' => $fixture['casInstructor']->id]);
        $local = $this->carriedLoad($fixture, 3);
        $placementFields = ['semester_id', 'section_id', 'course_id', 'department_id', 'room_id', 'day', 'start_time', 'end_time', 'status'];
        $placements = Schedule::whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Schedule $schedule): array => [$schedule->id => $schedule->only($placementFields)]);

        // A teaching assignment permits instructor changes, not editing the
        // source college's timetable through the general schedule endpoint.
        $this->actingAs($fixture['casSecretary'])
            ->putJson("/api/schedules/{$blocks->first()->id}", ['faculty_id' => null])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only manage schedules for your department.');

        // Nor may the source college remove the teaching college's instructor.
        $this->actingAs($fixture['itSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => null]],
            ])
            ->assertForbidden();
        $this->assertSame(3, Schedule::whereIn('id', $ids)->whereNotNull('faculty_id')->count());

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => null]],
            ])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 3)
            ->assertJsonCount(3, 'schedules');

        foreach (Schedule::whereIn('id', $ids)->get() as $schedule) {
            $this->assertNull($schedule->faculty_id);
            $this->assertSame($placements[$schedule->id], $schedule->only($placementFields));
        }
        $this->assertSame($fixture['casInstructor']->id, $local->fresh()->faculty_id);
    }

    /**
     * Archiving keeps `faculty_id` on the class (so a restore brings it back), but the
     * course must not stay locked to an instructor who no longer exists.
     */
    public function test_archiving_the_instructor_releases_the_course_assignment_lock(): void
    {
        $fixture = $this->fixture();
        $ids = $this->meetingBlocks($fixture)->pluck('id')->all();
        Schedule::whereIn('id', $ids)->update(['faculty_id' => $fixture['casInstructor']->id]);

        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertStatus(422)
            ->assertJsonPath('instructor_assigned_classes', 1);

        $fixture['casInstructor']->delete();

        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertOk();
        $this->assertNull($fixture['gec']->fresh()->teaching_department_id);
        $this->assertSame(3, Schedule::whereIn('id', $ids)->where('faculty_id', $fixture['casInstructor']->id)->count());
    }

    /** @return array<string, array{0: string}> */
    public static function removalRoutes(): array
    {
        return ['cross-department single class' => ['instructor-assignments'], 'batch' => ['batch-faculty']];
    }

    #[DataProvider('removalRoutes')]
    public function test_removing_an_instructor_releases_the_done_lock(string $route): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]],
            ])
            ->assertOk();
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty-done', ['ids' => $ids, 'done' => true])
            ->assertOk();

        $request = $route === 'batch-faculty'
            ? $this->actingAs($fixture['casSecretary'])->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => null]],
            ])
            : $this->actingAs($fixture['casSecretary'])->patchJson("/api/instructor-assignments/{$blocks->first()->id}", [
                'faculty_id' => null,
            ]);
        $request->assertOk();

        foreach (Schedule::whereIn('id', $ids)->get() as $schedule) {
            $this->assertNull($schedule->faculty_id);
            $this->assertFalse($schedule->faculty_assignment_done, 'A class without an instructor must not stay marked done.');
        }

        // Reassignable straight away, without reopening the list first.
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]],
            ])
            ->assertOk();
    }

    public function test_the_teaching_college_returns_an_unstaffed_section_to_the_offering_college(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$blocks->first()->id]])
            ->assertOk()
            ->assertJsonPath('sections_returned', 1)
            ->assertJsonPath('cleared_course_ids', [$fixture['gec']->id])
            ->assertJsonCount(3, 'schedule_ids');

        // The whole class goes back, every meeting.
        $this->assertSame(3, Schedule::whereIn('id', $ids)->where('delegation_returned_from_department_id', $fixture['cas']->id)->count());
        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'cross_department_sections_returned', 'department_id' => $fixture['cas']->id]);
        $this->assertDatabaseHas('system_notifications', ['type' => 'cross_department_sections_returned']);
        $this->assertNull($fixture['gec']->fresh()->teaching_department_id);
        $this->assertDatabaseHas('course_teaching_assignments', [
            'course_id' => $fixture['gec']->id, 'semester_id' => $fixture['semester']->id,
            'teaching_department_id' => null, 'teaching_program_id' => null, 'teaching_source_program_id' => null,
        ]);

        // The teaching college no longer sees or assigns it.
        $casView = $this->actingAs($fixture['casSecretary'])->getJson('/api/instructor-assignments')->assertOk()->json('schedules');
        $this->assertSame([], collect($casView)->whereIn('id', $ids)->values()->all());
        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$blocks->first()->id}", ['faculty_id' => $fixture['casInstructor']->id])
            ->assertForbidden();
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]]])
            ->assertForbidden();

        // The offering college assigns its own instructor, under its own rules.
        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/instructor-assignments/{$blocks->first()->id}", ['faculty_id' => $fixture['itInstructor']->id])
            ->assertOk();
        $this->assertSame(['Friday', 'Monday', 'Wednesday'], $this->daysTaughtBy($fixture, $fixture['itInstructor']));
        // A teaching-college instructor is still refused on the returned class.
        $this->actingAs($fixture['itSecretary'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]]])
            ->assertStatus(422);
    }

    public function test_the_return_listing_uses_the_recorded_returning_program(): void
    {
        $fixture = $this->fixture();
        $this->placeInCurriculum($fixture);
        $fixture['gec']->update(['teaching_program_id' => $fixture['casProgram']->id]);
        $blocks = $this->meetingBlocks($fixture);
        $head = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'department_id' => $fixture['cas']->id, 'program_id' => $fixture['casProgram']->id,
        ]));
        $this->actingAs($head)->postJson('/api/instructor-assignments/return-sections', [
            'schedule_ids' => [$blocks->first()->id],
        ])->assertOk();

        // Later account and course changes must not rewrite who returned the section.
        $otherProgram = Program::create(['department_id' => $fixture['cas']->id, 'code' => 'OTHER', 'name' => 'Another Program']);
        $head->update(['program_id' => $otherProgram->id]);
        $fixture['gec']->refresh()->update(['teaching_department_id' => $fixture['cas']->id, 'teaching_program_id' => $otherProgram->id]);
        $fixture['casProgram']->delete();

        $section = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()->json('courses'))->flatMap(fn (array $course) => $course['section_assignments'])->first();
        $this->assertSame($fixture['cas']->id, $section['returned_from_department_id']);
        $this->assertSame($fixture['casProgram']->id, $section['returned_from_program_id']);
        $this->assertSame('BACAS', $section['returned_from_program_code']);
        $this->assertSame('Arts and Sciences', $section['returned_from_program_name']);

        // Do not reuse an older event if the current return has no matching history.
        Schedule::whereIn('id', $blocks->pluck('id'))->update(['delegation_returned_at' => now()->addDay()]);
        $section = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()->json('courses'))->flatMap(fn (array $course) => $course['section_assignments'])->first();
        $this->assertNull($section['returned_from_program_id']);
    }

    public function test_the_return_listing_does_not_guess_a_program_when_return_history_is_missing(): void
    {
        $fixture = $this->fixture();
        $this->placeInCurriculum($fixture);
        $fixture['gec']->update(['teaching_program_id' => $fixture['casProgram']->id]);
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))->update([
            'delegation_returned_from_department_id' => $fixture['cas']->id, 'delegation_returned_at' => now(),
        ]);

        $section = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()->json('courses'))->flatMap(fn (array $course) => $course['section_assignments'])->first();
        $this->assertSame('CAS', $section['returned_from_department_code']);
        $this->assertNull($section['returned_from_program_id']);
        $this->assertNull($section['returned_from_program_code']);
    }

    public function test_partial_returns_keep_the_assignment_until_the_last_section_is_returned(): void
    {
        $fixture = $this->fixture();
        $this->placeInCurriculum($fixture);
        $fixture['gec']->update(['teaching_program_id' => $fixture['casProgram']->id, 'teaching_source_program_id' => $fixture['itProgram']->id]);
        $first = $this->meetingBlocks($fixture);
        $last = $this->sectionClass($fixture, 'BSIT 1B');
        // The semester mapping and an offering in a different semester must stay independent.
        $otherSemester = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false]);
        $otherAssignment = CourseTeachingAssignment::create([
            'course_id' => $fixture['gec']->id, 'semester_id' => $otherSemester->id,
            'teaching_department_id' => $fixture['cas']->id, 'teaching_program_id' => $fixture['casProgram']->id,
        ]);

        $this->actingAs($fixture['casSecretary'])->postJson('/api/instructor-assignments/return-sections', [
            'schedule_ids' => [$first->first()->id],
        ])->assertOk()->assertJsonPath('cleared_course_ids', []);
        $this->assertSame($fixture['cas']->id, (int) $fixture['gec']->fresh()->teaching_department_id);
        $listed = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()->json('courses'))->firstWhere('id', $fixture['gec']->id);
        $this->assertSame($fixture['casProgram']->id, $listed['teaching_program_id']);

        $this->actingAs($fixture['casSecretary'])->postJson('/api/instructor-assignments/return-sections', [
            'schedule_ids' => [$last->id],
        ])->assertOk()->assertJsonPath('cleared_course_ids', [$fixture['gec']->id]);
        $listed = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')
            ->assertOk()->json('courses'))->firstWhere('id', $fixture['gec']->id);
        $this->assertNull($listed['teaching_department_id']);
        $this->assertNull($listed['teaching_program_id']);
        $this->assertSame($fixture['casProgram']->id, $otherAssignment->fresh()->teaching_program_id);
        $this->assertCount(2, $listed['section_assignments']);
    }

    public function test_returns_from_another_program_do_not_clear_sections_still_following_the_course_assignment(): void
    {
        $fixture = $this->fixture();
        $hm = $this->thirdCollege($fixture);
        $first = $this->meetingBlocks($fixture);
        $kept = $this->sectionClass($fixture, 'BSIT 1B');
        $this->actingAs($fixture['casSecretary'])->postJson('/api/instructor-assignments/return-sections', [
            'schedule_ids' => [$first->first()->id],
        ])->assertOk();
        $this->actingAs($fixture['itSecretary'])->postJson('/api/course-teaching-assignments/sections', [
            'schedule_ids' => [$first->first()->id], 'teaching_department_id' => $hm['department']->id,
            'teaching_program_id' => $hm['program']->id,
        ])->assertOk();
        $this->actingAs($hm['secretary'])->postJson('/api/instructor-assignments/return-sections', [
            'schedule_ids' => [$first->first()->id],
        ])->assertOk()->assertJsonPath('cleared_course_ids', []);
        $this->assertSame($fixture['cas']->id, (int) $fixture['gec']->fresh()->teaching_department_id);
        $this->assertNull($kept->fresh()->delegation_returned_from_department_id);
    }

    public function test_existing_fully_returned_assignments_are_repaired_without_clearing_partial_or_new_assignments(): void
    {
        $fixture = $this->fixture();
        $rows = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $rows->pluck('id'))->update([
            'delegation_returned_from_department_id' => $fixture['cas']->id, 'delegation_returned_at' => now(),
        ]);
        $partial = $fixture['gec']->replicate();
        $partial->course_code = 'PARTIAL';
        $partial->save();
        $new = $fixture['gec']->replicate();
        $new->course_code = 'NEW';
        $new->save();
        foreach ([$partial, $new] as $course) {
            $returned = $rows->first()->fresh()->replicate();
            $returned->course_id = $course->id;
            $returned->save();
        }
        $remaining = $rows->first()->fresh()->replicate();
        $remaining->fill(['course_id' => $partial->id, 'delegation_returned_from_department_id' => null, 'delegation_returned_at' => null])->save();
        CourseTeachingAssignment::where('semester_id', $fixture['semester']->id)->where('course_id', $new->id)
            ->update(['updated_at' => now()->addMinute()]);
        $before = Schedule::orderBy('id')->get()->toArray();

        $migration = require database_path('migrations/2026_11_26_140000_clear_fully_returned_course_teaching_assignments.php');
        $migration->up();
        $this->assertNull($fixture['gec']->fresh()->teaching_department_id);
        $this->assertSame($fixture['cas']->id, (int) $partial->fresh()->teaching_department_id);
        $this->assertSame($fixture['cas']->id, (int) $new->fresh()->teaching_department_id);
        $this->assertSame($before, Schedule::orderBy('id')->get()->toArray());
        $migration->up();
        $migration->down();
        $this->assertNull($fixture['gec']->fresh()->teaching_department_id);
    }

    public function test_only_unstaffed_sections_can_be_returned(): void
    {
        $fixture = $this->fixture();
        $staffed = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $staffed->pluck('id'))->update(['faculty_id' => $fixture['casInstructor']->id]);
        $otherSection = Sections::create([
            'section_name' => 'BSIT 1B', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $fixture['it']->id, 'program_id' => $fixture['itProgram']->id,
            'semester_id' => $fixture['semester']->id, 'status' => 'active',
        ]);
        $unstaffed = Schedule::create([
            'semester_id' => $fixture['semester']->id, 'section_id' => $otherSection->id, 'course_id' => $fixture['gec']->id,
            'room_id' => $fixture['room']->id, 'department_id' => $fixture['it']->id, 'day' => 'Tuesday',
            'start_time' => '10:00', 'end_time' => '11:30', 'mode' => 'on-site', 'status' => 'faculty_assignment',
        ]);

        // A request that includes a staffed section changes nothing.
        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$staffed->first()->id, $unstaffed->id]])
            ->assertStatus(422)
            ->assertJsonCount(1, 'blocked')
            ->assertJsonPath('blocked.0.section_id', $fixture['section']->id);
        $this->assertSame(0, Schedule::whereNotNull('delegation_returned_from_department_id')->count());

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$unstaffed->id]])
            ->assertOk();
        $this->assertSame($fixture['cas']->id, $unstaffed->fresh()->delegation_returned_from_department_id);
        // The staffed section stays with the teaching college, instructor intact.
        $this->assertTrue(Schedule::whereIn('id', $staffed->pluck('id'))->get()->every(
            fn (Schedule $row): bool => $row->delegation_returned_from_department_id === null && $row->faculty_id === $fixture['casInstructor']->id,
        ));
    }

    public function test_the_offering_college_cannot_return_its_own_classes(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$blocks->first()->id]])
            ->assertForbidden();

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$blocks->first()->id]])
            ->assertOk();
        // Already returned: a second return is no longer the teaching college's to make.
        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$blocks->first()->id]])
            ->assertForbidden();
    }

    public function test_a_returned_section_can_be_handed_to_another_department(): void
    {
        $fixture = $this->fixture();
        $this->placeInCurriculum($fixture);
        $hm = $this->thirdCollege($fixture);
        $returned = $this->meetingBlocks($fixture);
        $kept = $this->sectionClass($fixture, 'BSIT 1B');
        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$returned->first()->id]])
            ->assertOk();

        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections', [
                'schedule_ids' => [$returned->first()->id],
                'teaching_department_id' => $hm['department']->id,
            ])
            ->assertOk()
            ->assertJsonPath('sections_assigned', 1)
            ->assertJsonCount(3, 'schedule_ids');

        // Only that section moved. The course-level assignment and its other section stay with CAS.
        $this->assertSame($fixture['cas']->id, (int) $fixture['gec']->fresh()->teaching_department_id);
        $this->assertSame(3, Schedule::whereIn('id', $returned->pluck('id'))->where('section_teaching_department_id', $hm['department']->id)->count());
        $this->assertNull($kept->fresh()->section_teaching_department_id);
        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'cross_department_section_assigned']);
        $this->assertDatabaseHas('system_notifications', ['type' => 'incoming_cross_department_courses', 'department_id' => $hm['department']->id]);

        $visibleTo = fn (User $user): array => collect($this->actingAs($user)->getJson('/api/instructor-assignments')->assertOk()->json('schedules'))
            ->pluck('id')->map('intval')->sort()->values()->all();
        $this->assertSame($returned->pluck('id')->sort()->values()->all(), $visibleTo($hm['secretary']));
        $this->assertSame([$kept->id], $visibleTo($fixture['casSecretary']));

        // The new receiver staffs it; neither the old receiver nor the owner may.
        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$returned->first()->id}", ['faculty_id' => $fixture['casInstructor']->id])
            ->assertForbidden();
        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/instructor-assignments/{$returned->first()->id}", ['faculty_id' => $fixture['itInstructor']->id])
            ->assertForbidden();
        $this->actingAs($hm['secretary'])
            ->patchJson("/api/instructor-assignments/{$returned->first()->id}", ['faculty_id' => $hm['instructor']->id])
            ->assertOk();
        $this->assertSame(3, Schedule::whereIn('id', $returned->pluck('id'))->where('faculty_id', $hm['instructor']->id)->count());

        // The receiver's Course Assignment lists the course as incoming, counting only its section.
        $incoming = collect($this->actingAs($hm['secretary'])->getJson('/api/course-teaching-assignments')->assertOk()
            ->json('incoming_cross_department_courses'))->firstWhere('id', $fixture['gec']->id);
        $this->assertSame(3, $incoming['schedule_count']);
        $this->assertTrue($incoming['section_level']);
    }

    public function test_only_returned_unstaffed_sections_can_be_reassigned(): void
    {
        $fixture = $this->fixture();
        $hm = $this->thirdCollege($fixture);
        $returned = $this->meetingBlocks($fixture);
        $kept = $this->sectionClass($fixture, 'BSIT 1B');
        $assign = fn (array $ids, ?int $departmentId = null) => $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections', ['schedule_ids' => $ids, 'teaching_department_id' => $departmentId ?? $hm['department']->id]);

        // A section still under the course-level assignment cannot move on its own.
        $assign([$kept->id])->assertStatus(422)->assertJsonPath('blocked.0.section_id', $kept->section_id);

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$returned->first()->id]])
            ->assertOk();
        // Not to its own single-program department, whose courses are managed in-house.
        $assign([$returned->first()->id], $fixture['it']->id)->assertStatus(422)->assertJsonPath('reason', 'own_department');
        // Not by another department.
        $this->actingAs($hm['secretary'])
            ->postJson('/api/course-teaching-assignments/sections', ['schedule_ids' => [$returned->first()->id], 'teaching_department_id' => $hm['department']->id])
            ->assertForbidden();

        $assign([$returned->first()->id])->assertOk();
        $this->actingAs($hm['secretary'])
            ->patchJson("/api/instructor-assignments/{$returned->first()->id}", ['faculty_id' => $hm['instructor']->id])
            ->assertOk();

        // Once staffed it stays where it is: not handed on, not taken back.
        $assign([$returned->first()->id], $fixture['cas']->id)->assertStatus(422);
        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections/remove', ['schedule_ids' => [$returned->first()->id]])
            ->assertStatus(422);
        $this->assertSame(3, Schedule::whereIn('id', $returned->pluck('id'))->where('section_teaching_department_id', $hm['department']->id)->count());
    }

    public function test_course_level_changes_leave_a_returned_section_alone(): void
    {
        $fixture = $this->fixture();
        $hm = $this->thirdCollege($fixture);
        $returned = $this->meetingBlocks($fixture);
        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$returned->first()->id]])
            ->assertOk();
        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections', ['schedule_ids' => [$returned->first()->id], 'teaching_department_id' => $hm['department']->id])
            ->assertOk();
        $this->actingAs($hm['secretary'])
            ->patchJson("/api/instructor-assignments/{$returned->first()->id}", ['faculty_id' => $hm['instructor']->id])
            ->assertOk();

        // The staffed section-level class does not lock the course-level assignment...
        $this->actingAs($fixture['itSecretary'])
            ->deleteJson("/api/course-teaching-assignments/{$fixture['gec']->id}")
            ->assertOk();

        // ...and the course-level change does not reach it.
        $this->assertNull($fixture['gec']->fresh()->teaching_department_id);
        $this->assertTrue(Schedule::whereIn('id', $returned->pluck('id'))->get()->every(
            fn (Schedule $row): bool => $row->section_teaching_department_id === $hm['department']->id && $row->faculty_id === $hm['instructor']->id,
        ));
    }

    public function test_a_section_can_go_round_the_cycle_without_duplicates(): void
    {
        $fixture = $this->fixture();
        $this->placeInCurriculum($fixture);
        $hm = $this->thirdCollege($fixture);
        $returned = $this->meetingBlocks($fixture);
        $id = $returned->first()->id;
        $rowCount = Schedule::count();

        $this->actingAs($fixture['casSecretary'])->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$id]])->assertOk();
        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections', ['schedule_ids' => [$id], 'teaching_department_id' => $hm['department']->id, 'teaching_program_id' => $hm['program']->id])
            ->assertOk();
        // The new receiver cannot staff it either, and returns it.
        $this->actingAs($hm['secretary'])->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$id]])->assertOk();

        $this->assertTrue(Schedule::whereIn('id', $returned->pluck('id'))->get()->every(
            fn (Schedule $row): bool => $row->delegation_returned_from_department_id === $hm['department']->id && $row->section_teaching_department_id === null,
        ));
        $section = collect($this->actingAs($fixture['itSecretary'])->getJson('/api/course-teaching-assignments')->assertOk()->json('courses'))
            ->flatMap(fn (array $course) => $course['section_assignments'] ?? [])->firstWhere('section_id', $returned->first()->section_id);
        $this->assertNotNull($section);
        $this->assertTrue($section['returned'] && $section['reassignable']);
        $this->assertSame($hm['program']->id, $section['returned_from_program_id']);
        $this->assertSame('BSHM', $section['returned_from_program_code']);

        // Back to the original receiver, section by section, then taken back in-house.
        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections', ['schedule_ids' => [$id], 'teaching_department_id' => $fixture['cas']->id])
            ->assertOk();
        $this->actingAs($fixture['casSecretary'])
            ->patchJson("/api/instructor-assignments/{$id}", ['faculty_id' => $fixture['casInstructor']->id])
            ->assertOk();
        $this->actingAs($fixture['casSecretary'])->patchJson("/api/instructor-assignments/{$id}", ['faculty_id' => null])->assertOk();
        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/course-teaching-assignments/sections/remove', ['schedule_ids' => [$id]])
            ->assertOk();
        $this->actingAs($fixture['itSecretary'])
            ->patchJson("/api/instructor-assignments/{$id}", ['faculty_id' => $fixture['itInstructor']->id])
            ->assertOk();

        $this->assertSame($rowCount, Schedule::count(), 'Reassigning never creates schedule rows.');
    }

    public function test_a_returned_class_no_longer_counts_as_pending_for_the_teaching_college(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $pending = fn (): int => (int) $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")->assertOk()->json('cross_department_pending');
        $this->assertSame(1, $pending());

        $this->actingAs($fixture['casSecretary'])
            ->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$blocks->first()->id]])
            ->assertOk();

        $this->assertSame(0, $pending());
    }

    public function test_completion_notice_reaches_only_the_program_that_handed_the_course_over(): void
    {
        $fixture = $this->fixture();
        $otherProgram = Program::create(['department_id' => $fixture['it']->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $fixture['gec']->update(['teaching_source_program_id' => $fixture['itProgram']->id]);
        $head = fn (int $programId) => User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['it']->id,
            'program_id' => $programId,
        ]);
        $sourceHead = $head($fixture['itProgram']->id);
        $otherHead = $head($otherProgram->id);
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))->update(['faculty_id' => $fixture['casInstructor']->id]);

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty-done', ['ids' => $blocks->pluck('id')->all(), 'done' => true])
            ->assertOk();

        $received = fn (User $user): bool => DB::table('system_notifications')
            ->where('user_id', $user->id)
            ->where('type', 'cross_department_instructor_assignments_completed')
            ->exists();
        $this->assertTrue($received($sourceHead));
        $this->assertTrue($received($fixture['itSecretary']));
        $this->assertFalse($received($otherHead));
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
        $engineering = Departments::create([
            'department_name' => 'Engineering',
            'department_code' => 'COE',
        ]);
        // Fully configured on purpose: the refusal under test is the domain
        // rule about who may claim the course, not a missing capability.
        Program::create([
            'department_id' => $engineering->id,
            'code' => 'BSCE',
            'name' => 'Civil Engineering',
        ]);
        $outsider = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $engineering->id,
        ]));

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

    public function test_owner_reassignment_keeps_the_teaching_college_handoff_done(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]],
            ])
            ->assertOk();
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty-done', ['ids' => $ids, 'done' => true])
            ->assertOk();

        foreach (['finalized', 'reassignment'] as $status) {
            $this->actingAs($fixture['itSecretary'])
                ->patchJson('/api/schedules/batch-status', ['ids' => $ids, 'status' => $status])
                ->assertOk();
        }

        $this->assertTrue(Schedule::query()->whereIn('id', $ids)->get()->every(
            fn (Schedule $schedule): bool => $schedule->faculty_assignment_done,
        ));
        $sourceSchedules = collect(
            $this->actingAs($fixture['itSecretary'])->getJson('/api/initial-data')->assertOk()->json('schedules'),
        )->whereIn('id', $ids);
        $this->assertCount(3, $sourceSchedules);
        $this->assertTrue($sourceSchedules->every(
            fn (array $schedule): bool => (int) $schedule['faculty_id'] === $fixture['casInstructor']->id,
        ));
    }

    /**
     * VPAA approval is final: the owning college cannot recall an approved timetable, so the
     * teaching college keeps seeing its classes and the instructor it assigned.
     */
    #[DataProvider('recallScopes')]
    public function test_an_approved_college_cannot_be_recalled_out_of_cross_department(bool $recallAll): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();
        $otherSection = $fixture['section']->replicate();
        $otherSection->section_name = 'BSIT 1B';
        $otherSection->save();
        $otherIds = $this->meetingBlocks(array_replace($fixture, ['section' => $otherSection]))->pluck('id')->all();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]],
            ])
            ->assertOk();
        $submission = ScheduleSubmission::create([
            'department_id' => $fixture['it']->id,
            'semester_id' => $fixture['semester']->id,
            'revision_number' => 1,
            'status' => 'approved',
            'submitted_at' => now(),
            'vpaa_reviewed_at' => now(),
        ]);
        $submission->sections()->attach([$fixture['section']->id, $otherSection->id], ['state' => 'included']);

        $visibleDepartments = fn (): array => collect(
            $this->actingAs($fixture['casSecretary'])->getJson('/api/instructor-assignments')->assertOk()->json('schedules'),
        )->pluck('department_id')->unique()->values()->all();
        $pending = fn (): int => (int) $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")->assertOk()->json('cross_department_pending');

        $this->assertSame([$fixture['it']->id], $visibleDepartments());
        $this->assertSame(1, $pending(), 'BSIT 1B still needs its instructor.');

        $recalled = $recallAll ? [$fixture['section']->id, $otherSection->id] : [$fixture['section']->id];
        $this->actingAs($fixture['itSecretary'])
            ->postJson("/api/departments/{$fixture['it']->id}/withdraw-submission", ['section_ids' => $recalled])
            ->assertUnprocessable();

        $this->assertSame([$fixture['it']->id], $visibleDepartments());
        $this->assertSame(1, $pending());
        $this->assertSame('approved', $submission->refresh()->status);
        $this->assertTrue(Schedule::query()->whereIn('id', [...$ids, ...$otherIds])->get()->every(
            fn (Schedule $schedule): bool => $schedule->status !== 'revision',
        ));
        $this->assertSame($fixture['casInstructor']->id, (int) Schedule::findOrFail($ids[0])->faculty_id);
    }

    public static function recallScopes(): array
    {
        return ['one section recalled' => [false], 'every section recalled' => [true]];
    }

    /**
     * A class back in revision that still carries the teaching college's instructor (legacy data
     * from before VPAA approval became final) keeps that instructor until the owner resets it.
     */
    public function test_a_revision_class_keeps_the_teaching_college_instructor_until_reset(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        $ids = $blocks->pluck('id')->all();

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => $ids, 'faculty_id' => $fixture['casInstructor']->id]],
            ])
            ->assertOk();
        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty-done', ['ids' => $ids, 'done' => true])
            ->assertOk();

        $submission = ScheduleSubmission::create([
            'department_id' => $fixture['it']->id,
            'semester_id' => $fixture['semester']->id,
            'revision_number' => 1,
            'status' => 'approved',
            'submitted_at' => now(),
        ]);
        $submission->sections()->attach([$fixture['section']->id], ['state' => 'included']);

        // VPAA-approved classes cannot be recalled; put the class in revision directly.
        $this->actingAs($fixture['itSecretary'])
            ->postJson("/api/departments/{$fixture['it']->id}/withdraw-submission", ['section_ids' => [$fixture['section']->id]])
            ->assertUnprocessable();
        Schedule::query()->whereIn('id', $ids)->update(['status' => 'revision']);
        $submission->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $submission->sections()->updateExistingPivot($fixture['section']->id, ['state' => 'withdrawn']);
        ApiCache::forgetGroups(['initial.data.schedules', 'initial.data']);

        $this->assertTrue(Schedule::query()->whereIn('id', $ids)->get()->every(
            fn (Schedule $schedule): bool => $schedule->status === 'revision'
                && $schedule->faculty_assignment_done
                && (int) $schedule->faculty_id === $fixture['casInstructor']->id,
        ));
        $sourceSchedules = collect(
            $this->actingAs($fixture['itSecretary'])->getJson('/api/initial-data')->assertOk()->json('schedules'),
        )->whereIn('id', $ids);
        $this->assertCount(3, $sourceSchedules);
        $this->assertTrue($sourceSchedules->every(
            fn (array $schedule): bool => (int) $schedule['faculty_id'] === $fixture['casInstructor']->id,
        ));

        $this->actingAs($fixture['itSecretary'])
            ->postJson('/api/schedules/batch', [
                'operations' => [],
                'delete_ids' => $ids,
                'replace_section_ids' => [$fixture['section']->id],
                'replace_semester_id' => $fixture['semester']->id,
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 1)
            ->assertJsonPath('cross_department_instructors_released', 1);

        $this->assertSame(0, Schedule::query()->whereIn('id', $ids)->count());
        $notice = DB::table('system_notifications')
            ->where('user_id', $fixture['casSecretary']->id)
            ->where('type', 'cross_department_instructors_released')
            ->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('reset its schedule', $notice->message);
        $this->assertStringContainsString('GEC 101 (BSIT 1A)', $notice->message);
        $this->assertFalse(DB::table('system_notifications')
            ->where('user_id', $fixture['itSecretary']->id)
            ->where('type', 'cross_department_instructors_released')
            ->exists());
    }

    public function test_a_load_past_the_ceiling_is_refused_before_the_batch_is_written(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);

        // Basic Load (21 maximum less 6 deload) plus the 3-unit overload allowance
        // already carried, so the 3-unit class would go past the ceiling.
        $this->carriedLoad($fixture, 18);

        $assignment = [[
            'schedule_ids' => $blocks->pluck('id')->all(),
            'faculty_id' => $fixture['casInstructor']->id,
        ]];

        $this->actingAs($fixture['casSecretary'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => $assignment, 'confirm_overload' => true])
            ->assertStatus(422)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.faculty_id', $fixture['casInstructor']->id)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.tier', 'beyond_ceiling')
            // Three meeting blocks are one class, so the load rises by the course's
            // units once rather than three times.
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.added_units', 3)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.projected_units', 21)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.assignment_label', 'GEC 101 — BSIT 1A');

        $this->assertSame([], $this->daysTaughtBy($fixture, $fixture['casInstructor']));
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
     * @return Collection<int, Schedule>
     */
    /**
     * The dashboard cannot see this work from its own schedule rows -- the class
     * lives in IT's section -- so the count travels on the status payload the
     * dashboard already fetches.
     */
    public function test_schedule_status_counts_delegated_classes_awaiting_an_instructor(): void
    {
        $fixture = $this->fixture();
        $this->meetingBlocks($fixture);

        // One class across three meeting rows, counted once.
        $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_pending', 1);

        // The college that owns the offering is not the one that has to staff it.
        $this->actingAs($fixture['itSecretary'])
            ->getJson("/api/departments/{$fixture['it']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_pending', 0);
    }

    /**
     * A course handed to a sibling program never leaves the college (BSED-ENG
     * Prof Ed taught by BEED), so "owned by another college" alone missed it and
     * the receiving program saw a notification with nothing pending.
     */
    public function test_schedule_status_counts_a_course_handed_to_a_sibling_program(): void
    {
        $fixture = $this->fixture();
        $sibling = Program::create([
            'department_id' => $fixture['it']->id,
            'code' => 'BSCS',
            'name' => 'Computer Science',
        ]);
        $fixture['gec']->update([
            'program_id' => $fixture['itProgram']->id,
            'teaching_department_id' => $fixture['it']->id,
            'teaching_program_id' => $sibling->id,
        ]);
        $this->meetingBlocks($fixture);
        $head = fn (int $programId) => $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $fixture['it']->id,
            'program_id' => $programId,
        ]));

        $this->actingAs($head($sibling->id))
            ->getJson("/api/departments/{$fixture['it']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_pending', 1);

        // The program that handed it over has nothing to staff.
        $this->actingAs($head($fixture['itProgram']->id))
            ->getJson("/api/departments/{$fixture['it']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_pending', 0);
    }

    public function test_an_assigned_delegated_class_is_no_longer_counted(): void
    {
        $fixture = $this->fixture();
        $blocks = $this->meetingBlocks($fixture);
        Schedule::whereIn('id', $blocks->pluck('id'))
            ->update(['faculty_id' => $fixture['casInstructor']->id]);

        $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_pending', 0);
    }

    private function meetingBlocks(array $fixture)
    {
        return collect(['Monday', 'Wednesday', 'Friday'])->map(fn (string $day): Schedule => Schedule::create([
            'semester_id' => $fixture['semester']->id,
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
            'program_id' => $fixture['casProgram']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);

        return Schedule::create(array_merge([
            'semester_id' => $fixture['semester']->id,
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

    /**
     * Course Assignment lists only courses placed in an active curriculum for the semester.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function placeInCurriculum(array $fixture): void
    {
        $curriculum = Curriculum::create([
            'name' => 'BSIT Curriculum', 'code' => 'BSIT-2026', 'effective_school_year' => '2026-2027',
            'department_id' => $fixture['it']->id, 'program_id' => $fixture['itProgram']->id, 'status' => 'active',
        ]);
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id, 'course_id' => $fixture['gec']->id, 'year_level' => 1, 'semester' => 1,
        ]);
    }

    /**
     * A third college that can take a returned section.
     *
     * @param  array<string, mixed>  $fixture
     * @return array{department: Departments, program: Program, instructor: Faculty, secretary: User}
     */
    private function thirdCollege(array $fixture): array
    {
        $department = Departments::create(['department_name' => 'Hospitality Management', 'department_code' => 'CHM']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSHM', 'name' => 'Hospitality Management']);

        return [
            'department' => $department,
            'program' => $program,
            'instructor' => $this->instructor('Host', $department->id),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /**
     * Another IT section taking GEC 101, one meeting.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function sectionClass(array $fixture, string $name): Schedule
    {
        $section = Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $fixture['it']->id, 'program_id' => $fixture['itProgram']->id,
            'semester_id' => $fixture['semester']->id, 'status' => 'active',
        ]);

        return Schedule::create([
            'semester_id' => $fixture['semester']->id, 'section_id' => $section->id, 'course_id' => $fixture['gec']->id,
            'room_id' => $fixture['room']->id, 'department_id' => $fixture['it']->id, 'day' => 'Tuesday',
            'start_time' => '10:00', 'end_time' => '11:30', 'mode' => 'on-site', 'status' => 'faculty_assignment',
        ]);
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
        // CapabilityMiddleware withholds every schedule capability except
        // schedule.view from a department that owns no program, and a section
        // cannot be scheduled until it belongs to one.
        $itProgram = Program::create([
            'department_id' => $it->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);
        $casProgram = Program::create([
            'department_id' => $cas->id,
            'code' => 'BACAS',
            'name' => 'Arts and Sciences',
        ]);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'it' => $it,
            'cas' => $cas,
            'semester' => $semester,
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
            'itProgram' => $itProgram,
            'casProgram' => $casProgram,
            'section' => Sections::create([
                'section_name' => 'BSIT 1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $it->id,
                'program_id' => $itProgram->id,
                'semester_id' => $semester->id,
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
            'itSecretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $it->id])),
            'casSecretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id])),
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
        ], $overrides));
    }
}
