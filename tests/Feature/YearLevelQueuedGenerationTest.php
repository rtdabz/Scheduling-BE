<?php

namespace Tests\Feature;

use App\Exceptions\YearLevelGenerationException;
use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\GenerationCancellationToken;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class YearLevelQueuedGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_level_preview_can_be_queued_and_polled(): void
    {
        Queue::fake();

        [$semester, $department, $section, $course, $user] = $this->generationFixture();

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview/queue', [
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [$course->id],
            ]],
        ]);

        $response->assertAccepted()->assertJsonStructure(['run_id', 'status']);
        $runId = (string) $response->json('run_id');

        Queue::assertPushedOn('scheduling', GenerateYearLevelSchedulePreview::class);
        $this->assertDatabaseHas('schedule_generation_runs', [
            'run_id' => $runId,
            'requested_by' => $user->id,
            'status' => 'queued',
        ]);

        ScheduleGenerationRun::query()->where('run_id', $runId)->update([
            'status' => 'completed',
            'result' => ['schedules' => []],
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/schedule-recommendations/generation-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.schedules', []);
    }

    public function test_queued_generation_persists_structured_failure_without_retrying_domain_failure(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();

        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'status' => 'queued',
        ]);

        $generator = Mockery::mock(YearLevelScheduleGenerationService::class);
        $generator->shouldReceive('preview')
            ->once()
            ->andThrow(new YearLevelGenerationException(
                'No valid timetable was found.',
                YearLevelGenerationException::STAGE_SEARCH,
                attempts: [['strategy' => 'baseline', 'outcome' => 'failed']],
            ));

        $job = new GenerateYearLevelSchedulePreview(
            $runId,
            [(int) $section->id],
            [(int) $section->id => ['course_ids' => [(int) $course->id]]],
        );
        $job->handle($generator);

        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('year_level_generation_failed', $run->result['error_code']);
        $this->assertSame('search', $run->result['stage']);
        $this->assertSame('No valid timetable was found.', $run->error_message);
    }

    public function test_worker_failure_marks_an_active_run_failed(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        $run = ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 3,
            'status' => 'running',
            'started_at' => now(),
        ]);

        (new GenerateYearLevelSchedulePreview($runId, [(int) $section->id], []))
            ->failed(new \RuntimeException('Maximum execution time exceeded'));

        $this->assertDatabaseHas('schedule_generation_runs', [
            'id' => $run->id,
            'status' => 'failed',
            'error_message' => 'Maximum execution time exceeded',
        ]);
    }

    public function test_poll_reconciles_an_orphaned_running_run(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 3,
            'status' => 'running',
            'started_at' => now()->subSeconds(181),
        ]);

        $this->actingAs($user)
            ->getJson("/api/schedule-recommendations/generation-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }

    public function test_active_run_lookup_recovers_generation_started_before_a_reload(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 2,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/schedule-recommendations/active-generation-run?department_id={$department->id}&semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('run.run_id', $runId)
            ->assertJsonPath('run.year_level', 2);
    }

    public function test_active_run_lookup_reports_no_run_for_an_orphaned_generation(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        ScheduleGenerationRun::create([
            'run_id' => (string) Str::uuid(),
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 2,
            'status' => 'running',
            'started_at' => now()->subSeconds(181),
        ]);

        $this->actingAs($user)
            ->getJson("/api/schedule-recommendations/active-generation-run?department_id={$department->id}&semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('run', null);
    }

    public function test_active_run_lookup_ignores_a_finished_run(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        ScheduleGenerationRun::create([
            'run_id' => (string) Str::uuid(),
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 2,
            'status' => 'completed',
            'result' => ['schedules' => []],
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/schedule-recommendations/active-generation-run?department_id={$department->id}&semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('run', null);
    }

    public function test_cancelling_a_running_run_marks_it_cancelled(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/schedule-recommendations/generation-runs/{$runId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        $this->assertNotNull($run->finished_at);
    }

    public function test_cancelling_a_queued_run_removes_its_unreserved_queue_job(): void
    {
        // The real queue table is what the cancel endpoint prunes, so this
        // case cannot run on the synchronous test driver.
        config(['queue.default' => 'database']);
        [$semester, $department, $section, $course, $user] = $this->generationFixture();

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview/queue', [
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [$course->id],
            ]],
        ]);
        $runId = (string) $response->assertAccepted()->json('run_id');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'scheduling')->count());

        $this->actingAs($user)
            ->postJson("/api/schedule-recommendations/generation-runs/{$runId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(0, DB::table('jobs')->where('queue', 'scheduling')->count());
    }

    public function test_cancelling_a_finished_run_is_a_no_op(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'status' => 'completed',
            'result' => ['schedules' => []],
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/schedule-recommendations/generation-runs/{$runId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_another_user_cannot_cancel_a_run(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $other = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
            'is_active' => true,
        ]));

        $this->actingAs($other)
            ->postJson("/api/schedule-recommendations/generation-runs/{$runId}/cancel")
            ->assertForbidden();

        $this->assertDatabaseHas('schedule_generation_runs', ['run_id' => $runId, 'status' => 'running']);
    }

    public function test_a_cancelled_run_is_not_overwritten_by_the_worker(): void
    {
        [$semester, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'semester_id' => $semester->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'status' => 'queued',
        ]);

        // The generator notices the cancellation at its next placement
        // boundary and unwinds; the worker must leave the run terminal.
        $generator = Mockery::mock(YearLevelScheduleGenerationService::class);
        $generator->shouldReceive('preview')
            ->once()
            ->andReturnUsing(function (array $sections, array $configs, GenerationCancellationToken $cancellation) use ($runId) {
                ScheduleGenerationRun::query()->where('run_id', $runId)->update(['status' => 'cancelled']);
                $cancellation->abortIfCancelled();

                return [];
            });

        (new GenerateYearLevelSchedulePreview(
            $runId,
            [(int) $section->id],
            [(int) $section->id => ['course_ids' => [(int) $course->id]]],
        ))->handle($generator);

        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    /** @return array{Semester, Departments, Sections, Course, User} */
    private function generationFixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
        ]);
        // Schedule capabilities and section scheduling both require the
        // department to own a program.
        $departmentProgram = Program::create([
            'department_id' => $department->id,
            'code' => 'P'.$department->id,
            'name' => 'Program '.$department->id,
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $departmentProgram->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Introduction to Computing',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create([
            'room_code' => 'IT 101',
            'building' => 'IT Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $user = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
            'is_active' => true,
        ]));

        return [$semester, $department, $section, $course, $user];
    }
}
