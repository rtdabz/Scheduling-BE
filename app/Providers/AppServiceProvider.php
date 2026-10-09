<?php

namespace App\Providers;

use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\Solver\CspSchedulingSolverAdapter;
use App\Services\Scheduling\Engine\Solver\CspYearLevelSchedulingSolverAdapter;
use App\Services\Scheduling\Engine\Solver\SchedulingSolver;
use App\Services\Scheduling\Engine\Solver\YearLevelSchedulingSolver;
use App\Services\Scheduling\Lock\DatabaseSchedulingScopeLock;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Recommendations\Providers\ConflictRecommendationProvider;
use App\Services\Scheduling\Recommendations\Providers\DraftRecommendationProvider;
use App\Services\Scheduling\Recommendations\Providers\GenerationRecommendationProvider;
use App\Services\Scheduling\Recommendations\Providers\LegacySplitRecommendationProvider;
use App\Services\Scheduling\Recommendations\Providers\PlacementRecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingQueryCounter;
use App\Support\LiveUpdateRecorder;
use App\Support\LiveUpdates;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecommendationEngine::class, static function ($app): RecommendationEngine {
            $providers = [];
            foreach (RecommendationSource::cases() as $source) {
                $provider = match ($source) {
                    RecommendationSource::ManualPlacement => PlacementRecommendationProvider::class,
                    RecommendationSource::DraftReview => DraftRecommendationProvider::class,
                    RecommendationSource::Conflict => ConflictRecommendationProvider::class,
                    RecommendationSource::LegacySplit => LegacySplitRecommendationProvider::class,
                    RecommendationSource::Configuration,
                    RecommendationSource::Feasibility,
                    RecommendationSource::Search,
                    RecommendationSource::PreferredDays => GenerationRecommendationProvider::class,
                };
                $providers[$source->value] = static fn () => $app->make($provider);
            }

            return new RecommendationEngine($providers);
        });
        $this->app->bind(SchedulingSolver::class, CspSchedulingSolverAdapter::class);
        $this->app->bind(YearLevelSchedulingSolver::class, static function ($app): YearLevelSchedulingSolver {
            return new CspYearLevelSchedulingSolverAdapter($app->make(CspSolver::class));
        });
        $this->app->bind(SchedulingScopeLock::class, DatabaseSchedulingScopeLock::class);
        $this->app->singleton(SchedulingQueryCounter::class);
        $this->app->singleton(LiveUpdates::class);
    }

    public function boot(): void
    {
        LiveUpdateRecorder::register($this->app);

        Event::listen(JobProcessing::class, static function (): void {
            SchedulingPolicy::clearFieldCourseCache();
            SchedulingPolicy::clearTimeCache();
        });

        $queryCounter = $this->app->make(SchedulingQueryCounter::class);
        DB::listen(function ($query) use ($queryCounter): void {
            $queryCounter->record();

            if ((bool) config('app.performance_logging', false)) {
                if ($query->time >= 100) {
                    Log::warning('slow_database_query', [
                        'duration_ms' => $query->time,
                        'sql' => $query->sql,
                        'bindings_count' => count($query->bindings),
                    ]);
                }
            }
        });
    }
}
