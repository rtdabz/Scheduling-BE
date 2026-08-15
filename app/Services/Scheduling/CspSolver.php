<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CSPSolver
{
    private const FIELD_DAY_END_TIME = '17:00:00';

    private const SOFT_FIELD_EVENING_PENALTY = 6;

    private const SPLIT_LECTURE_LAB_START_PAIR_LIMIT = 10;

    /** @var array<string, bool> */
    private array $databaseValidityCache = [];

    /**
     * Existing persisted schedules indexed for O(1) conflict lookup.
     * Keyed as "r:{roomId}:{day}" and "s:{sectionId}:{day}".
     *
     * @var array<string, list<array{start_time: string, end_time: string}>>
     */
    private array $existingScheduleIndex = [];

    /** @var array<int, int> */
    private array $existingRoomUseCounts = [];

    /** @var array<string, int> */
    private array $existingRoomDayUseSlots = [];

    /** @var array<int, array{physical: int, online: int, regular_physical?: int, protected_physical?: int}> */
    private array $existingSectionDeliveryCounts = [];

    /** @var array{active_sections: int, physical_rooms: int, target_physical_ratio: float, scarcity_multiplier: float, section_regular_physical_targets?: array<int, int>, section_lab_physical_targets?: array<int, int>, section_online_targets?: array<int, int>} */
    private array $departmentRoomFairness = [
        'active_sections' => 1,
        'physical_rooms' => 0,
        'target_physical_ratio' => 1.0,
        'scarcity_multiplier' => 0.0,
        'section_regular_physical_targets' => [],
        'section_lab_physical_targets' => [],
        'section_online_targets' => [],
    ];

    /**
     * Exposes the prepared department-level fairness targets to coordinators
     * that rank complete multi-section candidates. The returned snapshot is
     * read-only; hard constraints and the solver search remain unchanged.
     *
     * @return array{
     *     active_sections: int,
     *     physical_rooms: int,
     *     target_physical_ratio: float,
     *     scarcity_multiplier: float,
     *     section_regular_physical_targets: array<int, int>,
     *     section_lab_physical_targets: array<int, int>,
     *     section_online_targets: array<int, int>
     * }
     */
    public function departmentRoomFairness(): array
    {
        return $this->departmentRoomFairness;
    }

    /** @var array<int, int> */
    private array $roomCapacities = [];

    /** @var array<int, string> */
    private array $roomTypes = [];

    private int $iterations = 0;

    private int $maxIterations = 250_000;

    private float $startedAt = 0.0;

    private float $timeoutSeconds = 8.0;

    private bool $searchLimitReached = false;

    public function __construct() {}

    /**
     * @param array{
     *     section_id?: int|string,
     *     sectionId?: int|string,
     *     course_ids?: list<int|string>,
     *     courseIds?: list<int|string>,
     *     mode?: string,
     *     delivery_mode?: string,
     *     deliveryMode?: string,
     *     is_hybrid?: bool|int|string,
     *     isHybrid?: bool|int|string,
     *     preferred_patterns?: array<int|string, string|null>,
     *     preferredPatternsByCourseId?: array<int|string, string|null>,
     *     max_solutions?: int|string,
     *     maxSolutions?: int|string,
     *     max_iterations?: int|string,
     *     maxIterations?: int|string,
     *     timeout_seconds?: float|int|string,
     *     timeoutSeconds?: float|int|string
     * } $input
     */
    public function solveFromSchema(array $input): array
    {
        return array_map(
            static fn (array $solution): array => $solution['schedules'],
            $this->solveRankedFromSchema($input),
        );
    }

    /**
     * @param array{
     *     section_id?: int|string,
     *     sectionId?: int|string,
     *     course_ids?: list<int|string>,
     *     courseIds?: list<int|string>,
     *     mode?: string,
     *     delivery_mode?: string,
     *     deliveryMode?: string,
     *     is_hybrid?: bool|int|string,
     *     isHybrid?: bool|int|string,
     *     preferred_patterns?: array<int|string, string|null>,
     *     preferredPatternsByCourseId?: array<int|string, string|null>,
     *     max_solutions?: int|string,
     *     maxSolutions?: int|string,
     *     max_iterations?: int|string,
     *     maxIterations?: int|string,
     *     timeout_seconds?: float|int|string,
     *     timeoutSeconds?: float|int|string
     * } $input
     */
    public function solveRankedFromSchema(array $input): array
    {
        $schema = $this->normalizeInputSchema($input);

        return $this->solveRanked(
            sectionId: $schema['section_id'],
            courseIds: $schema['course_ids'],
            maxSolutions: $schema['max_solutions'],
            maxIterations: $schema['max_iterations'],
            timeoutSeconds: $schema['timeout_seconds'],
            deliveryMode: $schema['delivery_mode'],
            isHybrid: $schema['is_hybrid'],
            preferredPatternsByCourseId: $schema['preferred_patterns'],
            selectedLectureLabCourseIds: $schema['selected_split_session_course_ids'],
            balancedSplitCourseIds: $schema['balanced_split_course_ids'],
            anchoredSchedulesByCourseId: $schema['anchored_schedules'],
            deliveryModesByCourseId: $schema['delivery_modes_by_course_id'],
            seed: $schema['seed'] ?? null,
        );
    }

    /**
     * @param  list<int|string>  $courseIds
     * @param  array<int|string, string|null>  $preferredPatternsByCourseId
     */
    public function solve(
        int $sectionId,
        array $courseIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
        string $deliveryMode = 'on-site',
        bool $isHybrid = false,
        array $preferredPatternsByCourseId = [],
        array $selectedLectureLabCourseIds = [],
        array $balancedSplitCourseIds = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        ?int $seed = null,
    ): array {
        $rankedSolutions = $this->solveRanked(
            sectionId: $sectionId,
            courseIds: $courseIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            selectedLectureLabCourseIds: $selectedLectureLabCourseIds,
            balancedSplitCourseIds: $balancedSplitCourseIds,
            anchoredSchedulesByCourseId: $anchoredSchedulesByCourseId,
            deliveryModesByCourseId: $deliveryModesByCourseId,
            seed: $seed,
        );

        return array_map(
            static fn (array $solution): array => $solution['schedules'],
            $rankedSolutions,
        );
    }

    /**
     * @param  list<int|string>  $courseIds
     * @param  array<int|string, string|null>  $preferredPatternsByCourseId
     */
    public function solveRanked(
        int $sectionId,
        array $courseIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
        string $deliveryMode = 'on-site',
        bool $isHybrid = false,
        array $preferredPatternsByCourseId = [],
        array $selectedLectureLabCourseIds = [],
        array $balancedSplitCourseIds = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        ?int $seed = null,
    ): array {
        $this->validateArguments(
            courseIds: $courseIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
        );

        $anchoredSchedulesByCourseId = $this->normalizeAnchoredSchedulesByCourseId(
            anchoredSchedules: $anchoredSchedulesByCourseId,
            validCourseIds: $courseIds,
        );

        $this->resetSearchState(
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );

        $courseIds = $this->normalizeCourseIds($courseIds);

        if ($courseIds === []) {
            return [];
        }

        /** @var Sections $section */
        $section = Sections::query()
            ->with('term')
            ->findOrFail($sectionId);

        $this->validateSectionForScheduling($section);

        $coursesQuery = Course::query();
        if ($this->courseCategoryTablesExist()) {
            $coursesQuery->with('categories');
        }

        $courses = $coursesQuery
            ->whereIn('id', $courseIds)
            ->get()
            ->keyBy('id');

        $activeCurriculum = Curriculum::query()
            ->where('department_id', $section->department_id)
            ->where('status', 'active')
            ->first();

        if ($activeCurriculum) {
            $pivotMap = \DB::table('curriculum_course')
                ->where('curriculum_id', $activeCurriculum->id)
                ->whereIn('course_id', $courseIds)
                ->get()
                ->keyBy('course_id');

            foreach ($courses as $course) {
                if (isset($pivotMap[$course->id])) {
                    $p = $pivotMap[$course->id];
                    $course->year_level = (string) $p->year_level;
                    $course->semester = (string) $p->semester === '1' ? '1st' : ((string) $p->semester === '2' ? '2nd' : 'summer');
                }
            }
        }

        $this->ensureAllCoursesExist(
            courseIds: $courseIds,
            courses: $courses,
        );

        $courses = $courses
            ->filter(fn (Course $course): bool => $this->isSchedulableCourse($course))
            ->values()
            ->keyBy('id');

        if ($courses->isEmpty()) {
            throw new RuntimeException(
                'No schedulable courses found for this section. Courses with 0 lecture hours, 0 lab hours, and 0 units are treated as non-timetable requirements.'
            );
        }

        $this->validateCoursesForSection(
            section: $section,
            courses: $courses,
        );

        $preferredPatternsByCourseId = $this->normalizePreferredPatternsByCourseId(
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            validCourseIds: $courseIds,
        );
        $selectedLectureLabCourseIds = $this->normalizeCourseIds($selectedLectureLabCourseIds);
        $balancedSplitCourseIds = $this->normalizeCourseIds($balancedSplitCourseIds);
        $deliveryModesByCourseId = $this->normalizeDeliveryModesByCourseId(
            deliveryModesByCourseId: $deliveryModesByCourseId,
            validCourseIds: $courseIds,
        );

        $requiredRoomTypes = $this->requiredRoomTypesForDeliveryMode(
            courses: $courses,
            deliveryMode: $deliveryMode,
        );

        $this->validateRoomTypes($requiredRoomTypes);

        $rooms = Rooms::query()
            ->where('status', 'available')
            ->whereIn('room_type', $requiredRoomTypes)
            ->where(function ($query) use ($section): void {
                $query
                    ->whereNull('department_id')
                    ->orWhere('department_id', $section->department_id);
            })
            ->orderBy('room_code')
            ->get();

        foreach ($requiredRoomTypes as $rt) {
            if (($rt === 'field' || $rt === 'online') && ! $rooms->contains('room_type', $rt)) {
                $existingVirtual = Rooms::query()->where('room_code', strtoupper($rt))->first();
                $virtualRoom = new Rooms([
                    'room_code' => strtoupper($rt),
                    'room_type' => $rt,
                    'status' => 'available',
                    'department_id' => null,
                    'max_concurrent_classes' => in_array($rt, ['field', 'online'], true) ? 3 : 1,
                ]);
                $virtualRoom->id = $existingVirtual ? $existingVirtual->id : ($rt === 'field' ? 99999 : 99998);
                $rooms->push($virtualRoom);
            }
        }

        $this->roomCapacities = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                (int) $room->id => in_array((string) $room->room_type, ['field', 'online'], true)
                    ? 3
                    : max(1, (int) ($room->max_concurrent_classes ?? 1)),
            ])
            ->all();
        $this->roomTypes = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                (int) $room->id => (string) $room->room_type,
            ])
            ->all();

        $this->ensureRoomDomainsExist(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
        );

        $this->prepareDepartmentRoomFairness(
            section: $section,
            rooms: $rooms,
        );

        $this->preloadExistingSchedules(
            termId: (int) $section->term_id,
            sectionId: (int) $section->id,
            departmentId: (int) $section->department_id,
            replaceCourseIds: $courseIds,
        );

        $solverSeed = $seed !== null ? (int) $seed : random_int(1, 1000000);

        $department = Departments::query()->find((int) $section->department_id);
        $lectureLabScheduleOverrideEnabled = (bool) ($department?->lecture_lab_schedule_override_enabled ?? false);
        $fieldEveningScheduleEnabled = (bool) ($department?->field_evening_schedule_enabled ?? false);
        $sundayOnlineOnlyEnabled = (bool) ($department?->sunday_online_only_enabled ?? true);
        $forcedDaysByCourseId = $this->forcedDaysByCourseId((int) $section->department_id, $courseIds);

        $variables = $this->buildVariables(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            sectionId: (int) $section->id,
            seed: $solverSeed,
            lectureLabScheduleOverrideEnabled: $lectureLabScheduleOverrideEnabled,
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
            selectedLectureLabCourseIds: $selectedLectureLabCourseIds,
            balancedSplitCourseIds: $balancedSplitCourseIds,
            forcedDaysByCourseId: $forcedDaysByCourseId,
            anchoredSchedulesByCourseId: $anchoredSchedulesByCourseId,
            deliveryModesByCourseId: $deliveryModesByCourseId,
        );

        $variables = $this->prunePersistedConflictingCandidates(
            variables: $variables,
            sectionId: (int) $section->id,
            departmentId: (int) $section->department_id,
        );

        usort(
            $variables,
            static function (array $left, array $right): int {
                $domainComparison = count($left['domain'])
                    <=> count($right['domain']);

                if ($domainComparison !== 0) {
                    return $domainComparison;
                }

                return $right['duration_slots']
                    <=> $left['duration_slots'];
            },
        );

        foreach ($variables as $variable) {
            if ($variable['domain'] === []) {
                return [];
            }
        }

        // Collect a larger candidate pool so the diversity filter has more
        // material to choose from. We gather up to 20× the requested solutions
        // (capped at 200) to maximise the variety of distinct scheduling choices
        // before applying diversity-aware selection.
        $candidatePoolLimit = min(
            max($maxSolutions * 20, 50),
            100,
        );
        $onlineCapableAssignments = $this->onlineCapableVariableCount($variables);
        $balancedOnlineAssignments = min($onlineCapableAssignments, max(
            $this->minimumOnlineTargetForSection((int) $section->id),
            $this->splitLectureOnlineVariableCount($variables),
        ));

        $rawSolutions = [];
        $solutionSignatures = [];

        // First collect unrestricted valid candidates. A second pass adds
        // delivery-mode variety for the post-CSP evaluator; online balance is
        // not a hard requirement and cannot invalidate an otherwise valid CSP
        // solution.
        $unrestrictedPoolLimit = max($maxSolutions, intdiv($candidatePoolLimit, 2));
        $this->backtrack(
            variableIndex: 0,
            variables: $variables,
            section: $section,
            assignments: [],
            solutions: $rawSolutions,
            solutionSignatures: $solutionSignatures,
            solutionLimit: $unrestrictedPoolLimit,
            minimumOnlineAssignments: 0,
        );

        if ($balancedOnlineAssignments > 0 && ! $this->hasExceededSearchLimits()) {
            $this->backtrack(
                variableIndex: 0,
                variables: $variables,
                section: $section,
                assignments: [],
                solutions: $rawSolutions,
                solutionSignatures: $solutionSignatures,
                solutionLimit: $candidatePoolLimit,
                minimumOnlineAssignments: $balancedOnlineAssignments,
            );
        }

        // Score every raw solution.
        $scored = array_map(
            function (array $assignments) use ($courses): array {
                return [
                    'rank' => 0,
                    'score' => $this->calculateScore($assignments, $courses),
                    'schedules' => $this->toPublicScheduleRows($assignments),
                    '_raw' => $assignments,
                ];
            },
            $rawSolutions,
        );

        // Select a diverse subset of the scored solutions.
        $ranked = $this->selectDiverseSolutions($scored, $maxSolutions);

        // Strip the internal _raw field and assign sequential ranks.
        foreach ($ranked as $index => &$solution) {
            unset($solution['_raw']);
            $solution['rank'] = $index + 1;
        }

        unset($solution);

        return $ranked;
    }

    public function searchLimitReached(): bool
    {
        return $this->searchLimitReached;
    }

    public function iterationsUsed(): int
    {
        return $this->iterations;
    }

    private function backtrack(
        int $variableIndex,
        array $variables,
        Sections $section,
        array $assignments,
        array &$solutions,
        array &$solutionSignatures,
        int $solutionLimit,
        int $minimumOnlineAssignments = 0,
    ): void {
        if (count($solutions) >= $solutionLimit) {
            return;
        }

        if ($this->hasExceededSearchLimits()) {
            $this->searchLimitReached = true;

            return;
        }

        if ($variableIndex >= count($variables)) {
            if ($this->onlineLectureAssignmentCount($assignments) < $minimumOnlineAssignments) {
                return;
            }

            $signature = $this->createSolutionSignature($assignments);

            if (! isset($solutionSignatures[$signature])) {
                $solutionSignatures[$signature] = true;
                $solutions[] = $assignments;
            }

            return;
        }

        $variable = $variables[$variableIndex];

        $currentOnlineAssignments = $this->onlineLectureAssignmentCount($assignments);
        $remainingOnlineCapableAssignments = $this->onlineCapableVariableCount(
            array_slice($variables, $variableIndex),
        );
        if ($currentOnlineAssignments + $remainingOnlineCapableAssignments < $minimumOnlineAssignments) {
            return;
        }

        $domain = $this->rankDomainForTentativeCompactness($variable['domain'], $assignments);

        foreach ($domain as $candidate) {
            $this->iterations++;

            if ($this->hasExceededSearchLimits()) {
                $this->searchLimitReached = true;

                return;
            }

            if ($this->conflictsWithTentativeAssignments(
                candidate: $candidate,
                assignments: $assignments,
                sectionId: (int) $section->id,
            )) {
                continue;
            }

            if (! $this->passesFastCandidateGuards(
                candidate: $candidate,
                section: $section,
            )) {
                continue;
            }

            $nextAssignments = $assignments;
            $nextAssignments[] = $this->withScheduleContext(
                assignment: $candidate,
                section: $section,
            );

            $this->backtrack(
                variableIndex: $variableIndex + 1,
                variables: $variables,
                section: $section,
                assignments: $nextAssignments,
                solutions: $solutions,
                solutionSignatures: $solutionSignatures,
                solutionLimit: $solutionLimit,
                minimumOnlineAssignments: $minimumOnlineAssignments,
            );

            if (count($solutions) >= $solutionLimit) {
                return;
            }
        }
    }

    /**
     * Re-ranks a variable domain against the partial assignment already built
     * during search. This helps the solver fill adjacent room/section openings
     * before it explores starts that create 30-minute or 1-hour holes.
     *
     * @param  list<array<string, mixed>>  $domain
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function rankDomainForTentativeCompactness(array $domain, array $assignments): array
    {
        if ($assignments === [] || count($domain) < 2) {
            return $domain;
        }

        usort(
            $domain,
            fn (array $left, array $right): int => $this->candidateTentativeGapPenalty($left, $assignments)
                    <=> $this->candidateTentativeGapPenalty($right, $assignments),
        );

        return $domain;
    }

    /**
     * Scores how much a candidate would spread the current partial timetable.
     * Lower is better. Gaps in the same physical room are weighted heavily;
     * section-day gaps are also penalized so student schedules stay compact.
     *
     * @param  list<array<string, mixed>>  $assignments
     */
    private function candidateTentativeGapPenalty(array $candidate, array $assignments): int
    {
        $penalty = 0;

        foreach ($candidate['blocks'] ?? [] as $candidateBlock) {
            $candidateRoomId = $this->nullableRoomId(
                array_key_exists('room_id', $candidateBlock)
                    ? $candidateBlock['room_id']
                    : ($candidate['room_id'] ?? null)
            );
            $candidateIsVirtual = $this->isVirtualCandidateBlock($candidate, $candidateBlock);
            $bestSectionGap = null;
            $bestRoomGap = null;

            foreach ($assignments as $assignment) {
                foreach ($assignment['blocks'] ?? [] as $assignedBlock) {
                    if (($candidateBlock['day'] ?? null) !== ($assignedBlock['day'] ?? null)) {
                        continue;
                    }

                    $gap = $this->nonOverlappingSlotGap($candidateBlock, $assignedBlock);
                    if ($gap === null) {
                        continue;
                    }

                    $bestSectionGap = $bestSectionGap === null ? $gap : min($bestSectionGap, $gap);

                    $assignedRoomId = $this->nullableRoomId(
                        array_key_exists('room_id', $assignedBlock)
                            ? $assignedBlock['room_id']
                            : ($assignment['room_id'] ?? null)
                    );

                    if (
                        ! $candidateIsVirtual
                        && $candidateRoomId !== null
                        && $assignedRoomId !== null
                        && $candidateRoomId === $assignedRoomId
                        && ! $this->isVirtualCandidateBlock($assignment, $assignedBlock)
                    ) {
                        $bestRoomGap = $bestRoomGap === null ? $gap : min($bestRoomGap, $gap);
                    }
                }
            }

            if ($bestRoomGap !== null) {
                $penalty += $bestRoomGap * 500;
                if ($bestRoomGap > 0 && $bestRoomGap <= 2) {
                    $penalty += 3000;
                }
            }

            if ($bestSectionGap !== null) {
                $penalty += $bestSectionGap * 80;
                if ($bestSectionGap > 0 && $bestSectionGap <= 2) {
                    $penalty += 600;
                }
            }
        }

        return $penalty;
    }

    private function nonOverlappingSlotGap(array $left, array $right): ?int
    {
        $leftStart = (int) ($left['start_slot'] ?? 0);
        $leftEnd = (int) ($left['end_slot'] ?? $leftStart);
        $rightStart = (int) ($right['start_slot'] ?? 0);
        $rightEnd = (int) ($right['end_slot'] ?? $rightStart);

        if ($leftEnd <= $rightStart) {
            return max(0, $rightStart - $leftEnd);
        }

        if ($rightEnd <= $leftStart) {
            return max(0, $leftStart - $rightEnd);
        }

        return null;
    }

    private function buildVariables(
        Collection $courses,
        Collection $rooms,
        string $deliveryMode,
        bool $isHybrid,
        array $preferredPatternsByCourseId,
        int $sectionId = 0,
        int $seed = 0,
        bool $lectureLabScheduleOverrideEnabled = false,
        bool $fieldEveningScheduleEnabled = false,
        bool $sundayOnlineOnlyEnabled = true,
        array $selectedLectureLabCourseIds = [],
        array $balancedSplitCourseIds = [],
        array $forcedDaysByCourseId = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
    ): array {
        $variables = [];

        foreach ($courses as $course) {
            $courseDeliveryMode = $deliveryModesByCourseId[(int) $course->id] ?? $deliveryMode;
            $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
            $lecHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            $hasBothComponents = $lectureLabScheduleOverrideEnabled
                && $isMajor
                && in_array((int) $course->id, $selectedLectureLabCourseIds, true)
                && $lecHours > 0
                && $labHours > 0;

            $preferredPattern = $this->normalizePreferredPattern(
                $preferredPatternsByCourseId[(int) $course->id] ?? null,
            );
            $requiresBalancedSplit = in_array((int) $course->id, $balancedSplitCourseIds, true);

            if ($hasBothComponents) {
                $durationSlots = ($lecHours * 2) + ($labHours * 6);
            } else {
                $durationSlots = $this->getDurationSlots($course);
            }

            $domain = match (true) {
                $courseDeliveryMode === 'online' && $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $isHybrid,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $hasBothComponents && $preferredPattern === null => $this->buildDefaultLectureLabDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $isHybrid,
                    anchoredSchedule: $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $requiresBalancedSplit && $preferredPattern === null => $this->buildFlexibleBalancedSplitDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $isHybrid,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $isHybrid,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                default => $this->buildPatternDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $isHybrid,
                    requireBalancedDurations: $requiresBalancedSplit,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
            };

            if (array_key_exists((int) $course->id, $deliveryModesByCourseId)) {
                $domain = array_values(array_filter($domain, static function (array $candidate) use ($courseDeliveryMode): bool {
                    foreach ($candidate['blocks'] ?? [] as $block) {
                        if ((string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site') !== $courseDeliveryMode) {
                            return false;
                        }
                    }

                    return true;
                }));
            }

            $forcedDay = $forcedDaysByCourseId[(int) $course->id] ?? null;
            if ($forcedDay !== null) {
                $domain = $this->filterDomainByForcedDay($domain, $forcedDay);
            }

            // Sort by (day, start_slot) then interleave on-site and online so
            // the backtracker sees both modes at every time slot — not all
            // on-site first followed by all online.
            usort(
                $domain,
                function (array $left, array $right): int {
                    $leftFirstBlock = $left['blocks'][0];
                    $rightFirstBlock = $right['blocks'][0];

                    $dayDiff = $this->dayIndex($leftFirstBlock['day'])
                        <=> $this->dayIndex($rightFirstBlock['day']);
                    if ($dayDiff !== 0) {
                        return $dayDiff;
                    }

                    $slotDiff = $leftFirstBlock['start_slot'] <=> $rightFirstBlock['start_slot'];
                    if ($slotDiff !== 0) {
                        return $slotDiff;
                    }

                    $leftOnlineLectureRank = $this->hasOnlineLectureBlock($left) ? 1 : 0;
                    $rightOnlineLectureRank = $this->hasOnlineLectureBlock($right) ? 1 : 0;
                    $onlineLectureDiff = $leftOnlineLectureRank <=> $rightOnlineLectureRank;
                    if ($onlineLectureDiff !== 0) {
                        return $onlineLectureDiff;
                    }

                    $modeOrder = ['on-site' => 0, 'field' => 1, 'online' => 2];
                    $leftModeRank = $modeOrder[$left['mode']] ?? 99;
                    $rightModeRank = $modeOrder[$right['mode']] ?? 99;
                    $modeDiff = $leftModeRank <=> $rightModeRank;
                    if ($modeDiff !== 0) {
                        return $modeDiff;
                    }

                    return ($left['room_id'] ?? 0) <=> ($right['room_id'] ?? 0);
                },
            );

            // Apply a deterministic section+course-seeded shuffle to the domain
            // so each section explores a different ordering of candidates,
            // preventing resource starvation where section 1 always claims the
            // same on-site rooms first.
            $shuffleSeed = abs($sectionId * 2053 + (int) $course->id * 97 + $seed);
            $domain = $this->seededShuffle($domain, $shuffleSeed);

            // Enforce domain candidate priority order after shuffle:
            //   0 → preferred physical room, on-site  (laboratory for lab courses, lecture for lecture courses)
            //   1 → fallback physical room, on-site   (lecture room fallback for lab courses)
            //   2 → online delivery mode              (tried last when physical rooms unavailable)
            usort(
                $domain,
                function (array $a, array $b) use ($sectionId): int {
                    $priorityDiff = $this->candidateAllocationPriority($a, $sectionId)
                        <=> $this->candidateAllocationPriority($b, $sectionId);
                    if ($priorityDiff !== 0) {
                        return $priorityDiff;
                    }

                    $availabilityDiff = $this->candidateRoomAvailabilityPenalty($a)
                        <=> $this->candidateRoomAvailabilityPenalty($b);
                    if ($availabilityDiff !== 0) {
                        return $availabilityDiff;
                    }

                    return $this->candidateRoomConcentrationPenalty($a)
                        <=> $this->candidateRoomConcentrationPenalty($b);
                },
            );

            $variables[] = [
                'course_id' => (int) $course->id,
                'is_field' => $this->isFieldCourse($course),
                'duration_slots' => $durationSlots,
                'preferred_pattern' => $preferredPattern,
                'delivery_mode' => $courseDeliveryMode,
                'is_hybrid' => $isHybrid,
                'domain' => $domain,
            ];
        }

        return $variables;
    }

    private function prunePersistedConflictingCandidates(array $variables, int $sectionId, int $departmentId): array
    {
        foreach ($variables as &$variable) {
            $variable['domain'] = array_values(array_filter(
                $variable['domain'],
                fn (array $candidate): bool => ! $this->candidateHasPersistedConflict(
                    candidate: $candidate,
                    sectionId: $sectionId,
                    departmentId: $departmentId,
                ),
            ));

            $hasWeekdayPhysicalAlternative = $this->hasWeekdayPhysicalCandidate($variable['domain']);
            $variable['domain'] = array_map(
                static function (array $candidate) use ($hasWeekdayPhysicalAlternative): array {
                    $candidate['_weekday_physical_available'] = $hasWeekdayPhysicalAlternative;

                    return $candidate;
                },
                $variable['domain'],
            );
        }

        unset($variable);

        return $variables;
    }

    private function candidateHasPersistedConflict(array $candidate, int $sectionId, int $departmentId): bool
    {
        foreach ($candidate['blocks'] as $block) {
            $blockRoomId = $this->nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );
            $blockMode = $block['mode'] ?? $candidate['mode'] ?? 'on-site';

            if ($this->hasExistingScheduleConflict(
                roomId: $blockRoomId,
                sectionId: $sectionId,
                day: $block['day'],
                startTime: $block['start_time'],
                endTime: $block['end_time'],
                skipRoomConflictCheck: false,
                facultyId: isset($candidate['faculty_id']) ? $this->nullableRoomId($candidate['faculty_id']) : null,
                mode: $blockMode,
                departmentId: $departmentId,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic Fisher-Yates shuffle seeded with $seed.
     * Produces a stable ordering per (section, course) pair without using
     * PHP's global mt_rand state, which would introduce non-determinism.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function seededShuffle(array $items, int $seed): array
    {
        $n = count($items);
        if ($n <= 1) {
            return $items;
        }

        // LCG parameters (Numerical Recipes)
        $a = 1664525;
        $c = 1013904223;
        $m = 2 ** 32;
        $state = $seed % $m;

        for ($i = $n - 1; $i > 0; $i--) {
            $state = (int) (($a * $state + $c) % $m);
            $j = $state % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /**
     * Returns the list of (day, mode) pairs that are valid for a given course
     * based on its category, delivery type, and institutional scheduling rules:
     *
     *  - NSTP (ROTC/CWTS/LTS)        : Monday-Sunday, field mode.
     *  - PATHFIT / other field (non-NSTP): Monday–Friday, field mode.
     *  - Minor non-field (GEC, GEE, ...): Monday-Saturday, on-site or online.
     *  - Major                        : Monday–Saturday on-site or online;
     *                                   Sunday online-only.
     *
     * @return list<array{0: string, 1: string}> Each entry is [day, mode].
     */
    private function allowedDayModePairsForCourse(Course $course, bool $sundayOnlineOnlyEnabled = true): array
    {
        if ($this->isNstpCourse($course)) {
            return array_map(
                static fn (string $d): array => [$d, 'field'],
                SchedulingPolicy::DAYS,
            );
        }

        if ($this->isFieldCourse($course)) {
            // PATHFIT and other non-NSTP field courses: Mon–Fri, field.
            return array_map(
                static fn (string $d): array => [$d, 'field'],
                SchedulingPolicy::WEEKDAYS,
            );
        }

        $category = strtolower((string) ($course->course_category ?? 'major'));

        if ($category === 'minor') {
            // Minor subjects share the Mon-Sat domain used by regular classes.
            $pairs = [];
            foreach (SchedulingPolicy::WEEKDAYS_AND_SATURDAY as $day) {
                $pairs[] = [$day, 'on-site'];
                $pairs[] = [$day, 'online'];
            }

            return $pairs;
        }

        // Major courses: Mon–Sat on-site or online; Sunday online-only.
        $pairs = [];
        foreach (SchedulingPolicy::WEEKDAYS_AND_SATURDAY as $day) {
            $pairs[] = [$day, 'on-site'];
            $pairs[] = [$day, 'online'];
        }
        if (! $sundayOnlineOnlyEnabled) {
            $pairs[] = ['Sunday', 'on-site'];
        }
        $pairs[] = ['Sunday', 'online'];

        return $pairs;
    }

    private function buildSingleDayDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $deliveryMode,
        bool $isHybrid,
        bool $fieldEveningScheduleEnabled = false,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        $startSlots = SchedulingPolicy::generatedStartSlotsForDuration($durationSlots);

        if (empty($startSlots)) {
            return [];
        }

        $domain = [];
        $isLabCourse = $this->isMajorLabCourse($course);
        $hasLectureAndLab = $this->hasLectureAndLabHours($course);
        $singleBlockMeetingType = $this->singleBlockMeetingTypeForCourse($course);

        foreach ($this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled) as [$day, $mode]) {
            if ($hasLectureAndLab && $mode === 'online' && $deliveryMode !== 'online') {
                continue;
            }

            $isField = $this->isFieldCourse($course);
            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField => 'field',
                default => (string) $course->room_type_required,
            };

            // For on-site courses, prioritize room type based on curriculum (lab_hours)
            // with compatible fallbacks (lecture for lab courses, lab for lecture courses).
            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = ['laboratory'];
                } elseif ($targetRoomType === 'lecture') {
                    $roomTypes = ['lecture'];
                }
            }

            foreach ($startSlots as $startSlot) {
                $endSlot = $startSlot + $durationSlots;

                if ($isField && ! $fieldEveningScheduleEnabled && $this->endsAfterFieldDayWindow($endSlot)) {
                    continue;
                }

                if ($mode === 'online') {
                    $domain[] = [
                        'course_id' => (int) $course->id,
                        'room_id' => null,
                        'room_type' => 'online',
                        'preferred_pattern' => null,
                        'mode' => $mode,
                        'is_hybrid' => $isHybrid,
                        '_lab_fallback' => false,
                        'blocks' => [
                            array_merge($this->makeBlock(
                                day: $day,
                                startSlot: $startSlot,
                                endSlot: $endSlot,
                            ), $singleBlockMeetingType !== null ? [
                                'meeting_type' => $singleBlockMeetingType,
                            ] : []),
                        ],
                    ];

                    continue;
                }

                foreach ($roomTypes as $roomType) {
                    $roomsForType = $matchingRooms->filter(
                        static fn (Rooms $room): bool => $room->room_type === $roomType,
                    );

                    foreach ($roomsForType as $room) {
                        $domain[] = [
                            'course_id' => (int) $course->id,
                            'room_id' => (int) $room->id,
                            'room_type' => $roomType,
                            'preferred_pattern' => null,
                            'mode' => $mode,
                            'is_hybrid' => $mode === 'field' ? false : $isHybrid,
                            '_lab_fallback' => ($isLabCourse && $roomType === 'lecture') || (! $isLabCourse && $roomType === 'laboratory'),
                            'blocks' => [
                                array_merge($this->makeBlock(
                                    day: $day,
                                    startSlot: $startSlot,
                                    endSlot: $endSlot,
                                ), $singleBlockMeetingType !== null ? [
                                    'meeting_type' => $singleBlockMeetingType,
                                ] : []),
                            ],
                        ];
                    }
                }
            }
        }

        return $domain;
    }

    private function hasOnlineLectureBlock(array $candidate): bool
    {
        foreach ($candidate['blocks'] ?? [] as $block) {
            if (($block['meeting_type'] ?? null) === 'lecture' && ($block['mode'] ?? $candidate['mode'] ?? 'on-site') === 'online') {
                return true;
            }
        }

        return false;
    }

    private function onlineLectureAssignmentCount(array $assignments): int
    {
        return count(array_filter(
            $assignments,
            fn (array $assignment): bool => $this->hasOnlineLectureBlock($assignment),
        ));
    }

    private function filterDomainByForcedDay(array $domain, string $forcedDay): array
    {
        return array_values(array_filter(
            $domain,
            static function (array $candidate) use ($forcedDay): bool {
                foreach ($candidate['blocks'] ?? [] as $block) {
                    if (($block['day'] ?? null) !== $forcedDay) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function forcedDaysByCourseId(int $departmentId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('department_forced_course_days')
            ->where('department_id', $departmentId)
            ->whereIn('course_id', $courseIds)
            ->pluck('day', 'course_id')
            ->mapWithKeys(static fn ($day, $courseId): array => [(int) $courseId => (string) $day])
            ->all();
    }

    private function buildDefaultLectureLabDomain(
        Course $course,
        Collection $matchingRooms,
        string $deliveryMode,
        bool $isHybrid,
        ?array $anchoredSchedule = null,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        if ($this->isFieldCourse($course)) {
            return [];
        }

        $lectureSlots = (int) ($course->lecture_hours ?? 0) * 2;
        $labSlots = (int) ($course->lab_hours ?? 0) * 6;

        if ($lectureSlots <= 0 || $labSlots <= 0) {
            return [];
        }

        $labRooms = $matchingRooms->filter(
            static fn (Rooms $room): bool => $room->room_type === 'laboratory',
        );

        $lectureRooms = $matchingRooms->filter(
            static fn (Rooms $room): bool => $room->room_type === 'lecture',
        );

        $lectureOptions = $lectureRooms
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => 'lecture',
                'mode' => 'on-site',
            ])
            ->values()
            ->all();

        $lectureOptions[] = [
            'room_id' => null,
            'room_type' => 'online',
            'mode' => 'online',
        ];

        $labOptions = $labRooms
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => 'laboratory',
                'mode' => 'on-site',
            ])
            ->values()
            ->all();

        $dayPairs = $this->splitLectureLabDayPairs($course, $sundayOnlineOnlyEnabled);

        $domain = [];
        $componentOrders = [
            [
                ['type' => 'lecture', 'slots' => $lectureSlots],
                ['type' => 'laboratory', 'slots' => $labSlots],
            ],
            [
                ['type' => 'laboratory', 'slots' => $labSlots],
                ['type' => 'lecture', 'slots' => $lectureSlots],
            ],
        ];

        foreach ($dayPairs as [$day1, $day2]) {
            foreach ($componentOrders as [$firstComponent, $secondComponent]) {
                $day1StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($firstComponent['slots']);
                $day2StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($secondComponent['slots']);

                if (empty($day1StartSlots) || empty($day2StartSlots)) {
                    continue;
                }

                $firstOptions = $firstComponent['type'] === 'laboratory' ? $labOptions : $lectureOptions;
                $secondOptions = $secondComponent['type'] === 'laboratory' ? $labOptions : $lectureOptions;

                foreach ($this->rankedSplitStartPairs($day1StartSlots, $day2StartSlots) as [$day1Start, $day2Start]) {
                    $day1End = $day1Start + $firstComponent['slots'];
                    $day2End = $day2Start + $secondComponent['slots'];

                    foreach ($firstOptions as $option1) {
                        foreach ($secondOptions as $option2) {
                            $domain[] = [
                                'course_id' => (int) $course->id,
                                'room_id' => $option1['room_id'],
                                'room_type' => $option1['room_type'],
                                'preferred_pattern' => null,
                                'mode' => $option1['mode'],
                                'is_hybrid' => $isHybrid,
                                '_lab_fallback' => false,
                                'blocks' => [
                                    array_merge($this->makeBlock(
                                        day: $day1,
                                        startSlot: $day1Start,
                                        endSlot: $day1End,
                                    ), [
                                        'room_id' => $option1['room_id'],
                                        'room_type' => $option1['room_type'],
                                        'mode' => $option1['mode'],
                                        'meeting_type' => $firstComponent['type'],
                                    ]),
                                    array_merge($this->makeBlock(
                                        day: $day2,
                                        startSlot: $day2Start,
                                        endSlot: $day2End,
                                    ), [
                                        'room_id' => $option2['room_id'],
                                        'room_type' => $option2['room_type'],
                                        'mode' => $option2['mode'],
                                        'meeting_type' => $secondComponent['type'],
                                    ]),
                                ],
                            ];
                        }
                    }
                }
            }
        }

        if ($anchoredSchedule !== null) {
            $anchoredDomain = $this->filterLectureLabDomainByAnchor($domain, $anchoredSchedule);
            if ($anchoredDomain !== []) {
                return $anchoredDomain;
            }
        }

        return $domain;
    }

    private function splitLectureLabDayPairs(Course $course, bool $sundayOnlineOnlyEnabled): array
    {
        $onSiteDays = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            array_filter(
                $this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled),
                static fn (array $pair): bool => $pair[1] === 'on-site',
            ),
        )));

        $pairs = [];
        foreach (SchedulingPolicy::FIXED_MEETING_PATTERNS as $days) {
            if (in_array($days[0], $onSiteDays, true) && in_array($days[1], $onSiteDays, true)) {
                $pairs[] = $days;
            }
        }

        $fallbackPairs = [
            ['Monday', 'Tuesday'],
            ['Monday', 'Thursday'],
            ['Tuesday', 'Wednesday'],
            ['Tuesday', 'Friday'],
            ['Wednesday', 'Thursday'],
            ['Wednesday', 'Friday'],
            ['Thursday', 'Friday'],
            ['Monday', 'Friday'],
            ['Thursday', 'Saturday'],
            ['Friday', 'Saturday'],
            ['Tuesday', 'Saturday'],
        ];

        foreach ($fallbackPairs as $days) {
            if (in_array($days[0], $onSiteDays, true) && in_array($days[1], $onSiteDays, true)) {
                $pairs[] = $days;
            }
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[implode('|', $pair)] = $pair;
        }

        return array_values($unique);
    }

    private function filterLectureLabDomainByAnchor(array $domain, array $anchoredSchedule): array
    {
        $anchorDay = (string) ($anchoredSchedule['day'] ?? '');
        $anchorStart = (string) ($anchoredSchedule['start_time'] ?? '');
        $anchorEnd = (string) ($anchoredSchedule['end_time'] ?? '');
        $anchorRoomId = $this->nullableRoomId($anchoredSchedule['room_id'] ?? null);

        if ($anchorDay === '' || $anchorStart === '' || $anchorEnd === '') {
            return [];
        }

        return array_values(array_filter(
            $domain,
            function (array $candidate) use ($anchorDay, $anchorStart, $anchorEnd, $anchorRoomId): bool {
                foreach ($candidate['blocks'] ?? [] as $block) {
                    if (($block['meeting_type'] ?? null) !== 'laboratory') {
                        continue;
                    }

                    $blockRoomId = $this->nullableRoomId(
                        array_key_exists('room_id', $block)
                            ? $block['room_id']
                            : ($candidate['room_id'] ?? null)
                    );

                    $roomMatches = $anchorRoomId === null || $blockRoomId === $anchorRoomId;

                    if (
                        ($block['day'] ?? null) === $anchorDay
                        && ($block['start_time'] ?? null) === $anchorStart
                        && ($block['end_time'] ?? null) === $anchorEnd
                        && $roomMatches
                    ) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    private function buildFlexibleBalancedSplitDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $deliveryMode,
        bool $isHybrid,
        bool $fieldEveningScheduleEnabled = false,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        $domain = [];

        foreach ($this->balancedSplitDayPairs($course, $sundayOnlineOnlyEnabled) as [$day1, $day2]) {
            $domain = array_merge(
                $domain,
                $this->buildPatternDomain(
                    course: $course,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    preferredPattern: sprintf('days:%d-%d', $this->dayIndex($day1), $this->dayIndex($day2)),
                    deliveryMode: $deliveryMode,
                    isHybrid: $isHybrid,
                    requireBalancedDurations: true,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
            );
        }

        return $domain;
    }

    private function balancedSplitDayPairs(Course $course, bool $sundayOnlineOnlyEnabled): array
    {
        $allowedDays = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            $this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled),
        )));

        $preferredPairs = [
            ['Monday', 'Wednesday'],
            ['Tuesday', 'Thursday'],
            ['Monday', 'Tuesday'],
            ['Monday', 'Thursday'],
            ['Tuesday', 'Wednesday'],
            ['Tuesday', 'Friday'],
            ['Wednesday', 'Thursday'],
            ['Wednesday', 'Friday'],
            ['Thursday', 'Friday'],
            ['Monday', 'Friday'],
        ];

        $pairs = [];
        foreach ($preferredPairs as $days) {
            if (in_array($days[0], $allowedDays, true) && in_array($days[1], $allowedDays, true)) {
                $pairs[] = $days;
            }
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[implode('|', $pair)] = $pair;
        }

        return array_values($unique);
    }

    private function buildPatternDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
        string $deliveryMode,
        bool $isHybrid,
        bool $requireBalancedDurations = false,
        bool $fieldEveningScheduleEnabled = false,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        if ($durationSlots < 2) {
            return [];
        }

        [$day1, $day2] = $this->patternDays($preferredPattern);

        $allowedDays = array_unique(
            array_column($this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled), 0),
        );

        if (! in_array($day1, $allowedDays, true) || ! in_array($day2, $allowedDays, true)) {
            return [];
        }

        $domain = [];
        $isField = $this->isFieldCourse($course);
        $isLabCourse = $this->isMajorLabCourse($course);
        $modes = $isField ? ['field'] : ['on-site', 'online'];

        foreach ($modes as $mode) {
            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField => 'field',
                default => (string) $course->room_type_required,
            };

            // For on-site courses, prioritize room type based on curriculum (lab_hours)
            // with compatible fallbacks (lecture for lab courses, lab for lecture courses).
            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = ['laboratory'];
                } elseif ($targetRoomType === 'lecture') {
                    $roomTypes = ['lecture'];
                }
            }

            $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
            $lecHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            $hasBothComponents = $isMajor && $lecHours > 0 && $labHours > 0;

            $durations = [];
            if ($hasBothComponents) {
                $lectureSlots = $lecHours * 2;
                $labSlots = $labHours * 6;
                $durations[] = [$labSlots, $lectureSlots];
                $durations[] = [$lectureSlots, $labSlots];
            } elseif ($requireBalancedDurations) {
                if ($durationSlots % 2 !== 0) {
                    return [];
                }

                $halfDuration = (int) ($durationSlots / 2);
                $durations[] = [$halfDuration, $halfDuration];
            } else {
                for ($day1Duration = 1; $day1Duration < $durationSlots; $day1Duration++) {
                    $durations[] = [$day1Duration, $durationSlots - $day1Duration];
                }
            }

            foreach ($durations as [$day1Duration, $day2Duration]) {
                $day1StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($day1Duration);
                $day2StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($day2Duration);

                if (empty($day1StartSlots) || empty($day2StartSlots)) {
                    continue;
                }

                foreach ($this->rankedSplitStartPairs($day1StartSlots, $day2StartSlots) as [$day1Start, $day2Start]) {
                    $day1End = $day1Start + $day1Duration;
                    $day2End = $day2Start + $day2Duration;

                    if ($isField && ! $fieldEveningScheduleEnabled && (
                        $this->endsAfterFieldDayWindow($day1End)
                        || $this->endsAfterFieldDayWindow($day2End)
                    )) {
                        continue;
                    }

                    if ($hasBothComponents && $mode === 'on-site') {
                        $labOptions = $matchingRooms
                            ->filter(static fn (Rooms $room): bool => $room->room_type === 'laboratory')
                            ->map(static fn (Rooms $room): array => [
                                'room_id' => (int) $room->id,
                                'room_type' => 'laboratory',
                                'mode' => 'on-site',
                            ])
                            ->values()
                            ->all();
                        $lectureOptions = $matchingRooms
                            ->filter(static fn (Rooms $room): bool => $room->room_type === 'lecture')
                            ->map(static fn (Rooms $room): array => [
                                'room_id' => (int) $room->id,
                                'room_type' => 'lecture',
                                'mode' => 'on-site',
                            ])
                            ->values()
                            ->all();
                        $lectureOptions[] = [
                            'room_id' => null,
                            'room_type' => 'online',
                            'mode' => 'online',
                        ];
                        $day1IsLab = ($day1Duration === $labSlots);
                        $firstOptions = $day1IsLab ? $labOptions : $lectureOptions;
                        $secondOptions = $day1IsLab ? $lectureOptions : $labOptions;

                        foreach ($firstOptions as $option1) {
                            foreach ($secondOptions as $option2) {
                                $domain[] = [
                                    'course_id' => (int) $course->id,
                                    'room_id' => $option1['room_id'],
                                    'room_type' => $option1['room_type'],
                                    'preferred_pattern' => $preferredPattern,
                                    'mode' => $option1['mode'],
                                    'is_hybrid' => $isHybrid,
                                    '_lab_fallback' => false,
                                    'blocks' => [
                                        array_merge($this->makeBlock(
                                            day: $day1,
                                            startSlot: $day1Start,
                                            endSlot: $day1End,
                                        ), [
                                            'room_id' => $option1['room_id'],
                                            'room_type' => $option1['room_type'],
                                            'mode' => $option1['mode'],
                                            'meeting_type' => $day1IsLab ? 'laboratory' : 'lecture',
                                        ]),
                                        array_merge($this->makeBlock(
                                            day: $day2,
                                            startSlot: $day2Start,
                                            endSlot: $day2End,
                                        ), [
                                            'room_id' => $option2['room_id'],
                                            'room_type' => $option2['room_type'],
                                            'mode' => $option2['mode'],
                                            'meeting_type' => $day1IsLab ? 'lecture' : 'laboratory',
                                        ]),
                                    ],
                                ];
                            }
                        }
                    } else {
                        if ($mode === 'online') {
                            $domain[] = [
                                'course_id' => (int) $course->id,
                                'room_id' => null,
                                'room_type' => 'online',
                                'preferred_pattern' => $preferredPattern,
                                'mode' => $mode,
                                'is_hybrid' => $isHybrid,
                                '_lab_fallback' => false,
                                'blocks' => [
                                    $this->makeBlock(
                                        day: $day1,
                                        startSlot: $day1Start,
                                        endSlot: $day1End,
                                    ),
                                    $this->makeBlock(
                                        day: $day2,
                                        startSlot: $day2Start,
                                        endSlot: $day2End,
                                    ),
                                ],
                            ];

                            continue;
                        }

                        foreach ($roomTypes as $roomType) {
                            $roomsForType = $matchingRooms->filter(
                                static fn (Rooms $room): bool => $room->room_type === $roomType,
                            );

                            foreach ($roomsForType as $room) {
                                $domain[] = [
                                    'course_id' => (int) $course->id,
                                    'room_id' => (int) $room->id,
                                    'room_type' => $roomType,
                                    'preferred_pattern' => $preferredPattern,
                                    'mode' => $mode,
                                    'is_hybrid' => $mode === 'field' ? false : $isHybrid,
                                    '_lab_fallback' => ($isLabCourse && $roomType === 'lecture') || (! $isLabCourse && $roomType === 'laboratory'),
                                    'blocks' => [
                                        $this->makeBlock(
                                            day: $day1,
                                            startSlot: $day1Start,
                                            endSlot: $day1End,
                                        ),
                                        $this->makeBlock(
                                            day: $day2,
                                            startSlot: $day2Start,
                                            endSlot: $day2End,
                                        ),
                                    ],
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $domain;
    }

    /**
     * Split lecture/lab courses multiply day, time, room, and component-order
     * choices. Searching every start-time pair is expensive and rarely useful,
     * so prefer aligned or nearby times first and keep a bounded candidate set.
     *
     * @param  list<int>  $firstStartSlots
     * @param  list<int>  $secondStartSlots
     * @return list<array{0: int, 1: int}>
     */
    private function rankedSplitStartPairs(array $firstStartSlots, array $secondStartSlots): array
    {
        $pairs = [];

        foreach ($firstStartSlots as $firstStart) {
            foreach ($secondStartSlots as $secondStart) {
                $pairs[] = [
                    'first' => (int) $firstStart,
                    'second' => (int) $secondStart,
                    'score' => (abs((int) $firstStart - (int) $secondStart) * 10)
                        + min((int) $firstStart, (int) $secondStart),
                ];
            }
        }

        usort(
            $pairs,
            static fn (array $left, array $right): int => $left['score'] <=> $right['score']
                ?: $left['first'] <=> $right['first']
                ?: $left['second'] <=> $right['second'],
        );

        return array_map(
            static fn (array $pair): array => [$pair['first'], $pair['second']],
            array_slice($pairs, 0, self::SPLIT_LECTURE_LAB_START_PAIR_LIMIT),
        );
    }

    private function makeBlock(
        string $day,
        int $startSlot,
        int $endSlot,
    ): array {
        return [
            'day' => $day,
            'start_slot' => $startSlot,
            'end_slot' => $endSlot,
            'start_time' => $this->slotToTime($startSlot),
            'end_time' => $this->slotToTime($endSlot),
        ];
    }

    private function endsAfterFieldDayWindow(int $endSlot): bool
    {
        return $this->slotToTime($endSlot) > self::FIELD_DAY_END_TIME;
    }

    private function conflictsWithTentativeAssignments(
        array $candidate,
        array $assignments,
        ?int $sectionId = null,
    ): bool {
        // Build a count of blocks already assigned per day (for the per-day cap check).
        // We count unique courses, not blocks, to avoid over-penalizing split patterns.
        $dayCourseCounts = [];
        foreach ($assignments as $assigned) {
            $seenDays = [];
            foreach ($assigned['blocks'] as $assignedBlock) {
                $d = $assignedBlock['day'];
                if (! isset($seenDays[$d])) {
                    $seenDays[$d] = true;
                    $dayCourseCounts[$d] = ($dayCourseCounts[$d] ?? 0) + 1;
                }
            }
        }

        foreach ($candidate['blocks'] as $candidateBlock) {
            $day = $candidateBlock['day'];

            // Per-day course cap: count unique courses (not blocks) already on this day.
            $existingPersistedCount = $sectionId !== null
                ? count($this->existingScheduleIndex["s:{$sectionId}:{$day}"] ?? [])
                : 0;
            $tentativeCount = ($dayCourseCounts[$day] ?? 0) + 1;

            if (($tentativeCount + $existingPersistedCount) > SchedulingPolicy::MAX_CLASSES_PER_DAY) {
                return true;
            }

            foreach ($assignments as $assigned) {
                foreach ($assigned['blocks'] as $assignedBlock) {
                    if ($day !== $assignedBlock['day']) {
                        continue;
                    }

                    $overlaps =
                        $candidateBlock['start_slot'] < $assignedBlock['end_slot']
                        && $assignedBlock['start_slot'] < $candidateBlock['end_slot'];

                    if ($overlaps) {
                        // Section time overlap — always a conflict.
                        return true;
                    }

                }
            }

            // Update the course count for subsequent candidate blocks.
            $dayCourseCounts[$day] = ($dayCourseCounts[$day] ?? 0) + 1;
        }

        return false;
    }

    private function passesFastCandidateGuards(
        array $candidate,
        Sections $section,
    ): bool {
        $facultyId = isset($candidate['faculty_id']) && $candidate['faculty_id'] !== null
            ? (int) $candidate['faculty_id']
            : null;

        // Lightweight mode/room-type alignment guard using the room_type embedded
        // in the candidate by the domain builder. This is a zero-query safety net
        // that catches any mode/room mismatch (e.g. online room for an on-site course)
        // without hitting the database on every backtracking iteration.
        $candidateRoomType = $candidate['room_type'] ?? null;

        foreach ($candidate['blocks'] as $block) {
            $blockRoomId = $this->nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );
            $blockMode = $block['mode'] ?? $candidate['mode'] ?? 'on-site';
            $blockRoomType = $block['room_type'] ?? $candidateRoomType;
            $skipRoomCheck = $blockMode === 'online';

            if ($blockRoomType !== null) {
                $blockModeRoomMismatch = match ($blockMode) {
                    'online' => $blockRoomType !== 'online',
                    'field' => $blockRoomType !== 'field',
                    default => in_array($blockRoomType, ['online', 'field'], true),
                };

                if ($blockModeRoomMismatch) {
                    return false;
                }
            }

            $cacheKey = implode('|', [
                (int) $section->term_id,
                (int) $section->id,
                $candidate['course_id'],
                $blockRoomId ?? 'none',
                $block['day'],
                $block['start_time'],
                $block['end_time'],
                $candidate['preferred_pattern'] ?? 'null',
                $blockMode,
                $candidate['is_hybrid'] ? '1' : '0',
            ]);

            if (array_key_exists($cacheKey, $this->databaseValidityCache)) {
                if (! $this->databaseValidityCache[$cacheKey]) {
                    return false;
                }

                continue;
            }

            $isValid = ! $this->hasExistingScheduleConflict(
                roomId: $blockRoomId,
                sectionId: (int) $section->id,
                day: $block['day'],
                startTime: $block['start_time'],
                endTime: $block['end_time'],
                skipRoomConflictCheck: $blockMode === 'online' ? false : $skipRoomCheck,
                facultyId: $facultyId,
                mode: $blockMode,
                departmentId: (int) ($candidate['department_id'] ?? $section->department_id),
            );

            $this->databaseValidityCache[$cacheKey] = $isValid;

            if (! $isValid) {
                return false;
            }
        }

        return true;
    }

    private function withScheduleContext(array $assignment, Sections $section): array
    {
        return array_merge($assignment, [
            'term_id' => (int) $section->term_id,
            'section_id' => (int) $section->id,
            'department_id' => (int) $section->department_id,
        ]);
    }

    private function createSolutionSignature(array $assignments): string
    {
        $signatureRows = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] as $block) {
                $blockRoomId = array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($assignment['room_id'] ?? null);

                $signatureRows[] = [
                    'course_id' => $assignment['course_id'],
                    'room_id' => $blockRoomId,
                    'preferred_pattern' => $assignment['preferred_pattern'],
                    'mode' => $block['mode'] ?? $assignment['mode'],
                    'is_hybrid' => $assignment['is_hybrid'],
                    'day' => $block['day'],
                    'start_slot' => $block['start_slot'],
                    'end_slot' => $block['end_slot'],
                ];
            }
        }

        usort(
            $signatureRows,
            function (array $left, array $right): int {
                return [
                    $left['course_id'],
                    $this->dayIndex($left['day']),
                    $left['start_slot'],
                    $left['end_slot'],
                    $left['room_id'] ?? 0,
                    $left['preferred_pattern'] ?? '',
                    $left['mode'],
                    $left['is_hybrid'] ? 1 : 0,
                ] <=> [
                    $right['course_id'],
                    $this->dayIndex($right['day']),
                    $right['start_slot'],
                    $right['end_slot'],
                    $right['room_id'] ?? 0,
                    $right['preferred_pattern'] ?? '',
                    $right['mode'],
                    $right['is_hybrid'] ? 1 : 0,
                ];
            },
        );

        return hash(
            'sha256',
            (string) json_encode($signatureRows, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Selects up to $limit solutions from the scored candidate pool using a
     * greedy diversity-first algorithm.
     *
     * Algorithm:
     *   1. Seed the selection with the best-scoring (lowest penalty) solution.
     *   2. For each subsequent slot, score every remaining candidate by how
     *      different it is from all already-selected solutions, then pick the
     *      one with the highest combined diversity+quality value.
     *
     * Diversity dimensions (each contributes to the diversity score):
     *   - Day-set difference: distinct weekdays used vs. already-selected sets.
     *   - Time-band difference: morning/midday/afternoon/evening bands.
     *   - Room difference: whether a different room is used.
     *   - Meeting-pattern difference: single vs. split, or different pattern days.
     *
     * @param  array<int, array{rank: int, score: int, schedules: array, _raw: array}>  $scored
     * @return array<int, array{rank: int, score: int, schedules: array, _raw: array}>
     */
    private function selectDiverseSolutions(array $scored, int $limit): array
    {
        if ($scored === [] || $limit <= 0) {
            return [];
        }

        // Sort by ascending score (lower penalty = better quality) to bias
        // the first pick toward the best solution.
        usort(
            $scored,
            static function (array $left, array $right): int {
                if ($left['score'] !== $right['score']) {
                    return $left['score'] <=> $right['score'];
                }

                return (string) json_encode($left['schedules'])
                    <=> (string) json_encode($right['schedules']);
            },
        );

        $selected = [];
        $remaining = $scored;

        // Seed with the highest-quality solution.
        $selected[] = array_shift($remaining);

        while (count($selected) < $limit && $remaining !== []) {
            $bestIndex = 0;
            $bestCombined = PHP_INT_MIN;

            foreach ($remaining as $idx => $candidate) {
                // Diversity: how different is this candidate from every already-
                // selected solution? Sum the minimum pairwise differences.
                $minDiversity = PHP_INT_MAX;

                foreach ($selected as $sel) {
                    $diversity = $this->computeSolutionDiversity(
                        $candidate['_raw'],
                        $sel['_raw'],
                    );

                    if ($diversity < $minDiversity) {
                        $minDiversity = $diversity;
                    }
                }

                // Quality: negate the penalty score so lower penalty = higher value.
                // Scale by a small factor so diversity dominates when quality is close.
                $qualityValue = -$candidate['score'];

                // Combined value: diversity (primary) + quality (secondary tiebreak).
                // We multiply diversity by 100 to ensure it outweighs small score diffs.
                $combined = ($minDiversity * 100) + $qualityValue;

                if ($combined > $bestCombined) {
                    $bestCombined = $combined;
                    $bestIndex = $idx;
                }
            }

            $selected[] = $remaining[$bestIndex];
            array_splice($remaining, $bestIndex, 1);
        }

        return $selected;
    }

    /**
     * Computes a diversity score between two raw assignment sets.
     *
     * Returns an integer in [0, ∞) where higher means MORE different.
     * Scores are deliberately coarse-grained so that only substantial
     * scheduling differences (different days, time bands, rooms) contribute,
     * not trivial 30-minute shifts.
     *
     * Components:
     *   +4 per weekday that appears in one solution but not the other.
     *   +3 if the dominant time band (morning/midday/afternoon/evening) differs.
     *   +2 per room that appears in one solution but not the other.
     *   +2 if the meeting count (single vs. split) differs.
     *   +1 if the pattern keys differ (e.g., MW vs. TTh vs. days:x-y).
     */
    private function computeSolutionDiversity(array $rawA, array $rawB): int
    {
        $daysA = [];
        $daysB = [];
        $roomsA = [];
        $roomsB = [];
        $bandsA = [];
        $bandsB = [];
        $blocksA = 0;
        $blocksB = 0;
        $onlineBlocksA = 0;
        $onlineBlocksB = 0;
        $patternA = [];
        $patternB = [];
        $modesA = [];
        $modesB = [];

        foreach ($rawA as $assignment) {
            if (($assignment['room_id'] ?? null) !== null) {
                $roomsA[] = $assignment['room_id'];
            }
            if (! empty($assignment['preferred_pattern'])) {
                $patternA[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysA[] = $block['day'];
                $bandsA[] = $this->computeTimeBand($block['start_slot']);
                $modesA[] = $block['mode'] ?? $assignment['mode'] ?? 'on-site';
                if (($block['mode'] ?? $assignment['mode'] ?? 'on-site') === 'online') {
                    $onlineBlocksA++;
                }
                $blocksA++;
            }
        }

        foreach ($rawB as $assignment) {
            if (($assignment['room_id'] ?? null) !== null) {
                $roomsB[] = $assignment['room_id'];
            }
            if (! empty($assignment['preferred_pattern'])) {
                $patternB[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysB[] = $block['day'];
                $bandsB[] = $this->computeTimeBand($block['start_slot']);
                $modesB[] = $block['mode'] ?? $assignment['mode'] ?? 'on-site';
                if (($block['mode'] ?? $assignment['mode'] ?? 'on-site') === 'online') {
                    $onlineBlocksB++;
                }
                $blocksB++;
            }
        }

        $daysA = array_unique($daysA);
        $daysB = array_unique($daysB);
        $roomsA = array_unique($roomsA);
        $roomsB = array_unique($roomsB);
        $bandsA = array_unique($bandsA);
        $bandsB = array_unique($bandsB);
        $modesA = array_unique($modesA);
        $modesB = array_unique($modesB);

        $diversity = 0;

        // Day-set symmetric difference (4 pts per distinct day not shared).
        $dayDiff = array_merge(
            array_diff($daysA, $daysB),
            array_diff($daysB, $daysA),
        );
        $diversity += count(array_unique($dayDiff)) * 4;

        // Time-band symmetric difference (3 pts per distinct band not shared).
        $bandDiff = array_merge(
            array_diff($bandsA, $bandsB),
            array_diff($bandsB, $bandsA),
        );
        $diversity += count(array_unique($bandDiff)) * 3;

        // Room symmetric difference (2 pts per room not shared).
        $roomDiff = array_merge(
            array_diff($roomsA, $roomsB),
            array_diff($roomsB, $roomsA),
        );
        $diversity += count(array_unique($roomDiff)) * 2;

        $modeDiff = array_merge(
            array_diff($modesA, $modesB),
            array_diff($modesB, $modesA),
        );
        $diversity += count(array_unique($modeDiff)) * 3;
        $diversity += abs($onlineBlocksA - $onlineBlocksB) * 3;

        // Meeting count difference (2 pts if one is single and the other split).
        if (($blocksA === 1) !== ($blocksB === 1)) {
            $diversity += 2;
        }

        // Pattern key difference (1 pt if the pattern strings differ).
        $patternA = array_unique($patternA);
        $patternB = array_unique($patternB);
        sort($patternA);
        sort($patternB);
        if ($patternA !== $patternB) {
            $diversity += 1;
        }

        return $diversity;
    }

    /**
     * Returns a coarse time-band label for a slot index.
     *
     * Bands (in 30-min slots from 07:00):
     *   morning   → slots  0–5  (07:00–09:30)
     *   midday    → slots  6–11 (10:00–12:30)
     *   afternoon → slots 12–17 (13:00–15:30)
     *   evening   → slots 18–23 (16:00–19:00)
     */
    private function computeTimeBand(int $startSlot): string
    {
        if ($startSlot < 6) {
            return 'morning';
        }

        if ($startSlot < 12) {
            return 'midday';
        }

        if ($startSlot < 18) {
            return 'afternoon';
        }

        return 'evening';
    }

    private function calculateScore(array $assignments, ?Collection $courses = null): int
    {
        $score = 0;
        $byDay = [];
        $physicalRoomBlockCounts = [];
        $physicalRoomBlocksByRoomDay = [];
        $physicalRoomBlockTotal = 0;
        $generatedDeliveryCountsBySection = [];
        $eligibleLectureDeliveryCountsBySection = [];

        foreach ($assignments as $assignment) {
            $blockDurations = [];
            $assignmentSectionId = (int) ($assignment['section_id'] ?? 0);

            foreach ($assignment['blocks'] as $block) {
                $blockRoomId = $this->nullableRoomId(
                    array_key_exists('room_id', $block)
                        ? $block['room_id']
                        : ($assignment['room_id'] ?? null)
                );
                $byDay[$block['day']][] = [
                    'course_id' => $assignment['course_id'],
                    'room_id' => $blockRoomId,
                    'start_slot' => $block['start_slot'],
                    'end_slot' => $block['end_slot'],
                ];

                $blockMode = (string) ($block['mode'] ?? $assignment['mode'] ?? 'on-site');
                $blockRoomType = (string) ($block['room_type'] ?? $assignment['room_type'] ?? '');
                $isOnlineBlock = $blockMode === 'online' || $blockRoomType === 'online';
                $isPhysicalRoomBlock = $blockRoomId !== null
                    && ! $isOnlineBlock
                    && $blockMode !== 'field'
                    && ! in_array($blockRoomType, ['field'], true);

                if ($assignmentSectionId > 0) {
                    $generatedDeliveryCountsBySection[$assignmentSectionId] ??= [
                        'physical' => 0,
                        'online' => 0,
                        'protected_physical' => 0,
                        'regular_physical' => 0,
                    ];

                    if ($isOnlineBlock) {
                        $generatedDeliveryCountsBySection[$assignmentSectionId]['online']++;
                    } elseif ($isPhysicalRoomBlock) {
                        $generatedDeliveryCountsBySection[$assignmentSectionId]['physical']++;

                        if (
                            ($block['meeting_type'] ?? null) === 'laboratory'
                            || $blockRoomType === 'laboratory'
                            || ($assignment['_lab_fallback'] ?? false)
                        ) {
                            $generatedDeliveryCountsBySection[$assignmentSectionId]['protected_physical']++;
                        } else {
                            $generatedDeliveryCountsBySection[$assignmentSectionId]['regular_physical']++;
                        }
                    }

                    $isLectureBlock = ($block['meeting_type'] ?? null) === 'lecture'
                        || (
                            ($assignment['room_type'] ?? null) === 'lecture'
                            && ($block['meeting_type'] ?? null) !== 'laboratory'
                            && ($block['room_type'] ?? null) !== 'laboratory'
                        );

                    if ($isLectureBlock && ! $this->isFieldCandidateBlock($assignment, $block)) {
                        $eligibleLectureDeliveryCountsBySection[$assignmentSectionId] ??= [
                            'physical' => 0,
                            'online' => 0,
                        ];

                        if ($isOnlineBlock) {
                            $eligibleLectureDeliveryCountsBySection[$assignmentSectionId]['online']++;
                        } elseif ($isPhysicalRoomBlock) {
                            $eligibleLectureDeliveryCountsBySection[$assignmentSectionId]['physical']++;
                        }
                    }
                }

                if (
                    $isPhysicalRoomBlock
                ) {
                    $physicalRoomBlockCounts[$blockRoomId] = ($physicalRoomBlockCounts[$blockRoomId] ?? 0) + 1;
                    $physicalRoomBlocksByRoomDay["{$blockRoomId}:{$block['day']}"][] = [
                        'start_slot' => $block['start_slot'],
                        'end_slot' => $block['end_slot'],
                    ];
                    $physicalRoomBlockTotal++;

                    // Prefer rooms that are still empty or lightly used in the current term.
                    $score += ($this->existingRoomUseCounts[$blockRoomId] ?? 0) * 3;
                }

                $blockDurations[] = $block['end_slot'] - $block['start_slot'];

                if ($block['day'] === 'Saturday') {
                    $score += 200;
                }

                if ($block['day'] === 'Sunday') {
                    $score += 1000;
                }

                if ($assignment['_weekday_physical_available'] ?? false) {
                    if (in_array($block['day'], ['Saturday', 'Sunday'], true)) {
                        $score += SchedulingPolicy::SOFT_WEEKDAY_PHYSICAL_MIGRATION_PENALTY;
                    }

                    if ($isOnlineBlock) {
                        $score += SchedulingPolicy::SOFT_WEEKDAY_ONLINE_MIGRATION_PENALTY;
                    }
                }

                if ($block['start_slot'] > SchedulingPolicy::SOFT_LATE_START_AFTER_SLOT) {
                    $score += ($block['start_slot'] - SchedulingPolicy::SOFT_LATE_START_AFTER_SLOT)
                        * SchedulingPolicy::SOFT_LATE_SLOT_PENALTY;
                }

                if (
                    (
                        ($block['mode'] ?? $assignment['mode'] ?? null) === 'field'
                        || ($block['room_type'] ?? $assignment['room_type'] ?? null) === 'field'
                    )
                    && $this->endsAfterFieldDayWindow((int) $block['end_slot'])
                ) {
                    $score += self::SOFT_FIELD_EVENING_PENALTY;
                }
            }

            if (count($blockDurations) === 2) {
                $score += abs($blockDurations[0] - $blockDurations[1]);
            }
        }

        $score += $this->calculateDepartmentRoomFairnessPenalty($generatedDeliveryCountsBySection);

        if ($physicalRoomBlockTotal > 0) {
            $uniquePhysicalRooms = count($physicalRoomBlockCounts);
            // Avoid concentrating generated classes in one room when other compatible rooms are free.
            $score += max(0, $physicalRoomBlockTotal - $uniquePhysicalRooms) * 12;
        }

        foreach ($physicalRoomBlocksByRoomDay as $roomDayBlocks) {
            if (count($roomDayBlocks) < 2) {
                continue;
            }

            usort(
                $roomDayBlocks,
                static fn (array $left, array $right): int => $left['start_slot'] <=> $right['start_slot'],
            );

            $previous = null;
            foreach ($roomDayBlocks as $roomDayBlock) {
                if ($previous !== null) {
                    $gapSlots = max(0, $roomDayBlock['start_slot'] - $previous['end_slot']);

                    if ($gapSlots > 0) {
                        $score += $gapSlots * SchedulingPolicy::SOFT_ROOM_IDLE_GAP_SLOT_PENALTY;

                        if ($gapSlots < 3) {
                            $score += SchedulingPolicy::SOFT_UNUSABLE_ROOM_GAP_PENALTY;
                        }

                        if ($gapSlots >= 2) {
                            $score += SchedulingPolicy::SOFT_FILLABLE_ROOM_GAP_BONUS_PENALTY;
                        }
                    }
                }

                $previous = $roomDayBlock;
            }
        }

        foreach ($byDay as $dayName => $dayAssignments) {
            // Workload distribution penalty: heavily stacked days (>3 classes/day) get penalized
            $classCount = count($dayAssignments);
            if ($classCount > 3) {
                $score += ($classCount - 3) * 5;
            }

            usort(
                $dayAssignments,
                static fn (array $left, array $right): int => $left['start_slot'] <=> $right['start_slot'],
            );

            $previous = null;

            foreach ($dayAssignments as $assignment) {
                if ($previous !== null) {
                    $gapSlots = max(
                        0,
                        $assignment['start_slot'] - $previous['end_slot'],
                    );

                    if ($gapSlots > 0) {
                        $score += SchedulingPolicy::SOFT_UNUSABLE_GAP_PENALTY;
                        $score += $gapSlots * SchedulingPolicy::SOFT_GAP_SLOT_PENALTY;
                    }

                    if (
                        $assignment['room_id'] !== null &&
                        $previous['room_id'] !== null &&
                        $assignment['room_id'] !== $previous['room_id']
                    ) {
                        $score += SchedulingPolicy::SOFT_ROOM_CHANGE_PENALTY;
                    }
                }

                $previous = $assignment;
            }
        }

        // Upper limit penalty for online class distribution (max 5 online classes per section).
        if ($courses !== null && count($courses) >= 4) {
            $onlineCount = 0;
            foreach ($assignments as $assignment) {
                foreach ($assignment['blocks'] as $block) {
                    if (($block['mode'] ?? $assignment['mode'] ?? '') === 'online') {
                        $onlineCount++;
                    }
                }
            }

            if ($onlineCount > 5) {
                $score += ($onlineCount - 5) * 20;
            }
        }

        // Soft penalty for online delivery mode when physical rooms are preferred.
        foreach ($assignments as $assignment) {
            if ($this->candidateContainsLaboratoryBlock($assignment) && ! $this->hasOnlineLectureBlock($assignment)) {
                $score += 10000;
            }

            foreach ($assignment['blocks'] as $block) {
                if (
                    ($block['mode'] ?? $assignment['mode'] ?? '') === 'online'
                    && ($block['meeting_type'] ?? null) !== 'lecture'
                ) {
                    $score += SchedulingPolicy::SOFT_ONLINE_FALLBACK_PENALTY;
                }
            }
        }

        // Soft penalty: a major lab course assigned to a lecture room because no
        // lab was available. Solutions with actual lab-room assignments score lower
        // (better) and are ranked above lecture-room fallbacks.
        if ($courses !== null) {
            foreach ($assignments as $assignment) {
                $courseObj = $courses[(int) $assignment['course_id']] ?? null;
                if ($courseObj === null || ! $this->isMajorLabCourse($courseObj)) {
                    continue;
                }

                // The _lab_fallback flag is set during domain building and carried
                // through to the assignment — no extra DB query needed.
                if ($assignment['_lab_fallback'] ?? false) {
                    $score += SchedulingPolicy::SOFT_LAB_FALLBACK_PENALTY;
                }
            }
        }

        return $score;
    }

    /**
     * Balance lecture meetings across online and physical delivery. Field and
     * laboratory blocks are excluded so hands-on meetings stay face-to-face.
     *
     * @param  array<int, array{physical: int, online: int}>  $eligibleLectureDeliveryCountsBySection
     */
    private function calculateLectureDeliveryBalancePenalty(array $eligibleLectureDeliveryCountsBySection): int
    {
        $penalty = 0;
        foreach ($eligibleLectureDeliveryCountsBySection as $sectionId => $counts) {
            $physical = max(0, (int) ($counts['physical'] ?? 0));
            $online = max(0, (int) ($counts['online'] ?? 0));
            $total = $physical + $online;

            if ($total < 2) {
                continue;
            }

            $penalty += abs($physical - $online) * 10000;
        }

        return $penalty;
    }

    private function toPublicScheduleRows(array $assignments): array
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            $hasMultipleBlocks = count($assignment['blocks']) > 1;
            $splitGroupId = $hasMultipleBlocks ? (string) Str::uuid() : null;

            foreach ($assignment['blocks'] as $index => $block) {
                $row = [
                    'term_id' => (int) $assignment['term_id'],
                    'section_id' => (int) $assignment['section_id'],
                    'course_id' => (int) $assignment['course_id'],
                    'faculty_id' => null,
                    'room_id' => $this->nullableRoomId(
                        array_key_exists('room_id', $block)
                            ? $block['room_id']
                            : ($assignment['room_id'] ?? null)
                    ),
                    'department_id' => (int) $assignment['department_id'],
                    'day' => $block['day'],
                    'start_time' => $block['start_time'],
                    'end_time' => $block['end_time'],
                    'mode' => $block['mode'] ?? $assignment['mode'],
                    'is_hybrid' => (bool) $assignment['is_hybrid'],
                    'preferred_pattern' => $assignment['preferred_pattern'],
                    'status' => 'draft',
                ];

                if ($hasMultipleBlocks) {
                    $row['split_group_id'] = $splitGroupId;
                    $row['meeting_index'] = $index + 1;

                    // Determine meeting type: lecture or laboratory
                    $courseId = (int) $assignment['course_id'];
                    $courseObj = Course::find($courseId);
                    if (! empty($block['meeting_type'])) {
                        $row['meeting_type'] = $block['meeting_type'];
                    } elseif ($courseObj && $courseObj->lab_hours > 0) {
                        $blockSlots = $block['end_slot'] - $block['start_slot'];
                        if ($blockSlots === $courseObj->lab_hours * 6) {
                            $row['meeting_type'] = 'laboratory';
                        } elseif ($blockSlots === $courseObj->lecture_hours * 2) {
                            $row['meeting_type'] = 'lecture';
                        } else {
                            $row['meeting_type'] = ($index === 0) ? 'lecture' : 'laboratory';
                        }
                    } else {
                        $row['meeting_type'] = 'lecture';
                    }
                } elseif (! empty($block['meeting_type'])) {
                    $row['meeting_type'] = $block['meeting_type'];
                }

                $rows[] = $row;
            }
        }

        usort(
            $rows,
            function (array $left, array $right): int {
                return [
                    $this->dayIndex($left['day']),
                    $left['start_time'],
                    $left['course_id'],
                    $left['room_id'] ?? 0,
                ] <=> [
                    $this->dayIndex($right['day']),
                    $right['start_time'],
                    $right['course_id'],
                    $right['room_id'] ?? 0,
                ];
            },
        );

        return $rows;
    }

    private function getDurationSlots(Course $course): int
    {
        $rawSlots = $this->rawDurationSlots($course);

        if (abs($rawSlots - round($rawSlots)) > 0.00001) {
            throw new RuntimeException(sprintf(
                'Course %d has units %.2f, which cannot be represented '
                .'using 30-minute scheduling slots.',
                $course->id,
                $course->units,
            ));
        }

        $durationSlots = (int) round($rawSlots);

        if ($durationSlots <= 0) {
            throw new RuntimeException(sprintf(
                'Course %d must have a duration greater than zero.',
                $course->id,
            ));
        }

        if ($durationSlots > SchedulingPolicy::totalSlots()) {
            throw new RuntimeException(sprintf(
                'Course %d requires %d slots, which exceeds the daily grid.',
                $course->id,
                $durationSlots,
            ));
        }

        return $durationSlots;
    }

    private function isSchedulableCourse(Course $course): bool
    {
        return $this->rawDurationSlots($course) > 0;
    }

    private function rawDurationSlots(Course $course): float
    {
        return (float) $course->units * 2;
    }

    private function normalizePreferredPattern(mixed $preferredPattern): ?string
    {
        return SchedulingPolicy::normalizePreferredPattern($preferredPattern);
    }

    /** @return array{0: string, 1: string} */
    private function patternDays(string $preferredPattern): array
    {
        $allowedDays = SchedulingPolicy::allowedDaysForPattern($preferredPattern);

        if ($allowedDays === null) {
            throw new RuntimeException('Preferred pattern is required for split domains.');
        }

        return $allowedDays;
    }

    private function slotToTime(int $slot): string
    {
        return SchedulingPolicy::slotToTime($slot);
    }

    private function dayIndex(string $day): int
    {
        return SchedulingPolicy::dayIndex($day);
    }

    private function hasExceededSearchLimits(): bool
    {
        if ($this->iterations >= $this->maxIterations) {
            return true;
        }

        return (microtime(true) - $this->startedAt)
            >= $this->timeoutSeconds;
    }

    private function resetSearchState(
        int $maxIterations,
        float $timeoutSeconds,
    ): void {
        $this->iterations = 0;
        $this->maxIterations = $maxIterations;
        $this->startedAt = microtime(true);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->searchLimitReached = false;
        $this->databaseValidityCache = [];
        $this->existingScheduleIndex = [];
        $this->existingRoomUseCounts = [];
        $this->existingRoomDayUseSlots = [];
        $this->existingSectionDeliveryCounts = [];
        $this->departmentRoomFairness = [
            'active_sections' => 1,
            'physical_rooms' => 0,
            'target_physical_ratio' => 1.0,
            'scarcity_multiplier' => 0.0,
            'section_regular_physical_targets' => [],
            'section_lab_physical_targets' => [],
            'section_online_targets' => [],
        ];
    }

    private function normalizeInputSchema(array $input): array
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
            'delivery_modes_by_course_id' => $input['delivery_modes_by_course_id']
                ?? $input['deliveryModesByCourseId']
                ?? [],
            'max_solutions' => (int) ($input['max_solutions'] ?? $input['maxSolutions'] ?? 2),
            'max_iterations' => (int) ($input['max_iterations'] ?? $input['maxIterations'] ?? 250_000),
            'timeout_seconds' => (float) ($input['timeout_seconds'] ?? $input['timeoutSeconds'] ?? 8.0),
            'seed' => isset($input['seed']) ? (int) $input['seed'] : null,
        ];
    }

    private function normalizeCourseIds(array $courseIds): array
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

    private function normalizeAnchoredSchedulesByCourseId(array $anchoredSchedules, array $validCourseIds): array
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
                'room_id' => $this->nullableRoomId($anchor['room_id'] ?? $anchor['roomId'] ?? null),
            ];
        }

        return $normalized;
    }

    private function normalizeDeliveryModesByCourseId(array $deliveryModesByCourseId, array $validCourseIds): array
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

    private function ensureAllCoursesExist(
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

    private function validateArguments(
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

            $this->normalizePreferredPattern($pattern);
        }
    }

    private function validateSectionForScheduling(Sections $section): void
    {
        if ($section->status !== 'active') {
            throw new InvalidArgumentException(sprintf(
                'Section %d is not active.',
                $section->id,
            ));
        }

        if (! SchedulingPolicy::isValidYearLevel((string) $section->year_level)) {
            throw new InvalidArgumentException(sprintf(
                'Section %d has unsupported year level "%s".',
                $section->id,
                $section->year_level,
            ));
        }

        if (! SchedulingPolicy::isValidSemester((string) $section->semester)) {
            throw new InvalidArgumentException(sprintf(
                'Section %d has unsupported semester "%s".',
                $section->id,
                $section->semester,
            ));
        }

        if (! $section->term) {
            throw new InvalidArgumentException(sprintf(
                'Section %d is not linked to an academic term.',
                $section->id,
            ));
        }

        if ($section->term->semester !== $section->semester) {
            throw new InvalidArgumentException(sprintf(
                'Section %d semester does not match its academic term.',
                $section->id,
            ));
        }
    }

    private function validateCoursesForSection(
        Sections $section,
        Collection $courses,
    ): void {
        foreach ($courses as $course) {
            if ($course->status !== 'active') {
                throw new InvalidArgumentException(sprintf(
                    'Course %d is not active.',
                    $course->id,
                ));
            }

            if ((string) $course->year_level !== (string) $section->year_level) {
                throw new InvalidArgumentException(sprintf(
                    'Course %d year level does not match section %d.',
                    $course->id,
                    $section->id,
                ));
            }

            if ((string) $course->semester !== (string) $section->semester) {
                throw new InvalidArgumentException(sprintf(
                    'Course %d semester does not match section %d.',
                    $course->id,
                    $section->id,
                ));
            }

            if (! SchedulingPolicy::isValidRoomType((string) $course->room_type_required)) {
                throw new InvalidArgumentException(sprintf(
                    'Course %d has unsupported room type "%s".',
                    $course->id,
                    $course->room_type_required,
                ));
            }

            if (
                $course->course_category === 'major'
                && $course->department_id !== null
                && (int) $course->department_id !== (int) $section->department_id
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Major course %d does not belong to section %d department.',
                    $course->id,
                    $section->id,
                ));
            }

            $this->getDurationSlots($course);
        }
    }

    private function validateRoomTypes(array $roomTypes): void
    {
        foreach ($roomTypes as $roomType) {
            if (! SchedulingPolicy::isValidRoomType((string) $roomType)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported room type "%s".',
                    $roomType,
                ));
            }
        }
    }

    private function ensureRoomDomainsExist(
        Collection $courses,
        Collection &$rooms,
        string $deliveryMode = 'on-site',
    ): void {
        // Missing physical rooms are handled by online fallback candidates in
        // the domain builders, so generation should not fail up-front here.
        return;

        // Collect all missing physical room types up-front so the error message
        // names every missing type in a single, actionable response — rather than
        // silently returning "no recommendations" and forcing the user to retry.
        // Virtual rooms (online/field) are always injected before this runs.
        $missingTypes = [];

        foreach ($courses as $course) {
            foreach ($this->requiredPhysicalRoomTypesForCourse($course, $deliveryMode) as $roomType) {
                $hasMatchingRoom = $rooms->contains(
                    static fn (Rooms $room): bool => $room->room_type === $roomType,
                );

                if (! $hasMatchingRoom && ! in_array($roomType, $missingTypes, true)) {
                    $missingTypes[] = $roomType;
                }
            }
        }

        foreach ($courses as $course) {
            $targetRoomType = $this->targetRoomTypeForCourse(
                course: $course,
                deliveryMode: $deliveryMode,
            );

            // Virtual rooms are handled before this method — skip them here.
            if (in_array($targetRoomType, ['online', 'field'], true)) {
                continue;
            }

            // For major lab courses a lecture room is a valid fallback.
            $hasLectureFallback = $this->isMajorLabCourse($course)
                && $rooms->contains(
                    static fn (Rooms $room): bool => $room->room_type === 'lecture',
                );

            $hasMatchingRoom = $hasLectureFallback || $rooms->contains(
                static fn (Rooms $room): bool => $room->room_type === $targetRoomType,
            );

            if (! $hasMatchingRoom && ! in_array($targetRoomType, $missingTypes, true)) {
                $missingTypes[] = $targetRoomType;
            }
        }

        if (! empty($missingTypes)) {
            $labels = array_map(static function (string $type): string {
                return match ($type) {
                    'laboratory' => 'laboratory room',
                    'lecture' => 'classroom (lecture room)',
                    default => "{$type} room",
                };
            }, $missingTypes);

            $list = implode(' and ', $labels);

            throw new InvalidArgumentException(
                "No {$list} found for this department. "
                .'Please add the required room(s) under Room Management before generating a schedule.'
            );
        }
    }

    /**
     * Return the physical room types that must exist before generation can be
     * attempted. Online fallback is only useful when a physical room type exists
     * but is occupied; missing room inventory should be reported directly.
     *
     * @return list<string>
     */
    private function requiredPhysicalRoomTypesForCourse(Course $course, string $deliveryMode): array
    {
        if ($deliveryMode === 'online' || $deliveryMode === 'field' || $this->isFieldCourse($course)) {
            return [];
        }

        $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
        $lectureHours = (int) ($course->lecture_hours ?? 0);
        $labHours = (int) ($course->lab_hours ?? 0);

        $types = [];

        if ($lectureHours > 0) {
            $types[] = 'lecture';
        }

        if ($types === []) {
            $targetRoomType = $this->targetRoomTypeForCourse(
                course: $course,
                deliveryMode: $deliveryMode,
            );

            if (in_array($targetRoomType, ['lecture', 'laboratory'], true)) {
                $types[] = $targetRoomType;
            }
        }

        return array_values(array_unique($types));
    }

    private function requiredRoomTypesForDeliveryMode(
        Collection $courses,
        string $deliveryMode,
    ): array {
        if ($deliveryMode === 'online') {
            return ['online'];
        }

        if ($deliveryMode === 'field') {
            return ['field'];
        }

        $types = $courses
            ->map(fn (Course $course): string => $this->targetRoomTypeForCourse($course, $deliveryMode))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // When any course in the batch has a laboratory preference, also fetch
        // lecture rooms so they are available as a fallback for departments
        // that have no lab rooms or whose labs are fully booked.
        $hasLabCourse = $courses->contains(
            fn (Course $course): bool => $this->isMajorLabCourse($course),
        );
        if ($hasLabCourse && ! in_array('lecture', $types, true)) {
            $types[] = 'lecture';
        }

        if (! in_array('online', $types, true)) {
            $types[] = 'online';
        }

        return $types;
    }

    private function targetRoomTypeForCourse(
        Course $course,
        string $deliveryMode,
    ): string {
        if ($deliveryMode === 'online') {
            return 'online';
        }

        if ($deliveryMode === 'field') {
            return 'field';
        }

        if ($this->isFieldCourse($course)) {
            return 'field';
        }

        return (string) $course->room_type_required;
    }

    private function isFieldCourse(Course $course): bool
    {
        return SchedulingPolicy::isFieldCourse($course);
    }

    private function isNstpCourse(Course $course): bool
    {
        return SchedulingPolicy::isNstpCourse($course);
    }

    /**
     * Returns true when the course is a major course that prefers a laboratory
     * room (room_type_required === 'laboratory') and is not a field/NSTP course.
     * Used to decide whether lecture rooms should be included as a fallback in
     * the CSP domain and whether a lab-fallback penalty should be applied.
     */
    private function isMajorLabCourse(Course $course): bool
    {
        if ($this->isFieldCourse($course) || $this->isNstpCourse($course)) {
            return false;
        }

        return SchedulingPolicy::isLaboratoryCourse($course);
    }

    private function hasLectureAndLabHours(Course $course): bool
    {
        return (int) ($course->lecture_hours ?? 0) > 0
            && (int) ($course->lab_hours ?? 0) > 0;
    }

    private function courseCategoryTablesExist(): bool
    {
        try {
            return Schema::hasTable('course_categories')
                && Schema::hasTable('course_category_mapping');
        } catch (\Throwable) {
            return false;
        }
    }

    private function singleBlockMeetingTypeForCourse(Course $course): ?string
    {
        if ($this->isFieldCourse($course)) {
            return null;
        }

        return SchedulingPolicy::isLaboratoryCourse($course)
            ? 'laboratory'
            : 'lecture';
    }

    /**
     * Returns the priority tier for a domain candidate. Lower numbers are tried
     * first by the backtracker:
     *
     *   0 → preferred physical room, on-site  (laboratory for lab courses, lecture for lecture courses)
     *   1 → fallback physical room, on-site   (lecture room fallback for lab courses)
     *   2 → online delivery mode              (tried last when physical rooms are unavailable)
     */
    private static function candidatePriority(array $candidate): int
    {
        if (($candidate['mode'] ?? '') === 'online') {
            if (self::candidateContainsOnlyLectureBlocks($candidate)) {
                return 0;
            }

            return 3;
        }

        if ($candidate['_lab_fallback'] ?? false) {
            return 1;
        }

        return 0;
    }

    private function candidateAllocationPriority(array $candidate, int $sectionId): int
    {
        $mode = (string) ($candidate['mode'] ?? 'on-site');

        if ($mode === 'field' || $this->candidateContainsFieldBlock($candidate)) {
            return 0;
        }

        if ($this->candidateContainsLaboratoryBlock($candidate)) {
            return $mode === 'online'
                ? 6
                : ($this->candidateContainsWeekendBlock($candidate) ? 3 : 0);
        }

        if ($mode === 'online') {
            return $this->candidateContainsWeekendBlock($candidate) ? 5 : 4;
        }

        if ($candidate['_lab_fallback'] ?? false) {
            return 5;
        }

        return $this->candidateContainsWeekendBlock($candidate) ? 2 : 1;
    }

    private function hasWeekdayPhysicalCandidate(array $domain): bool
    {
        foreach ($domain as $candidate) {
            if ($this->isEntirelyWeekdayPhysicalCandidate($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isEntirelyWeekdayPhysicalCandidate(array $candidate): bool
    {
        $blocks = $candidate['blocks'] ?? [];
        if ($blocks === []) {
            return false;
        }

        foreach ($blocks as $block) {
            $mode = $block['mode'] ?? $candidate['mode'] ?? null;
            $roomId = $block['room_id'] ?? $candidate['room_id'] ?? null;
            $roomType = $block['room_type'] ?? $candidate['room_type'] ?? null;

            if ($roomId === null
                || in_array($mode, ['online', 'field'], true)
                || in_array($roomType, ['online', 'field'], true)
                || ! in_array($block['day'] ?? null, SchedulingPolicy::WEEKDAYS, true)) {
                return false;
            }
        }

        return true;
    }

    private function candidateContainsWeekendBlock(array $candidate): bool
    {
        foreach ($candidate['blocks'] ?? [] as $block) {
            if (in_array($block['day'] ?? null, ['Saturday', 'Sunday'], true)) {
                return true;
            }
        }

        return false;
    }

    private function candidateContainsLaboratoryBlock(array $candidate): bool
    {
        foreach ($candidate['blocks'] ?? [] as $block) {
            $meetingType = $block['meeting_type'] ?? null;
            $roomType = $block['room_type'] ?? $candidate['room_type'] ?? null;

            if ($meetingType === 'laboratory' || $roomType === 'laboratory') {
                return true;
            }
        }

        return false;
    }

    private function candidateContainsFieldBlock(array $candidate): bool
    {
        foreach ($candidate['blocks'] ?? [] as $block) {
            $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? '');
            $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? '');

            if ($mode === 'field' || $roomType === 'field') {
                return true;
            }
        }

        return false;
    }

    private static function candidateContainsOnlyLectureBlocks(array $candidate): bool
    {
        $blocks = $candidate['blocks'] ?? [];

        if ($blocks === []) {
            return false;
        }

        foreach ($blocks as $block) {
            $meetingType = $block['meeting_type'] ?? null;
            $roomType = $block['room_type'] ?? $candidate['room_type'] ?? null;

            if ($meetingType === 'laboratory' || $roomType === 'laboratory' || $roomType === 'field') {
                return false;
            }
        }

        return true;
    }

    private function isFieldCandidateBlock(array $candidate, array $block): bool
    {
        $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? '');
        $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? '');

        return $mode === 'field' || $roomType === 'field';
    }

    private function candidateRoomAvailabilityPenalty(array $candidate): int
    {
        $penalty = 0;

        foreach ($candidate['blocks'] ?? [] as $block) {
            $roomId = $this->nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );

            if ($roomId === null || $this->isVirtualCandidateBlock($candidate, $block)) {
                continue;
            }

            $day = (string) ($block['day'] ?? '');
            $penalty += $this->existingRoomDayUseSlots["{$roomId}:{$day}"] ?? 0;
        }

        return $penalty;
    }

    private function candidateRoomConcentrationPenalty(array $candidate): int
    {
        $penalty = 0;

        foreach ($candidate['blocks'] ?? [] as $block) {
            $roomId = $this->nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );

            if ($roomId === null || $this->isVirtualCandidateBlock($candidate, $block)) {
                continue;
            }

            $penalty += $this->existingRoomUseCounts[$roomId] ?? 0;
        }

        return $penalty;
    }

    private function isVirtualCandidateBlock(array $candidate, array $block): bool
    {
        $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site');
        $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? '');

        return $mode === 'online'
            || $mode === 'field'
            || in_array($roomType, ['online', 'field'], true);
    }

    private function normalizePreferredPatternsByCourseId(
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

            $normalized[$courseId] = $this->normalizePreferredPattern($pattern);
        }

        return $normalized;
    }

    private function prepareDepartmentRoomFairness(Sections $section, Collection $rooms): void
    {
        $activeSections = Sections::query()
            ->where('department_id', (int) $section->department_id)
            ->where('term_id', (int) $section->term_id)
            ->where('status', 'active')
            ->get(['id', 'year_level', 'semester']);

        $activeSectionCount = $activeSections->count();

        $physicalRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => ! in_array((string) $room->room_type, ['online', 'field'], true))
            ->count();
        $lectureRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => (string) $room->room_type === 'lecture')
            ->count();
        $laboratoryRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => (string) $room->room_type === 'laboratory')
            ->count();

        $demandBySection = $this->departmentDemandBySection(
            departmentId: (int) $section->department_id,
            sections: $activeSections,
        );

        $activeSectionCount = max(1, $activeSectionCount);
        $physicalRoomCount = max(0, $physicalRoomCount);
        $totalRegularDemand = array_sum(array_column($demandBySection, 'regular'));
        $totalLabDemand = array_sum(array_column($demandBySection, 'lab'));

        $regularPhysicalRatio = $this->physicalDemandRatio(
            roomCount: $lectureRoomCount,
            sectionCount: $activeSectionCount,
            totalDemand: $totalRegularDemand,
        );
        $labPhysicalRatio = $laboratoryRoomCount > 0 ? 1.0 : 0.0;
        $targetPhysicalRatio = $totalRegularDemand + $totalLabDemand > 0
            ? (($regularPhysicalRatio * $totalRegularDemand) + ($labPhysicalRatio * $totalLabDemand))
                / max(1, $totalRegularDemand + $totalLabDemand)
            : 1.0;
        $scarcityMultiplier = max(0.0, 1.0 - $targetPhysicalRatio);

        $this->departmentRoomFairness = [
            'active_sections' => $activeSectionCount,
            'physical_rooms' => $physicalRoomCount,
            'target_physical_ratio' => $targetPhysicalRatio,
            'scarcity_multiplier' => $scarcityMultiplier,
            'section_regular_physical_targets' => $this->physicalTargetsBySection($demandBySection, 'regular', $regularPhysicalRatio),
            'section_lab_physical_targets' => $this->physicalTargetsBySection($demandBySection, 'lab', $labPhysicalRatio),
            'section_online_targets' => $this->onlineTargetsBySection(
                demandBySection: $demandBySection,
                regularPhysicalTargets: $this->physicalTargetsBySection($demandBySection, 'regular', $regularPhysicalRatio),
            ),
        ];
    }

    /**
     * @param  Collection<int, Sections>  $sections
     * @return array<int, array{regular: int, lab: int}>
     */
    private function departmentDemandBySection(int $departmentId, Collection $sections): array
    {
        $demand = [];
        foreach ($sections as $section) {
            $demand[(int) $section->id] = ['regular' => 0, 'lab' => 0];
        }

        if ($sections->isEmpty()) {
            return $demand;
        }

        $activeCurriculum = Curriculum::query()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->first();

        if ($activeCurriculum === null) {
            return $demand;
        }

        $lectureLabSplitEnabled = (bool) Departments::query()
            ->whereKey($departmentId)
            ->value('lecture_lab_schedule_override_enabled');

        $semesterMap = [
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
        ];
        $sectionsByPeriod = $sections->groupBy(
            static fn (Sections $section): string => (string) $section->year_level.'|'.(string) ($semesterMap[(string) $section->semester] ?? $section->semester),
        );

        $courses = DB::table('curriculum_course')
            ->join('courses', 'courses.id', '=', 'curriculum_course.course_id')
            ->where('curriculum_course.curriculum_id', (int) $activeCurriculum->id)
            ->where('courses.status', 'active')
            ->get([
                'curriculum_course.year_level',
                'curriculum_course.semester',
                'courses.lecture_hours',
                'courses.lab_hours',
                'courses.room_type_required',
                'courses.course_code',
            ]);

        foreach ($courses as $course) {
            $periodKey = (string) $course->year_level.'|'.(string) $course->semester;
            $matchingSections = $sectionsByPeriod->get($periodKey);
            if ($matchingSections === null) {
                continue;
            }

            $lectureHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            if ($lectureHours <= 0 && $labHours <= 0) {
                continue;
            }

            $roomType = (string) ($course->room_type_required ?? 'lecture');
            $courseCode = (string) ($course->course_code ?? '');
            if ($roomType === 'field' || preg_match('/\b(?:NSTP|ROTC|CWTS|LTS)\b/i', $courseCode) === 1) {
                continue;
            }

            foreach ($matchingSections as $matchingSection) {
                $matchingSectionId = (int) $matchingSection->id;

                if ($labHours > 0 || $roomType === 'laboratory') {
                    $demand[$matchingSectionId]['lab']++;

                    if ($lectureLabSplitEnabled && $lectureHours > 0) {
                        $demand[$matchingSectionId]['regular']++;
                    }

                    continue;
                }

                $demand[$matchingSectionId]['regular']++;
            }
        }

        return $demand;
    }

    private function physicalDemandRatio(int $roomCount, int $sectionCount, int $totalDemand): float
    {
        if ($totalDemand <= 0) {
            return 1.0;
        }

        $roomShare = $roomCount / max(1, $sectionCount);
        $demandShare = ($roomCount * SchedulingPolicy::MAX_CLASSES_PER_DAY) / max(1, $totalDemand);

        return max(0.35, min(1.0, max($roomShare, $demandShare)));
    }

    /**
     * @param  array<int, array{regular: int, lab: int}>  $demandBySection
     * @return array<int, int>
     */
    private function physicalTargetsBySection(array $demandBySection, string $bucket, float $ratio): array
    {
        $targets = [];

        foreach ($demandBySection as $sectionId => $demand) {
            $sectionDemand = max(0, (int) ($demand[$bucket] ?? 0));
            $targets[(int) $sectionId] = $sectionDemand > 0
                ? ($ratio <= 0.0 ? 0 : max(1, (int) round($sectionDemand * $ratio)))
                : 0;
        }

        return $targets;
    }

    /**
     * @param  array<int, array{regular: int, lab: int}>  $demandBySection
     * @param  array<int, int>  $regularPhysicalTargets
     * @return array<int, int>
     */
    private function onlineTargetsBySection(array $demandBySection, array $regularPhysicalTargets): array
    {
        $targets = [];

        foreach ($demandBySection as $sectionId => $demand) {
            $regularDemand = max(0, (int) ($demand['regular'] ?? 0));
            $regularPhysicalTarget = max(0, (int) ($regularPhysicalTargets[$sectionId] ?? 0));
            $targets[(int) $sectionId] = max(0, $regularDemand - $regularPhysicalTarget);
        }

        return $targets;
    }

    private function minimumOnlineTargetForSection(int $sectionId): int
    {
        $onlineTargets = $this->departmentRoomFairness['section_online_targets'] ?? [];
        $target = max(0, (int) ($onlineTargets[$sectionId] ?? 0));
        $existingOnline = max(0, (int) ($this->existingSectionDeliveryCounts[$sectionId]['online'] ?? 0));

        return max(0, $target - $existingOnline);
    }

    private function onlineCapableVariableCount(array $variables): int
    {
        $count = 0;

        foreach ($variables as $variable) {
            foreach ($variable['domain'] ?? [] as $candidate) {
                if ($this->hasOnlineLectureBlock($candidate)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function splitLectureOnlineVariableCount(array $variables): int
    {
        $count = 0;

        foreach ($variables as $variable) {
            foreach ($variable['domain'] ?? [] as $candidate) {
                if (
                    $this->candidateContainsLaboratoryBlock($candidate)
                    && $this->hasOnlineLectureBlock($candidate)
                ) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<int, array{physical: int, online: int, protected_physical: int}>  $generatedDeliveryCountsBySection
     */
    private function calculateDepartmentRoomFairnessPenalty(array $generatedDeliveryCountsBySection): int
    {
        $targetPhysicalRatio = (float) $this->departmentRoomFairness['target_physical_ratio'];
        $scarcityMultiplier = (float) $this->departmentRoomFairness['scarcity_multiplier'];
        $regularPhysicalTargets = $this->departmentRoomFairness['section_regular_physical_targets'] ?? [];
        $labPhysicalTargets = $this->departmentRoomFairness['section_lab_physical_targets'] ?? [];
        $onlineTargets = $this->departmentRoomFairness['section_online_targets'] ?? [];

        if ($targetPhysicalRatio >= 1.0 || $scarcityMultiplier <= 0.0) {
            return 0;
        }

        $penalty = 0;

        foreach ($generatedDeliveryCountsBySection as $sectionId => $generatedCounts) {
            $existingCounts = $this->existingSectionDeliveryCounts[$sectionId] ?? [
                'physical' => 0,
                'online' => 0,
            ];

            $generatedPhysical = max(0, (int) ($generatedCounts['physical'] ?? 0));
            $generatedOnline = max(0, (int) ($generatedCounts['online'] ?? 0));
            $protectedPhysical = max(0, (int) ($generatedCounts['protected_physical'] ?? 0));
            $regularPhysical = max(0, (int) ($generatedCounts['regular_physical'] ?? ($generatedPhysical - $protectedPhysical)));
            $regularTotal = max(0, $regularPhysical + $generatedOnline);

            if ($regularTotal > 0) {
                $allowedRegularPhysical = (int) ceil($regularTotal * $targetPhysicalRatio);
                $excessPhysicalBlocks = max(0, $regularPhysical - $allowedRegularPhysical);
                $penalty += (int) round($excessPhysicalBlocks * 18 * $scarcityMultiplier);
            }

            $regularTarget = max(0, (int) ($regularPhysicalTargets[$sectionId] ?? PHP_INT_MAX));
            if ($regularTarget !== PHP_INT_MAX && $regularPhysical > $regularTarget) {
                $penalty += (int) round(($regularPhysical - $regularTarget) * 240 * max(0.25, $scarcityMultiplier));
            }

            $labTarget = max(0, (int) ($labPhysicalTargets[$sectionId] ?? PHP_INT_MAX));
            if ($labTarget !== PHP_INT_MAX && $protectedPhysical > $labTarget) {
                $penalty += (int) round(($protectedPhysical - $labTarget) * 320 * max(0.25, $scarcityMultiplier));
            }

            if ($generatedPhysical === 0 && (($regularTarget + $labTarget) > 0)) {
                $penalty += 500;
            }

            $projectedSectionOnline = (int) ($existingCounts['online'] ?? 0) + $generatedOnline;
            $onlineTarget = max(0, (int) ($onlineTargets[$sectionId] ?? PHP_INT_MAX));
            if ($onlineTarget !== PHP_INT_MAX && $projectedSectionOnline > $onlineTarget) {
                $penalty += (int) round(($projectedSectionOnline - $onlineTarget) * 520 * max(0.25, $scarcityMultiplier));
            }

            if ($onlineTarget > 0 && $generatedOnline === 0 && $regularPhysical > $regularTarget) {
                $penalty += 300;
            }

            $projectedSectionPhysical = (int) $existingCounts['physical'] + $generatedPhysical;
            $allSectionPhysicalCounts = array_map(
                static fn (array $counts): int => (int) ($counts['physical'] ?? 0),
                $this->existingSectionDeliveryCounts,
            );
            $allSectionPhysicalCounts[$sectionId] = $projectedSectionPhysical;

            if (count($allSectionPhysicalCounts) > 1) {
                $averagePhysical = array_sum($allSectionPhysicalCounts) / count($allSectionPhysicalCounts);
                $excessOverAverage = max(0.0, $projectedSectionPhysical - $averagePhysical - 1.0);
                $penalty += (int) round($excessOverAverage * 4 * $scarcityMultiplier);
            }

            $allSectionOnlineCounts = array_map(
                static fn (array $counts): int => (int) ($counts['online'] ?? 0),
                $this->existingSectionDeliveryCounts,
            );
            $allSectionOnlineCounts[$sectionId] = $projectedSectionOnline;

            if (count($allSectionOnlineCounts) > 1) {
                $averageOnline = array_sum($allSectionOnlineCounts) / count($allSectionOnlineCounts);
                $excessOnlineOverAverage = max(0.0, $projectedSectionOnline - $averageOnline - 1.0);
                $penalty += (int) round($excessOnlineOverAverage * 180 * max(0.25, $scarcityMultiplier));
            }
        }

        return $penalty;
    }

    /**
     * Pre-fetches all persisted schedules for the given term into memory and
     * builds three lookup indexes:
     *   "r:{roomId}:{day}"     → time ranges already booked for that room on that day
     *   "s:{sectionId}:{day}" → time ranges already booked for that section on that day
     *   "f:{facultyId}:{day}" → time ranges already booked for that instructor on that day
     *
     * This single query replaces the repeated per-candidate DB queries that were
     * previously issued inside the backtracking loop.
     */
    private function preloadExistingSchedules(int $termId, int $sectionId, int $departmentId, array $replaceCourseIds = []): void
    {
        $this->existingScheduleIndex = [];
        $this->existingRoomUseCounts = [];
        $this->existingRoomDayUseSlots = [];
        $this->existingSectionDeliveryCounts = [];

        $replaceCourseIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $courseId): int => (int) $courseId, $replaceCourseIds),
            static fn (int $courseId): bool => $courseId > 0,
        )));

        $schedules = Schedule::query()
            ->where('term_id', $termId)
            ->when($replaceCourseIds !== [], function ($query) use ($sectionId, $replaceCourseIds): void {
                $query->where(function ($q) use ($sectionId, $replaceCourseIds): void {
                    $q->where('section_id', '!=', $sectionId)
                        ->orWhereNotIn('course_id', $replaceCourseIds)
                        ->orWhereNotIn('status', ['draft', 'completed', 'revision']);
                });
            })
            ->get(['room_id', 'section_id', 'faculty_id', 'department_id', 'day', 'start_time', 'end_time', 'mode']);

        $knownRoomTypeIds = array_fill_keys(array_keys($this->roomTypes), true);
        $missingRoomTypeIds = $schedules
            ->pluck('room_id')
            ->filter()
            ->map(static fn (mixed $roomId): int => (int) $roomId)
            ->unique()
            ->reject(static fn (int $roomId): bool => isset($knownRoomTypeIds[$roomId]))
            ->values();

        if ($missingRoomTypeIds->isNotEmpty()) {
            Rooms::query()
                ->whereIn('id', $missingRoomTypeIds->all())
                ->pluck('room_type', 'id')
                ->each(function (string $roomType, int|string $roomId): void {
                    $this->roomTypes[(int) $roomId] = $roomType;
                });
        }

        foreach ($schedules as $schedule) {
            $timeRange = [
                'start_time' => (string) $schedule->start_time,
                'end_time' => (string) $schedule->end_time,
            ];

            if ($schedule->room_id !== null) {
                $roomId = (int) $schedule->room_id;
                $roomType = $this->roomTypes[$roomId] ?? null;
                $roomKey = in_array($roomType, ['field', 'online'], true)
                    ? "r:{$roomId}:{$schedule->department_id}:{$schedule->day}"
                    : "r:{$roomId}:{$schedule->day}";
                $this->existingScheduleIndex[$roomKey][] = $timeRange;
                $this->existingRoomUseCounts[$roomId] = ($this->existingRoomUseCounts[$roomId] ?? 0) + 1;
                $this->existingRoomDayUseSlots["{$roomId}:{$schedule->day}"] =
                    ($this->existingRoomDayUseSlots["{$roomId}:{$schedule->day}"] ?? 0)
                    + max(0, $this->timeToMinutes((string) $schedule->end_time) - $this->timeToMinutes((string) $schedule->start_time));
            }
            if (($schedule->mode ?? null) === 'online') {
                $this->existingScheduleIndex["online:{$schedule->department_id}:{$schedule->day}"][] = $timeRange;
            }

            if ((int) $schedule->department_id === $departmentId) {
                $existingSectionId = (int) $schedule->section_id;
                $this->existingSectionDeliveryCounts[$existingSectionId] ??= [
                    'physical' => 0,
                    'online' => 0,
                    'regular_physical' => 0,
                    'protected_physical' => 0,
                ];

                $scheduleRoomId = $schedule->room_id !== null ? (int) $schedule->room_id : null;
                $scheduleRoomType = (string) ($scheduleRoomId !== null ? ($this->roomTypes[$scheduleRoomId] ?? '') : '');
                $scheduleMode = (string) ($schedule->mode ?? '');

                if ($scheduleMode === 'online' || $scheduleRoomType === 'online') {
                    $this->existingSectionDeliveryCounts[$existingSectionId]['online']++;
                } elseif ($scheduleRoomId !== null && ! in_array($scheduleRoomType, ['field'], true)) {
                    $this->existingSectionDeliveryCounts[$existingSectionId]['physical']++;
                    if ($scheduleRoomType === 'laboratory') {
                        $this->existingSectionDeliveryCounts[$existingSectionId]['protected_physical']++;
                    } else {
                        $this->existingSectionDeliveryCounts[$existingSectionId]['regular_physical']++;
                    }
                }
            }

            $this->existingScheduleIndex["s:{$schedule->section_id}:{$schedule->day}"][] = $timeRange;

            // Index instructor availability so the CSP can avoid recommending
            // slots that conflict with an already-assigned faculty member.
            if (! empty($schedule->faculty_id)) {
                $this->existingScheduleIndex["f:{$schedule->faculty_id}:{$schedule->day}"][] = $timeRange;
            }
        }
    }

    /**
     * Returns true if any persisted schedule conflicts with the given time window
     * for either the candidate room, the target section, or an assigned instructor.
     *
     * @param  bool  $skipRoomConflictCheck  When true, the room-level index check is skipped.
     *                                       The section-level check is always applied to prevent a single section from
     *                                       double-booking itself at the same time slot.
     * @param  int|null  $facultyId  When provided, the instructor index is checked to
     *                               ensure the faculty member is not already teaching another class at the same
     *                               day and time, regardless of delivery mode.
     */
    private function hasExistingScheduleConflict(
        ?int $roomId,
        int $sectionId,
        string $day,
        string $startTime,
        string $endTime,
        bool $skipRoomConflictCheck = false,
        ?int $facultyId = null,
        string $mode = 'on-site',
        int $departmentId = 0,
    ): bool {
        if ($mode === 'online') {
            $onlineCapacity = 3;
            $overlapCount = 0;
            foreach ($this->existingScheduleIndex["online:{$departmentId}:{$day}"] ?? [] as $existing) {
                if ($startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                    $overlapCount++;
                    if ($overlapCount >= $onlineCapacity) {
                        return true;
                    }
                }
            }
        }

        if (! $skipRoomConflictCheck && $roomId !== null) {
            $capacity = $this->roomCapacities[$roomId] ?? 1;
            $overlapCount = 0;

            $roomType = $this->roomTypes[$roomId] ?? null;
            $roomKey = in_array($roomType, ['field', 'online'], true)
                ? "r:{$roomId}:{$departmentId}:{$day}"
                : "r:{$roomId}:{$day}";

            foreach ($this->existingScheduleIndex[$roomKey] ?? [] as $existing) {
                if ($startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                    $overlapCount++;
                    if ($capacity <= 1 || $overlapCount >= $capacity) {
                        return true;
                    }
                }
            }
        }

        foreach ($this->existingScheduleIndex["s:{$sectionId}:{$day}"] ?? [] as $existing) {
            if ($startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                return true;
            }
        }

        if ($facultyId !== null) {
            foreach ($this->existingScheduleIndex["f:{$facultyId}:{$day}"] ?? [] as $existing) {
                if ($startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                    return true;
                }
            }
        }

        return false;
    }

    private function nullableRoomId(mixed $roomId): ?int
    {
        if ($roomId === null || $roomId === '') {
            return null;
        }

        return (int) $roomId;
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', SchedulingPolicy::normalizeTime($time)));

        return ($hours * 60) + $minutes;
    }
}
