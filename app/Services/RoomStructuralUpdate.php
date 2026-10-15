<?php

namespace App\Services;

use App\Exceptions\ScheduleConflictException;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoomStructuralUpdate
{
    public function __construct(private readonly SchedulingScopeLock $lock) {}

    public function apply(Rooms $room, array $values): void
    {
        $semesterIds = Schedule::where('room_id', $room->id)->pluck('semester_id')->unique()->map('intval')->all();
        $this->lock->execute($semesterIds, function () use ($room, $values): void {
            DB::transaction(function () use ($room, $values): void {
                $current = Rooms::whereKey($room->id)->lockForUpdate()->firstOrFail();
                $current->fill($values);
                $structural = $current->isDirty(['room_type', 'allow_lecture_usage', 'department_id', 'status']);
                if (($current->isDirty('department_id') || ($current->isDirty('room_type') && Rooms::isSharedType($current->room_type))) && (
                    $current->home_program_id !== null
                    || RoomRequest::where('room_id', $room->id)->whereIn('status', [RoomRequest::STATUS_PENDING, RoomRequest::STATUS_APPROVED])
                        ->whereHas('academicSemester', fn ($query) => $query->where('is_active', true))->exists()
                )) {
                    throw ValidationException::withMessages(['department_id' => 'Resolve this room’s program assignment and active room requests before changing its department.']);
                }
                $current->save();
                if ($structural) {
                    $engine = app(RuleEngine::class);
                    foreach (Schedule::where('room_id', $room->id)->lockForUpdate()->get() as $schedule) {
                        $violations = array_values(array_filter($engine->validateStanding($schedule->toArray()),
                            fn ($violation) => in_array($violation['rule'], ['room_type_match', 'room_department_alignment'], true)
                                || ($violation['rule'] === 'room_availability' && $schedule->academicSemester?->is_active)));
                        if ($violations !== []) {
                            throw new ScheduleConflictException($violations, 'This room change would invalidate scheduled classes. Move the affected classes first.');
                        }
                    }
                }
                $room->setRawAttributes($current->getAttributes(), true);
            });
        });
    }
}
