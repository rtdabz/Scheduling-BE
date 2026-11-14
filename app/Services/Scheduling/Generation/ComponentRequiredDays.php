<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Run-scoped Lecture / Laboratory Required Days of Integrated courses.
 *
 * One normalizer and one row check are shared by generation, draft review and
 * the generated batch save, so the three never disagree. Components are matched
 * by their meeting type, never by meeting order, position or duration.
 */
final class ComponentRequiredDays
{
    public const KEY = 'component_required_days_by_course_id';

    public const COMPONENTS = ['lecture', 'laboratory'];

    /** Result key: section id => course id => {lecture?, laboratory?}. */
    public const REPORT_KEY = 'component_required_days';

    private const ERROR_KEY = 'component_required_days_by_course_id';

    /** Request validation rules for one section configuration list. */
    public static function rules(string $prefix = 'section_configs.*.'): array
    {
        return [
            $prefix.self::KEY => 'sometimes|nullable|array',
            $prefix.self::KEY.'.*' => 'nullable|array:'.implode(',', self::COMPONENTS),
            $prefix.self::KEY.'.*.lecture' => SchedulingPolicy::allowedDaysRule('sometimes|nullable'),
            $prefix.self::KEY.'.*.laboratory' => SchedulingPolicy::allowedDaysRule('sometimes|nullable'),
        ];
    }

