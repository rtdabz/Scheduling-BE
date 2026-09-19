<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * slot_grid, operating_hours, field_evening_window.
 *
 * Whether the meeting's times are real, aligned to the 30-minute grid, inside
 * the institution's opening hours, and — for field courses — finished by the
 * institution's field end time.
 *
 * slotGrid() and withinOperatingHours() are pure and static so the constraint
 * kernel runs these same checks, passing the hours pinned in its snapshot;
 * RuleEngine passes nothing and gets the live settings.
 */
final class OperatingHoursRule
{
    /** @return array<string, mixed>|null */
    public static function slotGrid(string $startTime, string $endTime, ?string $openingTime = null): ?array
    {
        $startMinutes = RuleSupport::timeToMinutes($startTime);
        $endMinutes = RuleSupport::timeToMinutes($endTime);

        if ($startMinutes === null || $endMinutes === null) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule start and end times must be valid time values.',
            ];
        }

        if ($endMinutes <= $startMinutes) {
            return [
                'rule' => 'operating_hours',
                'message' => 'Schedule end time must be after start time.',
            ];
        }

        $opening = SchedulingPolicy::timeToMinutes($openingTime ?? SchedulingPolicy::openingTime());
        if (($startMinutes - $opening) % SchedulingPolicy::SLOT_MINUTES !== 0
            || ($endMinutes - $opening) % SchedulingPolicy::SLOT_MINUTES !== 0) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule times must align to 30-minute scheduling slots.',
            ];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function withinOperatingHours(string $startTime, string $endTime, ?string $openingTime = null, ?string $closingTime = null): ?array
    {
        $start = SchedulingPolicy::normalizeTime($startTime);
        $end = SchedulingPolicy::normalizeTime($endTime);
        $opening = SchedulingPolicy::normalizeTime($openingTime ?? SchedulingPolicy::openingTime());
        $closing = SchedulingPolicy::normalizeTime($closingTime ?? SchedulingPolicy::closingTime());

        if ($start < $opening) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule starts at {$startTime}, which is before operating hours begin ("
                    .date('g:i A', strtotime($opening)).').',
            ];
        }

        if ($end > $closing) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule ends at {$endTime}, which exceeds operating hours ("
                    .date('g:i A', strtotime($closing)).' cutoff).',
            ];
        }

        return null;
    }

    /**
     * Field courses end by the institution's field end time, which the VPAA sets
     * beside the operating hours (SchedulingPolicy::fieldDayEndTime). It was a
     * hardcoded 5:00 PM; setting it to the closing time allows evening field
     * classes.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function fieldEveningWindow(array $attempt, AttemptRecords $records): ?array
    {
        $departmentId = $records->departmentId();
        $isFieldPlacement = $records->mode === 'field' || SchedulingPolicy::isFieldCourse($records->course, $departmentId);
        if (! $isFieldPlacement) {
            return null;
        }

        return self::fieldEveningMismatch((string) ($attempt['end_time'] ?? ''), SchedulingPolicy::fieldDayEndTime());
    }

    /**
     * field_evening_window for a meeting already known to be a field placement.
     * Shared with the constraint kernel, which passes its snapshot's field end
     * time instead of the live setting.
     *
     * @return array{rule: string, message: string}|null
     */
    public static function fieldEveningMismatch(string $endTime, string $fieldEndTime): ?array
    {
        if (SchedulingPolicy::timeToMinutes($endTime) <= SchedulingPolicy::timeToMinutes($fieldEndTime)) {
            return null;
        }

        return [
            'rule' => 'field_evening_window',
            'message' => 'Field courses must end by '.date('g:i A', strtotime($fieldEndTime)).'.',
        ];
    }
}
