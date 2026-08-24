<?php

namespace App\Jobs;

use App\Http\Controllers\ScheduleRecommendationController;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use App\Models\Sections;
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
    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

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
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = $controller->runAsyncSectionPreview($this->sectionId, $this->input);
            $run->update(['status' => 'completed', 'result' => $result, 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => $exception->getMessage(), 'finished_at' => now()]);
            throw $exception;
        }
    }
}
