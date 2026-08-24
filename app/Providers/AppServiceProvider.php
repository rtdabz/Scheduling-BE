<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ((bool) config('app.performance_logging', false)) {
            DB::listen(function ($query): void {
                if ($query->time >= 100) {
                    Log::warning('slow_database_query', [
                        'duration_ms' => $query->time,
                        'sql' => $query->sql,
                        'bindings_count' => count($query->bindings),
                    ]);
                }
            });
        }
    }
}
