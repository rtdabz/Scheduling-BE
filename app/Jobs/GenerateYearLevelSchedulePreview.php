<?php

namespace App\Jobs;

use App\Exceptions\GenerationCancelledException;
use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\DepartmentCourseRules;
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

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $runId,
        public readonly array $sectionIds,
        public readonly array $configsBySectionId,
        public readonly ?array $ruleOverrides = null,
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

            $result = DepartmentCourseRules::withOverride((int) $run->department_id, $this->ruleOverrides, fn () => $generator->preview(
                $sections,
                $this->configsBySectionId,
                new GenerationCancellationToken(fn (): bool => ScheduleGenerationRun::query()
                    ->where('run_id', $this->runId)
                    ->where('status', 'cancelled')
                    ->exists()),
                fn (array $report) => ScheduleGenerationRun::query()
                    ->where('run_id', $this->runId)
                    ->where('status', 'running')
                    ->update(['result' => $report]),
            ));
            $this->finalize([
                'status' => 'completed',
                'result' => $result,
                'error_message' => null,
            ]);
        } catch (GenerationCancelledException) {
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
     * @param  array<string, mixed>  $attributes
     */
    private function finalize(array $attributes): void
    {
        ScheduleGenerationRun::query()
            ->where('run_id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update($attributes + ['finished_at' => now()]);
    }

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
