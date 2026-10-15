<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A department mid-transition runs an old and a new curriculum in the same
 * semester: the incoming cohort follows the new one while the upper years finish
 * on the old. What each cohort follows is recorded on its sections, and every
 * scheduling stage resolves course placements through that.
 */
class MultipleCurriculaPerSemesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_department_can_run_several_active_curricula_at_once(): void
    {
        $fixture = $this->fixture();

        // Publishing a second curriculum used to demote the first — the model
        // enforced one active curriculum per department. It no longer does.
        $this->assertSame('active', $fixture['old']->refresh()->status);
        $this->assertSame('active', $fixture['new']->refresh()->status);
    }

    public function test_the_newest_active_curriculum_is_labelled_new_and_the_others_old(): void
    {
        $fixture = $this->fixture();

        $rows = collect(
            $this->actingAs($fixture['secretary'])
                ->getJson('/api/curriculum?department_id='.$fixture['department']->id)
                ->assertOk()
                ->json(),
        )->keyBy('id');

        $this->assertSame('new', $rows[$fixture['new']->id]['lifecycle']);
        $this->assertSame('New Curriculum', $rows[$fixture['new']->id]['lifecycle_label']);
        $this->assertSame('old', $rows[$fixture['old']->id]['lifecycle']);
        $this->assertSame('Old Curriculum', $rows[$fixture['old']->id]['lifecycle_label']);
    }

    /**
     * With only one curriculum there is nothing to compare against, so no
     * old/new badge is claimed — the status pill already says "active".
     */
    public function test_a_lone_active_curriculum_is_not_labelled_old_or_new(): void
    {
        $fixture = $this->fixture();
        $fixture['old']->update(['status' => 'archived']);

        $rows = collect(
            $this->actingAs($fixture['secretary'])
                ->getJson('/api/curriculum?department_id='.$fixture['department']->id)
                ->assertOk()
                ->json(),
        )->keyBy('id');

        $this->assertSame('only', $rows[$fixture['new']->id]['lifecycle']);
        $this->assertSame('archived', $rows[$fixture['old']->id]['lifecycle']);
    }

    /**
     * The heart of the feature: two year levels in the same semester, on different
     * curricula, each generated from its own course list.
     */
    public function test_each_year_level_generates_from_the_curriculum_its_sections_follow(): void
    {
        $fixture = $this->fixture();

        $firstYear = $this->generate($fixture['secretary'], $fixture, 1);
        $secondYear = $this->generate($fixture['secretary'], $fixture, 2);

        // Year 1 follows the new curriculum, which places NEW 101 at year 1.
        $this->assertSame(
            [$fixture['newCourse']->id],
            collect($firstYear->json('schedules'))->pluck('course_id')->unique()->values()->all(),
        );

        // Year 2 is still on the old curriculum, which places OLD 201 at year 2.
        // Under the old "first active curriculum wins" rule this cohort would
        // have been scheduled against the new curriculum instead.
        $this->assertSame(
            [$fixture['oldCourse']->id],
            collect($secondYear->json('schedules'))->pluck('course_id')->unique()->values()->all(),
        );
    }

    public function test_a_section_cannot_be_generated_until_its_curriculum_is_chosen(): void
    {
        $fixture = $this->fixture();

        // Two curricula are selectable, so nothing can be adopted automatically.
        Sections::whereKey($fixture['year1']->id)->update(['curriculum_id' => null]);

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/schedule-recommendations/year-level-preview', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 1,
                'section_configs' => [['section_id' => $fixture['year1']->id]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Section IT 1A is not assigned to a curriculum. Choose the curriculum this year level follows before generating a schedule.');
    }

    /**
     * A department that has published exactly one curriculum has nothing to
     * choose between, so sections created before it existed adopt it rather than
     * blocking generation on a question with one answer.
     */
    public function test_a_section_adopts_the_only_curriculum_its_department_runs(): void
    {
        $fixture = $this->fixture();
        $fixture['old']->update(['status' => 'archived']);
        Sections::whereKey($fixture['year1']->id)->update(['curriculum_id' => null]);

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/schedule-recommendations/year-level-preview', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 1,
                'section_configs' => [['section_id' => $fixture['year1']->id]],
            ])
            ->assertOk();

        $this->assertSame(
            (int) $fixture['new']->id,
            (int) $fixture['year1']->refresh()->curriculum_id,
        );
    }

    public function test_assigning_a_curriculum_moves_every_section_of_the_year_level(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections/assign-curriculum', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 2,
                'curriculum_id' => $fixture['new']->id,
            ])
            ->assertOk();

        $this->assertSame(
            (int) $fixture['new']->id,
            (int) $fixture['year2']->refresh()->curriculum_id,
        );
    }

    public function test_bulk_curriculum_assignment_rejects_the_whole_batch_when_a_section_is_locked(): void
    {
        $fixture = $this->fixture();
        $fixture['year1']->update(['year_level' => '2', 'curriculum_id' => $fixture['old']->id]);
        $schedule = $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);
        foreach (['submitted', 'approved_by_dean', 'conditionally_approved', 'approved', 'faculty_assignment', 'reassignment', 'finalized'] as $status) {
            $schedule->update(['status' => $status]);
            $this->actingAs($fixture['secretary'])->postJson('/api/sections/assign-curriculum', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 2,
                'curriculum_id' => $fixture['new']->id,
            ])->assertUnprocessable()->assertJsonPath('message', 'This section has classes at an approval stage. Recall the submission first, then try again.');
            $this->assertEquals($fixture['old']->id, $fixture['year1']->fresh()->curriculum_id);
            $this->assertEquals($fixture['old']->id, $fixture['year2']->fresh()->curriculum_id);
            $this->assertEquals($fixture['old']->id, $schedule->fresh()->curriculum_id);
        }
    }

    public function test_bulk_curriculum_assignment_allows_no_op_on_locked_sections_and_explicit_unlocked_targets(): void
    {
        $fixture = $this->fixture();
        $fixture['year1']->update(['year_level' => '2', 'curriculum_id' => $fixture['old']->id]);
        $schedule = $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);
        $schedule->update(['status' => 'approved_by_dean']);
        $payload = [
            'semester_id' => $fixture['semester']->id,
            'department_id' => $fixture['department']->id,
            'year_level' => 2,
            'curriculum_id' => $fixture['old']->id,
        ];
        $this->actingAs($fixture['secretary'])->postJson('/api/sections/assign-curriculum', $payload)->assertOk();
        $this->postJson('/api/sections/assign-curriculum', [
            ...$payload, 'curriculum_id' => $fixture['new']->id, 'section_ids' => [$fixture['year1']->id],
        ])->assertOk()->assertJsonPath('section_ids', [$fixture['year1']->id]);
        $this->assertEquals($fixture['new']->id, $fixture['year1']->fresh()->curriculum_id);
        $this->assertEquals($fixture['old']->id, $fixture['year2']->fresh()->curriculum_id);
        $this->assertEquals($fixture['old']->id, $schedule->fresh()->curriculum_id);
    }

    /**
     * A curriculum is in service or out of it — deactivating is the only way out
     * short of archiving, and there is no third "draft" state to land in.
     */
    public function test_deactivating_a_curriculum_takes_it_out_of_service(): void
    {
        $fixture = $this->fixture();
        Sections::whereKey($fixture['year2']->id)->update(['curriculum_id' => $fixture['new']->id]);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();

        $this->assertSame('deactivated', $fixture['old']->refresh()->status);
    }

    /** A deactivated curriculum is out of service, so no cohort may be pointed at it. */
    public function test_a_deactivated_curriculum_cannot_be_assigned_to_a_year_level(): void
    {
        $fixture = $this->fixture();
        Sections::whereKey($fixture['year2']->id)->update(['curriculum_id' => $fixture['new']->id]);
        $fixture['old']->update(['status' => 'deactivated']);

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections/assign-curriculum', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 2,
                'curriculum_id' => $fixture['old']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose an active curriculum belonging to this department.');
    }

    /**
     * The bar for "still in use" is a plotted schedule, not an assignment. A
     * cohort pointed at a curriculum nobody has generated against is repointed
     * with a dropdown, so it must not lock the curriculum in service.
     */
    public function test_a_curriculum_assigned_to_a_year_level_but_never_scheduled_can_be_deactivated(): void
    {
        $fixture = $this->fixture();

        // IT 2A follows the old curriculum, and no schedule exists for it.
        $this->assertSame($fixture['old']->id, $fixture['year2']->refresh()->curriculum_id);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();

        $this->assertSame('deactivated', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_assigned_to_a_year_level_but_never_scheduled_can_be_archived(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'archived'])
            ->assertOk();

        $this->assertSame('archived', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_with_a_plotted_schedule_cannot_be_deactivated(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertStatus(422)
            ->assertJsonPath('blocking_sections.0.section_name', 'IT 2A');

        $this->assertSame('active', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_with_a_plotted_schedule_cannot_be_archived(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonPath('blocking_sections.0.section_name', 'IT 2A');

        $this->assertSame('active', $fixture['old']->refresh()->status);
    }

    /**
     * A schedule that has been discarded is not a plotted schedule. Soft-deleted
     * rows must stop blocking, or a curriculum would be locked in service by a
     * timetable nobody can see any more.
     */
    public function test_a_deleted_schedule_no_longer_blocks_deactivation(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);
        $schedule->delete();

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();
    }

    /**
     * A schedule plotted from the new curriculum says nothing about the old one,
     * even though both cohorts sit in the same department and semester.
     */
    public function test_a_schedule_from_another_curriculum_does_not_block_deactivation(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year1'], $fixture['new'], $fixture['newCourse']);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();
    }

    /**
     * The list endpoint feeds the card's disabled-button state, so it has to
     * separate "assigned" from "scheduled" the same way the guard does.
     */
    public function test_the_list_reports_assigned_and_scheduled_cohorts_separately(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year1'], $fixture['new'], $fixture['newCourse']);

        $rows = collect(
            $this->actingAs($fixture['secretary'])
                ->getJson('/api/curriculum?department_id='.$fixture['department']->id)
                ->assertOk()
                ->json(),
        )->keyBy('id');

        $this->assertSame(1, $rows[$fixture['old']->id]['active_sections_count']);
        $this->assertSame(0, $rows[$fixture['old']->id]['scheduled_sections_count']);
        $this->assertSame(1, $rows[$fixture['new']->id]['active_sections_count']);
        $this->assertSame(1, $rows[$fixture['new']->id]['scheduled_sections_count']);
    }

    public function test_a_curriculum_no_cohort_follows_can_be_archived_while_active(): void
    {
        $fixture = $this->fixture();
        Sections::whereKey($fixture['year2']->id)->update(['curriculum_id' => $fixture['new']->id]);

        $this->actingAs($fixture['secretary'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'archived'])
            ->assertOk();

        $this->assertSame('archived', $fixture['old']->refresh()->status);
    }

    /**
     * Generation is configured against a course list that only means anything
     * relative to a curriculum, so a request built from a stale one is refused
     * rather than quietly generating courses the user never saw.
     */
    public function test_a_generation_request_built_from_a_stale_curriculum_is_refused(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/schedule-recommendations/year-level-preview', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => 1,
                'section_configs' => [[
                    'section_id' => $fixture['year1']->id,
                    'curriculum_id' => $fixture['old']->id,
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'curriculum_selection_stale');
    }

    /**
     * The same course sits at different year levels in the two curricula, and
     * the course list endpoint can only answer correctly once told which one.
     */
    public function test_the_course_list_reports_placements_from_the_requested_curriculum(): void
    {
        $fixture = $this->fixture();

        $shared = $this->course('SHARED 1', $fixture['department']->id);
        $this->place($fixture['old'], $shared, 4);
        $this->place($fixture['new'], $shared, 3);

        $fromOld = collect(
            $this->actingAs($fixture['secretary'])
                ->getJson('/api/courses?department_id='.$fixture['department']->id.'&curriculum_id='.$fixture['old']->id)
                ->assertOk()
                ->json(),
        )->firstWhere('course_code', 'SHARED 1');

        $fromNew = collect(
            $this->actingAs($fixture['secretary'])
                ->getJson('/api/courses?department_id='.$fixture['department']->id.'&curriculum_id='.$fixture['new']->id)
                ->assertOk()
                ->json(),
        )->firstWhere('course_code', 'SHARED 1');

        $this->assertSame('4', (string) $fromOld['year_level']);
        $this->assertSame('3', (string) $fromNew['year_level']);
    }

    /**
     * The validator used to read the department's first active curriculum, so a
     * cohort on the newer one was judged against the older placement.
     */
    public function test_the_validator_reads_placements_from_the_curriculum_the_section_follows(): void
    {
        $fixture = $this->fixture();
        $shared = $this->course('SHARED 1', $fixture['department']->id);
        $this->place($fixture['old'], $shared, 4);
        $this->place($fixture['new'], $shared, 3);

        $year3 = Sections::create([
            'section_name' => 'IT 3A', 'year_level' => '3', 'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'curriculum_id' => $fixture['new']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);

        $violations = app(RuleEngine::class)->validate([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $year3->id,
            'course_id' => $shared->id,
            'department_id' => $fixture['department']->id,
            'room_id' => Rooms::query()->firstOrFail()->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
        ]);

        $rules = array_column($violations, 'rule');
        $this->assertNotContains('subject_section_year_alignment', $rules);
        $this->assertNotContains('subject_section_semester_alignment', $rules);
        // The catalogue default on the course row is never overwritten.
        $this->assertSame('1', (string) $shared->refresh()->year_level);
    }

    /**
     * Two curricula, two cohorts: year 1 on the new curriculum, year 2 on the
     * old one, each with one course placed only by its own curriculum.
     *
     * @return array<string, mixed>
     */
    public function test_section_program_only_change_validates_retained_curriculum_and_schedules(): void
    {
        $f = $this->fixture();
        $other = Program::create(['department_id' => $f['department']->id, 'code' => 'OTHER']);
        $f['old']->update(['program_id' => $f['program']->id]);
        $this->actingAs($f['secretary'])->patchJson('/api/sections/'.$f['year2']->id, ['program_id' => $other->id])->assertUnprocessable();
        $f['old']->update(['program_id' => null]);
        $schedule = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $this->patchJson('/api/sections/'.$f['year2']->id, ['program_id' => $other->id])->assertUnprocessable();
        $this->patchJson('/api/sections/'.$f['year2']->id, ['year_level' => '1'])->assertUnprocessable();
        $this->patchJson('/api/sections/'.$f['year2']->id, ['curriculum_id' => $f['new']->id])->assertUnprocessable();
        $this->assertEquals($f['program']->id, $f['year2']->fresh()->program_id);
        $this->assertEquals($f['old']->id, $schedule->fresh()->curriculum_id);
        $this->patchJson('/api/sections/'.$f['year2']->id, ['section_name' => 'Renamed'])->assertOk();
        $schedule->delete();
        $this->patchJson('/api/sections/'.$f['year2']->id, ['program_id' => $other->id])->assertOk();
    }

    public function test_course_replacement_and_placement_moves_share_the_detach_guard(): void
    {
        $f = $this->fixture();
        $schedule = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $url = '/api/curriculum/'.$f['old']->id.'/courses';
        $this->actingAs($f['secretary']);
        $removal = $this->deleteJson($url.'/'.$f['oldCourse']->id)->assertUnprocessable();
        $this->postJson($url, ['course_id' => $f['newCourse']->id, 'replace_course_id' => $f['oldCourse']->id, 'year_level' => 2, 'semester' => 1])
            ->assertUnprocessable()->assertJsonPath('message', $removal->json('message'));
        $this->postJson($url, ['course_id' => $f['oldCourse']->id, 'year_level' => 1, 'semester' => 1])->assertUnprocessable();
        $this->postJson($url.'/batch', ['courses' => [
            ['course_id' => $f['newCourse']->id, 'year_level' => 2, 'semester' => 1],
            ['course_id' => $f['oldCourse']->id, 'year_level' => 1, 'semester' => 1],
        ]])->assertUnprocessable();
        $this->assertFalse($f['old']->courses()->whereKey($f['newCourse']->id)->exists());
        $this->assertDatabaseHas('curriculum_course', ['curriculum_id' => $f['old']->id, 'course_id' => $f['oldCourse']->id, 'year_level' => 2]);
        $this->postJson($url, ['course_id' => $f['oldCourse']->id, 'year_level' => 2, 'semester' => 1])->assertOk();
        $schedule->delete();
        $this->postJson($url, ['course_id' => $f['newCourse']->id, 'replace_course_id' => $f['oldCourse']->id, 'year_level' => 2, 'semester' => 1])->assertOk();
        $this->assertFalse($f['old']->courses()->whereKey($f['oldCourse']->id)->exists());
    }

    public function test_split_mutations_reject_locked_and_archived_parents(): void
    {
        $f = $this->fixture();
        $schedule = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $schedule->update(['status' => 'finalized']);
        $data = ['schedule_id' => $schedule->id, 'split_group_id' => 'test-group', 'meeting_type' => 'lecture', 'meeting_index' => 1];
        $this->actingAs($f['secretary'])->postJson('/api/schedule-splits', $data)->assertUnprocessable();
        $split = \App\Models\ScheduleSplit::create($data);
        $this->patchJson('/api/schedule-splits/'.$split->id, ['meeting_type' => 'laboratory'])->assertUnprocessable();
        $this->deleteJson('/api/schedule-splits/'.$split->id)->assertUnprocessable();
        $this->assertSame('lecture', $split->fresh()->meeting_type);
        $this->assertNotSoftDeleted($split);
        $schedule->delete();
        $this->postJson('/api/schedule-splits', $data)->assertUnprocessable();
    }

    public function test_valid_split_changes_record_history_and_invalid_changes_roll_back(): void
    {
        $f = $this->fixture();
        $schedule = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $schedule->update(['end_time' => '11:00:00']);
        $this->actingAs($f['secretary']);
        $response = $this->postJson('/api/schedule-splits', ['schedule_id' => $schedule->id, 'split_group_id' => 'test-group', 'meeting_type' => 'lecture', 'meeting_index' => 1])->assertCreated();
        $id = $response->json('id');
        $this->assertDatabaseHas('schedule_history_versions', ['source' => 'schedule_split']);
        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'schedule_updated']);
        $this->patchJson('/api/schedule-splits/'.$id, ['meeting_type' => 'laboratory'])->assertUnprocessable();
        $this->assertDatabaseHas('schedule_splits', ['id' => $id, 'meeting_type' => 'lecture']);
        $this->deleteJson('/api/schedule-splits/'.$id)->assertOk();
        $this->assertSoftDeleted('schedule_splits', ['id' => $id]);
    }

    public function test_split_reassignment_checks_both_parents_and_required_metadata_cannot_be_removed(): void
    {
        $f = $this->fixture();
        $source = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $target = $this->plot($f, $f['year1'], $f['new'], $f['newCourse']);
        $target->update(['status' => 'finalized']);
        $split = \App\Models\ScheduleSplit::create(['schedule_id' => $source->id, 'split_group_id' => 'required-group', 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $this->actingAs($f['secretary'])->patchJson('/api/schedule-splits/'.$split->id, ['schedule_id' => $target->id])->assertUnprocessable();
        $target->delete();
        $this->patchJson('/api/schedule-splits/'.$split->id, ['schedule_id' => $target->id])->assertUnprocessable();
        $source->update(['is_hybrid' => true]);
        $this->deleteJson('/api/schedule-splits/'.$split->id)->assertUnprocessable();
        $this->assertNotSoftDeleted($split);
        $this->assertEquals($source->id, $split->fresh()->schedule_id);
    }

    public function test_split_group_indexes_are_validated_and_success_invalidates_cached_payloads(): void
    {
        $f = $this->fixture();
        $first = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $second = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $first->update(['end_time' => '11:00:00']);
        $second->update(['day' => 'Tuesday', 'end_time' => '11:00:00']);
        \App\Models\ScheduleSplit::create(['schedule_id' => $first->id, 'split_group_id' => 'linked', 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $split = \App\Models\ScheduleSplit::create(['schedule_id' => $second->id, 'split_group_id' => 'linked', 'meeting_type' => 'lecture', 'meeting_index' => 2]);
        $this->actingAs($f['secretary'])->patchJson('/api/schedule-splits/'.$split->id, ['meeting_index' => 1])->assertUnprocessable();
        $this->assertEquals(2, $split->fresh()->meeting_index);
        $cacheKey = \App\Support\ApiCache::key('initial.data');
        $this->patchJson('/api/schedule-splits/'.$split->id, ['meeting_index' => 3])->assertOk();
        $this->assertNotSame($cacheKey, \App\Support\ApiCache::key('initial.data'));
    }

    public function test_room_changes_reject_incompatible_classes_and_allow_compatible_edits(): void
    {
        $f = $this->fixture();
        $class = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $class->update(['status' => 'finalized']);
        $room = Rooms::findOrFail($class->room_id);
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->putJson('/api/rooms/'.$room->id, ['room_type' => 'field'])->assertUnprocessable();
        $this->assertSame('lecture', $room->fresh()->room_type);
        $this->putJson('/api/rooms/'.$room->id, ['building' => 'Renamed'])->assertOk();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTHER']);
        $this->putJson('/api/rooms/'.$room->id, ['department_id' => $other->id])->assertUnprocessable();
        $this->assertEquals($f['department']->id, $room->fresh()->department_id);
        $this->putJson('/api/rooms/'.$room->id, ['room_type' => 'laboratory', 'allow_lecture_usage' => true])->assertOk();
        $this->putJson('/api/rooms/'.$room->id, ['allow_lecture_usage' => false])->assertUnprocessable();
        $this->assertTrue($room->fresh()->allow_lecture_usage);
    }

    public function test_schedule_restoration_uses_current_room_and_curriculum_rules(): void
    {
        $f = $this->fixture();
        $class = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $class->update(['end_time' => '11:00:00']);
        $room = Rooms::findOrFail($class->room_id);
        $class->delete();
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $room->update(['status' => 'not available']);
        $url = '/api/archives/schedules/'.$class->id.'/restore';
        $this->postJson($url)->assertUnprocessable();
        $this->assertSoftDeleted($class);
        $room->update(['status' => 'available']);
        $f['old']->courses()->detach($f['oldCourse']->id);
        $this->postJson($url)->assertUnprocessable();
        $this->assertSoftDeleted($class);
        $this->place($f['old'], $f['oldCourse'], 2);
        $this->postJson($url)->assertOk();
        $this->assertNotSoftDeleted($class);
    }

    public function test_archiving_programs_and_departments_reports_live_dependencies(): void
    {
        $f = $this->fixture();
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->deleteJson('/api/programs/'.$f['program']->id)->assertUnprocessable()->assertJsonFragment(['sections']);
        $isolated = Departments::create(['department_name' => 'Isolated', 'department_code' => 'ISO']);
        $course = $this->course('ISO101', $isolated->id);
        $this->deleteJson('/api/departments/'.$isolated->id)->assertUnprocessable()->assertJsonPath('blocking_relations.0', 'courses');
        $course->delete();
        $this->deleteJson('/api/departments/'.$isolated->id)->assertOk();
        $program = Program::create(['department_id' => $f['department']->id, 'code' => 'CUR']);
        $f['old']->update(['program_id' => $program->id]);
        $this->deleteJson('/api/programs/'.$program->id)->assertUnprocessable()->assertJsonFragment(['curricula']);
        $f['old']->update(['status' => 'archived']);
        $this->deleteJson('/api/programs/'.$program->id)->assertOk();
    }

    public function test_remaining_authoring_routes_reject_archived_parents(): void
    {
        $f = $this->fixture();
        Program::create(['department_id' => $f['department']->id, 'code' => 'LIVE']);
        $f['program']->delete();
        $this->actingAs($f['secretary']);
        $this->postJson('/api/curriculum', ['name' => 'Invalid', 'code' => 'INVALID', 'effective_school_year' => '2026-2027', 'program_id' => $f['program']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->postJson('/api/sections', ['section_name' => 'INVALID', 'year_level' => '1', 'department_id' => $f['department']->id, 'program_id' => $f['program']->id, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $f['department']->delete();
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->postJson('/api/rooms', ['room_code' => 'INVALID', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_required_meeting_groups_restore_atomically(): void
    {
        $f = $this->fixture();
        $first = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $second = $this->plot($f, $f['year2'], $f['old'], $f['oldCourse']);
        $first->update(['preferred_pattern' => 'consecutive:2', 'split_group_id' => 'restore-group', 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $second->update(['preferred_pattern' => 'consecutive:2', 'day' => 'Tuesday', 'split_group_id' => 'restore-group', 'meeting_type' => 'lecture', 'meeting_index' => 2]);
        $first->delete();
        $second->delete();
        $room = Rooms::findOrFail($first->room_id);
        $room->update(['status' => 'not available']);
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->postJson('/api/archives/schedules/'.$first->id.'/restore')->assertUnprocessable();
        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'record_restored']);
        $room->update(['status' => 'available']);
        $this->postJson('/api/archives/schedules/'.$first->id.'/restore')->assertOk();
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
        $this->assertDatabaseCount('schedule_splits', 2);
    }

    public function test_room_ownership_change_preserves_program_allocations(): void
    {
        $f = $this->fixture();
        $room = Rooms::firstOrFail();
        $room->forceFill(['home_program_id' => $f['program']->id])->save();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTHER']);
        $this->actingAs(User::factory()->create(['role' => 'vpaa']))->putJson('/api/rooms/'.$room->id, ['department_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertEquals($f['program']->id, $room->fresh()->home_program_id);
        $this->assertEquals($f['department']->id, $room->department_id);
    }

    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        $old = Curriculum::create([
            'name' => 'BSIT 2020', 'code' => 'BSIT-2020',
            'department_id' => $department->id,
            'effective_school_year' => '2020-2021',
            'status' => 'active',
        ]);
        $new = Curriculum::create([
            'name' => 'BSIT 2026', 'code' => 'BSIT-2026',
            'department_id' => $department->id,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $newCourse = $this->course('NEW 101', $department->id);
        $oldCourse = $this->course('OLD 201', $department->id);
        $this->place($new, $newCourse, 1);
        $this->place($old, $oldCourse, 2);

        $section = fn (string $name, string $yearLevel, Curriculum $curriculum) => Sections::create([
            'section_name' => $name,
            'year_level' => $yearLevel,
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'IT 101', 'building' => 'IT Building',
            'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $department->id,
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'program' => $program,
            'old' => $old,
            'new' => $new,
            'oldCourse' => $oldCourse,
            'newCourse' => $newCourse,
            'year1' => $section('IT 1A', '1', $new),
            'year2' => $section('IT 2A', '2', $old),
            // Curriculum authoring sits with the secretary, so it holds
            // `curriculum.manage` on top of the workspace capabilities. Retiring
            // a curriculum is exercised through this account because the VPAA no
            // longer holds curriculum write access at all.
            'secretary' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
                [...self::SCHEDULE_WORKSPACE_CAPABILITIES, 'curriculum.manage'],
            ),
        ];
    }

    private function course(string $code, int $departmentId): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code,
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            // The stored year level is a catalogue default only; the curriculum
            // placement is what scheduling reads.
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $departmentId, 'status' => 'active',
        ]);
    }

    private function place(Curriculum $curriculum, Course $course, int $yearLevel, int $period = 1): void
    {
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => $yearLevel,
            'semester' => $period,
        ]);
    }

    /**
     * The minimum that counts as a plotted schedule: one live schedules row for
     * this cohort, stamped with the curriculum it was generated from.
     */
    private function plot(array $fixture, Sections $section, Curriculum $curriculum, Course $course): Schedule
    {
        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'room_id' => Rooms::query()->firstOrFail()->id,
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'day' => 'Monday',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);
    }

    private function generate(User $actor, array $fixture, int $yearLevel)
    {
        $section = $yearLevel === 1 ? $fixture['year1'] : $fixture['year2'];

        return $this->actingAs($actor)
            ->postJson('/api/schedule-recommendations/year-level-preview', [
                'semester_id' => $fixture['semester']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => $yearLevel,
                'section_configs' => [['section_id' => $section->id]],
            ])
            ->assertOk();
    }
}
