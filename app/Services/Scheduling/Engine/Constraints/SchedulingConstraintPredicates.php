<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class SchedulingConstraintPredicates
{
    public static function overlaps(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        return SchedulingPolicy::timeToMinutes($leftStart) < SchedulingPolicy::timeToMinutes($rightEnd)
            && SchedulingPolicy::timeToMinutes($leftEnd) > SchedulingPolicy::timeToMinutes($rightStart);
    }

    /** @param array<string, mixed>|ScheduleRow $other */
    public static function rowOverlaps(ScheduleRow $row, array|ScheduleRow $other): bool
    {
        return $row->day === self::stringValue($other, 'day')
            && self::overlaps(
                $row->startTime,
                $row->endTime,
                self::stringValue($other, 'start_time'),
                self::stringValue($other, 'end_time'),
            );
    }

    // Course classification lives in SchedulingPolicy, shared with RuleEngine
    // and the solver. These keep the kernel call sites short; the snapshot's
    // field-course codes stand in for the database lookup.

    /** @param array<string, mixed> $course */
    public static function isNstpCourse(array $course): bool
    {
        return SchedulingPolicy::isNstpCourse($course);
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<string>  $fieldCourseCodes
     */
    public static function isFieldCourse(array $course, array $fieldCourseCodes): bool
    {
        return SchedulingPolicy::isFieldCourse($course, fieldCourseCodes: $fieldCourseCodes);
    }

    /** @param array<string, mixed> $course */
    public static function isLaboratoryCourse(array $course): bool
    {
        return SchedulingPolicy::isLaboratoryCourse($course);
    }

    /** @param array<string, mixed> $course */
    public static function isMajorCourse(array $course): bool
    {
        return SchedulingPolicy::isMajorCourse($course);
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<string>  $fieldCourseCodes
     */
    public static function effectiveRoomType(array $course, array $fieldCourseCodes, ?string $meetingType = null): string
    {
        return SchedulingPolicy::effectiveRoomType($course, null, $meetingType, $fieldCourseCodes);
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<string>  $fieldCourseCodes
     */
    public static function allowsRoomTba(array $course, array $fieldCourseCodes, ?string $meetingType = null): bool
    {
        return SchedulingPolicy::allowsRoomTbaFallback($course, null, $meetingType, $fieldCourseCodes);
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<string>  $fieldCourseCodes
     */
    public static function allowsOnline(array $course, array $fieldCourseCodes, ?string $meetingType = null): bool
    {
        return SchedulingPolicy::allowsOnlineRoomFallback($course, null, $meetingType, $fieldCourseCodes);
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  array<string, mixed>  $room
     */
    public static function canUseLaboratoryForLecture(array $course, array $room): bool
    {
        return SchedulingPolicy::laboratoryServesLecture($course, $room);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private static function stringValue(array|ScheduleRow $row, string $key): string
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'day' => $row->day,
                'start_time' => $row->startTime,
                'end_time' => $row->endTime,
                default => '',
            };
        }

        return (string) ($row[$key] ?? '');
    }
}
