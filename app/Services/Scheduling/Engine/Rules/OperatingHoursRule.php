<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Departments;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * slot_grid, operating_hours, field_evening_window.
 *
 * Whether the meeting's times are real, aligned to the 30-minute grid, inside
 * the institution's opening hours, and — for field courses — finished by the
 * daytime boundary unless the department allows evening field classes.
 */
final class OperatingHoursRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /** @return array<string, mixed>|null */
    public function slotGrid(string $startTime, string $endTime): ?array
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

        $opening = SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime());
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
    public function withinOperatingHours(string $startTime, string $endTime): ?array
    {
        $start = SchedulingPolicy::normalizeTime($startTime);
        $end = SchedulingPolicy::normalizeTime($endTime);

        if ($start < SchedulingPolicy::openingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule starts at {$startTime}, which is before operating hours begin ("
                    .date('g:i A', strtotime(SchedulingPolicy::openingTime())).').',
            ];
        }

        if ($end > SchedulingPolicy::closingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule ends at {$endTime}, which exceeds operating hours ("
                    .date('g:i A', strtotime(SchedulingPolicy::closingTime())).' cutoff).',
            ];
        }

        return null;
    }

    /**
     * Field courses stop at 17:00 unless the department opts into evening use.
     *
     * The setting was previously read only by CspSolver, so it steered generation
     * but not manual placement — the Settings page promised a limit that a
     * drag-and-drop could ignore (audit finding #41).
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

        $eveningEnabled = (bool) $this->lookups->remember(
            'fieldEvening:'.$departmentId,
            fn () => Departments::query()->whereKey($departmentId)->value('field_evening_schedule_enabled') ?? false,
        );
        if ($eveningEnabled) {
            return null;
        }

        if (SchedulingPolicy::normalizeTime((string) ($attempt['end_time'] ?? '')) <= SchedulingPolicy::FIELD_DAY_END_TIME) {
            return null;
        }

        return [
            'rule' => 'field_evening_window',
            'message' => 'Field courses must end by 5:00 PM unless evening field scheduling is enabled for this department.',
        ];
    }
}
