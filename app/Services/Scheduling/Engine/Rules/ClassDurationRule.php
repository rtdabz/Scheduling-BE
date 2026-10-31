<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class ClassDurationRule
{
    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function check(array $attempt, AttemptRecords $records): ?array
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
            ->get(['id', 'start_time', 'end_time']);
        if ($isIntegratedSession) {
            $rows = $rows->filter(static fn (Schedule $row): bool => $row->meeting_type === $meetingType
                && SchedulingPolicy::isIntegratedSession($course, $row->meeting_type, $row->split_group_id));
        }
        $minutesOf = static fn (Schedule $row): int => max(0, RuleSupport::durationMinutes((string) $row->start_time, (string) $row->end_time));

        $minutesBefore = $rows->sum($minutesOf);
        $kept = $rows->reject(static fn (Schedule $row): bool => in_array((int) $row->id, $ignoreIds, true));
        $attemptMinutes = max(0, RuleSupport::durationMinutes((string) $attempt['start_time'], (string) $attempt['end_time']));
        $totalMinutes = $kept->sum($minutesOf) + $attemptMinutes;

        $allowedMinutes = $isIntegratedSession
            ? SchedulingPolicy::componentMinutes($placed, $meetingType)
            : SchedulingPolicy::courseDurationMinutes($placed);
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
