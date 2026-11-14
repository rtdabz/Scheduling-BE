<?php

use App\Jobs\ResolveLoginGeolocation;
use App\Models\AuthenticationAuditLog;
use App\Models\LoginGeolocation;
use App\Models\RoomRecoveryPlan;
use App\Services\AuthenticationAuditService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cache:prune-expired')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('model:prune', ['--model' => [RoomRecoveryPlan::class, LoginGeolocation::class]])
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

// Fill in sign-in locations whose after-response lookup never completed (provider outage,
// missing grants, crashed request). Only records still inside the retention window.
Artisan::command('logins:resolve-locations {--limit=200}', function () {
    $ids = AuthenticationAuditLog::query()
        ->whereIn('event', AuthenticationAuditService::LOCATED_EVENTS)
        ->whereDoesntHave('geolocation')
        ->where('created_at', '>=', now()->subDays(LoginGeolocation::retentionDays()))
        ->orderByDesc('id')
        ->limit(max(1, (int) $this->option('limit')))
        ->pluck('id');

    foreach ($ids as $id) {
        ResolveLoginGeolocation::dispatchSync($id);
    }

    $this->info("Resolved locations for {$ids->count()} sign-in record(s).");
})->purpose('Attach approximate locations to sign-ins that are missing one');

Schedule::command('logins:resolve-locations')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
