<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Course;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

class YearLevelGenerationDiagnostics
{
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
            if ($code === 'insufficient_room_slots') {
                array_push($recommendations, ...$this->roomSlotReliefRecommendations($constraint));
            }
            $adjustments = [];
            $courseCode = (string) ($context['course_code'] ?? '');
            $title = match ($code) {
                'no_physical_rooms' => 'Add a usable lecture or laboratory room',
                'insufficient_room_slots' => 'Reduce on-site demand for this year level',
                'fixed_pattern_overloaded' => sprintf(
                    'Let the generator choose days for %s courses',
                    (string) ($context['pattern'] ?? 'fixed-pattern'),
                ),
                'preferred_days_too_few_for_hybrid' => sprintf(
                    'Add a Preferred Day, or schedule %s on-site',
                    $courseCode !== '' ? $courseCode : 'the course',
                ),
                'component_duration_exceeds_day' => sprintf(
                    'Shorten the %s block or extend operating hours',
                    $courseCode !== '' ? $courseCode : 'course',
                ),
                'forced_day_capacity_exceeded' => sprintf(
                    'Release the %s Required Day or add rooms',
                    (string) ($context['forced_day'] ?? ''),
                ),
                default => 'Adjust the generation scope',
            };

            $adjustmentType = match ($code) {
                'fixed_pattern_overloaded' => 'clear_pattern',
                'preferred_days_too_few_for_hybrid' => null,
                default => false,
            };
            if ($adjustmentType !== false) {
                foreach (($context['targets'] ?? []) as $target) {
                    $adjustments[] = [
                        'type' => $adjustmentType ?? (string) ($target['adjustment_type'] ?? ''),
                        'section_id' => (int) ($target['section_id'] ?? 0),
                        'course_id' => (int) ($target['course_id'] ?? 0),
                        'value' => $target['value'] ?? null,
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
                'status' => 'active',
                'resolved' => false,
            ];
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $constraint
     * @return list<array<string, mixed>>
     */
    private function roomSlotReliefRecommendations(array $constraint): array
    {
        $context = (array) ($constraint['context'] ?? []);
        $shortfall = (int) ($context['shortfall_slots'] ?? 0);
        $recommendations = [];

        foreach ((array) ($context['options'] ?? []) as $option) {
            $kind = (string) ($option['kind'] ?? '');
            $recommendations[] = [
                'id' => 'room-capacity-'.$kind,
                'title' => $kind === 'online' ? 'Online' : 'Hybrid Split',
                'detected_cause' => 'These options free up enough room time to schedule all sections. On-site Split will not help because it still needs the same room on two days.',
                'suggested_adjustment' => sprintf(
                    '%s. This frees %d room slots, while %d are needed.',
                    implode(', ', (array) ($option['course_codes'] ?? [])),
                    (int) ($option['frees'] ?? 0),
                    $shortfall,
                ),
                'section_id' => null,
                'section_name' => null,
                'course_id' => null,
                'course_code' => null,
                'impact' => 'medium',
                'adjustments' => array_map(static fn (array $target): array => [
                    'type' => (string) ($target['adjustment_type'] ?? ''),
                    'section_id' => (int) ($target['section_id'] ?? 0),
                    'course_id' => (int) ($target['course_id'] ?? 0),
                    'value' => $target['value'] ?? null,
                    'section_name' => (string) ($target['section_name'] ?? ''),
                    'course_code' => (string) ($target['course_code'] ?? ''),
                ], (array) ($option['targets'] ?? [])),
                'status' => 'active',
                'resolved' => false,
            ];
        }

        return $recommendations;
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

    /**
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $strategies  retry strategies that were planned
     * @return list<array<string, mixed>>
     */
    public function searchRecommendations(
        ?array $bottleneck,
        array $strategies,
        ?Collection $courses = null,
        array $configsBySectionId = [],
        ?string $suggestedPreferredDay = null,
        bool $searchIncomplete = false,
    ): array
    {
        $preferredDay = $this->preferredDayRecommendation($suggestedPreferredDay, $configsBySectionId);

        if ($bottleneck === null) {
            return [...$preferredDay, [
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
                'status' => 'active',
                'resolved' => false,
            ]];
        }

        $recommendations = [];

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
                'status' => 'active',
                'resolved' => false,
            ];
        }

        if (($bottleneck['type'] ?? null) === self::TYPE_BALANCED_SPLIT) {
            $courseId = (int) ($bottleneck['course_id'] ?? 0);
            $course = $courses?->get($courseId);
            $sectionId = (int) ($bottleneck['section_id'] ?? 0);
            $sectionName = (string) ($bottleneck['section_name'] ?? 'the section');
            $courseCode = (string) ($bottleneck['course_code'] ?? ($course?->course_code ?? 'the course'));
            $splitIds = array_map('intval', $configsBySectionId[$sectionId]['balanced_split_course_ids'] ?? []);
            $hybridIds = array_map('intval', $configsBySectionId[$sectionId]['hybrid_split_course_ids'] ?? []);
            $mode = $configsBySectionId[$sectionId]['delivery_modes_by_course_id'][$courseId] ?? null;
            $splitCause = sprintf('%s has no two free on-site slots for its Split Session.', $courseCode);

            if ($course !== null
                && SchedulingPolicy::hybridSplitEligible($course)
                && in_array($courseId, $splitIds, true)
                && (bool) ($bottleneck['hybrid_split_slot_available'] ?? false)
                && ! in_array($courseId, $hybridIds, true)) {
                $recommendations[] = [
                    'id' => 'recommend-hybrid-split-'.$sectionId.'-'.$courseId,
                    'title' => 'Hybrid Split',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Meet once online and once on campus: two 1.5-hour meetings for %s in %s.', $courseCode, $sectionName),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'medium',
                    'adjustments' => [[
                        'type' => 'enable_hybrid_split',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => null,
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }

            if ($course !== null
                && in_array($courseId, $splitIds, true)
                && ! in_array($courseId, $hybridIds, true)
                && $mode !== 'online'
                && SchedulingPolicy::allowsOnlineRoomFallback($course, null, null, [])) {
                $recommendations[] = [
                    'id' => 'recommend-online-split-'.$sectionId.'-'.$courseId,
                    'title' => 'Online Split',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Hold both meetings of %s in %s online. They need free class time, not a room.', $courseCode, $sectionName),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'medium',
                    'adjustments' => [[
                        'type' => 'set_delivery_mode',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => 'online',
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }

            if (! $this->offersAdjustment($recommendations, 'disable_minor_split', $sectionId, $courseId)) {
                $recommendations[] = [
                    'id' => 'recommend-regular-meeting-'.$sectionId.'-'.$courseId,
                    'title' => 'Regular Meeting',
                    'detected_cause' => $splitCause,
                    'suggested_adjustment' => sprintf('Meet once a week for the full length of %s instead of twice.', $courseCode),
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => $courseId,
                    'course_code' => $courseCode,
                    'impact' => 'high',
                    'adjustments' => [[
                        'type' => 'disable_minor_split',
                        'section_id' => $sectionId,
                        'course_id' => $courseId,
                        'value' => null,
                        'section_name' => $sectionName,
                        'course_code' => $courseCode,
                    ]],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }
        }

        if ($searchIncomplete || ! in_array($bottleneck['type'] ?? null, [
            self::TYPE_LABORATORY_ROOM,
            self::TYPE_FORCED_ON_SITE,
            self::TYPE_LIMITED_ROOMS,
            self::TYPE_SEARCH_EXHAUSTED,
        ], true)) {
            return [...$recommendations, ...$preferredDay];
        }

        $recommendations = [...$recommendations, ...$preferredDay];

        $recommendations[] = [
            'id' => 'advisory-resources',
            'title' => 'Free up room-time for this year level',
            'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
            'suggested_adjustment' => $bottleneck['type'] === self::TYPE_LABORATORY_ROOM
                ? 'Add or re-enable a laboratory room for the department, or move a laboratory course to a different year-level run.'
                : 'Delete stale draft schedules for other year levels in this semester, or add an available room, then generate again.',
            'section_id' => ($bottleneck['section_id'] ?? 0) > 0 ? (int) $bottleneck['section_id'] : null,
            'section_name' => ($bottleneck['section_name'] ?? '') !== '' ? (string) $bottleneck['section_name'] : null,
            'course_id' => $bottleneck['course_id'] ?? null,
            'course_code' => $bottleneck['course_code'] ?? null,
            'impact' => 'high',
            'adjustments' => [],
            'status' => 'active',
            'resolved' => false,
        ];

        return $recommendations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<array<string, mixed>>
     */
    public function preferredDayRecommendation(?string $day, array $configsBySectionId, bool $timetableFits = false): array
    {
        if ($day === null || $day === '' || $configsBySectionId === []) {
            return [];
        }

        $allowedDays = SchedulingPolicy::normalizeAllowedDays(
            $configsBySectionId[array_key_first($configsBySectionId)]['allowed_days'] ?? null,
        ) ?? [];

        return [[
            'id' => 'add-preferred-day-'.strtolower($day),
            'title' => sprintf('Add %s to the Preferred Days', $day),
            'detected_cause' => $timetableFits
                ? sprintf('The timetable fits, but only on %s, so room-time on other days goes unused.', implode(', ', $allowedDays))
                : sprintf('The Preferred Days limit every section to %s, and the timetable does not fit in them.', implode(', ', $allowedDays)),
            'suggested_adjustment' => sprintf(
                'Open %s as well. It is the least booked day the Preferred Days leave out, so it adds the most free room-time.',
                $day,
            ),
            'section_id' => null,
            'section_name' => null,
            'course_id' => null,
            'course_code' => null,
            'impact' => $timetableFits ? 'low' : 'medium',
            'adjustments' => array_map(static fn (int|string $sectionId): array => [
                'type' => 'add_preferred_day',
                'section_id' => (int) $sectionId,
                'course_id' => 0,
                'value' => $day,
                'section_name' => '',
                'course_code' => '',
            ], array_keys($configsBySectionId)),
            'status' => 'active',
            'resolved' => false,
        ]];
    }

    /** @param  list<array<string, mixed>>  $recommendations */
    private function offersAdjustment(array $recommendations, string $type, int $sectionId, int $courseId): bool
    {
        foreach ($recommendations as $recommendation) {
            foreach ((array) ($recommendation['adjustments'] ?? []) as $adjustment) {
                if (($adjustment['type'] ?? null) === $type
                    && (int) ($adjustment['section_id'] ?? 0) === $sectionId
                    && (int) ($adjustment['course_id'] ?? 0) === $courseId) {
                    return true;
                }
            }
        }

        return false;
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
