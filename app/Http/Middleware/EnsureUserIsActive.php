<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CapabilityRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            $request->user()?->tokens()->delete();

            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        $user = $request->user();
        if ($user && ! app(CapabilityRegistry::class)->supportsRole((string) $user->role)) {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account role is no longer supported. Contact the VPAA office.'], 403);
        }

        if ($user?->awaitingRoleReview()) {
            $user->tokens()->delete();

            return response()->json(['message' => User::ROLE_REVIEW_MESSAGE], 403);
        }

        return $next($request);
    }
}
