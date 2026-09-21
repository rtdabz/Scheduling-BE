<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Rooms;
use App\Models\Schedule;

/**
 * room_conflict, faculty_conflict, section_conflict, subject_section_time_conflict.
 *
 * One resource cannot be in two places at once: a lecture or laboratory room
 * holds one class, an instructor teaches one class, a section attends one
 * class. Field and online rooms are shared without a limit, so they never
 * conflict on booking. Online sections taking the same course must also use
 * different time windows.
 *
 * Every clashing meeting is reported, so a faculty override can mark all of
 * them; a clash someone chose to override is filtered out afterwards by
 * FacultyConflictOverride.
 */
final class OverlapConflict
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function check(array $attempt): array
    {
        return array_values(array_filter([
            $this->roomClash($attempt),
            $this->facultyClash($attempt),
            $this->sectionClash($attempt),
            $this->sameOnlineCourseClash($attempt),
        ]));
    }

    /** @param array<string, mixed> $attempt */
    private function roomClash(array $attempt): ?array
    {
        $roomId = $attempt['room_id'] ?? null;
        if ((string) ($attempt['mode'] ?? 'on-site') === 'online' || $roomId === null) {
            return null;
        }

        $roomId = (int) $roomId;
        $room = $this->lookups->remember('room:'.$roomId, fn () => Rooms::query()->find($roomId));
        if (Rooms::isSharedType($room?->room_type)) {
            return null;
        }

        return $this->clash($attempt, 'room_id', $roomId, 'room_conflict', fn (Schedule $first, string $day, string $more): string => "Room is already booked on {$day} from {$first->start_time} to {$first->end_time} "
            ."for {$first->course?->course_code} ({$first->section?->section_name}){$more}.");
    }

    /** @param array<string, mixed> $attempt */
    private function facultyClash(array $attempt): ?array
    {
        if (empty($attempt['faculty_id'])) {
            return null;
        }

        return $this->clash($attempt, 'faculty_id', $attempt['faculty_id'], 'faculty_conflict', fn (Schedule $first, string $day): string => "Faculty is already teaching on {$day} from {$first->start_time} to {$first->end_time} "
            ."for {$first->course?->course_code} ({$first->section?->section_name}).");
    }

    /** @param array<string, mixed> $attempt */
    private function sectionClash(array $attempt): ?array
    {
        return $this->clash($attempt, 'section_id', $attempt['section_id'], 'section_conflict', fn (Schedule $first, string $day, string $more): string => "Section already has a class on {$day} from {$first->start_time} to {$first->end_time} "
            ."({$first->course?->course_code}){$more}.");
    }

    /**
     * Every schedule holding `$column = $value` that overlaps the attempt,
     * folded into one violation.
     *
     * @param  array<string, mixed>  $attempt
     * @param  callable(Schedule, string, string): string  $message  first clash, day, " and N more"
     * @return array<string, mixed>|null
     */
    private function clash(array $attempt, string $column, mixed $value, string $rule, callable $message): ?array
    {
        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflicts = Schedule::where($column, $value)
            ->where('semester_id', $attempt['semester_id'])
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', (string) $attempt['end_time'])
            ->where('end_time', '>', (string) $attempt['start_time'])
            ->with(['course', 'section'])
            ->orderBy('start_time')
            ->get();

        if ($conflicts->isEmpty()) {
            return null;
        }

        $first = $conflicts->first();
        $more = $conflicts->count() > 1 ? ' and '.($conflicts->count() - 1).' more' : '';

        return [
            'rule' => $rule,
            'message' => $message($first, $day, $more),
            'conflicting_schedule_id' => $first->id,
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }

    /** @param array<string, mixed> $attempt */
    private function sameOnlineCourseClash(array $attempt): ?array
    {
        if ((string) ($attempt['mode'] ?? 'on-site') !== 'online') {
            return null;
        }

        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflict = Schedule::query()
            ->where('course_id', RuleSupport::courseId($attempt))
            ->where('section_id', '!=', (int) $attempt['section_id'])
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('mode', 'online')
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', (string) $attempt['end_time'])
            ->where('end_time', '>', (string) $attempt['start_time'])
            ->with(['course', 'section'])
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'subject_section_time_conflict',
            'message' => "{$conflict->course?->course_code} is already scheduled for another section ({$conflict->section?->section_name}) on {$day} from {$conflict->start_time} to {$conflict->end_time}.",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }
}
