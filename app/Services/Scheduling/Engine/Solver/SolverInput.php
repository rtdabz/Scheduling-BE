<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * The solver's input boundary: validates arguments and normalizes every
 * per-course option map (patterns, delivery modes, requirements, time
 * preferences, anchors) into the canonical form the search expects,
 * dropping unknown values and courses outside the requested list.
 * Pure and static; extracted from CspSolver unchanged apart from visibility.
 */
final class SolverInput
{
    public static function normalizeInputSchema(array $input): array
    {
        $sectionId = $input['section_id'] ?? $input['sectionId'] ?? null;
        $courseIds = $input['course_ids'] ?? $input['courseIds'] ?? null;

        if (! is_int($sectionId) && ! ctype_digit((string) $sectionId)) {
            throw new InvalidArgumentException('section_id must be an integer.');
        }

        if (! is_array($courseIds)) {
            throw new InvalidArgumentException('course_ids must be an array.');
        }

        $deliveryMode = (string) (
            $input['delivery_mode']
            ?? $input['deliveryMode']
            ?? $input['mode']
            ?? 'on-site'
        );

        $isHybrid = filter_var(
            $input['is_hybrid'] ?? $input['isHybrid'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($isHybrid === null) {
            throw new InvalidArgumentException('is_hybrid must be boolean.');
        }

        return [
            'section_id' => (int) $sectionId,
            'course_ids' => $courseIds,
            'delivery_mode' => $deliveryMode,
            'is_hybrid' => $isHybrid,
            'preferred_patterns' => $input['preferred_patterns']
                ?? $input['preferredPatternsByCourseId']
                ?? [],
            'anchored_schedules' => $input['anchored_schedules']
                ?? $input['anchoredSchedules']
                ?? [],
            'selected_split_session_course_ids' => $input['selected_split_session_course_ids']
                ?? $input['selectedSplitSessionCourseIds']
                ?? [],
            'balanced_split_course_ids' => $input['balanced_split_course_ids']
                ?? $input['balancedSplitCourseIds']
                ?? [],
            'hybrid_split_course_ids' => $input['hybrid_split_course_ids']
                ?? $input['hybridSplitCourseIds']
                ?? [],
            'delivery_modes_by_course_id' => $input['delivery_modes_by_course_id']
                ?? $input['deliveryModesByCourseId']
                ?? [],
            'requirements_by_course_id' => $input['requirements_by_course_id']
                ?? $input['requirementsByCourseId']
                ?? [],
            'allowed_days' => SchedulingPolicy::normalizeAllowedDays(
                $input['allowed_days'] ?? $input['allowedDays'] ?? null,
            ),
            'allow_friday_saturday_split' => (bool) filter_var(
                $input['allow_friday_saturday_split'] ?? $input['allowFridaySaturdaySplit'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'tentative_schedules' => is_array($input['tentative_schedules'] ?? null)
                ? $input['tentative_schedules']
                : [],
            'max_solutions' => (int) ($input['max_solutions'] ?? $input['maxSolutions'] ?? 2),
            'max_iterations' => (int) ($input['max_iterations'] ?? $input['maxIterations'] ?? 250_000),
            'timeout_seconds' => (float) ($input['timeout_seconds'] ?? $input['timeoutSeconds'] ?? 8.0),
            'seed' => isset($input['seed']) ? (int) $input['seed'] : null,
            'throw_on_empty_domain' => filter_var(
                $input['throw_on_empty_domain'] ?? $input['throwOnEmptyDomain'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'allow_room_tba_fallback' => filter_var(
                $input['allow_room_tba_fallback'] ?? $input['allowRoomTbaFallback'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'allow_online_fallback' => filter_var(
                $input['allow_online_fallback'] ?? $input['allowOnlineFallback'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
            ),
        ];
    }

    public static function validateArguments(
        array $courseIds,
        int $maxSolutions,
        int $maxIterations,
        float $timeoutSeconds,
        string $deliveryMode,
        bool $isHybrid,
        array $preferredPatternsByCourseId,
    ): void {
        foreach ($courseIds as $courseId) {
            if (! is_int($courseId) && ! ctype_digit((string) $courseId)) {
                throw new InvalidArgumentException(
                    'Every course ID must be an integer.',
                );
            }
        }

        if ($maxSolutions < 1 || $maxSolutions > 25) {
            throw new InvalidArgumentException(
                'maxSolutions must be between 1 and 25.',
            );
        }

        if ($maxIterations < 1) {
            throw new InvalidArgumentException(
                'maxIterations must be greater than zero.',
            );
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException(
                'timeoutSeconds must be greater than zero.',
            );
        }

        if (! SchedulingPolicy::isValidDeliveryMode($deliveryMode)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported delivery mode "%s".',
                $deliveryMode,
            ));
        }

        if ($deliveryMode === 'field' && $isHybrid) {
            throw new InvalidArgumentException(
                'Field schedules cannot be marked as hybrid.',
            );
        }

        foreach ($preferredPatternsByCourseId as $courseId => $pattern) {
            if (! is_int($courseId) && ! ctype_digit((string) $courseId)) {
                throw new InvalidArgumentException(
                    'Preferred pattern course IDs must be integers.',
                );
            }

            self::normalizePreferredPattern($pattern);
        }
    }

    public static function ensureAllCoursesExist(
        array $courseIds,
        Collection $courses,
    ): void {
        $foundIds = $courses
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $missingIds = array_values(array_diff($courseIds, $foundIds));

        if ($missingIds !== []) {
            throw new InvalidArgumentException(
                'The following course IDs do not exist: '
                .implode(', ', $missingIds),
            );
        }
    }

    public static function normalizeCourseIds(array $courseIds): array
    {
        $normalized = array_map(
            static fn (mixed $courseId): int => (int) $courseId,
            $courseIds,
        );

        $normalized = array_values(array_unique($normalized));

        return array_values(array_filter(
            $normalized,
            static fn (int $courseId): bool => $courseId > 0,
        ));
    }

    public static function normalizeAnchoredSchedulesByCourseId(array $anchoredSchedules, array $validCourseIds): array
    {
        $validCourseIdSet = array_fill_keys($validCourseIds, true);
        $normalized = [];

        foreach ($anchoredSchedules as $anchor) {
            if (! is_array($anchor)) {
                continue;
            }

            $courseId = (int) ($anchor['course_id'] ?? $anchor['courseId'] ?? 0);
            if ($courseId <= 0 || ! isset($validCourseIdSet[$courseId])) {
                continue;
            }

            $day = (string) ($anchor['day'] ?? '');
            $startTime = (string) ($anchor['start_time'] ?? $anchor['startTime'] ?? '');
            $endTime = (string) ($anchor['end_time'] ?? $anchor['endTime'] ?? '');

            if ($day === '' || $startTime === '' || $endTime === '') {
                continue;
            }

            $normalized[$courseId] = [
                'course_id' => $courseId,
                'day' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'room_id' => self::nullableRoomId($anchor['room_id'] ?? $anchor['roomId'] ?? null),
            ];
        }

        return $normalized;
    }

    public static function normalizeDeliveryModesByCourseId(array $deliveryModesByCourseId, array $validCourseIds): array
    {
        $valid = array_fill_keys($validCourseIds, true);
        $normalized = [];
        foreach ($deliveryModesByCourseId as $courseId => $mode) {
            $courseId = (int) $courseId;
            $mode = (string) $mode;
            if (! isset($valid[$courseId]) || ! in_array($mode, SchedulingPolicy::DELIVERY_MODES, true)) {
                throw new InvalidArgumentException('Invalid per-course delivery mode configuration.');
            }
            $normalized[$courseId] = $mode;
        }

        return $normalized;
    }

    /** @return array<int, list<array<string, mixed>>> */
    public static function normalizeRequirements(array $requirementsByCourseId, array $validCourseIds): array
    {
        $valid = array_fill_keys(array_map('intval', $validCourseIds), true);
        $normalized = [];

        foreach ($requirementsByCourseId as $courseId => $requirements) {
            $courseId = (int) $courseId;
            if (! isset($valid[$courseId]) || ! is_array($requirements)) {
                continue;
            }

            $rows = array_values(array_filter(
                $requirements,
                static fn (mixed $requirement): bool => is_array($requirement),
            ));
            if ($rows !== []) {
                $normalized[$courseId] = $rows;
            }
        }

        return $normalized;
    }

    public static function normalizePreferredPatternsByCourseId(
        array $preferredPatternsByCourseId,
        array $validCourseIds,
    ): array {
        $validCourseIdMap = array_fill_keys($validCourseIds, true);
        $normalized = [];

        foreach ($preferredPatternsByCourseId as $courseId => $pattern) {
            $courseId = (int) $courseId;

            if (! isset($validCourseIdMap[$courseId])) {
                throw new InvalidArgumentException(sprintf(
                    'Preferred pattern references unknown course ID %d.',
                    $courseId,
                ));
            }

            $normalized[$courseId] = self::normalizePreferredPattern($pattern);
        }

        return $normalized;
    }

    public static function normalizePreferredPattern(mixed $preferredPattern): ?string
    {
        return SchedulingPolicy::normalizePreferredPattern($preferredPattern);
    }

    public static function nullableRoomId(mixed $roomId): ?int
    {
        if ($roomId === null || $roomId === '') {
            return null;
        }

        return (int) $roomId;
    }
}
