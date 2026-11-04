<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Services\Archive\ArchiveCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who archived a record for types whose delete endpoints had no audit event. Accounts
 * (user_archived) and class schedules (schedule_deleted) keep their existing events. Archives
 * made before this existed have no recorded actor and are shown as unknown.
 */
final class ArchiveAuditRecorder
{
    public const ACTION = 'record_archived';

    /** @var list<class-string<Model>> */
    private const MODELS = [Departments::class, Program::class, Rooms::class, Faculty::class, Designation::class, Course::class, Semester::class];

    public static function register(): void
    {
        foreach (self::MODELS as $modelClass) {
            $modelClass::deleted(static function (Model $record): void {
                if (method_exists($record, 'isForceDeleting') && $record->isForceDeleting()) {
                    return;
                }

                $type = ArchiveCatalog::typeOf($record);
                SchedulingAuditLog::create([
                    'user_id' => request()?->user()?->id,
                    'department_id' => $record instanceof Departments ? $record->getKey() : $record->getAttribute('department_id'),
                    'action' => self::ACTION,
                    'metadata' => [
                        'type' => $type,
                        'id' => (int) $record->getKey(),
                        'label' => app(ArchiveCatalog::class)->label($type, $record),
                    ],
                    'created_at' => now(),
                ]);
            });
        }
    }
}
