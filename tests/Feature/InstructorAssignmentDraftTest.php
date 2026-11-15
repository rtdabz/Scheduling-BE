<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\InstructorAssignmentDraft;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorAssignmentDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_is_saved_and_restored_for_the_same_user(): void
    {
        [$user, $semester] = $this->fixture();

        $this->actingAs($user)
            ->putJson('/api/instructor-assignment-draft', $this->body($semester, 5, ['10:20' => ['7', [101, 102]]]))
            ->assertOk()
            ->assertJsonPath('revision', 5);

        $this->actingAs($user)
            ->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('revision', 5)
            ->assertJsonPath('payload.step', 2)
            ->assertJsonPath('payload.assignments.0.key', '10:20')
            ->assertJsonPath('payload.assignments.0.schedule_ids', [101, 102]);
    }

    public function test_an_older_revision_never_overwrites_newer_progress(): void
    {
        [$user, $semester] = $this->fixture();

        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 9, ['a' => ['1', [1]], 'b' => ['1', [2]]]))->assertOk();
        // A delayed request from before the newer save arrives late.
        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 4, ['a' => ['1', [1]]]))
            ->assertStatus(409)
            ->assertJsonPath('revision', 9);

        $this->assertCount(2, InstructorAssignmentDraft::query()->first()->payload['assignments']);
    }

    public function test_clearing_keeps_the_revision_so_late_saves_cannot_revive_the_draft(): void
    {
        [$user, $semester] = $this->fixture();

        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 3, ['a' => ['1', [1]]]))->assertOk();
        $this->actingAs($user)->deleteJson("/api/instructor-assignment-draft?semester_id={$semester->id}&revision=6")->assertOk();
        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 5, ['a' => ['1', [1]]]))->assertStatus(409);

        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('payload', null);
    }

    public function test_drafts_are_private_to_their_owner(): void
    {
        [$user, $semester, $department] = $this->fixture();
        $colleague = $this->grantCapabilities(User::factory()->create(['role' => 'program_head', 'department_id' => $department->id]));

        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 1, ['a' => ['1', [1]]]))->assertOk();

        $this->actingAs($colleague)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('payload', null);
    }

    public function test_duplicate_classes_in_one_draft_are_rejected(): void
    {
        [$user, $semester] = $this->fixture();
        $body = $this->body($semester, 1, ['a' => ['1', [1]]]);
        $body['payload']['assignments'][] = $body['payload']['assignments'][0];

        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $body)->assertUnprocessable();
    }

    public function test_cross_department_lists_are_kept_apart_from_the_schedule_list_and_each_other(): void
    {
        [$user, $semester] = $this->fixture();

        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 5, ['own' => ['1', [1]]]))->assertOk();
        // A lower revision in another context is not refused: each context keeps its own revision.
        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 2, ['x3' => ['2', [30]]]) + ['context' => 'cross_department:3'])->assertOk();
        $this->actingAs($user)->putJson('/api/instructor-assignment-draft', $this->body($semester, 2, ['x4' => ['2', [40]]]) + ['context' => 'cross_department:4'])->assertOk();

        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}")
            ->assertJsonPath('payload.assignments.0.key', 'own');
        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}&context=schedule")
            ->assertJsonPath('payload.assignments.0.key', 'own');
        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}&context=cross_department:3")
            ->assertJsonPath('payload.assignments.0.key', 'x3');

        // Clearing one cross-department list leaves the others.
        $this->actingAs($user)->deleteJson("/api/instructor-assignment-draft?semester_id={$semester->id}&revision=9&context=cross_department:3")->assertOk();
        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}&context=cross_department:3")
            ->assertJsonPath('payload', null);
        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}&context=cross_department:4")
            ->assertJsonPath('payload.assignments.0.key', 'x4');
        $this->actingAs($user)->getJson("/api/instructor-assignment-draft?semester_id={$semester->id}")
            ->assertJsonPath('payload.assignments.0.key', 'own');
    }

    public function test_unknown_contexts_are_rejected(): void
    {
        [$user, $semester] = $this->fixture();

        foreach (['other', 'cross_department', 'cross_department:0', 'cross_department:abc'] as $context) {
            $this->actingAs($user)
                ->putJson('/api/instructor-assignment-draft', $this->body($semester, 1, []) + ['context' => $context])
                ->assertUnprocessable();
        }
    }

    public function test_roles_without_instructor_assignment_cannot_save(): void
    {
        [, $semester, $department] = $this->fixture();
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]), ['schedule.view']);

        $this->actingAs($dean)->putJson('/api/instructor-assignment-draft', $this->body($semester, 1, []))->assertForbidden();
    }

    /** @param array<string, array{0: string, 1: list<int>}> $assignments */
    private function body(Semester $semester, int $revision, array $assignments): array
    {
        return [
            'semester_id' => $semester->id,
            'revision' => $revision,
            'payload' => [
                'step' => 2,
                'faculty_id' => '7',
                'year_level' => '1',
                'course_id' => '10',
                'assignments' => collect($assignments)->map(fn (array $item, string $key): array => [
                    'key' => $key, 'faculty_id' => $item[0], 'schedule_ids' => $item[1],
                ])->values()->all(),
            ],
        ];
    }

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, $semester, $department];
    }
}
