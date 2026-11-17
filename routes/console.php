<?php

use App\Jobs\ResolveLoginGeolocation;
use App\Models\AuthenticationAuditLog;
use App\Models\LoginGeolocation;
use App\Models\RoomRecoveryPlan;
use App\Services\AuthenticationAuditService;
use App\Services\ReverseGeocodingService;
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

    // Name the place at device fixes recorded before reverse geocoding existed, or whose lookup failed.
    $unnamed = LoginGeolocation::query()
        ->whereNotNull('device_latitude')
        ->whereNull('device_city')->whereNull('device_region')->whereNull('device_country')
        ->orderByDesc('id')
        ->limit(max(1, (int) $this->option('limit')))
        ->get();

    $named = 0;
    foreach ($unnamed as $index => $geo) {
        if ($index > 0) {
            sleep(1); // Nominatim usage policy: at most one request per second.
        }
        if ($place = app(ReverseGeocodingService::class)->placeAt($geo->device_latitude, $geo->device_longitude)) {
            $geo->update($place);
            $named++;
        }
    }

    $this->info("Named device locations for {$named} of {$unnamed->count()} sign-in record(s).");
})->purpose('Attach approximate locations to sign-ins that are missing one');

Schedule::command('logins:resolve-locations')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
