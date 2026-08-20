<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Sections;
use Illuminate\Support\Collection;

/**
 * Plans the retry ladder used after a year-level search failure.
 *
 * Raising the iteration limit does not help here: the same ordering explores the
 * same dead end more slowly. What helps is changing the shape of the problem, so
 * every strategy either re-orders the search or relaxes exactly one *user
 * preference* — a chosen MW/TTh pattern, a lecture/lab split toggle, a forced
 * delivery mode. Institutional rules (room types, forced day rules, operating
 * hours, conflict checks) are never touched, and each applied relaxation is
 * reported back so the user sees what changed.
 */
class YearLevelRetryStrategyPlanner
{
    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<string, mixed>|null  $bottleneck
     * @return list<array<string, mixed>>
     */
    public function plan(array $sections, array $configsBySectionId, Collection $courses, ?array $bottleneck): array
    {
        $sectionNames = [];
        foreach ($sections as $section) {
            $sectionNames[(int) $section->id] = (string) $section->section_name;
        }

        $focusSectionId = (int) ($bottleneck['section_id'] ?? 0);
        $focusCourseId = (int) ($bottleneck['course_id'] ?? 0);
        $type = (string) ($bottleneck['type'] ?? YearLevelGenerationDiagnostics::TYPE_SEARCH_EXHAUSTED);

        $byKey = [];
        foreach ([
            $this->alternateOrdering($sections),
            $this->alternatePattern($configsBySectionId, $courses, $sectionNames, $focusSectionId, $focusCourseId),
            $this->clearBottleneckPattern($configsBySectionId, $courses, $sectionNames, $focusSectionId, $focusCourseId),
            $this->clearSectionPatterns($configsBySectionId, $courses, $sectionNames, $focusSectionId),
            $this->clearBottleneckSplit($configsBySectionId, $courses, $sectionNames, $focusSectionId, $focusCourseId),
            $this->clearSectionForcedModes($configsBySectionId, $courses, $sectionNames, $focusSectionId),
            $this->clearAllPatterns($configsBySectionId, $courses, $sectionNames),
        ] as $strategy) {
            if ($strategy !== null) {
                $byKey[$strategy['key']] = $strategy;
            }
        }

        $preferred = match ($type) {
            YearLevelGenerationDiagnostics::TYPE_FIXED_PATTERN => [
                'alternate_pattern', 'clear_bottleneck_pattern', 'alternate_ordering', 'clear_section_patterns', 'clear_all_patterns',
            ],
            YearLevelGenerationDiagnostics::TYPE_LECTURE_LAB_SPLIT => [
                'alternate_ordering', 'clear_bottleneck_split', 'alternate_pattern', 'clear_section_patterns',
            ],
            YearLevelGenerationDiagnostics::TYPE_LABORATORY_ROOM => [
                'alternate_ordering', 'clear_bottleneck_split', 'clear_section_forced_modes', 'clear_section_patterns',
            ],
            YearLevelGenerationDiagnostics::TYPE_FORCED_ON_SITE,
            YearLevelGenerationDiagnostics::TYPE_LIMITED_ROOMS => [
                'clear_section_forced_modes', 'alternate_ordering', 'clear_section_patterns', 'clear_bottleneck_split',
            ],
            default => [
                'alternate_ordering', 'clear_bottleneck_pattern', 'clear_bottleneck_split', 'clear_section_forced_modes', 'clear_all_patterns',
            ],
        };

        $ordered = [];
        foreach ($preferred as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }

        return [...$ordered, ...array_values($byKey)];
    }

