<?php

namespace App\Services;

use App\Jobs\ResolveLoginGeolocation;
use App\Models\AuthenticationAuditLog;
use App\Models\User;
use App\Support\ActorIdentity;
use App\Support\DeviceLocation;
use Illuminate\Http\Request;

class AuthenticationAuditService
{
    /** Login events that get a location (optional device fix + approximate IP) attached after the response. */
    public const LOCATED_EVENTS = ['login_succeeded', 'login_failed'];

    public function record(Request $request, string $event, ?User $subject = null, array $metadata = []): AuthenticationAuditLog
    {
        if ($subject) {
            $metadata['_subject'] = ActorIdentity::snapshot($subject);
            if (in_array($event, ['user_updated', 'profile_updated', 'user_status_changed'], true)) {
                $after = $subject->only([...ActorIdentity::FIELDS, 'email', 'deleted_at']);
                $metadata['before'] = array_replace($after, array_intersect_key($subject->getPrevious(), $after));
                $metadata['after'] = $after;
            }
        }

        $actor = $request->user() ?? (in_array($event, ActorIdentity::SELF_EVENTS, true) ? $subject : null);

        $log = AuthenticationAuditLog::create([
            'actor_user_id' => $actor?->id,
            'subject_user_id' => $subject?->id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
        ]);

        if (in_array($event, self::LOCATED_EVENTS, true)) {
            ResolveLoginGeolocation::dispatchAfterResponse($log->id, DeviceLocation::fromRequest($request));
        }

        return $log;
    }
}
