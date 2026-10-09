<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Course;
use App\Services\Scheduling\Recommendations\GenerationRecommendationPolicy;
use Illuminate\Support\Collection;

class YearLevelGenerationDiagnostics
{
    public function __construct(private readonly GenerationRecommendationPolicy $policy = new GenerationRecommendationPolicy)
    {
        // Keep explicit diagnostics injection compatible with existing callers.
    }

    public const TYPE_FIXED_PATTERN = 'fixed_pattern';

    public const TYPE_LECTURE_LAB_SPLIT = 'lecture_lab_split';

    public const TYPE_BALANCED_SPLIT = 'balanced_split';

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

    /** Compatibility entry point; the shared policy owns option construction. */
    public function feasibilityRecommendations(array $blockingConstraints): array
    {
        return $this->policy->feasibilityRecommendations($blockingConstraints);
    }

    /**
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
        $balancedSplitCourses = array_values((array) ($failure['balanced_split_courses'] ?? []));
        $laboratoryCourses = array_values((array) ($failure['laboratory_courses'] ?? []));
        $forcedOnSiteCourses = array_values((array) ($failure['forced_on_site_courses'] ?? []));
        $courseCount = (int) ($failure['course_count'] ?? 0);
        $preflightPatternConflict = (bool) ($failure['preflight_pattern_conflict'] ?? false);

        [$type, $focus] = $this->observedBlocker($failure) ?? match (true) {
            $patternCourses !== [] => [self::TYPE_FIXED_PATTERN, $patternCourses[0]],
            $splitCourses !== [] => [self::TYPE_LECTURE_LAB_SPLIT, $splitCourses[0]],
            $balancedSplitCourses !== [] => [self::TYPE_BALANCED_SPLIT, $balancedSplitCourses[0]],
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
            'balanced_split_course_count' => count($balancedSplitCourses),
            'hybrid_split_slot_available' => (bool) ($failure['hybrid_split_slot_available'] ?? false),
            'laboratory_course_count' => count($laboratoryCourses),
            'forced_on_site_count' => count($forcedOnSiteCourses),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @return array<string, mixed>|null
     */
    public function markSearchIncomplete(?array $bottleneck): ?array
    {
        if ($bottleneck === null) {
            return null;
        }

        $courseCode = (string) ($bottleneck['course_code'] ?? '');

        return [
            ...$bottleneck,
            'search_limit_reached' => true,
            'search_incomplete' => true,
            'detected_cause' => $courseCode !== ''
                ? sprintf(
                    'The search ran out of time while placing %s, before it could tell whether it fits. That is where the search got stuck, not a proven conflict.',
                    $courseCode,
                )
                : 'The search ran out of time before it could tell whether a timetable fits. That is where the search got stuck, not a proven conflict.',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $attempts
     */
    public function searchMessage(?array $bottleneck, array $attempts, bool $searchIncomplete = false): string
    {
        $triedCount = max(1, count(array_filter(
            $attempts,
            static fn (array $attempt): bool => ($attempt['outcome'] ?? '') === 'failed',
        )));

        if ($searchIncomplete) {
            $courseCode = (string) ($bottleneck['course_code'] ?? '');
            $sectionName = (string) ($bottleneck['section_name'] ?? '');
            $stuckOn = match (true) {
                $courseCode !== '' && $sectionName !== '' => sprintf(' The search got stuck on %s in %s.', $courseCode, $sectionName),
                $sectionName !== '' => sprintf(' The search got stuck on %s.', $sectionName),
                default => '',
            };

            return sprintf(
                'The generator ran out of search time after %d attempt%s, before it could check every arrangement, so a timetable may still fit. Generate again with the same settings before changing any.%s',
                $triedCount,
                $triedCount === 1 ? '' : 's',
                $stuckOn,
            );
        }

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

    /** Compatibility entry point for existing diagnostics callers. */
    public function searchRecommendations(
        ?array $bottleneck, array $strategies, ?Collection $courses = null, array $configsBySectionId = [],
        ?string $suggestedPreferredDay = null, bool $searchIncomplete = false,
    ): array {
        return $this->policy->searchRecommendations($bottleneck, $strategies, $courses, $configsBySectionId, $suggestedPreferredDay, $searchIncomplete);
    }

    public function preferredDayRecommendation(?string $day, array $configsBySectionId, bool $timetableFits = false): array
    {
        return $this->policy->preferredDayRecommendation($day, $configsBySectionId, $timetableFits);
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function observedBlocker(array $failure): ?array
    {
        $courseId = (int) ($failure['blocking_course']['course_id'] ?? 0);
        if ($courseId <= 0) {
            return null;
        }

        $courseCount = (int) ($failure['course_count'] ?? 0);
        $allForced = $courseCount > 0 && count((array) ($failure['forced_on_site_courses'] ?? [])) >= $courseCount;

        foreach ([
            'pattern_courses' => self::TYPE_FIXED_PATTERN,
            'split_courses' => self::TYPE_LECTURE_LAB_SPLIT,
            'balanced_split_courses' => self::TYPE_BALANCED_SPLIT,
            'laboratory_courses' => self::TYPE_LABORATORY_ROOM,
            'forced_on_site_courses' => $allForced ? self::TYPE_FORCED_ON_SITE : self::TYPE_LIMITED_ROOMS,
        ] as $key => $type) {
            foreach ((array) ($failure[$key] ?? []) as $course) {
                if ((int) ($course['course_id'] ?? 0) === $courseId) {
                    return [$type, $course];
                }
            }
        }

        return null;
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
            + ((array) ($failure['split_courses'] ?? []) !== [] ? 1 : 0)
            + ((array) ($failure['balanced_split_courses'] ?? []) !== [] ? 1 : 0);
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
            self::TYPE_BALANCED_SPLIT => sprintf(
                'The Split schedule on %s needs two vacant equal-length meetings, and no complete pair remains.',
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
