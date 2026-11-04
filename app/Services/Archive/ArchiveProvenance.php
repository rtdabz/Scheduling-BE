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
            'schedules' => $this->scheduleDeletion((int) $record->getKey(), (int) $record->getAttribute('semester_id'), $archivedAt),
            'schedule-splits' => $this->splitArchive($record, $archivedAt),
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
        $log = $this->within(AuthenticationAuditLog::query()->with(['actor' => self::actorColumns(...)]), $archivedAt)
            ->where('event', 'user_archived')
            ->where('subject_user_id', $user->getKey())
            ->first();

        return $log === null ? null : ['actor' => $this->actor($log->actor), 'event' => 'user_archived'];
    }

    /** @return array<string, mixed>|null */
    private function scheduleDeletion(int $scheduleId, int $semesterId, Carbon $archivedAt): ?array
    {
        $log = $this->within(SchedulingAuditLog::query()->with(['user' => self::actorColumns(...)]), $archivedAt)
            ->whereIn('action', ['schedule_deleted', 'schedule_batch_deleted'])
            ->where('semester_id', $semesterId)
            ->limit(25)
            ->get()
            ->first(function (SchedulingAuditLog $log) use ($scheduleId): bool {
                $metadata = $log->metadata ?? [];

                return (int) ($metadata['schedule_id'] ?? 0) === $scheduleId
                    || in_array($scheduleId, array_map('intval', (array) ($metadata['schedule_ids'] ?? [])), true);
            });

        return $log === null ? null : ['actor' => $this->actor($log->user), 'event' => $log->action];
    }

    /** @return array<string, mixed>|null */
    private function splitArchive(Model $split, Carbon $archivedAt): ?array
    {
        $schedule = $this->catalog->parentSchedule($split);
        $scheduleArchivedAt = $schedule?->deleted_at;
        if ($schedule === null || $scheduleArchivedAt === null || abs($scheduleArchivedAt->diffInSeconds($archivedAt)) > self::WINDOW_SECONDS) {
            return null;
        }

        $found = $this->scheduleDeletion((int) $schedule->id, (int) $schedule->semester_id, $scheduleArchivedAt);

        return $found === null ? null : [...$found, 'note' => 'Archived together with its class.'];
    }

    /** @return array<string, mixed>|null */
    private function archiveEvent(string $type, int $id, Carbon $archivedAt): ?array
    {
        $log = $this->within(SchedulingAuditLog::query()->with(['user' => self::actorColumns(...)]), $archivedAt)
            ->where('action', ArchiveAuditRecorder::ACTION)
            ->where('metadata->type', $type)
            ->where('metadata->id', $id)
            ->first();

        return $log === null ? null : ['actor' => $this->actor($log->user), 'event' => ArchiveAuditRecorder::ACTION];
    }

    /** Archived actors are still named in provenance. */
    private static function actorColumns($query): void
    {
        $query->withTrashed()->select('id', 'name', 'username', 'role');
    }

    private function within($query, Carbon $archivedAt)
    {
        return $query
            ->whereBetween('created_at', [$archivedAt->copy()->subSeconds(self::WINDOW_SECONDS), $archivedAt->copy()->addSeconds(self::WINDOW_SECONDS)])
            ->latest('created_at')
            ->latest('id');
    }

    /** @return array<string, mixed>|null */
    private function actor(?Model $user): ?array
    {
        return $user === null ? null : [
            'id' => (int) $user->getKey(),
            'name' => $user->getAttribute('name'),
            'username' => $user->getAttribute('username'),
            'role' => $user->getAttribute('role'),
        ];
    }
}
