<?php

namespace App\Jobs;

use App\Exceptions\GenerationConfigurationConfirmationException;
use App\Http\Controllers\ScheduleRecommendationController;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSectionSchedulePreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /**
     * A preview run is claimed exactly once: handle() flips the durable run
     * from queued to running, so every later attempt short-circuits and does
     * no work. Retrying therefore never re-runs generation - it only keeps the
     * caller watching a "queued" spinner through the whole backoff ladder when
     * a job fails before handle() is entered (a container or boot error).
     * Fail on the first attempt so failed() records the real cause at once.
     */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $runId,
        public readonly int $sectionId,
        public readonly array $input,
    ) {}

    public function handle(ScheduleRecommendationController $controller): void
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $this->runId)->firstOrFail();
        $requester = User::query()->find($run->requested_by);
        $section = Sections::query()->find($this->sectionId);
        if (! $requester?->is_active || ! $section || ($requester->role !== 'vpaa' && (int) $requester->department_id !== (int) $section->department_id)) {
            $run->update(['status' => 'cancelled', 'error_message' => 'Requester is no longer authorized.', 'finished_at' => now()]);

            return;
        }
        $term = Terms::query()->find((int) $section->term_id);
        if (! $term?->is_active || (string) $section->semester !== (string) $term->semester || (int) $run->term_id !== (int) $section->term_id) {
            $run->update(['status' => 'cancelled', 'error_message' => 'The selected academic term or semester is no longer active.', 'finished_at' => now()]);

            return;
        }
        $claimed = ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->where('status', 'queued')
            ->whereNull('finished_at')
            ->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        // The run was already finalized - cancelled, or expired by polling.
        // Do not revive it.
        if ($claimed === 0) {
            return;
        }

        try {
            $result = $controller->runAsyncSectionPreview($this->sectionId, $this->input);
            $this->finalize(['status' => 'completed', 'result' => $result]);
        } catch (GenerationConfigurationConfirmationException $exception) {
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

    /** Keep the durable run from remaining active after a worker-level failure. */
    public function failed(?Throwable $exception): void
    {
        ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Section generation job failed.',
                'finished_at' => now(),
            ]);
    }
}
