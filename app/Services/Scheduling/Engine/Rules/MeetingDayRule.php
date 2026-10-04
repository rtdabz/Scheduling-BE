<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        if (empty($preferredPattern) || SchedulingPolicy::consecutiveDayCount($preferredPattern) !== null) {
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
            fn () => DepartmentCourseRules::query($departmentId)
                ->where('course_id', $courseId)
                ->whereNull('section_id')
                ->value('forced_day'),
        );

        return self::forcedDayMismatch(is_string($forcedDay) ? $forcedDay : null, $day);
    }

    /** @return array<string, mixed>|null */
    public function sundayClasses(string $day, AttemptRecords $records): ?array
    {
        if ($day !== 'Sunday') {
            return null;
        }

        $departmentId = $records->departmentId();
        $enabled = $this->lookups->remember(
            "sundayClasses:{$departmentId}",
            fn () => (bool) DB::table('departments')->where('id', $departmentId)->value('sunday_classes_enabled'),
        );

        return self::sundayClassesMismatch((bool) $enabled, $day);
    }

    /**
     * @return array{rule: string, message: string}|null
     */
    public static function sundayClassesMismatch(bool $sundayClassesEnabled, string $day): ?array
    {
        if ($day !== 'Sunday' || $sundayClassesEnabled) {
            return null;
        }

        return [
            'rule' => 'sunday_classes',
            'message' => 'Sunday classes are not enabled for this department. The department secretary can turn them on in Scheduling Settings.',
        ];
    }

    /**
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
