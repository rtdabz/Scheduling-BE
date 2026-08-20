<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * Turns a failed year-level run into something a user can act on.
 *
 * Two entry points mirror the two ways a run can fail. A feasibility failure is
 * arithmetic — the request cannot fit, and the blocking constraints already say
 * why. A search failure is not proof of anything: the solver ran out of budget
 * on a specific section, so the job here is to name the most constrained thing
 * about that section and propose the smallest change that could unblock it.
 */
class YearLevelGenerationDiagnostics
{
    /** Bottleneck kinds, ordered by how confidently they explain a failure. */
    public const TYPE_FIXED_PATTERN = 'fixed_pattern';

    public const TYPE_LECTURE_LAB_SPLIT = 'lecture_lab_split';

    public const TYPE_LABORATORY_ROOM = 'laboratory_room';

    public const TYPE_FORCED_ON_SITE = 'forced_on_site';

    public const TYPE_LIMITED_ROOMS = 'limited_rooms';

    public const TYPE_SEARCH_EXHAUSTED = 'search_exhausted';

    /** @param  list<array<string, mixed>>  $blockingConstraints */
    public function feasibilityMessage(array $blockingConstraints): string
    {
        $first = $blockingConstraints[0]['message']
            ?? 'The requested year-level scope cannot fit the available resources.';

        return count($blockingConstraints) > 1
            ? sprintf('%s (%d blocking constraints in total.)', $first, count($blockingConstraints))
            : (string) $first;
    }

    /**
     * @param  list<array<string, mixed>>  $blockingConstraints
     * @return list<array<string, mixed>>
     */
    public function feasibilityRecommendations(array $blockingConstraints): array
    {
        $recommendations = [];

        foreach ($blockingConstraints as $index => $constraint) {
            $code = (string) ($constraint['code'] ?? 'blocking_constraint');
            $context = (array) ($constraint['context'] ?? []);
            $adjustments = [];
            $title = match ($code) {
                'no_physical_rooms' => 'Add a usable lecture or laboratory room',
                'insufficient_room_slots' => 'Reduce on-site demand for this year level',
                'no_laboratory_room' => 'Add a laboratory room for this department',
                'insufficient_laboratory_slots' => 'Free up laboratory capacity',
                'fixed_pattern_overloaded' => sprintf(
                    'Let the generator choose days for %s courses',
                    (string) ($context['pattern'] ?? 'fixed-pattern'),
                ),
                'insufficient_online_slots' => 'Raise the online slot limit or reduce forced Online courses',
                default => 'Adjust the generation scope',
            };

            if ($code === 'fixed_pattern_overloaded') {
                foreach (($context['targets'] ?? []) as $target) {
                    $adjustments[] = [
                        'type' => 'clear_pattern',
                        'section_id' => (int) ($target['section_id'] ?? 0),
                        'course_id' => (int) ($target['course_id'] ?? 0),
                        'value' => null,
                        'section_name' => (string) ($target['section_name'] ?? ''),
                        'course_code' => (string) ($target['course_code'] ?? ''),
                    ];
                }
            }

            $recommendations[] = [
                'id' => sprintf('feasibility-%s-%d', $code, $index),
                'title' => $title,
                'detected_cause' => (string) ($constraint['message'] ?? ''),
                'suggested_adjustment' => (string) ($constraint['suggested_action'] ?? ''),
                'section_id' => isset($constraint['section_id']) ? (int) $constraint['section_id'] : null,
                'section_name' => isset($constraint['section_name']) ? (string) $constraint['section_name'] : null,
                'course_id' => null,
                'course_code' => null,
                'impact' => $adjustments === [] ? 'high' : 'medium',
                'adjustments' => $adjustments,
            ];
        }

        return $recommendations;
    }

