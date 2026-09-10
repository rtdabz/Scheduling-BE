<?php

namespace App\Http\Middleware;

use App\Models\Program;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapabilityMiddleware
{
    public function handle(Request $request, Closure $next, ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        foreach ($capabilities as $capability) {
            $requiresProgram = str_starts_with($capability, 'schedule.') && $capability !== 'schedule.view';
            $departmentReady = $user->department_id === null
                || Program::query()->where('department_id', $user->department_id)->exists();

            if ($user->hasCapability($capability) && (! $requiresProgram || $departmentReady)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }
}
