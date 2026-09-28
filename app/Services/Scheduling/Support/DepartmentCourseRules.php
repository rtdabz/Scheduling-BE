<?php

namespace App\Services\Scheduling\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The department's per-course scheduling rules, one row per department,
 * course and section (null = course-wide):
 *
 * - Required Day (`forced_day`) and Field Course (`is_field`) are course-wide.
 * - Consecutive Days (`consecutive_day_count`, `preferred_start_day`,
 *   `meeting_days`) is course-wide or per section; a section's own row wins.
 *
 * Each rule's columns are cleared and set independently, and a row left with
 * no rule is removed. Readers filter on their own columns, so they never see
 * another rule's row.
 */
final class DepartmentCourseRules
{
    public const TABLE = 'department_course_rules';

    public const RULE_REQUIRED_DAY = 'required_day';

    public const RULE_FIELD = 'field';

    public const RULE_CONSECUTIVE = 'consecutive';

    /** Each rule's columns, at their "not set" value. */
    private const RULE_COLUMNS = [
        self::RULE_REQUIRED_DAY => ['forced_day' => null],
        self::RULE_FIELD => ['is_field' => false],
        self::RULE_CONSECUTIVE => ['consecutive_day_count' => null, 'preferred_start_day' => null, 'meeting_days' => null],
    ];

    public static function query(int $departmentId): Builder
    {
        return DB::table(self::TABLE)->where(self::TABLE.'.department_id', $departmentId);
    }

    /**
     * Field-course codes for a department, normalized, one per code. Read
     * through the course, so a renamed code carries its setting with it.
     *
     * @return list<string>
     */
    public static function fieldCourseCodes(int $departmentId): array
    {
        return self::query($departmentId)
            ->join('courses', 'courses.id', '=', self::TABLE.'.course_id')
            ->where(self::TABLE.'.is_field', true)
            ->pluck('courses.course_code')
            ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Unsets one rule for the given courses, on every row (course-wide and
     * per section), then drops rows that hold no rule any more.
     *
     * @param  list<int>  $courseIds
     */
    public static function clear(int $departmentId, string $rule, array $courseIds): void
    {
        if ($courseIds === []) {
            return;
        }

        self::lockDepartment($departmentId);

        self::query($departmentId)
            ->whereIn('course_id', $courseIds)
            ->update([...self::RULE_COLUMNS[$rule], 'updated_at' => now()]);

        self::query($departmentId)
            ->whereNull('forced_day')
            ->where('is_field', false)
            ->whereNull('consecutive_day_count')
            ->delete();
    }

    /**
     * Sets rule columns on the (course, section) row, creating it if needed.
     * Other rules on the row are left alone.
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(int $departmentId, int $courseId, ?int $sectionId, array $values): void
    {
        self::lockDepartment($departmentId);

        $row = self::query($departmentId)
            ->where('course_id', $courseId)
            ->when(
                $sectionId === null,
                fn (Builder $query) => $query->whereNull('section_id'),
                fn (Builder $query) => $query->where('section_id', $sectionId),
            );

        if ($row->exists()) {
            $row->update([...$values, 'updated_at' => now()]);

            return;
        }

        DB::table(self::TABLE)->insert([
            'department_id' => $departmentId,
            'course_id' => $courseId,
            'section_id' => $sectionId,
            ...$values,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The unique index does not hold for a null section_id (NULLs never
     * collide), so writers lock the department row: inside the caller's
     * transaction, a concurrent save waits instead of inserting a second
     * course-wide row.
     */
    private static function lockDepartment(int $departmentId): void
    {
        DB::table('departments')->where('id', $departmentId)->lockForUpdate()->value('id');
    }
}
