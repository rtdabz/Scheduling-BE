<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Channel auth lives under /api and uses the same bearer tokens as every
    // other API call; the default /broadcasting/auth route expects a session.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Lets repeat GETs of unchanged JSON come back as a bodyless 304
        // instead of re-sending (and re-parsing) the whole payload. A write
        // resent with the same Idempotency-Key (a retry after a slow connection
        // gave up) replays its first outcome instead of running twice.
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\ConditionalGetJson::class,
            \App\Http\Middleware\IdempotentRequests::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'capability' => \App\Http\Middleware\CapabilityMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });
    })->create();
