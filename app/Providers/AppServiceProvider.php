<?php

namespace App\Providers;

use App\Services\Scheduling\Lock\DatabaseSchedulingScopeLock;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\SchedulingQueryCounter;
use App\Services\Scheduling\Solver\CspYearLevelSchedulingSolverAdapter;
use App\Services\Scheduling\Solver\CspSchedulingSolverAdapter;
use App\Services\Scheduling\Solver\SchedulingSolver;
use App\Services\Scheduling\Solver\YearLevelSchedulingSolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SchedulingSolver::class, CspSchedulingSolverAdapter::class);
        $this->app->bind(YearLevelSchedulingSolver::class, static function ($app): YearLevelSchedulingSolver {
            return new CspYearLevelSchedulingSolverAdapter($app->make(\App\Services\Scheduling\CspSolver::class));
        });
        $this->app->bind(SchedulingScopeLock::class, DatabaseSchedulingScopeLock::class);
        $this->app->singleton(SchedulingQueryCounter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
