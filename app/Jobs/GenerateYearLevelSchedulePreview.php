<?php

namespace App\Jobs;

use App\Models\ScheduleGenerationRun;
use App\Models\User;
use App\Models\Sections;
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
            $sections = \App\Models\Sections::query()
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
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error_message' => $exception->getMessage(), 'finished_at' => now()]);
            throw $exception;
        }
    }
}
