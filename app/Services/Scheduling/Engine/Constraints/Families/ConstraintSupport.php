<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Helpers every constraint family shares: building a violation, and reading a
 * field from either a candidate ScheduleRow or a persisted schedule array, which
 * the families compare against each other interchangeably.
 */
final class ConstraintSupport
{
    /** @param array<string, mixed> $context */
    public static function violation(string $ruleId, string $message, string $scope = 'schedule_row', array $context = []): ConstraintViolation
    {
        $severity = (string) (SchedulingPolicy::CONSTRAINT_CATALOG[$ruleId]['severity'] ?? 'hard');

        return new ConstraintViolation($ruleId, $message, $severity, $scope, $context);
    }

    /** @param array<string, mixed>|ScheduleRow $other */
    public static function sameSemester(ScheduleRow $row, array|ScheduleRow $other): bool
    {
        return $row->semesterId === self::intValue($other, 'semester_id');
    }

    /**
     * @param  array<string, mixed>|ScheduleRow  $other
     * @return array<string, int>
     */
    public static function conflictContext(array|ScheduleRow $other): array
    {
        $id = self::nullableIntValue($other, 'id');

        return $id === null ? [] : ['conflicting_schedule_id' => $id];
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    public static function intValue(array|ScheduleRow $row, string $key): int
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'semester_id' => $row->semesterId,
                'section_id' => $row->sectionId,
                'course_id' => $row->courseId,
                'department_id' => $row->departmentId,
                default => 0,
            };
        }

        return (int) ($row[$key] ?? 0);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    public static function nullableIntValue(array|ScheduleRow $row, string $key): ?int
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'faculty_id' => $row->facultyId,
                'room_id' => $row->roomId,
                default => null,
            };
        }

        $value = $row[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    public static function stringValue(array|ScheduleRow $row, string $key): string
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'mode' => $row->mode,
                default => '',
            };
        }

        return (string) ($row[$key] ?? '');
    }
}