    /**
     * Validates and normalizes one section's map. Empty entries are dropped;
     * a constraint that cannot be applied is rejected, never silently dropped.
     *
     * @param  list<int>  $courseIds  the section's courses in this generation
     * @param  list<int>  $integratedCourseIds  the section's eligible Integrated courses
     * @param  list<string>|null  $allowedDays  the run's Preferred Days (null = any day)
     * @return array<int, array{lecture?: string, laboratory?: string}>
     *
     * @throws ValidationException
     */
    public static function normalize(
        Sections $section,
        mixed $raw,
        array $courseIds,
        array $integratedCourseIds,
        ?array $allowedDays,
    ): array {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw)) {
            self::fail('Lecture and Laboratory Required Days must be listed by course.');
        }

        $inScope = array_flip(array_map('intval', $courseIds));
        $integrated = array_flip(array_map('intval', $integratedCourseIds));
        $normalized = [];
        foreach ($raw as $courseKey => $entry) {
            $courseId = (int) $courseKey;
            if ($entry === null || $entry === []) {
                continue;
            }
            if (! is_array($entry) || array_diff(array_keys($entry), self::COMPONENTS) !== []) {
                self::fail('Only a Lecture Required Day and a Laboratory Required Day can be set for an Integrated course.');
            }
            $days = [];
            foreach (self::COMPONENTS as $component) {
                $day = $entry[$component] ?? null;
                if ($day === null || $day === '') {
                    continue;
                }
                if (! is_string($day) || ! in_array($day, SchedulingPolicy::PERSISTABLE_DAYS, true)) {
                    self::fail(sprintf('The %s Required Day must be a day of the week.', ucfirst($component)));
                }
                $days[$component] = $day;
            }
            if ($days === []) {
                continue;
            }

            $code = self::courseCode($courseId);
            if ($courseId <= 0 || ! isset($inScope[$courseId])) {
                self::fail(sprintf('%s is not part of this generation for %s. Clear its Lecture and Laboratory Required Days.', $code, $section->section_name));
            }
            if (! isset($integrated[$courseId])) {
                self::fail(sprintf(
                    '%s is not Integrated for %s. Lecture and Laboratory Required Days apply only to Integrated courses; clear them or set the course to Integrated in Setup Courses.',
                    $code,
                    $section->section_name,
                ));
            }
            if (count($days) === 2 && $days['lecture'] === $days['laboratory']) {
                self::fail(sprintf(
                    '%s: the lecture and laboratory must meet on different days, but both are set to %s. Choose another day for one of them.',
                    $code,
                    $days['lecture'],
                ));
            }

            $wholeCourseDay = SchedulingPolicy::forcedCourseDayMap((int) $section->department_id, [$courseId])[$courseId] ?? null;
            if ($wholeCourseDay !== null) {
                self::fail(sprintf(
                    '%s has a course-wide Required Day (%s) and Lecture/Laboratory Required Days. Clear one of them in Setup Courses.',
                    $code,
                    $wholeCourseDay,
                ));
            }

            foreach ($days as $component => $day) {
                if ($allowedDays !== null && ! in_array($day, $allowedDays, true)) {
                    self::fail(sprintf(
                        '%s: the %s Required Day (%s) is not one of the Preferred Days (%s). Add %s to the Preferred Days, or change the %s Required Day.',
                        $code,
                        ucfirst($component),
                        $day,
                        implode(', ', $allowedDays),
                        $day,
                        ucfirst($component),
                    ));
                }
                if ($day === 'Sunday' && ! self::sundayEnabled($section)) {
                    self::fail(sprintf(
                        '%s: the %s Required Day is Sunday, but Sunday classes are not enabled for this department. Choose another day, or ask the department secretary to enable Sunday classes.',
                        $code,
                        ucfirst($component),
                    ));
                }
            }
            if ($allowedDays !== null && count($allowedDays) < 2) {
                self::fail(sprintf(
                    '%s meets on two different days, but the Preferred Days allow only %s. Add another Preferred Day.',
                    $code,
                    implode(', ', $allowedDays),
                ));
            }

            $normalized[$courseId] = $days;
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * Normalizes a draft-review or batch-save context. Courses are checked
     * against the course record (Integrated-eligible), not trusted from input.
     *
     * @param  list<array<string, mixed>>  $sectionConfigs
     * @param  list<int>  $authorizedSectionIds
     * @param  list<string>|null  $allowedDays
     * @param  array<int, list<int>>|null  $courseIdsBySection  the courses each section's draft holds;
     *                                                          null = any Integrated-eligible course
     * @return array<int, array<int, array{lecture?: string, laboratory?: string}>>
     *
     * @throws ValidationException
     */
    public static function normalizeContext(array $sectionConfigs, array $authorizedSectionIds, ?array $allowedDays = null, ?array $courseIdsBySection = null): array
    {
        $authorized = array_flip(array_map('intval', $authorizedSectionIds));
        $bySection = [];
        foreach ($sectionConfigs as $config) {
            $sectionId = (int) ($config['section_id'] ?? 0);
            if (! isset($authorized[$sectionId])) {
                self::fail('The Lecture and Laboratory Required Days name a section outside this draft.');
            }
            $raw = $config[self::KEY] ?? [];
            if (! is_array($raw) || $raw === []) {
                continue;
            }
            $section = Sections::query()->find($sectionId);
            if ($section === null) {
                self::fail('The Lecture and Laboratory Required Days name a section outside this draft.');
            }
            $requested = array_values(array_filter(array_map('intval', array_keys($raw)), static fn (int $id): bool => $id > 0));
            $courseIds = $courseIdsBySection === null
                ? $requested
                : array_values(array_intersect($requested, array_map('intval', $courseIdsBySection[$sectionId] ?? [])));
            $eligible = app(GenerationCourseSelection::class)->integratedEligibleCourseIds($section, $courseIds);
            $pins = self::normalize($section, $raw, $courseIds, $eligible, $allowedDays);
            if ($pins !== []) {
                $bySection[$sectionId] = $pins;
            }
        }

        return $bySection;
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return array<int, array<int, array{lecture?: string, laboratory?: string}>>
     */
    public static function fromConfigs(array $configsBySectionId): array
    {
        $bySection = [];
        foreach ($configsBySectionId as $sectionId => $config) {
            $pins = array_filter((array) ($config[self::KEY] ?? []), static fn (mixed $entry): bool => is_array($entry) && $entry !== []);
            if ($pins !== []) {
                $bySection[(int) $sectionId] = $pins;
            }
        }

        return $bySection;
    }

    /** @return array{lecture?: string, laboratory?: string} */
    public static function forCourse(array $config, int $courseId): array
    {
        $entry = $config[self::KEY][$courseId] ?? $config[self::KEY][(string) $courseId] ?? [];

        return is_array($entry) ? array_intersect_key($entry, array_flip(self::COMPONENTS)) : [];
    }

    /**
     * Hard check of placed rows. A pinned class must have exactly one lecture
     * and one laboratory row, each on its pinned day. Classes absent from the
     * rows (unplaced) are not violations here.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array<int, array{lecture?: string, laboratory?: string}>>  $pinsBySection
     * @return list<array{section_id: int, course_id: int, component: string|null, required_day: string|null, message: string, rows: list<array<string, mixed>>}>
     */
    public static function violations(array $rows, array $pinsBySection): array
    {
        if ($pinsBySection === []) {
            return [];
        }
        $grouped = [];
        foreach ($rows as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            $courseId = (int) ($row['course_id'] ?? $row['subject_id'] ?? 0);
            if (isset($pinsBySection[$sectionId][$courseId])) {
                $grouped[$sectionId][$courseId][] = $row;
            }
        }

        $violations = [];
        foreach ($grouped as $sectionId => $courses) {
            foreach ($courses as $courseId => $classRows) {
                $pins = $pinsBySection[$sectionId][$courseId];
                $code = self::courseCode($courseId, $classRows[0]['course_code'] ?? null);
                $byType = [];
                foreach ($classRows as $row) {
                    $byType[(string) ($row['meeting_type'] ?? '')][] = $row;
                }
                if (count($classRows) !== 2 || count($byType['lecture'] ?? []) !== 1 || count($byType['laboratory'] ?? []) !== 1) {
                    $violations[] = [
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'component' => null,
                        'required_day' => null,
                        'message' => sprintf(
                            '%s must keep exactly one lecture and one laboratory meeting because it has Lecture/Laboratory Required Days.',
                            $code,
                        ),
                        'rows' => $classRows,
                    ];

                    continue;
                }
                foreach ($pins as $component => $day) {
                    $row = $byType[$component][0];
                    if ((string) ($row['day'] ?? '') === $day) {
                        continue;
                    }
                    $violations[] = [
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'component' => $component,
                        'required_day' => $day,
                        'message' => sprintf(
                            '%s: the %s must meet on %s (its %s Required Day), not %s.',
                            $code,
                            $component,
                            $day,
                            ucfirst($component),
                            (string) ($row['day'] ?? 'another day'),
                        ),
                        'rows' => [$row],
                    ];
                }
            }
        }

        return $violations;
    }

    private static function sundayEnabled(Sections $section): bool
    {
        return (bool) DB::table('departments')
            ->where('id', (int) $section->department_id)
            ->value('sunday_classes_enabled');
    }

    private static function courseCode(int $courseId, mixed $known = null): string
    {
        if (is_string($known) && $known !== '') {
            return $known;
        }

        return (string) (Course::query()->whereKey($courseId)->value('course_code') ?? "Course {$courseId}");
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages([self::ERROR_KEY => $message]);
    }
}