    /**
     * Pick the section/course most likely responsible for the search failure.
     *
     * @param  list<array<string, mixed>>  $failures  section failure records
     * @param  Collection<int, Course>  $courses
     * @return array<string, mixed>|null
     */
    public function detectBottleneck(array $failures, Collection $courses): ?array
    {
        $failure = $this->hardestFailure($failures);
        if ($failure === null) {
            return null;
        }

        $patternCourses = array_values((array) ($failure['pattern_courses'] ?? []));
        $splitCourses = array_values((array) ($failure['split_courses'] ?? []));
        $laboratoryCourses = array_values((array) ($failure['laboratory_courses'] ?? []));
        $forcedOnSiteCourses = array_values((array) ($failure['forced_on_site_courses'] ?? []));
        $courseCount = (int) ($failure['course_count'] ?? 0);
        $preflightPatternConflict = (bool) ($failure['preflight_pattern_conflict'] ?? false);

        [$type, $focus] = match (true) {
            $patternCourses !== [] => [self::TYPE_FIXED_PATTERN, $patternCourses[0]],
            $splitCourses !== [] => [self::TYPE_LECTURE_LAB_SPLIT, $splitCourses[0]],
            $laboratoryCourses !== [] => [self::TYPE_LABORATORY_ROOM, $laboratoryCourses[0]],
            $courseCount > 0 && count($forcedOnSiteCourses) >= $courseCount => [self::TYPE_FORCED_ON_SITE, $forcedOnSiteCourses[0]],
            $forcedOnSiteCourses !== [] => [self::TYPE_LIMITED_ROOMS, $forcedOnSiteCourses[0]],
            default => [self::TYPE_SEARCH_EXHAUSTED, null],
        };

        $courseId = $focus === null ? 0 : (int) ($focus['course_id'] ?? 0);
        $course = $courseId > 0 ? $courses->get($courseId) : null;

        return [
            'type' => $type,
            'section_id' => (int) ($failure['section_id'] ?? 0),
            'section_name' => (string) ($failure['section_name'] ?? ''),
            'course_id' => $courseId > 0 ? $courseId : null,
            'course_code' => $courseId > 0
                ? (string) ($focus['course_code'] ?? $course?->course_code ?? ('Course '.$courseId))
                : null,
            'detected_cause' => $this->causeText($type, $failure, $focus),
            'iterations' => (int) ($failure['iterations'] ?? 0),
            'search_limit_reached' => (bool) ($failure['search_limit_reached'] ?? false),
            'preflight_pattern_conflict' => $preflightPatternConflict,
            'course_count' => $courseCount,
            'pattern_course_count' => count($patternCourses),
            'split_course_count' => count($splitCourses),
            'laboratory_course_count' => count($laboratoryCourses),
            'forced_on_site_count' => count($forcedOnSiteCourses),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $attempts
     */
    public function searchMessage(?array $bottleneck, array $attempts): string
    {
        $triedCount = max(1, count(array_filter(
            $attempts,
            static fn (array $attempt): bool => ($attempt['outcome'] ?? '') === 'failed',
        )));

        if ($bottleneck === null) {
            return sprintf(
                'No year-level timetable satisfies all section constraints and available room capacity after %d generation attempt%s.',
                $triedCount,
                $triedCount === 1 ? '' : 's',
            );
        }

        return sprintf(
            'No year-level timetable satisfies all section constraints after %d generation attempt%s. %s is the blocking section: %s',
            $triedCount,
            $triedCount === 1 ? '' : 's',
            $bottleneck['section_name'] !== '' ? $bottleneck['section_name'] : 'One section',
            $bottleneck['detected_cause'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $strategies  retry strategies that were planned
     * @return list<array<string, mixed>>
     */
    public function searchRecommendations(?array $bottleneck, array $strategies): array
    {
        if ($bottleneck === null) {
            return [[
                'id' => 'search-generic',
                'title' => 'Reduce the constraints on this year level',
                'detected_cause' => 'The generator explored every ordering it could within the time budget without finding a conflict-free timetable.',
                'suggested_adjustment' => 'Clear old draft schedules for this year level, or relax fixed patterns and forced delivery modes, then generate again.',
                'section_id' => null,
                'section_name' => null,
                'course_id' => null,
                'course_code' => null,
                'impact' => 'medium',
                'adjustments' => [],
            ]];
        }

        $recommendations = [];

        // Every relaxation the retry ladder tried is, by construction, a change
        // the user can make permanently. Offering them in the same order keeps
        // the panel consistent with what the generator already attempted.
        foreach ($strategies as $strategy) {
            $adjustments = array_values((array) ($strategy['adjustments'] ?? []));
            if ($adjustments === []) {
                continue;
            }

            $first = $adjustments[0];
            $recommendations[] = [
                'id' => 'strategy-'.(string) ($strategy['key'] ?? count($recommendations)),
                'title' => (string) ($strategy['label'] ?? 'Adjust the configuration'),
                'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
                'suggested_adjustment' => (string) ($strategy['description'] ?? ''),
                'section_id' => isset($first['section_id']) ? (int) $first['section_id'] : null,
                'section_name' => isset($first['section_name']) && $first['section_name'] !== ''
                    ? (string) $first['section_name']
                    : null,
                'course_id' => isset($first['course_id']) ? (int) $first['course_id'] : null,
                'course_code' => isset($first['course_code']) && $first['course_code'] !== ''
                    ? (string) $first['course_code']
                    : null,
                'impact' => (string) ($strategy['impact'] ?? 'medium'),
                'adjustments' => $adjustments,
            ];
        }

        $recommendations[] = [
            'id' => 'advisory-resources',
            'title' => 'Free up room-time for this year level',
            'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
            'suggested_adjustment' => $bottleneck['type'] === self::TYPE_LABORATORY_ROOM
                ? 'Add or re-enable a laboratory room for the department, or move a laboratory course to a different year-level run.'
                : 'Delete stale draft schedules for other year levels in this term, or add an available room, then generate again.',
            'section_id' => ($bottleneck['section_id'] ?? 0) > 0 ? (int) $bottleneck['section_id'] : null,
            'section_name' => ($bottleneck['section_name'] ?? '') !== '' ? (string) $bottleneck['section_name'] : null,
            'course_id' => $bottleneck['course_id'] ?? null,
            'course_code' => $bottleneck['course_code'] ?? null,
            'impact' => 'high',
            'adjustments' => [],
        ];

        return $recommendations;
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @return array<string, mixed>|null
     */
    private function hardestFailure(array $failures): ?array
    {
        $failures = array_values(array_filter($failures));
        if ($failures === []) {
            return null;
        }

        usort($failures, function (array $left, array $right): int {
            return $this->failureHardness($right) <=> $this->failureHardness($left)
                ?: ((int) ($right['course_count'] ?? 0) <=> (int) ($left['course_count'] ?? 0));
        });

        return $failures[0];
    }

    /** @param array<string, mixed> $failure */
    private function failureHardness(array $failure): int
    {
        return ((bool) ($failure['preflight_pattern_conflict'] ?? false) ? 8 : 0)
            + ((bool) ($failure['search_limit_reached'] ?? false) ? 4 : 0)
            + ((array) ($failure['pattern_courses'] ?? []) !== [] ? 2 : 0)
            + ((array) ($failure['split_courses'] ?? []) !== [] ? 1 : 0);
    }

    /**
     * @param  array<string, mixed>  $failure
     * @param  array<string, mixed>|null  $focus
     */
    private function causeText(string $type, array $failure, ?array $focus): string
    {
        $courseCode = (string) ($focus['course_code'] ?? '');
        $pattern = (string) ($focus['pattern'] ?? '');
        $iterations = (int) ($failure['iterations'] ?? 0);

        return match ($type) {
            self::TYPE_FIXED_PATTERN => (bool) ($failure['preflight_pattern_conflict'] ?? false)
                ? sprintf(
                    'The fixed %s pattern on %s has no valid placement even before the other sections are staged.',
                    $pattern !== '' ? $pattern : 'MW/TTh',
                    $courseCode !== '' ? $courseCode : 'a course',
                )
                : sprintf(
                    'The fixed %s pattern on %s concentrates the section onto two days that earlier sections already filled.',
                    $pattern !== '' ? $pattern : 'MW/TTh',
                    $courseCode !== '' ? $courseCode : 'a course',
                ),
            self::TYPE_LECTURE_LAB_SPLIT => sprintf(
                'The lecture/laboratory split on %s needs a matching lecture block and laboratory block, and no free pair remains.',
                $courseCode !== '' ? $courseCode : 'a course',
            ),
            self::TYPE_LABORATORY_ROOM => sprintf(
                'Laboratory course %s could not claim a laboratory room slot that the other sections had not already taken.',
                $courseCode !== '' ? $courseCode : 'in this section',
            ),
            self::TYPE_FORCED_ON_SITE => 'Every course in the section is pinned to a physical room, so the generator has no online fallback left.',
            self::TYPE_LIMITED_ROOMS => sprintf(
                'Courses pinned to a physical room — starting with %s — ran out of eligible room-time.',
                $courseCode !== '' ? $courseCode : 'one course',
            ),
            default => $iterations === 0
                ? 'The section had no eligible candidate left once the earlier sections in the run were staged.'
                : sprintf('The solver exhausted its search budget after %d iterations without a conflict-free placement.', $iterations),
        };
    }
}
