<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Constraints;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\SchedulingPolicy;

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

    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $otherRows
     */
    public static function concurrencyExceeds(ScheduleRow $row, array $otherRows, int $capacity): bool
    {
        $events = [
            [SchedulingPolicy::timeToMinutes($row->startTime), 1],
            [SchedulingPolicy::timeToMinutes($row->endTime), -1],
        ];

        foreach ($otherRows as $other) {
            $events[] = [SchedulingPolicy::timeToMinutes(self::stringValue($other, 'start_time')), 1];
            $events[] = [SchedulingPolicy::timeToMinutes(self::stringValue($other, 'end_time')), -1];
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]),
        );

        $active = 0;
        foreach ($events as [, $delta]) {
            $active += $delta;
            if ($active > max(1, $capacity)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $course */
    public static function isNstpCourse(array $course): bool
    {
        $code = strtoupper((string) ($course['course_code'] ?? ''));
        $name = strtoupper((string) ($course['course_name'] ?? ''));
        $category = strtolower((string) ($course['course_category'] ?? ''));

        if (in_array($category, ['nstp', 'rotc', 'cwts', 'lts'], true)) {
            return true;
        }

        foreach (['NSTP', 'ROTC', 'CWTS', 'LTS'] as $keyword) {
            if (str_contains($code, $keyword) || str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $course */
    public static function isFieldCourse(array $course, array $fieldCourseCodes): bool
    {
        $normalizedFieldCodes = array_map(self::normalizeCourseCode(...), $fieldCourseCodes);

        return (string) ($course['room_type_required'] ?? '') === 'field'
            || self::isNstpCourse($course)
            || in_array(self::normalizeCourseCode((string) ($course['course_code'] ?? '')), $normalizedFieldCodes, true);
    }

    /** @param array<string, mixed> $course */
    public static function isLaboratoryCourse(array $course): bool
    {
        return (int) ($course['lab_hours'] ?? 0) > 0
            || (string) ($course['room_type_required'] ?? '') === 'laboratory';
    }

    /** @param array<string, mixed> $course */
    public static function isMajorCourse(array $course): bool
    {
        return strtolower(trim((string) ($course['course_category'] ?? $course['subject_category'] ?? ''))) === 'major';
    }

    /** @param array<string, mixed> $course */
    public static function effectiveRoomType(array $course, array $fieldCourseCodes, ?string $meetingType = null): string
    {
        // Field designation is authoritative even when a legacy split row
        // carries lecture/laboratory meeting metadata.
        if (self::isFieldCourse($course, $fieldCourseCodes)) {
            return 'field';
        }

        if ($meetingType !== null && in_array($meetingType, ['lecture', 'laboratory', 'field'], true)) {
            return $meetingType;
        }

        return self::isLaboratoryCourse($course)
            ? 'laboratory'
            : (string) ($course['room_type_required'] ?: 'lecture');
    }

    /** @param array<string, mixed> $course */
    public static function allowsRoomTba(array $course, array $fieldCourseCodes, ?string $meetingType = null): bool
    {
        return self::effectiveRoomType($course, $fieldCourseCodes, $meetingType) === 'laboratory';
    }

    /** @param array<string, mixed> $course */
    public static function allowsOnline(array $course, array $fieldCourseCodes, ?string $meetingType = null): bool
    {
        return self::effectiveRoomType($course, $fieldCourseCodes, $meetingType) === 'lecture'
            && ! self::isFieldCourse($course, $fieldCourseCodes)
            && ($meetingType === 'lecture' || ! self::isLaboratoryCourse($course));
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  array<string, mixed>  $room
     */
    public static function canUseLaboratoryForLecture(array $course, array $room): bool
    {
        return self::isMajorCourse($course)
            && (int) ($course['lecture_hours'] ?? 0) > 0
            && (int) ($course['lab_hours'] ?? 0) === 0
            && (string) ($course['room_type_required'] ?? 'lecture') === 'lecture'
            && (string) ($room['room_type'] ?? '') === 'laboratory'
            && (bool) ($room['allow_lecture_usage'] ?? false);
    }

    private static function normalizeCourseCode(string $code): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $code) ?? $code));
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