    /**
     * Re-order the sections and re-seed candidate exploration. No configured
     * preference changes, so this is always tried first when it is available.
     *
     * @param  list<Sections>  $sections
     * @return array<string, mixed>|null
     */
    private function alternateOrdering(array $sections): ?array
    {
        if (count($sections) < 2) {
            return null;
        }

        return [
            'key' => 'alternate_ordering',
            'label' => 'Alternative section ordering',
            'description' => 'Schedule the sections in a different order and re-seed room and slot exploration. Nothing you configured changes.',
            'impact' => 'low',
            'order_offset' => 1,
            'seed_offset' => 104729,
            'adjustments' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function alternatePattern(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        int $focusSectionId,
        int $focusCourseId,
    ): ?array {
        $adjustments = [];

        foreach ($this->focusedSectionIds($configsBySectionId, $focusSectionId) as $sectionId) {
            foreach (($configsBySectionId[$sectionId]['preferred_patterns'] ?? []) as $courseId => $pattern) {
                $courseId = (int) $courseId;
                if ($focusCourseId > 0 && $courseId !== $focusCourseId) {
                    continue;
                }

                $alternate = $this->alternatePatternFor($pattern);
                if ($alternate === null) {
                    continue;
                }

                $adjustments[] = [
                    'type' => 'set_pattern',
                    'section_id' => $sectionId,
                    'course_id' => $courseId,
                    'value' => $alternate,
                    'section_name' => $sectionNames[$sectionId] ?? '',
                    'course_code' => $this->courseCode($courses, $courseId),
                ];
            }
        }

        if ($adjustments === []) {
            return null;
        }

        return [
            'key' => 'alternate_pattern',
            'label' => sprintf('Switch %s to the other fixed pattern', $adjustments[0]['course_code']),
            'description' => sprintf(
                'Move %s in %s from %s to %s so its two meeting days land on the less contested half of the week.',
                $adjustments[0]['course_code'],
                $adjustments[0]['section_name'] !== '' ? $adjustments[0]['section_name'] : 'the section',
                $adjustments[0]['value'] === 'MW' ? 'TTh' : 'MW',
                (string) $adjustments[0]['value'],
            ),
            'impact' => 'medium',
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function clearBottleneckPattern(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        int $focusSectionId,
        int $focusCourseId,
    ): ?array {
        if ($focusSectionId <= 0 || $focusCourseId <= 0) {
            return null;
        }

        $patterns = $configsBySectionId[$focusSectionId]['preferred_patterns'] ?? [];
        if (! array_key_exists($focusCourseId, $patterns) && ! array_key_exists((string) $focusCourseId, $patterns)) {
            return null;
        }

        $courseCode = $this->courseCode($courses, $focusCourseId);

        return [
            'key' => 'clear_bottleneck_pattern',
            'label' => sprintf('Let the generator choose days for %s', $courseCode),
            'description' => sprintf(
                'Drop the fixed MW/TTh pattern on %s in %s. The course is still split into two meetings, but the generator picks the pair of days with room capacity left.',
                $courseCode,
                $sectionNames[$focusSectionId] ?? 'the section',
            ),
            'impact' => 'medium',
            'adjustments' => [[
                'type' => 'clear_pattern',
                'section_id' => $focusSectionId,
                'course_id' => $focusCourseId,
                'value' => null,
                'section_name' => $sectionNames[$focusSectionId] ?? '',
                'course_code' => $courseCode,
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function clearSectionPatterns(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        int $focusSectionId,
    ): ?array {
        if ($focusSectionId <= 0) {
            return null;
        }

        $adjustments = $this->clearPatternAdjustments($configsBySectionId, $courses, $sectionNames, [$focusSectionId]);
        if (count($adjustments) < 2) {
            // A single pattern is already covered by clear_bottleneck_pattern.
            return null;
        }

        return [
            'key' => 'clear_section_patterns',
            'label' => sprintf('Let the generator choose days for every fixed-pattern course in %s', $sectionNames[$focusSectionId] ?? 'the section'),
            'description' => sprintf(
                'Drop all %d fixed MW/TTh patterns in %s. Each course keeps its two meetings; the generator chooses the days.',
                count($adjustments),
                $sectionNames[$focusSectionId] ?? 'the section',
            ),
            'impact' => 'high',
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function clearAllPatterns(array $configsBySectionId, Collection $courses, array $sectionNames): ?array
    {
        $adjustments = $this->clearPatternAdjustments(
            $configsBySectionId,
            $courses,
            $sectionNames,
            array_map('intval', array_keys($configsBySectionId)),
        );

        $sectionCount = count(array_unique(array_column($adjustments, 'section_id')));
        if ($sectionCount < 2) {
            return null;
        }

        return [
            'key' => 'clear_all_patterns',
            'label' => 'Let the generator choose days for every fixed-pattern course',
            'description' => sprintf(
                'Drop all %d fixed MW/TTh patterns across the %d sections in this year level so the generator can spread the split meetings over the whole week.',
                count($adjustments),
                $sectionCount,
            ),
            'impact' => 'high',
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function clearBottleneckSplit(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        int $focusSectionId,
        int $focusCourseId,
    ): ?array {
        $sectionIds = $this->focusedSectionIds($configsBySectionId, $focusSectionId);
        $adjustments = [];

        foreach ($sectionIds as $sectionId) {
            $splitIds = array_map('intval', $configsBySectionId[$sectionId]['selected_split_session_course_ids'] ?? []);
            if ($splitIds === []) {
                continue;
            }

            $targets = $focusCourseId > 0 && in_array($focusCourseId, $splitIds, true)
                ? [$focusCourseId]
                : [$splitIds[0]];

            foreach ($targets as $courseId) {
                $adjustments[] = [
                    'type' => 'disable_lecture_lab_split',
                    'section_id' => $sectionId,
                    'course_id' => $courseId,
                    'value' => null,
                    'section_name' => $sectionNames[$sectionId] ?? '',
                    'course_code' => $this->courseCode($courses, $courseId),
                ];
            }
        }

        if ($adjustments === []) {
            return null;
        }

        return [
            'key' => 'clear_bottleneck_split',
            'label' => sprintf('Schedule %s as one block instead of a lecture/lab split', $adjustments[0]['course_code']),
            'description' => sprintf(
                'Turn off lecture/laboratory splitting for %s in %s so it needs one placement instead of a matched lecture and laboratory pair.',
                $adjustments[0]['course_code'],
                $adjustments[0]['section_name'] !== '' ? $adjustments[0]['section_name'] : 'the section',
            ),
            'impact' => 'high',
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @return array<string, mixed>|null
     */
    private function clearSectionForcedModes(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        int $focusSectionId,
    ): ?array {
        $adjustments = [];

        foreach ($this->focusedSectionIds($configsBySectionId, $focusSectionId) as $sectionId) {
            foreach (($configsBySectionId[$sectionId]['delivery_modes_by_course_id'] ?? []) as $courseId => $mode) {
                if ((string) $mode !== 'on-site') {
                    continue;
                }

                $adjustments[] = [
                    'type' => 'set_delivery_mode',
                    'section_id' => $sectionId,
                    'course_id' => (int) $courseId,
                    'value' => 'automatic',
                    'section_name' => $sectionNames[$sectionId] ?? '',
                    'course_code' => $this->courseCode($courses, (int) $courseId),
                ];
            }
        }

        if ($adjustments === []) {
            return null;
        }

        return [
            'key' => 'clear_section_forced_modes',
            'label' => sprintf(
                'Return %d forced F2F course%s to Automatic',
                count($adjustments),
                count($adjustments) === 1 ? '' : 's',
            ),
            'description' => sprintf(
                'Release the F2F pin on %s in %s so the generator may place %s in any eligible room, or online when no physical room is free.',
                implode(', ', array_slice(array_unique(array_column($adjustments, 'course_code')), 0, 4)),
                $adjustments[0]['section_name'] !== '' ? $adjustments[0]['section_name'] : 'the section',
                count($adjustments) === 1 ? 'it' : 'them',
            ),
            'impact' => 'high',
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @param  array<int, string>  $sectionNames
     * @param  list<int>  $sectionIds
     * @return list<array<string, mixed>>
     */
    private function clearPatternAdjustments(
        array $configsBySectionId,
        Collection $courses,
        array $sectionNames,
        array $sectionIds,
    ): array {
        $adjustments = [];

        foreach ($sectionIds as $sectionId) {
            foreach (($configsBySectionId[$sectionId]['preferred_patterns'] ?? []) as $courseId => $pattern) {
                if (SchedulingPolicy::normalizePreferredPattern($pattern) === null) {
                    continue;
                }

                $adjustments[] = [
                    'type' => 'clear_pattern',
                    'section_id' => (int) $sectionId,
                    'course_id' => (int) $courseId,
                    'value' => null,
                    'section_name' => $sectionNames[$sectionId] ?? '',
                    'course_code' => $this->courseCode($courses, (int) $courseId),
                ];
            }
        }

        return $adjustments;
    }

    /**
     * The bottleneck section first, then the rest, so a relaxation stays as
     * narrow as possible while still being available when the bottleneck could
     * not be attributed to a single section.
     *
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<int>
     */
    private function focusedSectionIds(array $configsBySectionId, int $focusSectionId): array
    {
        if ($focusSectionId > 0 && isset($configsBySectionId[$focusSectionId])) {
            return [$focusSectionId];
        }

        return array_map('intval', array_keys($configsBySectionId));
    }

    private function alternatePatternFor(mixed $pattern): ?string
    {
        return match (SchedulingPolicy::normalizePreferredPattern($pattern)) {
            'MW' => 'TTh',
            'TTh' => 'MW',
            default => null,
        };
    }

    /** @param  Collection<int, Course>  $courses */
    private function courseCode(Collection $courses, int $courseId): string
    {
        $course = $courses->get($courseId);

        return (string) ($course?->course_code ?? ('Course '.$courseId));
    }
}
