<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * valid_day, preferred_pattern, forced_course_day.
 *
 * Every course may use every day. A meeting is narrowed only by what the user
 * asked for -- a declared meeting pattern, or the day the department pinned the
 * course to -- never by the kind of course it is.
 *
 * The category day limits are gone: field courses were Monday to Friday, minors
 * Monday to Saturday, and a Sunday major had to be online. Sunday is now an
 * ordinary teaching day, so `field_day_constraint`, `minor_day_constraint` and
 * `major_sunday_mode_constraint` were removed along with the
 * `sunday_online_only_enabled` department switch that gated the last of them.
 */
final class MeetingDayRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /** @return array<string, mixed>|null */
    public static function validDay(string $day): ?array
    {
        if (in_array($day, SchedulingPolicy::PERSISTABLE_DAYS, true)) {
            return null;
        }

        return [
            'rule' => 'valid_day',
            'message' => "Unsupported schedule day '{$day}'.",
        ];
    }

    /** @return array<string, mixed>|null */
    public static function preferredPattern(string $day, ?string $preferredPattern): ?array
    {
        if (empty($preferredPattern)) {
            return null;
        }

        try {
            $allowedDays = SchedulingPolicy::allowedDaysForPattern($preferredPattern);
        } catch (InvalidArgumentException $exception) {
            return [
                'rule' => 'preferred_pattern',
                'message' => $exception->getMessage(),
            ];
        }

        if ($allowedDays !== null && ! in_array($day, $allowedDays, true)) {
            return [
                'rule' => 'preferred_pattern',
                'message' => "Preferred pattern conflict: '{$preferredPattern}' courses can only be scheduled on "
                    .implode(' or ', $allowedDays).", not {$day}.",
            ];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function forcedDay(string $day, AttemptRecords $records): ?array
    {
        $courseId = (int) $records->course->id;
        $departmentId = $records->departmentId();

        $forcedDay = $this->lookups->remember(
            "forcedDay:{$departmentId}:{$courseId}",
            fn () => DB::table('department_forced_course_days')
                ->where('department_id', $departmentId)
                ->where('course_id', $courseId)
                ->value('day'),
        );

        return self::forcedDayMismatch(is_string($forcedDay) ? $forcedDay : null, $day);
    }

    /**
     * forced_course_day: a course the department pinned to one day meets only
     * then. Shared with the constraint kernel, which reads the pin from its
     * snapshot.
     *
     * @return array{rule: string, message: string, required_day: string}|null
     */
    public static function forcedDayMismatch(?string $forcedDay, string $day): ?array
    {
        if ($forcedDay === null || $forcedDay === $day) {
            return null;
        }

        return [
            'rule' => 'forced_course_day',
            'message' => "This course is configured to meet on {$forcedDay}.",
            'required_day' => $forcedDay,
        ];
    }
}
