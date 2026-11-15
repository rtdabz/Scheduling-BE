<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\ScheduleGenerationDraft;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleGenerationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_progress_is_saved_and_restored_without_touching_schedules(): void
    {
        [$user, $department, $semester] = $this->fixture();

        $this->actingAs($user)
            ->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 7))
            ->assertOk();

        $this->actingAs($user)
            ->getJson("/api/schedule-generation-draft?department_id={$department->id}&semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('revision', 7)
            ->assertJsonPath('payload.step', 2)
            ->assertJsonPath('payload.year_level', 1)
            ->assertJsonPath('payload.configs.12.courseIds', ['5', '6'])
            ->assertJsonPath('payload.run_id', 'run-abc');

        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_an_older_revision_never_overwrites_newer_progress(): void
    {
        [$user, $department, $semester] = $this->fixture();

        $this->actingAs($user)->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 9, step: 3))->assertOk();
        $this->actingAs($user)->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 8, step: 1))->assertStatus(409);

        $this->assertSame(3, ScheduleGenerationDraft::query()->first()->payload['step']);
    }

    public function test_clearing_keeps_late_saves_from_reviving_the_draft(): void
    {
        [$user, $department, $semester] = $this->fixture();
        $this->actingAs($user)->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 2))->assertOk();

        $this->actingAs($user)
            ->deleteJson("/api/schedule-generation-draft?department_id={$department->id}&semester_id={$semester->id}&revision=5")
            ->assertOk();
        $this->actingAs($user)->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 4))->assertStatus(409);

        $this->assertNull(ScheduleGenerationDraft::query()->first()->payload);
    }

    public function test_another_department_cannot_be_read_or_written(): void
    {
        [$user, , $semester] = $this->fixture();
        $other = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);

        $this->actingAs($user)->putJson('/api/schedule-generation-draft', $this->body($other, $semester, 1))->assertForbidden();
        $this->actingAs($user)->getJson("/api/schedule-generation-draft?department_id={$other->id}&semester_id={$semester->id}")->assertForbidden();
    }

    public function test_roles_that_cannot_generate_cannot_save(): void
    {
        [, $department, $semester] = $this->fixture();
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]), ['schedule.view']);

        $this->actingAs($dean)->putJson('/api/schedule-generation-draft', $this->body($department, $semester, 1))->assertForbidden();
    }

    private function body(Departments $department, Semester $semester, int $revision, int $step = 2): array
    {
        return [
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'revision' => $revision,
            'payload' => [
                'step' => $step,
                'year_level' => 1,
                'active_section_id' => '12',
                'target_section_ids' => ['12'],
                'configs' => ['12' => ['courseIds' => ['5', '6'], 'locked' => false, 'splitCourseIds' => []]],
                'setup_draft' => ['completed' => true, 'preferredDays' => ['Monday'], 'excludedCourseIds' => []],
                'course_defaults' => ['allowFridaySaturdaySplit' => false],
                'run_id' => 'run-abc',
            ],
        ];
    }

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        // Generation requires the department to have a program.
        Program::create(['department_id' => $department->id, 'code' => 'BAS', 'name' => 'Arts and Sciences']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, $department, $semester];
    }
}
