<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The file cache store never evicts on its own, and ApiCache's version-bump
// invalidation orphans the superseded key permanently. Without this the cache
// directory grows without bound. No-ops on redis/memcached.
Schedule::command('cache:prune-expired')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();


