<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
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
     * The heart of the feature: two year levels in the same term, on different
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
                'term_id' => $fixture['term']->id,
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
                'term_id' => $fixture['term']->id,
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
                'term_id' => $fixture['term']->id,
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

    /**
     * A curriculum is in service or out of it — deactivating is the only way out
     * short of archiving, and there is no third "draft" state to land in.
     */
    public function test_deactivating_a_curriculum_takes_it_out_of_service(): void
    {
        $fixture = $this->fixture();
        Sections::whereKey($fixture['year2']->id)->update(['curriculum_id' => $fixture['new']->id]);

        $this->actingAs($fixture['vpaa'])
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
                'term_id' => $fixture['term']->id,
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

        $this->actingAs($fixture['vpaa'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();

        $this->assertSame('deactivated', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_assigned_to_a_year_level_but_never_scheduled_can_be_archived(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['vpaa'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'archived'])
            ->assertOk();

        $this->assertSame('archived', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_with_a_plotted_schedule_cannot_be_deactivated(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);

        $this->actingAs($fixture['vpaa'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertStatus(422)
            ->assertJsonPath('blocking_sections.0.section_name', 'IT 2A');

        $this->assertSame('active', $fixture['old']->refresh()->status);
    }

    public function test_a_curriculum_with_a_plotted_schedule_cannot_be_archived(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year2'], $fixture['old'], $fixture['oldCourse']);

        // Curriculum ownership sits with the VPAA portal; a secretary can read
        // curricula but not retire one.
        $this->actingAs($fixture['vpaa'])
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

        $this->actingAs($fixture['vpaa'])
            ->patchJson('/api/curriculum/'.$fixture['old']->id.'/status', ['status' => 'deactivated'])
            ->assertOk();
    }

    /**
     * A schedule plotted from the new curriculum says nothing about the old one,
     * even though both cohorts sit in the same department and term.
     */
    public function test_a_schedule_from_another_curriculum_does_not_block_deactivation(): void
    {
        $fixture = $this->fixture();
        $this->plot($fixture, $fixture['year1'], $fixture['new'], $fixture['newCourse']);

        $this->actingAs($fixture['vpaa'])
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

        $this->actingAs($fixture['vpaa'])
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
                'term_id' => $fixture['term']->id,
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
     * Two curricula, two cohorts: year 1 on the new curriculum, year 2 on the
     * old one, each with one course placed only by its own curriculum.
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $term = Terms::create([
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
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'IT 101', 'building' => 'IT Building',
            'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $department->id,
        ]);

        return [
            'term' => $term,
            'department' => $department,
            'program' => $program,
            'old' => $old,
            'new' => $new,
            'oldCourse' => $oldCourse,
            'newCourse' => $newCourse,
            'year1' => $section('IT 1A', '1', $new),
            'year2' => $section('IT 2A', '2', $old),
            'secretary' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            ),
            'vpaa' => $this->grantCapabilities(
                User::factory()->create(['role' => 'vpaa', 'department_id' => $department->id]),
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

    private function place(Curriculum $curriculum, Course $course, int $yearLevel, int $semester = 1): void
    {
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => $yearLevel,
            'semester' => $semester,
        ]);
    }

    /**
     * The minimum that counts as a plotted schedule: one live schedules row for
     * this cohort, stamped with the curriculum it was generated from.
     */
    private function plot(array $fixture, Sections $section, Curriculum $curriculum, Course $course): Schedule
    {
        return Schedule::create([
            'term_id' => $fixture['term']->id,
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
                'term_id' => $fixture['term']->id,
                'department_id' => $fixture['department']->id,
                'year_level' => $yearLevel,
                'section_configs' => [['section_id' => $section->id]],
            ])
            ->assertOk();
    }
}
