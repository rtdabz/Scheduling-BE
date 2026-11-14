<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class ClassDurationRule
{
    /**
     * @param  array<string, mixed>  $attempt
     * @param  list<array<string, mixed>>  $pending  the same save's other operations; counted only for a Consecutive Days row, so
     *                                              a whole run is judged together while other shapes keep their per-row check
     * @return array<string, mixed>|null
     */
    public function check(array $attempt, AttemptRecords $records, array $pending = []): ?array
    {
        $course = $records->course;
        $placed = SchedulingPolicy::placedCourse($course, $records->section->curriculum_id === null ? null : (int) $records->section->curriculum_id);
        $meetingType = isset($attempt['meeting_type']) ? (string) $attempt['meeting_type'] : null;
        $isIntegratedSession = SchedulingPolicy::isIntegratedSession($course, $meetingType, $attempt['split_group_id'] ?? null);
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);
        $rows = Schedule::query()
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('section_id', (int) $records->section->id)
            ->where('course_id', (int) $course->id)
            ->with('split:id,schedule_id,split_group_id,meeting_type')
            ->get(['id', 'start_time', 'end_time', 'preferred_pattern']);
        if ($isIntegratedSession) {
            $rows = $rows->filter(static fn (Schedule $row): bool => $row->meeting_type === $meetingType
                && SchedulingPolicy::isIntegratedSession($course, $row->meeting_type, $row->split_group_id));
        }
        $minutesOf = static fn (Schedule $row): int => max(0, RuleSupport::durationMinutes((string) $row->start_time, (string) $row->end_time));

        $minutesBefore = $rows->sum($minutesOf);
        $kept = $rows->reject(static fn (Schedule $row): bool => in_array((int) $row->id, $ignoreIds, true));
        $attemptMinutes = max(0, RuleSupport::durationMinutes((string) $attempt['start_time'], (string) $attempt['end_time']));
        $totalMinutes = $kept->sum($minutesOf) + $attemptMinutes;

        $groupId = trim((string) ($attempt['split_group_id'] ?? ''));
        $isRun = SchedulingPolicy::consecutiveDayCount($attempt['preferred_pattern'] ?? null) !== null;
        $linkedMeetings = 1 + $kept->filter(static fn (Schedule $row): bool => $groupId !== ''
            && (string) $row->split_group_id === $groupId
            && SchedulingPolicy::consecutiveDayCount($row->preferred_pattern) !== null)->count();
        if ($isRun) {
            foreach ($pending as $other) {
                if ((int) ($other['semester_id'] ?? 0) !== (int) $attempt['semester_id']
                    || (int) ($other['section_id'] ?? 0) !== (int) $records->section->id
                    || RuleSupport::courseId($other) !== (int) $course->id) {
                    continue;
                }
                $totalMinutes += max(0, RuleSupport::durationMinutes((string) ($other['start_time'] ?? '00:00'), (string) ($other['end_time'] ?? '00:00')));
                if ($groupId !== '' && trim((string) ($other['split_group_id'] ?? '')) === $groupId
                    && SchedulingPolicy::consecutiveDayCount($other['preferred_pattern'] ?? null) !== null) {
                    $linkedMeetings++;
                }
            }
        }
        $allowedMinutes = ($isIntegratedSession
            ? SchedulingPolicy::componentMinutes($placed, $meetingType)
            : SchedulingPolicy::courseDurationMinutes($placed))
            * SchedulingPolicy::durationAllowanceRepeats($attempt['preferred_pattern'] ?? null, $groupId, $linkedMeetings);
        if ($allowedMinutes <= 0) {
            return null;
        }

        if ($totalMinutes <= $allowedMinutes || $totalMinutes <= $minutesBefore) {
            return null;
        }

        return [
            'rule' => 'class_duration',
            'message' => $isIntegratedSession
                ? sprintf(
                    '%s would meet %s of %s a week for this section, but its %s holds %s.',
                    (string) $course->course_code,
                    $this->hours($totalMinutes),
                    $meetingType,
                    $meetingType,
                    $this->hours($allowedMinutes),
                )
                : sprintf(
                    '%s would meet %s a week for this section, but the course carries at most %s.',
                    (string) $course->course_code,
                    $this->hours($totalMinutes),
                    $this->hours($allowedMinutes),
                ),
            'scheduled_minutes' => $totalMinutes,
            'allowed_minutes' => $allowedMinutes,
        ];
    }

    private function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').($hours === 1.0 ? ' hour' : ' hours');
    }
}
