<?php

namespace App\Services;

use App\Models\AuthenticationAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuthenticationAuditService
{
    public function record(Request $request, string $event, ?User $subject = null, array $metadata = []): void
    {
        AuthenticationAuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'subject_user_id' => $subject?->id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
