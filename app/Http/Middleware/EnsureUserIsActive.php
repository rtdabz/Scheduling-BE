<?php

namespace App\Http\Middleware;

use App\Support\CapabilityRegistry;
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

        $user = $request->user();
        if ($user && ! app(CapabilityRegistry::class)->supportsRole((string) $user->role)) {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account role is no longer supported. Contact the VPAA office.'], 403);
        }

        return $next($request);
    }
}
