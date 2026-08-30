<?php

namespace Tests\Feature;

use App\Exceptions\YearLevelGenerationException;
use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\YearLevelScheduleGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        [$term, $department, $section, $course, $user] = $this->generationFixture();

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview/queue', [
            'term_id' => $term->id,
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
        [$term, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();

        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'term_id' => $term->id,
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
        [$term, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        $run = ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'term_id' => $term->id,
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
        [$term, $department, $section, $course, $user] = $this->generationFixture();
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $user->id,
            'term_id' => $term->id,
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

    /** @return array{Terms, Departments, Sections, Course, User} */
    private function generationFixture(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
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
        $user = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        return [$term, $department, $section, $course, $user];
    }
}
