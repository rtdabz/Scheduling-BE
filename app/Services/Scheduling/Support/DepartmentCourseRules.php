<?php

namespace App\Services\Scheduling\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DepartmentCourseRules
{
    public const TABLE = 'department_course_rules';

    public const RULE_REQUIRED_DAY = 'required_day';

    public const RULE_FIELD = 'field';

    public const RULE_CONSECUTIVE = 'consecutive';

    private const RULE_COLUMNS = [
        self::RULE_REQUIRED_DAY => ['forced_day' => null],
        self::RULE_FIELD => ['is_field' => false],
        self::RULE_CONSECUTIVE => ['consecutive_day_count' => null, 'preferred_start_day' => null, 'meeting_days' => null],
    ];

    private const COLUMNS = [
        'department_id', 'course_id', 'section_id', 'forced_day', 'is_field',
        'consecutive_day_count', 'preferred_start_day', 'meeting_days',
    ];

    /**
     * @var array{department_id: int, scope: list<int>, rows: list<array<string, mixed>>}|null
     */
    private static ?array $override = null;

    /**
     * @param  array{forced_day_rules?: list<array<string, mixed>>, consecutive_day_rules?: list<array<string, mixed>>, field_course_codes?: list<string>, scope_course_ids?: list<int>}|null  $rules
     */
    public static function withOverride(int $departmentId, ?array $rules, \Closure $callback): mixed
    {
        if ($rules === null || self::$override !== null) {
            return $callback();
        }

        self::$override = self::buildOverride($departmentId, $rules);
        SchedulingPolicy::clearFieldCourseCache();

        try {
            return $callback();
        } finally {
            self::$override = null;
            SchedulingPolicy::clearFieldCourseCache();
        }
    }

    public static function query(int $departmentId): Builder
    {
        if (self::$override !== null && self::$override['department_id'] === $departmentId) {
            return DB::query()
                ->fromSub(self::overriddenRules($departmentId), self::TABLE)
                ->where(self::TABLE.'.department_id', $departmentId);
        }

        return self::savedQuery($departmentId);
    }

    private static function savedQuery(int $departmentId): Builder
    {
        return DB::table(self::TABLE)->where(self::TABLE.'.department_id', $departmentId);
    }

    private static function overriddenRules(int $departmentId): Builder
    {
        $override = self::$override;
        $saved = DB::table(self::TABLE)
            ->where('department_id', $departmentId)
            ->whereNotIn('course_id', $override['scope'])
            ->select(self::COLUMNS);

        foreach ($override['rows'] as $row) {
            $saved->unionAll(DB::query()->selectRaw(
                implode(', ', array_map(static fn (string $column): string => "? as {$column}", self::COLUMNS)),
                array_map(static fn (string $column): mixed => $row[$column] ?? null, self::COLUMNS),
            ));
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{department_id: int, scope: list<int>, rows: list<array<string, mixed>>}
     */
    private static function buildOverride(int $departmentId, array $rules): array
    {
        $scope = [];
        $rows = [];
        $row = static function (int $courseId, ?int $sectionId) use (&$rows, &$scope, $departmentId): void {
            $scope[$courseId] = $courseId;
            $rows[$courseId.':'.($sectionId ?? 'all')] ??= [
                'department_id' => $departmentId,
                'course_id' => $courseId,
                'section_id' => $sectionId,
                'forced_day' => null,
                'is_field' => 0,
                'consecutive_day_count' => null,
                'preferred_start_day' => null,
                'meeting_days' => null,
            ];
        };

        foreach ($rules['scope_course_ids'] ?? [] as $courseId) {
            $scope[(int) $courseId] = (int) $courseId;
        }

        foreach ($rules['forced_day_rules'] ?? [] as $rule) {
            $row((int) $rule['course_id'], null);
            $rows[(int) $rule['course_id'].':all']['forced_day'] = (string) $rule['day'];
        }

        foreach ($rules['consecutive_day_rules'] ?? [] as $rule) {
            $sectionId = isset($rule['section_id']) ? (int) $rule['section_id'] : null;
            $courseId = (int) $rule['course_id'];
            $row($courseId, $sectionId);
            $meetingDays = SchedulingPolicy::parseMeetingDays($rule['meeting_days'] ?? null);
            $rows[$courseId.':'.($sectionId ?? 'all')] = [
                ...$rows[$courseId.':'.($sectionId ?? 'all')],
                'consecutive_day_count' => $meetingDays !== null ? count($meetingDays) : (int) $rule['day_count'],
                'preferred_start_day' => $meetingDays[0] ?? $rule['preferred_start_day'] ?? null,
                'meeting_days' => $meetingDays !== null ? implode(',', $meetingDays) : null,
            ];
        }

        $fieldCodes = array_flip(array_map(
            static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code),
            $rules['field_course_codes'] ?? [],
        ));
        if ($fieldCodes !== [] && $scope !== []) {
            $codes = DB::table('courses')->whereIn('id', array_values($scope))->pluck('course_code', 'id');
            foreach ($codes as $courseId => $code) {
                if (isset($fieldCodes[SchedulingPolicy::normalizeCourseCode((string) $code)])) {
                    $row((int) $courseId, null);
                    $rows[(int) $courseId.':all']['is_field'] = 1;
                }
            }
        }

        return ['department_id' => $departmentId, 'scope' => array_values($scope), 'rows' => array_values($rows)];
    }

    /**
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
     * @param  list<int>  $courseIds
     */
    public static function clear(int $departmentId, string $rule, array $courseIds): void
    {
        if ($courseIds === []) {
            return;
        }

        self::lockDepartment($departmentId);

        self::savedQuery($departmentId)
            ->whereIn('course_id', $courseIds)
            ->update([...self::RULE_COLUMNS[$rule], 'updated_at' => now()]);

        self::savedQuery($departmentId)
            ->whereNull('forced_day')
            ->where('is_field', false)
            ->whereNull('consecutive_day_count')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(int $departmentId, int $courseId, ?int $sectionId, array $values): void
    {
        self::lockDepartment($departmentId);

        $row = self::savedQuery($departmentId)
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

    private static function lockDepartment(int $departmentId): void
    {
        DB::table('departments')->where('id', $departmentId)->lockForUpdate()->value('id');
    }
}
