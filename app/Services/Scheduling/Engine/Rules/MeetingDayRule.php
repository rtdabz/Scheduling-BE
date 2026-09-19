<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * valid_day, preferred_pattern, field_day_constraint, minor_day_constraint,
 * forced_course_day.
 *
 * Which days a meeting may fall on:
 *  - NSTP (ROTC/CWTS/LTS): any day.
 *  - PATHFIT and other field courses: Monday to Friday.
 *  - Minor, non-field courses (GEC, GEE): Monday to Saturday.
 *  - A declared meeting pattern, or a department's forced day, narrows that.
 *
 * Majors may use any day; whether a Sunday major must be online is a delivery
 * question, answered by DeliveryModeRule.
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
    public function courseCategoryDay(string $day, AttemptRecords $records): ?array
    {
        $course = $records->course;

        // NSTP may use any day, and valid_day already rejects anything else, so
        // it only needs to be exempted from the field and minor limits below.
        if (SchedulingPolicy::isNstpCourse($course)) {
            return null;
        }

        if (SchedulingPolicy::isFieldCourse($course, $records->departmentId())) {
            return in_array($day, SchedulingPolicy::WEEKDAYS, true) ? null : [
                'rule' => 'field_day_constraint',
                'message' => 'PATHFIT and other field courses must be scheduled Monday through Friday.',
            ];
        }

        if (strtolower((string) ($course->course_category ?? 'major')) === 'minor') {
            return in_array($day, SchedulingPolicy::WEEKDAYS_AND_SATURDAY, true) ? null : [
                'rule' => 'minor_day_constraint',
                'message' => 'Minor courses (GEC, GEE, and similar) must be scheduled Monday through Saturday.',
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
