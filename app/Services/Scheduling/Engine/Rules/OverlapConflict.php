<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\Support\ConflictMessage;
use App\Services\Scheduling\Support\SchedulingPolicy;

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

        return $this->clash($attempt, 'room_id', $roomId, 'room_conflict', fn (Schedule $first, int $more): string => ConflictMessage::room(
            $room?->room_code,
            (string) $attempt['day'],
            (string) $attempt['start_time'],
            (string) $attempt['end_time'],
            ConflictMessage::bookingFrom($first),
            $more,
        ));
    }

    /** @param array<string, mixed> $attempt */
    private function facultyClash(array $attempt): ?array
    {
        if (empty($attempt['faculty_id'])) {
            return null;
        }

        $facultyId = (int) $attempt['faculty_id'];

        return $this->clash($attempt, 'faculty_id', $facultyId, 'faculty_conflict', function (Schedule $first, int $more) use ($attempt, $facultyId): string {
            $faculty = $this->lookups->remember('faculty:'.$facultyId, fn () => Faculty::find($facultyId));

            return ConflictMessage::instructor(
                $faculty === null ? null : trim("{$faculty->first_name} {$faculty->last_name}"),
                (string) $attempt['day'],
                (string) $attempt['start_time'],
                (string) $attempt['end_time'],
                ConflictMessage::bookingFrom($first),
                $more,
                in_array($first->status, SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES, true)
                    ? ''
                    : 'That class was recalled or returned for revision and still holds this instructor until it is re-approved.',
            );
        });
    }

    /** @param array<string, mixed> $attempt */
    private function sectionClash(array $attempt): ?array
    {
        $sectionId = (int) $attempt['section_id'];

        return $this->clash($attempt, 'section_id', $sectionId, 'section_conflict', fn (Schedule $first, int $more): string => ConflictMessage::section(
            $this->lookups->remember('section:'.$sectionId, fn () => Sections::find($sectionId))?->section_name,
            (string) $attempt['day'],
            (string) $attempt['start_time'],
            (string) $attempt['end_time'],
            ConflictMessage::bookingFrom($first),
            $more,
        ));
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @param  callable(Schedule, int): string  $message  first clash, number of further clashes
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
            ->with(['course', 'section', 'department'])
            ->orderBy('start_time')
            ->get();

        if ($conflicts->isEmpty()) {
            return null;
        }

        $first = $conflicts->first();

        return [
            'rule' => $rule,
            'message' => $message($first, $conflicts->count() - 1),
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
            ->with(['course', 'section', 'department'])
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'subject_section_time_conflict',
            'message' => ConflictMessage::onlineCourse(
                $conflict->course?->course_code,
                $day,
                (string) $attempt['start_time'],
                (string) $attempt['end_time'],
                ConflictMessage::bookingFrom($conflict),
            ),
            'conflicting_schedule_id' => $conflict->id,
        ];
    }
}
