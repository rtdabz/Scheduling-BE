<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Support;

use App\Models\Schedule;

/**
 * One wording for every scheduling conflict a user sees.
 *
 * Shape: "<What> is unavailable on <Day> <requested time>. <Who holds it, with what, when>. <What to do>."
 * Example: "Room BA 201 is unavailable on Mon 07:00–10:00. It is booked by CIT for PATH FIT 2 (BSIT 1I)
 * from 07:00–09:00. Please choose another time or room."
 *
 * A "booking" describes the existing class that holds the resource:
 * array{department?: ?string, course?: ?string, section?: ?string, start: string, end: string, pending?: bool}
 * Set "pending" when the other class is part of the same save rather than an existing record.
 */
final class ConflictMessage
{
    public const DASH = '–';

    /** @param array<string, mixed> $booking */
    public static function room(?string $room, string $day, string $start, string $end, array $booking, int $more = 0): string
    {
        $department = self::text($booking['department'] ?? null);

        return self::subject('Room', $room, 'The selected room')." is unavailable on ".self::slot($day, $start, $end).'. '
            .'It is booked'.($department === null ? '' : " by {$department}")
            .' for '.self::classLabel($booking).' from '.self::held($booking).self::more($more).'. '
            .'Please choose another time or room.';
    }

    /** @param array<string, mixed> $booking */
    public static function instructor(?string $name, string $day, string $start, string $end, array $booking, int $more = 0, string $note = ''): string
    {
        $department = self::text($booking['department'] ?? null);

        return self::subject('Instructor', $name, 'The instructor').' is unavailable on '.self::slot($day, $start, $end).'. '
            .'They are already teaching '.self::classLabel($booking)
            .($department === null ? '' : " for {$department}")
            .' from '.self::held($booking).self::more($more).'. '
            .($note === '' ? '' : trim($note).' ')
            .'Please choose another time or instructor.';
    }

    /** @param array<string, mixed> $booking */
    public static function section(?string $section, string $day, string $start, string $end, array $booking, int $more = 0): string
    {
        return self::subject('Section', $section, 'This section').' is unavailable on '.self::slot($day, $start, $end).'. '
            .'It already has '.(self::text($booking['course'] ?? null) ?? 'another class')
            .' from '.self::held($booking).self::more($more).'. '
            .'Please choose another time.';
    }

    /** @param array<string, mixed> $booking */
    public static function onlineCourse(?string $course, string $day, string $start, string $end, array $booking): string
    {
        $section = self::text($booking['section'] ?? null) ?? 'another section';
        $department = self::text($booking['department'] ?? null);

        return 'Online class '.(self::text($course) ?? 'for this course').' is unavailable on '.self::slot($day, $start, $end).'. '
            ."It is already running online for {$section}".($department === null ? '' : " ({$department})")
            .' from '.self::held($booking).'. '
            .'Please choose another time or delivery mode.';
    }

    /**
     * Two existing classes that collide (conflict inbox): both sides are named, then one fix is suggested.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    public static function doubleBooked(string $rule, string $day, string $overlapStart, string $overlapEnd, array $first, array $second): string
    {
        $slot = self::slot($day, $overlapStart, $overlapEnd);
        $held = static fn (array $booking, bool $withSection = true): string => ($withSection ? self::classLabel($booking) : (self::text($booking['course'] ?? null) ?? 'another class'))
            .' from '.self::window($booking);
        $byDepartment = static function (array $booking) use ($held): string {
            $department = self::text($booking['department'] ?? null);

            return ($department === null ? '' : "{$department} has ").$held($booking);
        };

        return match ($rule) {
            'room_conflict' => self::subject('Room', $first['room'] ?? null, 'A room')." is double-booked on {$slot}. "
                .ucfirst($byDepartment($first)).', and '.$byDepartment($second).'. '
                .'Move one class to another time or room.',
            'section_conflict' => self::subject('Section', $first['section'] ?? null, 'A section')." is double-booked on {$slot}. "
                .'It has '.$held($first, false).' and '.$held($second, false).'. '
                .'Move one class to another time.',
            'faculty_conflict' => self::subject('Instructor', $first['instructor'] ?? null, 'An instructor')." is double-booked on {$slot}. "
                .'They teach '.$held($first).' and '.$held($second).'. '
                .'Move one class or reassign the instructor.',
            'subject_section_time_conflict' => 'Online class '.(self::text($first['course'] ?? null) ?? 'for this course')." runs for two sections at once on {$slot}: "
                .(self::text($first['section'] ?? null) ?? 'one section').' from '.self::window($first).' and '
                .(self::text($second['section'] ?? null) ?? 'another section').' from '.self::window($second).'. '
                .'Move one class or change its delivery mode.',
            default => ucfirst($held($first))." conflicts with {$held($second)} on {$slot}.",
        };
    }

    /**
     * The booking record for an existing schedule; load course, section and department first to avoid extra queries.
     *
     * @return array{department: ?string, course: ?string, section: ?string, start: string, end: string}
     */
    public static function bookingFrom(Schedule $schedule): array
    {
        return [
            'department' => self::text($schedule->department?->department_code),
            'course' => self::text($schedule->course?->course_code),
            'section' => self::text($schedule->section?->section_name),
            'start' => (string) $schedule->start_time,
            'end' => (string) $schedule->end_time,
        ];
    }

    /** "Monday" -> "Mon"; unknown values pass through. */
    public static function day(string $day): string
    {
        $day = trim($day);

        return in_array($day, SchedulingPolicy::DAYS, true) ? substr($day, 0, 3) : $day;
    }

    /** "07:00:00" -> "07:00". */
    public static function time(string $time): string
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) === 1
            ? sprintf('%02d:%s', (int) $m[1], $m[2])
            : trim($time);
    }

    public static function slot(string $day, string $start, string $end): string
    {
        return self::day($day).' '.self::time($start).self::DASH.self::time($end);
    }

    /** @param array<string, mixed> $booking */
    private static function held(array $booking): string
    {
        return self::window($booking).(($booking['pending'] ?? false) === true ? ' in this same save' : '');
    }

    /** @param array<string, mixed> $booking */
    private static function window(array $booking): string
    {
        return self::time((string) ($booking['start'] ?? '')).self::DASH.self::time((string) ($booking['end'] ?? ''));
    }

    /** @param array<string, mixed> $booking */
    private static function classLabel(array $booking): string
    {
        $course = self::text($booking['course'] ?? null) ?? 'another class';
        $section = self::text($booking['section'] ?? null);

        return $section === null ? $course : "{$course} ({$section})";
    }

    private static function subject(string $noun, mixed $name, string $fallback): string
    {
        $name = self::text($name);

        return $name === null ? $fallback : "{$noun} {$name}";
    }

    private static function more(int $more): string
    {
        return $more > 0 ? ", plus {$more} more ".($more === 1 ? 'class' : 'classes') : '';
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
