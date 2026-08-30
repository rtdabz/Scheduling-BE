<?php

namespace App\Jobs;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\User;
use App\Services\Scheduling\YearLevelScheduleGenerationService;
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

    public int $tries = 3;

    /** A timeout is a terminal generation failure, not a retryable preview. */
    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly string $runId,
        public readonly array $sectionIds,
        public readonly array $configsBySectionId,
    ) {}

    public function handle(YearLevelScheduleGenerationService $generator): void
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $this->runId)->firstOrFail();
        $requester = User::query()->find($run->requested_by);
        if (! $requester?->is_active || ($requester->role !== 'vpaa' && (int) $requester->department_id !== (int) $run->department_id)) {
            $run->update(['status' => 'cancelled', 'error_message' => 'Requester is no longer authorized.', 'finished_at' => now()]);

            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $sections = Sections::query()
                ->with('department')
                ->whereIn('id', array_map('intval', $this->sectionIds))
                ->where('department_id', $run->department_id)
                ->orderBy('section_name')
                ->get()
                ->all();

            if ($sections === []) {
                throw new \RuntimeException('The sections for this generation run no longer exist.');
            }

            $result = $generator->preview($sections, $this->configsBySectionId);
            $run->update(['status' => 'completed', 'result' => $result, 'finished_at' => now()]);
        } catch (YearLevelGenerationException $exception) {
            $run->update([
                'status' => 'failed',
                'result' => $exception->payload(),
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        } catch (ScheduleGenerationPreflightException $exception) {
            $run->update([
                'status' => 'failed',
                'result' => $exception->payload(),
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => $exception->getMessage(), 'finished_at' => now()]);
            throw $exception;
        }
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
