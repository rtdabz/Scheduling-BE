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

            // Which capabilities a program-less department cannot exercise is
            // declared in config/capabilities.php. Inferring it from the
            // `schedule.` name prefix withheld capabilities that have nothing
            // to do with programs -- instructor assignment among them.
            if (! $this->capabilities->requiresProgram($capability) || $this->departmentReady($user)) {
                return $next($request);
            }
        }

        // The account does hold the capability; its department is simply not
        // set up yet. Saying so is the difference between "the VPAA never
        // granted this" and "someone still has to create a program".
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
