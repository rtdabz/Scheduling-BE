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
use App\Services\Scheduling\Generation\GenerationCourseSelection;
use App\Services\Scheduling\Schedule\DelegatedCourseSchedules;
use App\Services\Scheduling\Schedule\ProgramCourseVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramDelegatedCourseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function headStates(): array
    {
        return [
            'both headed' => [true, true, true],
            'source unheaded' => [false, true, false],
            'target unheaded' => [true, false, false],
            'both unheaded' => [false, false, false],
        ];
    }

    #[DataProvider('headStates')]
    public function test_course_lists_keep_the_two_head_exception_but_staffing_follows_the_receiving_program(bool $sourceActive, bool $targetActive, bool $hidden): void
    {
        $f = $this->fixture($sourceActive, $targetActive);
        foreach ($sourceActive ? [$f['sourceHead'], $f['office']] : [$f['office']] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/courses?all=true')->assertOk();
            $this->assertSame(! $hidden, collect($response->json())->contains('id', $f['handed']->id));
            $data = $this->actingAs($actor)->getJson('/api/initial-data')->assertOk();
            $this->assertSame(! $hidden, collect($data->json('courses'))->contains('id', $f['handed']->id));
            $row = collect($data->json('schedules'))->firstWhere('id', $f['row']->id);
            $this->assertNotNull($row, 'Saved timetable and occupancy facts must remain available.');
            $this->assertSame($actor->role !== 'program_head' && ! $targetActive, $row['instructor_assignment_visible']);
            if ($hidden) {
                $this->assertTrue(collect($data->json('schedule_course_references'))->firstWhere('id', $f['handed']->id)['delegated_only']);
            }
        }
        // The original ownership OR branch previously kept a handed-off course in its head's list.
        foreach ($sourceActive ? [$f['sourceHead'], $f['office']] : [$f['office']] as $actor) {
            $response = $this->actingAs($actor)->getJson('/api/instructor-assignments')->assertOk();
            $this->assertSame($actor->role !== 'program_head' && ! $targetActive, collect($response->json('schedules'))->contains('id', $f['row']->id));
        }
        if ($targetActive) {
            $this->actingAs($f['targetHead'])->getJson('/api/instructor-assignments')->assertOk()
                ->assertJsonPath('schedules.0.id', $f['row']->id);
        }
    }

    public function test_head_changes_refresh_visibility_even_when_payloads_are_cached(): void
    {
        $f = $this->fixture();
        foreach ([true, false, true] as $active) {
            $f['targetHead']->update(['is_active' => $active]);
            $data = $this->actingAs($f['sourceHead'])->getJson('/api/initial-data')->assertOk();
            $this->assertSame(! $active, collect($data->json('courses'))->contains('id', $f['handed']->id));
            $rows = $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk()->json('schedules');
            $this->assertSame(! $active, collect($rows)->contains('id', $f['row']->id));
        }
    }

    public function test_an_unknown_source_cannot_expose_a_receiving_programs_work_to_the_office(): void
    {
        $f = $this->fixture(false);
        $f['handed']->update(['program_id' => null, 'teaching_source_program_id' => null]);
        // The source course-list exception remains separate from receiving-program access.
        $this->assertSame([], $this->hidden($f['office']));
        $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk()
            ->assertJsonCount(0, 'schedules')->assertJsonCount(0, 'subjects')->assertJsonCount(0, 'departments');
        $this->actingAs($f['office'])->getJson('/api/departments/'.$f['department']->id.'/schedule-status')->assertOk()
            ->assertJsonPath('cross_department_pending', 0);
        $this->actingAs($f['targetHead'])->getJson('/api/instructor-assignments')->assertOk()
            ->assertJsonPath('schedules.0.id', $f['row']->id);
        $this->actingAs($f['targetHead'])->getJson('/api/departments/'.$f['department']->id.'/schedule-status')->assertOk()
            ->assertJsonPath('cross_department_pending', 1);

        foreach (['secretary', 'director'] as $role) {
            $f['office']->update(['role' => $role]);
            $this->actingAs($f['office'])->patchJson('/api/instructor-assignments/'.$f['row']->id, ['faculty_id' => $f['targetFaculty']->id])->assertForbidden();
            $this->actingAs($f['office'])->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['row']->id], 'faculty_id' => $f['targetFaculty']->id,
            ]]])->assertForbidden();
            $this->actingAs($f['office'])->patchJson('/api/schedules/batch-faculty-done', ['ids' => [$f['row']->id], 'done' => true])->assertForbidden();
            $this->actingAs($f['office'])->postJson('/api/instructor-assignments/return-sections', ['schedule_ids' => [$f['row']->id]])->assertForbidden();
            $this->actingAs($f['office'])->getJson('/api/instructor-assignments/'.$f['row']->id.'/recommendations')->assertForbidden();
        }
        $this->actingAs($f['targetHead'])->patchJson('/api/instructor-assignments/'.$f['row']->id, ['faculty_id' => $f['targetFaculty']->id])->assertOk();
        $this->actingAs($f['office'])->deleteJson('/api/instructor-assignments/sections/'.$f['sourceSection']->id)->assertUnprocessable();
        $this->actingAs($f['office'])->postJson('/api/instructor-assignments/clear', ['section_ids' => [$f['sourceSection']->id]])->assertUnprocessable();
        $this->assertSame($f['targetFaculty']->id, (int) $f['row']->fresh()->faculty_id);
        $this->assertFalse($f['row']->fresh()->faculty_assignment_done);
    }

    public function test_incoming_external_classes_respect_the_receiving_program_and_office_fallback(): void
    {
        $f = $this->fixture();
        $external = Departments::create(['department_name' => 'Technology', 'department_code' => 'CIT']);
        $f['source']->update(['department_id' => $external->id]);
        $f['sourceHead']->update(['department_id' => $external->id]);
        $f['sourceSection']->update(['department_id' => $external->id]);
        $f['handed']->update(['department_id' => $external->id]);
        $f['row']->update(['department_id' => $external->id]);
        foreach ([true, false, true] as $headed) {
            $f['targetHead']->update(['is_active' => $headed]);
            $response = $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk();
            $response->assertJsonCount($headed ? 0 : 1, 'schedules')->assertJsonCount($headed ? 0 : 1, 'departments');
            $this->actingAs($f['office'])->getJson('/api/departments/'.$f['department']->id.'/schedule-status')->assertOk()
                ->assertJsonPath('cross_department_pending', $headed ? 0 : 1);
        }
        $f['handed']->update(['teaching_program_id' => null]);
        $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(1, 'schedules');
    }

    public function test_returned_classes_follow_their_current_receiving_program_independently(): void
    {
        $f = $this->fixture();
        $returned = $f['row']->replicate();
        $returned->fill(['day' => 'Tuesday', 'delegation_returned_from_department_id' => $f['department']->id,
            'section_teaching_department_id' => $f['department']->id, 'section_teaching_program_id' => $f['source']->id])->save();
        $this->actingAs($f['sourceHead'])->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.id', $returned->id);
        $this->actingAs($f['targetHead'])->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.id', $f['row']->id);
        $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(0, 'schedules');
        $this->actingAs($f['office'])->patchJson('/api/instructor-assignments/'.$returned->id, ['faculty_id' => null])->assertForbidden();
        $f['sourceHead']->update(['is_active' => false]);
        $this->actingAs($f['office'])->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.id', $returned->id);
    }

    public function test_receiving_access_uses_each_rows_semester_even_with_cached_course_metadata(): void
    {
        $f = $this->fixture();
        $other = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $old = $f['row']->replicate();
        $old->semester_id = $other->id;
        $old->save();
        // No delegation exists for the other semester, despite the course's current projection.
        $request = Request::create('/');
        $request->setUserResolver(fn () => $f['office']);
        $this->assertSame([$f['row']->id], app(ProgramCourseVisibility::class)->hiddenInstructorScheduleIds($request, [$f['row'], $old]));
    }

    public function test_paginated_initial_data_filters_before_totals_and_keeps_optional_sections_optional(): void
    {
        $f = $this->fixture();
        $url = '/api/initial-data?include=courses,schedules&per_page=1';
        $data = $this->actingAs($f['sourceHead'])->getJson($url)->assertOk();
        $data->assertJsonPath('courses.meta.total', 1)->assertJsonPath('courses.data.0.id', $f['plain']->id)
            ->assertJsonPath('schedules.data.0.instructor_assignment_visible', false);
        $f['targetHead']->update(['is_active' => false]);
        $this->actingAs($f['sourceHead'])->getJson($url)->assertOk()->assertJsonPath('courses.meta.total', 2);
        $this->actingAs($f['sourceHead'])->getJson('/api/initial-data?include=faculties')->assertOk()
            ->assertJsonMissingPath('courses')->assertJsonMissingPath('schedules');
    }

    public function test_archived_or_wrong_department_heads_do_not_activate_the_restriction(): void
    {
        $f = $this->fixture();
        $f['targetHead']->delete();
        $this->assertSame([], $this->hidden($f['sourceHead']));
        $f['targetHead']->restore();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTHER']);
        $f['targetHead']->update(['department_id' => $other->id]);
        $this->assertSame([], $this->hidden($f['sourceHead']));
    }

    public function test_program_heads_do_not_get_unrelated_program_courses_through_catalog_flags(): void
    {
        $f = $this->fixture();
        foreach (['all=true', 'catalog=true', ''] as $query) {
            $listed = $this->actingAs($f['sourceHead'])->getJson('/api/courses?'.$query)->assertOk()->json();
            $this->assertNotContains($f['targetCourse']->id, array_column($listed, 'id'));
            $this->assertContains($f['plain']->id, array_column($listed, 'id'));
        }
        $this->actingAs($f['sourceHead'])->getJson('/api/courses/'.$f['handed']->id)->assertForbidden();
    }

    public function test_another_programs_shared_room_rows_are_conflict_facts_and_not_instructor_choices(): void
    {
        $f = $this->fixture();
        $room = Rooms::create(['room_code' => 'COMMON', 'room_type' => 'lecture', 'department_id' => $f['department']->id, 'status' => 'available']);
        $section = Sections::query()->where('program_id', $f['target']->id)->firstOrFail();
        $row = Schedule::create(['semester_id' => $f['semester']->id, 'department_id' => $f['department']->id,
            'program_id' => $f['target']->id, 'section_id' => $section->id, 'course_id' => $f['targetCourse']->id,
            'room_id' => $room->id, 'day' => 'Tuesday', 'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'on-site', 'status' => 'faculty_assignment']);
        $data = $this->actingAs($f['sourceHead'])->getJson('/api/initial-data')->assertOk();
        $visible = collect($data->json('schedules'))->firstWhere('id', $row->id);
        $this->assertNotNull($visible);
        $this->assertFalse($visible['instructor_assignment_visible']);
        $data = $this->actingAs($f['targetHead'])->getJson('/api/initial-data')->assertOk();
        $this->assertTrue(collect($data->json('schedules'))->firstWhere('id', $row->id)['instructor_assignment_visible']);
    }

    public function test_source_instructor_mutation_endpoints_reject_hidden_course_ids_atomically(): void
    {
        $f = $this->fixture();
        foreach ([$f['sourceHead'], $f['office']] as $actor) {
            $this->actingAs($actor)->patchJson('/api/instructor-assignments/'.$f['row']->id, ['faculty_id' => null])->assertForbidden();
            $this->actingAs($actor)->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['row']->id], 'faculty_id' => null,
            ]]])->assertForbidden();
            $this->actingAs($actor)->patchJson('/api/schedules/'.$f['row']->id, ['faculty_id' => $f['targetFaculty']->id])->assertForbidden();
        }
        $this->assertNull($f['row']->fresh()->faculty_id);
        $this->actingAs($f['targetHead'])->patchJson('/api/instructor-assignments/'.$f['row']->id, [
            'faculty_id' => $f['targetFaculty']->id,
        ])->assertOk();
        $this->assertSame($f['targetFaculty']->id, (int) $f['row']->fresh()->faculty_id);
    }

    public function test_a_source_cannot_post_a_hidden_course_for_manual_or_batch_scheduling(): void
    {
        $f = $this->fixture();
        $operation = $f['row']->only(['semester_id', 'section_id', 'course_id', 'department_id', 'program_id']);
        $operation += ['day' => 'Wednesday', 'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'online'];
        $this->actingAs($f['sourceHead'])->postJson('/api/schedules', $operation)->assertUnprocessable()
            ->assertJsonPath('message', ProgramCourseVisibility::ASSIGNED_ELSEWHERE);
        $this->actingAs($f['sourceHead'])->postJson('/api/schedules/batch', ['operations' => [$operation]])->assertUnprocessable()
            ->assertJsonPath('message', ProgramCourseVisibility::ASSIGNED_ELSEWHERE);
        $this->assertSame(1, Schedule::query()->count());
    }

    public function test_generation_defaults_exclude_handed_courses_and_explicit_selection_is_rejected(): void
    {
        $f = $this->fixture();
        $selection = app(GenerationCourseSelection::class);
        $this->assertSame([$f['plain']->id], $selection->resolveCourseIds($f['sourceSection'], null));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ProgramCourseVisibility::ASSIGNED_ELSEWHERE);
        $selection->resolveCourseIds($f['sourceSection'], [$f['plain']->id, $f['handed']->id]);
    }

    public function test_returned_sections_keep_their_independent_instructor_assignment(): void
    {
        $f = $this->fixture();
        $f['row']->update([
            'delegation_returned_from_department_id' => $f['department']->id,
            'section_teaching_department_id' => $f['department']->id,
            'section_teaching_program_id' => $f['source']->id,
        ]);
        $data = $this->actingAs($f['sourceHead'])->getJson('/api/initial-data')->assertOk();
        $this->assertTrue(collect($data->json('schedules'))->firstWhere('id', $f['row']->id)['instructor_assignment_visible']);
        $this->actingAs($f['sourceHead'])->getJson('/api/instructor-assignments')->assertOk()
            ->assertJsonPath('schedules.0.id', $f['row']->id);
    }

    public function test_course_assignment_stays_visible_and_removing_a_handoff_restores_selection(): void
    {
        $f = $this->fixture();
        $data = $this->actingAs($f['sourceHead'])->getJson('/api/course-teaching-assignments')->assertOk();
        $this->assertContains($f['handed']->id, array_column($data->json('courses'), 'id'));
        $this->actingAs($f['sourceHead'])->deleteJson('/api/course-teaching-assignments/'.$f['handed']->id)->assertOk();
        $this->assertSame([], $this->hidden($f['sourceHead']));
        $data = $this->actingAs($f['sourceHead'])->getJson('/api/initial-data')->assertOk();
        $this->assertContains($f['handed']->id, array_column($data->json('courses'), 'id'));
    }

    public function test_only_the_requested_semesters_delegation_is_used(): void
    {
        $f = $this->fixture();
        $other = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $f['sourceHead']);
        $policy = app(ProgramCourseVisibility::class);
        $this->assertSame([$f['handed']->id], $policy->hiddenCourseIds($request, $f['semester']->id));
        $this->assertSame([], $policy->hiddenCourseIds($request, $other->id));
    }

    public function test_shared_minors_use_the_recorded_source_and_department_only_delegations_keep_visibility(): void
    {
        $f = $this->fixture();
        $f['handed']->update(['program_id' => null]);
        $this->assertSame([$f['handed']->id], $this->hidden($f['sourceHead']));
        $f['handed']->update(['teaching_program_id' => null]);
        $this->assertSame([], $this->hidden($f['sourceHead']));
        $f['handed']->update(['teaching_program_id' => $f['source']->id]);
        $this->assertSame([], $this->hidden($f['sourceHead']));
        $f['handed']->update(['teaching_program_id' => $f['target']->id, 'teaching_source_program_id' => null]);
        $this->assertSame([], $this->hidden($f['sourceHead']));
        $f['handed']->update(['program_id' => $f['source']->id]);
        $this->assertSame([$f['handed']->id], $this->hidden($f['sourceHead']));
    }

    public function test_receiving_program_sections_keep_their_existing_scheduling_rules(): void
    {
        $f = $this->fixture();
        $message = app(DelegatedCourseSchedules::class)->refusal($f['handed']->id, $f['department']->id, $f['semester']->id, $f['target']->id);
        $this->assertNull($message, 'A different receiving-program section is not a duplicate of the retained source class.');
        $this->assertNull(app(DelegatedCourseSchedules::class)->refusal($f['plain']->id, $f['department']->id, $f['semester']->id, $f['source']->id));
    }

    public function test_director_accounts_follow_the_source_office_visibility_rule(): void
    {
        $f = $this->fixture();
        $f['office']->update(['role' => 'director']);
        $this->assertSame([$f['handed']->id], $this->hidden($f['office']));
        $listed = $this->actingAs($f['office'])->getJson('/api/courses?all=true')->assertOk()->json();
        $this->assertNotContains($f['handed']->id, array_column($listed, 'id'));
        $this->actingAs($f['office'])->patchJson('/api/instructor-assignments/'.$f['row']->id, ['faculty_id' => null])->assertForbidden();
    }

    public function test_cross_department_program_handoffs_have_the_same_source_visibility(): void
    {
        $f = $this->fixture();
        $department = Departments::create(['department_name' => 'Receiving', 'department_code' => 'REC']);
        $f['target']->update(['department_id' => $department->id]);
        $f['targetHead']->update(['department_id' => $department->id]);
        $f['handed']->update(['teaching_department_id' => $department->id]);
        $this->assertSame([$f['handed']->id], $this->hidden($f['sourceHead']));
        $this->assertSame([$f['handed']->id], $this->hidden($f['office']));
        $this->assertSame([], $this->hidden($f['targetHead']));
    }

    public function test_changing_an_existing_row_cannot_bypass_the_hidden_course_selection(): void
    {
        $f = $this->fixture();
        $f['row']->update(['course_id' => $f['plain']->id, 'status' => 'draft']);
        $this->actingAs($f['sourceHead'])->patchJson('/api/schedules/'.$f['row']->id, ['course_id' => $f['handed']->id])->assertUnprocessable()
            ->assertJsonPath('message', ProgramCourseVisibility::ASSIGNED_ELSEWHERE);
        $this->assertSame($f['plain']->id, (int) $f['row']->fresh()->course_id);
    }

    public function test_sociology_can_staff_only_its_courses_in_bscd_sections_and_mixed_writes_are_atomic(): void
    {
        $f = $this->fixture();
        $f['plain']->update([
            'course_code' => 'SCC 1', 'course_category' => 'major',
            'teaching_department_id' => $f['department']->id, 'teaching_program_id' => $f['source']->id,
        ]);
        $f['targetCourse']->update(['course_category' => 'major']);
        $sociologyFaculty = Faculty::create([
            'first_name' => 'Sociology', 'last_name' => 'Instructor', 'department_id' => $f['department']->id,
            'program_id' => $f['source']->id, 'employment_type' => 'full-time', 'max_units' => 21, 'status' => 'active',
        ]);
        $f['row']->update(['course_id' => $f['plain']->id, 'faculty_id' => $sociologyFaculty->id]);
        $section = Sections::query()->where('program_id', $f['target']->id)->firstOrFail();
        $incoming = $f['row']->replicate()->fill(['program_id' => $f['target']->id, 'section_id' => $section->id]);
        $incoming->save();
        $bscd = $incoming->replicate()->fill(['course_id' => $f['targetCourse']->id, 'faculty_id' => $f['targetFaculty']->id]);
        $bscd->save();

        $listed = $this->actingAs($f['sourceHead'])->getJson('/api/instructor-assignments')->assertOk();
        $this->assertEqualsCanonicalizing([$f['row']->id, $incoming->id], array_column($listed->json('schedules'), 'id'));
        $this->assertSame([$f['plain']->id], array_column($listed->json('subjects'), 'id'));
        $this->assertSame((int) $f['target']->id, (int) collect($listed->json('schedules'))->firstWhere('id', $incoming->id)['section']['program_id']);

        $data = $this->actingAs($f['sourceHead'])->getJson('/api/initial-data')->assertOk();
        $this->assertTrue(collect($data->json('schedules'))->firstWhere('id', $incoming->id)['instructor_assignment_visible']);
        $this->assertNull(collect($data->json('schedules'))->firstWhere('id', $bscd->id));
        $this->actingAs($f['sourceHead'])->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [['schedule_ids' => [$incoming->id, $bscd->id], 'faculty_id' => null]],
        ])->assertForbidden();
        $this->assertSame($sociologyFaculty->id, (int) $incoming->fresh()->faculty_id);
        $this->assertSame($f['targetFaculty']->id, (int) $bscd->fresh()->faculty_id);
        $this->actingAs($f['sourceHead'])->patchJson('/api/instructor-assignments/'.$bscd->id, ['faculty_id' => null])->assertForbidden();
        $this->actingAs($f['sourceHead'])->patchJson('/api/instructor-assignments/'.$incoming->id, ['faculty_id' => null])->assertOk();
        $this->assertNull($incoming->fresh()->faculty_id);
        $this->assertSame($f['targetFaculty']->id, (int) $bscd->fresh()->faculty_id);
    }

    private function hidden(User $actor): array
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);

        return app(ProgramCourseVisibility::class)->hiddenCourseIds($request);
    }

    private function fixture(bool $sourceActive = true, bool $targetActive = true): array
    {
        $department = Departments::create(['department_name' => 'Arts and Sciences', 'department_code' => 'CAS']);
        $source = Program::create(['department_id' => $department->id, 'code' => 'BAS', 'name' => 'BAS']);
        $target = Program::create(['department_id' => $department->id, 'code' => 'BSCD', 'name' => 'BSCD']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $sourceHead = $this->grantCapabilities(User::factory()->create(['role' => 'program_head', 'department_id' => $department->id, 'program_id' => $source->id, 'is_active' => $sourceActive]));
        $targetHead = $this->grantCapabilities(User::factory()->create(['role' => 'program_head', 'department_id' => $department->id, 'program_id' => $target->id, 'is_active' => $targetActive]));
        $office = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $createCourse = fn (string $code, ?int $programId) => Course::create([
            'department_id' => $department->id, 'program_id' => $programId, 'course_code' => $code, 'course_name' => $code,
            'course_category' => 'minor', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'year_level' => '1', 'semester' => '1st', 'room_type_required' => 'lecture', 'status' => 'active',
        ]);
        $handed = $createCourse('GEC 1', $source->id);
        $plain = $createCourse('GEC 2', $source->id);
        $targetCourse = $createCourse('GEC 3', $target->id);
        $handed->update(['teaching_department_id' => $department->id, 'teaching_program_id' => $target->id, 'teaching_source_program_id' => $source->id]);
        foreach ([$source, $target] as $program) {
            $curriculum = Curriculum::create(['department_id' => $department->id, 'program_id' => $program->id, 'name' => $program->code, 'code' => $program->code, 'status' => 'active', 'effective_school_year' => '2026-2027']);
            foreach ($program->id === $source->id ? [$handed, $plain] : [$targetCourse] as $course) {
                $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            }
            $section = Sections::create(['department_id' => $department->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id,
                'section_name' => $program->code.' 1A', 'year_level' => '1', 'semester' => '1st', 'semester_id' => $semester->id, 'status' => 'active']);
            if ($program->id === $source->id) {
                $sourceSection = $section;
            }
        }
        $row = Schedule::create(['semester_id' => $semester->id, 'department_id' => $department->id, 'program_id' => $source->id,
            'section_id' => $sourceSection->id, 'course_id' => $handed->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'online', 'status' => 'faculty_assignment']);
        $targetFaculty = Faculty::create(['first_name' => 'Target', 'last_name' => 'Instructor', 'department_id' => $department->id, 'program_id' => $target->id, 'employment_type' => 'full-time', 'max_units' => 21, 'status' => 'active']);

        return compact('department', 'source', 'target', 'semester', 'sourceHead', 'targetHead', 'office', 'handed', 'plain', 'targetCourse', 'sourceSection', 'row', 'targetFaculty');
    }
}
