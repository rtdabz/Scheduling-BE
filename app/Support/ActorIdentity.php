<?php

namespace App\Support;

use App\Models\AuthenticationAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Event-time identities are independent of the account's later name, role, or availability. */
final class ActorIdentity
{
    public const FIELDS = ['id', 'name', 'username', 'role', 'department_id', 'program_id', 'is_active'];

    public const SELF_EVENTS = ['login_succeeded', 'logout', 'profile_updated', 'password_reset', 'invitation_accepted', 'google_linked', 'google_unlinked'];

    public static function snapshot(?User $user): ?array
    {
        return $user === null ? null : $user->only(self::FIELDS);
    }

    public static function capture(Model $record, string $actorColumn, string $metadataColumn = 'metadata'): void
    {
        $id = $record->getAttribute($actorColumn);
        $metadata = $record->getAttribute($metadataColumn) ?? [];
        $metadata['_actor'] = $id ? self::snapshot(User::withTrashed()->find($id)) : null;
        $metadata['_actor_type'] = $id ? 'user' : (($metadata['_actor_type'] ?? null) === 'system' ? 'system' : 'unknown');
        $metadata['_references'] = array_filter($record->getAttributes(), static fn ($key) => str_ends_with($key, '_id'), ARRAY_FILTER_USE_KEY);
        $record->setAttribute($metadataColumn, $metadata);
    }

    public static function recorded(?array $metadata, ?int $actorId): ?array
    {
        $snapshot = $metadata['_actor'] ?? null;
        if (is_array($snapshot) && (int) ($snapshot['id'] ?? 0) === (int) $actorId) {
            return $snapshot + ['provenance' => 'recorded'];
        }

        return $actorId ? ['id' => $actorId, 'name' => null, 'username' => null, 'role' => null, 'provenance' => 'unavailable'] : null;
    }

    public static function authentication(AuthenticationAuditLog $log): ?array
    {
        $metadata = $log->metadata ?? [];
        if (array_key_exists('_actor', $metadata)) {
            return self::recorded($metadata, $log->actor_user_id);
        }
        $subject = $metadata['_subject'] ?? null;
        // Legacy subjects establish actors only for self-actions, never administrative actions.
        if (is_array($subject) && in_array($log->event, self::SELF_EVENTS, true)
            && ($log->actor_user_id === null || (int) $log->actor_user_id === (int) ($subject['id'] ?? 0))) {
            return $subject + ['provenance' => 'recorded_subject'];
        }

        return self::recorded($metadata, $log->actor_user_id);
    }
}
