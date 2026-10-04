<?php

namespace App\Http\Middleware;

use App\Models\Program;
use App\Support\CapabilityRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapabilityMiddleware
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    public function handle(Request $request, Closure $next, ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $held = [];
        foreach ($capabilities as $capability) {
            if (! $user->hasCapability($capability)) {
                continue;
            }
            $held[] = $capability;

            if (! $this->capabilities->requiresProgram($capability) || $this->departmentReady($user)) {
                return $next($request);
            }
        }

        if ($held !== []) {
            return response()->json([
                'message' => 'Your department has no program yet, so this action is unavailable. '
                    .'Ask the VPAA to add a program to your department.',
            ], 403);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    private function departmentReady($user): bool
    {
        return $user->department_id === null
            || Program::query()->where('department_id', $user->department_id)->exists();
    }
}
