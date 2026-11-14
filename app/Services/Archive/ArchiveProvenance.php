<?php

namespace App\Services\Archive;

use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
use App\Support\ArchiveAuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Finds the audit entry recorded when a record was archived. Only entries written within a
 * short window of the archive timestamp are trusted; anything else is reported as unknown
 * rather than guessed. No archive endpoint records a reason.
 */
final class ArchiveProvenance
{
    private const WINDOW_SECONDS = 120;

    public function __construct(private readonly ArchiveCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function for(string $type, Model $record): array
    {
        /** @var Carbon|null $archivedAt */
        $archivedAt = $record->getAttribute('deleted_at');
        $found = $archivedAt === null ? null : match ($type) {
            'users' => $this->accountArchive($record, $archivedAt),
            'buildings' => $this->archiveEvent('rooms', (int) $record->getKey(), $archivedAt),
            default => $this->archiveEvent($type, (int) $record->getKey(), $archivedAt),
        };

        return [
            'archived_at' => $archivedAt?->toIso8601String(),
            'recorded' => $found !== null,
            'actor' => $found['actor'] ?? null,
            'event' => $found['event'] ?? null,
            'note' => $found['note'] ?? null,
            'reason' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function accountArchive(Model $user, Carbon $archivedAt): ?array
    {
        $log = $this->within(AuthenticationAuditLog::query(), $archivedAt)
            ->where('event', 'user_archived')
            ->where('subject_user_id', $user->getKey())
            ->first();

        return $log === null ? null : ['actor' => $log->recorded_actor, 'event' => 'user_archived'];
    }

    /** @return array<string, mixed>|null */
    private function archiveEvent(string $type, int $id, Carbon $archivedAt): ?array
    {
        $log = $this->within(SchedulingAuditLog::query(), $archivedAt)
            ->where('action', ArchiveAuditRecorder::ACTION)
            ->where('metadata->type', $type)
            ->where('metadata->id', $id)
            ->first();

        return $log === null ? null : ['actor' => $log->recorded_actor, 'event' => ArchiveAuditRecorder::ACTION];
    }

    private function within($query, Carbon $archivedAt)
    {
        return $query
            ->whereBetween('created_at', [$archivedAt->copy()->subSeconds(self::WINDOW_SECONDS), $archivedAt->copy()->addSeconds(self::WINDOW_SECONDS)])
            ->latest('created_at')
            ->latest('id');
    }
}
