<?php

namespace App\Jobs;

use App\Exceptions\GenerationCancelledException;
use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\GenerationCancellationToken;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateYearLevelSchedulePreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    /**
     * A preview run is claimed exactly once: handle() flips the durable run
     * from queued to running, so every later attempt short-circuits and does
     * no work. Retrying therefore never re-runs generation - it only keeps the
     * caller watching a "queued" spinner through the whole backoff ladder when
     * a job fails before handle() is entered (a container or boot error).
     * Fail on the first attempt so failed() records the real cause at once.
     */
    public int $tries = 1;

    /** A timeout is a terminal generation failure, not a retryable preview. */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $runId,
        public readonly array $sectionIds,
        public readonly array $configsBySectionId,
    ) {}

    public function handle(YearLevelScheduleGenerationService $generator): void
    {
        $claimed = ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->where('status', 'queued')
            ->whereNull('finished_at')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'error_message' => null,
            ]);

        // The polling endpoint may have already finalized an unclaimed run.
        // Do not revive stale, cancelled, or otherwise terminal requests.
        if ($claimed === 0) {
            return;
        }

        $run = ScheduleGenerationRun::query()->where('run_id', $this->runId)->firstOrFail();
        $requester = User::query()->find($run->requested_by);
        if (! $requester?->is_active || ($requester->role !== 'vpaa' && (int) $requester->department_id !== (int) $run->department_id)) {
            $run->update(['status' => 'cancelled', 'error_message' => 'Requester is no longer authorized.', 'finished_at' => now()]);

            return;
        }
        $semester = Semester::query()->find($run->semester_id);
        if (! $semester?->is_active) {
            $run->update(['status' => 'cancelled', 'error_message' => 'The selected academic semester is no longer active.', 'finished_at' => now()]);

            return;
        }
        try {
            $sections = Sections::query()
                ->with('department')
                ->whereIn('id', array_map('intval', $this->sectionIds))
                ->where('semester_id', $run->semester_id)
                ->where('department_id', $run->department_id)
                ->where('year_level', (string) $run->year_level)
                ->where('semester', (string) $semester->semester)
                ->where('status', 'active')
                ->orderBy('section_name')
                ->get()
                ->all();

            if ($sections === []) {
                throw new \RuntimeException('The sections for this generation run no longer exist.');
            }

            $result = $generator->preview(
                $sections,
                $this->configsBySectionId,
                new GenerationCancellationToken(fn (): bool => ScheduleGenerationRun::query()
                    ->where('run_id', $this->runId)
                    ->where('status', 'cancelled')
                    ->exists()),
            );
            $this->finalize([
                'status' => 'completed',
                'result' => $result,
                'error_message' => null,
            ]);
        } catch (GenerationCancelledException) {
            // The cancel endpoint already made the run terminal. Unwind
            // without reporting a generation failure the user did not hit.
            ScheduleGenerationRun::query()
                ->where('run_id', $this->runId)
                ->whereNull('finished_at')
                ->update(['finished_at' => now()]);
        } catch (YearLevelGenerationException $exception) {
            $this->finalize([
                'status' => 'failed',
                'result' => $exception->payload(),
                'error_message' => $exception->getMessage(),
            ]);
        } catch (ScheduleGenerationPreflightException $exception) {
            $this->finalize([
                'status' => 'failed',
                'result' => $exception->payload(),
                'error_message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->finalize(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    /**
     * Write a terminal outcome only while the run is still active. A run the
     * cancel endpoint already finalized must not be revived as completed or
     * failed by work that was in flight when the user stopped it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finalize(array $attributes): void
    {
        ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update($attributes + ['finished_at' => now()]);
    }

    /**
     * Laravel invokes this after worker-level failures, including timeouts
     * that never reach handle()'s catch blocks. Keep the durable run record
     * from remaining in the misleading running state.
     */
    public function failed(?Throwable $exception): void
    {
        ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Year-level generation job failed.',
                'finished_at' => now(),
            ]);
    }
}
