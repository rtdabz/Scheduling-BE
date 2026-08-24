<?php

namespace App\Services;

use App\Models\AuthenticationAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuthenticationAuditService
{
    public function record(Request $request, string $event, ?User $subject = null, array $metadata = []): void
    {
        if ($subject) {
            $metadata['_subject'] = [
                'id' => $subject->id,
                'name' => $subject->name,
                'username' => $subject->username,
                'role' => $subject->role,
                'department_id' => $subject->department_id,
            ];
        }

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
