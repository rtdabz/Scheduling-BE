<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Legacy callers may hold an Eloquent instance created before the DB
        // default was refreshed. Only an explicit false disables access.
        if ($request->user()?->is_active === false) {
            $request->user()?->tokens()->delete();

            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        return $next($request);
    }
}
