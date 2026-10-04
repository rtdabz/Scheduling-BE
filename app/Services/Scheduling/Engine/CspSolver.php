<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Solver\DepartmentRoomFairness;
use App\Services\Scheduling\Engine\Solver\SolutionDiversity;
use App\Services\Scheduling\Engine\Solver\SolverInput;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CspSolver
{
    private const SPLIT_ROOM_OPTIONS_PER_SLOT = 6;

    private const SOFT_FIELD_EVENING_PENALTY = 6;

    private const CLASSROOM_GAP_SCHEDULABLE_SLOT_SOFT_PENALTY = 1800;

    private const CLASSROOM_GAP_LEFTOVER_SLOT_SOFT_PENALTY = 7000;

    private const CLASSROOM_FIVE_SLOT_GAP_SOFT_PENALTY = 20000;

    private const CLASSROOM_SIX_SLOT_GAP_SOFT_PENALTY = 14000;

    /** @var array<string, bool> */
    private array $databaseValidityCache = [];

    /**
     * @var array<string, list<array{start_time: string, end_time: string}>>
     */
    private array $existingScheduleIndex = [];

    private array $tentativeSchedules = [];

    public function setInputSnapshot(?SchedulingSnapshot $snapshot): void
    {
        $this->snapshotAutoCaptured = false;

        $unchanged = $this->inputSnapshot !== null
            && $snapshot !== null
            && $this->inputSnapshot->fingerprint === $snapshot->fingerprint;

        $this->inputSnapshot = $snapshot;

        if (! $unchanged) {
            $this->semesterScheduleRowsCache = [];
            $this->domainCache = [];
        }
    }

    /**
     * @param  list<int>  $courseIds
     */
    private function ensureSnapshotFor(int $sectionId, array $courseIds): void
    {
        if ($this->snapshotAutoCaptured) {
            $this->setInputSnapshot(null);
        }

        if ($this->inputSnapshot === null) {
            $section = Sections::query()->findOrFail($sectionId, ['id', 'semester_id', 'department_id']);
            $this->setInputSnapshot(app(SchedulingSnapshotRepository::class)->capture(
                semesterId: (int) $section->semester_id,
                departmentId: (int) $section->department_id,
                sectionIds: [$sectionId],
                courseIds: $courseIds,
            ));
            $this->snapshotAutoCaptured = true;
        }
    }

    private function roomFairness(): DepartmentRoomFairness
    {
        return $this->roomFairness ??= new DepartmentRoomFairness;
    }

    private function solutionDiversity(): SolutionDiversity
    {
        return $this->solutionDiversity ??= new SolutionDiversity;
    }

    private function sectionLabel(int $sectionId): string
    {
        return (string) ($this->inputSnapshot?->sectionsById[$sectionId]['section_name'] ?? 'Section');
    }

    private function snapshot(): SchedulingSnapshot
    {
        return $this->inputSnapshot ?? throw new RuntimeException('The solver has no scheduling snapshot.');
    }

    public function beginGenerationContext(): void
    {
        $this->loadedCoursesById = [];
        $this->semesterScheduleRowsCache = [];
        $this->domainCache = [];
    }

    /**
     * @var array<int, list<array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}>>
     */
    private array $roomGrantWindows = [];

    /** @var array<int, int> */
    private array $existingRoomUseCounts = [];

    /** @var array<string, int> */
    private array $existingRoomDayUseSlots = [];

    /** @var array<int, array{physical: int, online: int, regular_physical?: int, protected_physical?: int}> */
    private array $existingSectionDeliveryCounts = [];

    private ?DepartmentRoomFairness $roomFairness = null;


    /** @var array<int, string> */
    private array $generationForcedDaysByCourseId = [];

    /**
     * @var array<int, array{day_count: int, preferred_start_day: string|null, meeting_days?: list<string>|null}>
     */
    private array $consecutiveRulesByCourseId = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $requirementsByCourseId = [];

    /**
     * @var array<int, int>
     */
    private array $preferredRoomIdsByCourseId = [];

    /**
     * @var list<string>|null
     */
    private ?array $allowedDays = null;

    private bool $sundayClassesEnabled = false;

    private bool $allowFridaySaturdaySplit = false;

    private bool $lateWeekCapacityPreference = true;

    private bool $protectsSplitCapacity = true;

    private ?string $searchFromDay = null;

    /** @var array<int, Course> */
    private array $loadedCoursesById = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $semesterScheduleRowsCache = [];

    /**
     * @var array<string, array{domain: list<array<string, mixed>>, empty_after_requirements: bool}>
     */
    private array $domainCache = [];

    private ?SchedulingSnapshot $inputSnapshot = null;

    private bool $snapshotAutoCaptured = false;

    private ?SolutionDiversity $solutionDiversity = null;

    /**
     * @var array<string, mixed>|Departments|null
     */
    private array|Departments|null $departmentLabSettings = null;

    /**
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
        return $this->roomFairness()->toArray();
    }

    /** @return array<int, string> */
    public function generationForcedDaysByCourseId(): array
    {
        return $this->generationForcedDaysByCourseId;
    }

    /** @var array<int, string> */
    private array $roomTypes = [];

    private int $iterations = 0;

    private int $maxIterations = 250_000;

    private float $startedAt = 0.0;

    private float $timeoutSeconds = 8.0;

    private bool $searchLimitReached = false;

    /**
     * @var array<int, int>
     */
    private array $deadEndsByCourseId = [];

    private float $metricsStartedAt = 0.0;

    private int $metricsVariableCount = 0;

    private int $metricsCandidateCountBefore = 0;

    private int $metricsCandidateCountAfter = 0;

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
     *     timeoutSeconds?: float|int|string,
     *     throw_on_empty_domain?: bool|int|string,
     *     allow_room_tba_fallback?: bool|int|string
     * } $input
     */
    public function solveRankedFromSchema(array $input): array
    {
        $schema = SolverInput::normalizeInputSchema($input);

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
            hybridSplitCourseIds: $schema['hybrid_split_course_ids'],
            anchoredSchedulesByCourseId: $schema['anchored_schedules'],
            deliveryModesByCourseId: $schema['delivery_modes_by_course_id'],
            requirementsByCourseId: $schema['requirements_by_course_id'],
            allowedDays: $schema['allowed_days'],
            allowFridaySaturdaySplit: $schema['allow_friday_saturday_split'],
            seed: $schema['seed'] ?? null,
            tentativeSchedules: $schema['tentative_schedules'],
            throwOnEmptyDomain: $schema['throw_on_empty_domain'],
            allowRoomTbaFallback: $schema['allow_room_tba_fallback'],
            allowOnlineFallback: $schema['allow_online_fallback'],
            searchFromDay: $schema['search_from_day'],
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
        array $hybridSplitCourseIds = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        array $requirementsByCourseId = [],
        ?array $allowedDays = null,
        ?int $seed = null,
        array $tentativeSchedules = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
        bool $allowOnlineFallback = true,
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
            hybridSplitCourseIds: $hybridSplitCourseIds,
            anchoredSchedulesByCourseId: $anchoredSchedulesByCourseId,
            deliveryModesByCourseId: $deliveryModesByCourseId,
            requirementsByCourseId: $requirementsByCourseId,
            allowedDays: $allowedDays,
            seed: $seed,
            throwOnEmptyDomain: $throwOnEmptyDomain,
            allowRoomTbaFallback: $allowRoomTbaFallback,
            allowOnlineFallback: $allowOnlineFallback,
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
        array $hybridSplitCourseIds = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        array $requirementsByCourseId = [],
        ?array $allowedDays = null,
        ?int $seed = null,
        array $tentativeSchedules = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
        bool $allowOnlineFallback = true,
        bool $allowFridaySaturdaySplit = false,
        ?string $searchFromDay = null,
    ): array {
        SolverInput::validateArguments(
            courseIds: $courseIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
        );

        $anchoredSchedulesByCourseId = SolverInput::normalizeAnchoredSchedulesByCourseId(
            anchoredSchedules: $anchoredSchedulesByCourseId,
            validCourseIds: $courseIds,
        );

        $this->resetSearchState(
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );
        $this->tentativeSchedules = $tentativeSchedules;
        $this->generationForcedDaysByCourseId = [];
        $this->requirementsByCourseId = SolverInput::normalizeRequirements($requirementsByCourseId, $courseIds);
        $this->allowedDays = SchedulingPolicy::normalizeAllowedDays($allowedDays);
        $this->allowFridaySaturdaySplit = $allowFridaySaturdaySplit;
        $this->lateWeekCapacityPreference = count($courseIds) > 1;
        $this->searchFromDay = $this->lateWeekCapacityPreference ? null : $searchFromDay;

        $courseIds = SolverInput::normalizeCourseIds($courseIds);

        if ($courseIds === []) {
            return [];
        }

        $this->ensureSnapshotFor($sectionId, $courseIds);
        $snapshot = $this->snapshot();

        $sectionAttributes = $snapshot->sectionsById[$sectionId] ?? null;
        if (! is_array($sectionAttributes)) {
            throw new RuntimeException('The requested section is not present in the scheduling snapshot.');
        }
        $section = new Sections($sectionAttributes);
        $section->id = (int) ($sectionAttributes['id'] ?? $sectionId);
        $section->semester_id = (int) ($sectionAttributes['semester_id'] ?? $snapshot->semesterId);
        $section->department_id = (int) ($sectionAttributes['department_id'] ?? $snapshot->departmentId);
        $section->year_level = (string) ($sectionAttributes['year_level'] ?? '');
        $section->semester = (string) ($sectionAttributes['semester'] ?? '');
        $semester = new Semester($snapshot->semester);
        $semester->id = (int) ($snapshot->semester['id'] ?? $snapshot->semesterId);
        $semester->semester = (string) ($snapshot->semester['semester'] ?? '');
        $section->setRelation('academicSemester', $semester);

        if ($snapshot->semesterId !== (int) $section->semester_id || $snapshot->departmentId !== (int) $section->department_id) {
            throw new RuntimeException('The scheduling snapshot belongs to a different semester or department than the section being solved.');
        }

        $this->validateSectionForScheduling($section);

        $courses = collect($snapshot->coursesById)
            ->only(array_map('intval', $courseIds))
            ->map(function (array $attributes): Course {
                $course = new Course($attributes);
                $course->id = (int) ($attributes['id'] ?? 0);

                return $course;
            })
            ->keyBy('id');
        $courses = new Collection($courses->all());

        SolverInput::ensureAllCoursesExist(
            courseIds: $courseIds,
            courses: $courses,
        );

        $courses = $courses
            ->filter(fn (Course $course): bool => $this->isSchedulableCourse($course))
            ->values()
            ->keyBy('id');

        $this->loadedCoursesById = $courses->all();

        if ($courses->isEmpty()) {
            throw new RuntimeException(
                'No schedulable courses found for this section. Courses with 0 lecture hours, 0 lab hours, and 0 units are treated as non-timetable requirements.'
            );
        }

        $this->validateCoursesForSection(
            section: $section,
            courses: $courses,
        );

        $preferredPatternsByCourseId = SolverInput::normalizePreferredPatternsByCourseId(
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            validCourseIds: $courseIds,
        );
        $selectedLectureLabCourseIds = SolverInput::normalizeCourseIds($selectedLectureLabCourseIds);
        $balancedSplitCourseIds = SolverInput::normalizeCourseIds($balancedSplitCourseIds);
        $hybridSplitCourseIds = SolverInput::normalizeCourseIds($hybridSplitCourseIds);
        $deliveryModesByCourseId = SolverInput::normalizeDeliveryModesByCourseId(
            deliveryModesByCourseId: $deliveryModesByCourseId,
            validCourseIds: $courseIds,
        );

        $requiredRoomTypes = $this->requiredRoomTypesForDeliveryMode(
            courses: $courses,
            deliveryMode: $deliveryMode,
        );

        $this->validateRoomTypes($requiredRoomTypes);

        $this->roomGrantWindows = $this->grantWindowsForSection($section);

        $rooms = collect($snapshot->roomsById)
            ->map(static function (array $attributes): Rooms {
                $room = new Rooms($attributes);
                $room->id = (int) ($attributes['id'] ?? 0);

                return $room;
            })
            ->filter(static fn (Rooms $room): bool => (string) $room->status === 'available')
            ->filter(fn (Rooms $room): bool => in_array((string) $room->room_type, $requiredRoomTypes, true))
            ->filter(fn (Rooms $room): bool => $room->department_id === null
                || (int) $room->department_id === (int) $section->department_id
                || isset($this->roomGrantWindows[(int) $room->id]))
            ->sortBy('room_code')
            ->values();
        $rooms = new Collection($rooms->all());

        foreach ($requiredRoomTypes as $rt) {
            if (($rt === 'field' || $rt === 'online') && ! $rooms->contains('room_type', $rt)) {
                $existingVirtual = $rooms->firstWhere('room_code', strtoupper($rt));
                $virtualRoom = new Rooms([
                    'room_code' => strtoupper($rt),
                    'room_type' => $rt,
                    'status' => 'available',
                    'department_id' => null,
                ]);
                $virtualRoom->id = $existingVirtual ? $existingVirtual->id : ($rt === 'field' ? 99999 : 99998);
                $rooms->push($virtualRoom);
            }
        }

        $this->roomTypes = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                (int) $room->id => (string) $room->room_type,
            ])
            ->all();

        $this->roomFairness()->prepare(
            section: $section,
            rooms: $rooms,
        );

        $this->preloadExistingSchedules(
            semesterId: (int) $section->semester_id,
            sectionId: (int) $section->id,
            departmentId: (int) $section->department_id,
            replaceCourseIds: $courseIds,
            tentativeSchedules: $this->tentativeSchedules,
        );
        $this->blockRoomsOutsideGrantWindows();
        $this->blockLentWindows();
        $this->blockRoomsOnOtherProgramsDays($section);

        $solverSeed = $seed !== null ? (int) $seed : random_int(1, 1000000);

        $settings = $snapshot->departmentSettings;
        $lectureLabScheduleOverrideEnabled = (bool) ($settings['lecture_lab_schedule_override_enabled'] ?? false);
        $this->departmentLabSettings = $settings;
        $this->sundayClassesEnabled = (bool) ($settings['sunday_classes_enabled'] ?? false);
        $forcedDaysByCourseId = $this->forcedDaysByCourseId((int) $section->department_id, $courseIds);
        $this->generationForcedDaysByCourseId = $forcedDaysByCourseId;
        $this->consecutiveRulesByCourseId = array_intersect_key(
            $snapshot->consecutiveDayRulesFor((int) $section->id),
            array_fill_keys(array_map('intval', $courseIds), true),
        );

        $this->protectsSplitCapacity = $this->lateWeekCapacityPreference
            && ($balancedSplitCourseIds !== []
                || $hybridSplitCourseIds !== []
                || ($lectureLabScheduleOverrideEnabled && $selectedLectureLabCourseIds !== []));

        $variables = $this->buildVariables(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            sectionId: (int) $section->id,
            seed: $solverSeed,
            lectureLabScheduleOverrideEnabled: $lectureLabScheduleOverrideEnabled,
            selectedLectureLabCourseIds: $selectedLectureLabCourseIds,
            balancedSplitCourseIds: $balancedSplitCourseIds,
            hybridSplitCourseIds: $hybridSplitCourseIds,
            forcedDaysByCourseId: $forcedDaysByCourseId,
            anchoredSchedulesByCourseId: $anchoredSchedulesByCourseId,
            deliveryModesByCourseId: $deliveryModesByCourseId,
            requirementsByCourseId: $this->requirementsByCourseId,
            throwOnEmptyDomain: $throwOnEmptyDomain,
            allowRoomTbaFallback: $allowRoomTbaFallback,
        );

        $this->metricsCandidateCountBefore = array_sum(array_map(
            static fn (array $variable): int => count($variable['domain'] ?? []),
            $variables,
        ));

        $variables = $this->prunePersistedConflictingCandidates(
            variables: $variables,
            sectionId: (int) $section->id,
            departmentId: (int) $section->department_id,
        );

        $this->metricsVariableCount = count($variables);
        $this->metricsCandidateCountAfter = array_sum(array_map(
            static fn (array $variable): int => count($variable['domain'] ?? []),
            $variables,
        ));

        if (! $allowRoomTbaFallback) {
            foreach ($variables as &$variable) {
                $variable['domain'] = array_values(array_filter(
                    $variable['domain'],
                    static fn (array $candidate): bool => ! ($candidate['_room_tba'] ?? false),
                ));
            }
            unset($variable);
        }

        if (! $allowOnlineFallback) {
            foreach ($variables as &$variable) {
                $variable['domain'] = array_values(array_filter(
                    $variable['domain'],
                    static fn (array $candidate): bool => ! ($candidate['_lecture_online_fallback'] ?? false),
                ));
            }
            unset($variable);
        }

        if ($this->requirementsByCourseId !== []) {
            $coursesById = $courses->keyBy(static fn (Course $course): int => (int) $course->id);
            foreach ($variables as $variable) {
                if (($variable['domain'] ?? []) !== []) {
                    continue;
                }

                $course = $coursesById->get((int) $variable['course_id']);
                if ($throwOnEmptyDomain) {
                    throw new RuntimeException(sprintf(
                    '%s / %s has no eligible room candidates after existing schedule conflicts were applied.',
                    (string) $section->section_name,
                    (string) ($course?->course_code ?? ('Course '.$variable['course_id'])),
                    ));
                }
            }
        }

        usort(
            $variables,
            static function (array $left, array $right): int {
                $leftConstrained = ! empty($left['preferred_pattern']) || ! empty($left['forced_day']);
                $rightConstrained = ! empty($right['preferred_pattern']) || ! empty($right['forced_day']);
                if ($leftConstrained !== $rightConstrained) {
                    return $leftConstrained ? -1 : 1;
                }

                $leftSplit = (bool) ($left['is_split_lecture_lab'] ?? false);
                $rightSplit = (bool) ($right['is_split_lecture_lab'] ?? false);
                if ($leftSplit !== $rightSplit) {
                    return $leftSplit ? -1 : 1;
                }

                $leftSplitSession = (bool) ($left['is_split_session'] ?? false);
                $rightSplitSession = (bool) ($right['is_split_session'] ?? false);
                if ($leftSplitSession !== $rightSplitSession) {
                    return $leftSplitSession ? -1 : 1;
                }

                $priorityComparison = ($left['scheduling_priority'] ?? 2)
                    <=> ($right['scheduling_priority'] ?? 2);

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $roomOptionComparison = ($left['physical_room_options'] ?? PHP_INT_MAX)
                    <=> ($right['physical_room_options'] ?? PHP_INT_MAX);

                if ($roomOptionComparison !== 0) {
                    return $roomOptionComparison;
                }

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
                $this->recordDeadEnd((int) $variable['course_id']);

                return [];
            }
        }

        $candidatePoolLimit = min(
            max($maxSolutions * 3, 8),
            18,
        );

        if (! $this->lateWeekCapacityPreference) {
            $candidatePoolLimit = min(max($maxSolutions * 8, 24), 40);
        }

        $rawSolutions = [];
        $solutionSignatures = [];

        $unrestrictedPoolLimit = max($maxSolutions, intdiv($candidatePoolLimit, 2));
        $this->backtrack(
            variableIndex: 0,
            variables: $variables,
            section: $section,
            assignments: [],
            solutions: $rawSolutions,
            solutionSignatures: $solutionSignatures,
            solutionLimit: $unrestrictedPoolLimit,
        );

        $resolvedLaboratorySolutions = array_values(array_filter(
            $rawSolutions,
            fn (array $assignments): bool => ! $this->solutionContainsRoomTba($assignments),
        ));
        if ($resolvedLaboratorySolutions !== []) {
            $rawSolutions = $resolvedLaboratorySolutions;
        }

        $roomedLectureSolutions = array_values(array_filter(
            $rawSolutions,
            fn (array $assignments): bool => ! $this->solutionContainsOnlineFallback($assignments),
        ));
        if ($roomedLectureSolutions !== []) {
            $rawSolutions = $roomedLectureSolutions;
        }

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

        $ranked = $this->searchFromDay !== null
            ? $this->selectDiverseFromDay($scored, $maxSolutions, $this->searchFromDay)
            : $this->solutionDiversity()->selectDiverse($scored, $maxSolutions);

        foreach ($ranked as $index => &$solution) {
            unset($solution['_raw']);
            $solution['rank'] = $index + 1;
        }

        unset($solution);

        return $ranked;
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return list<array<string, mixed>>
     */
    private function selectDiverseFromDay(array $scored, int $limit, string $fromDay): array
    {
        $byDistance = [];
        foreach ($scored as $solution) {
            $distance = PHP_INT_MAX;
            foreach ($solution['_raw'] as $assignment) {
                $distance = min($distance, self::candidateDayDistance($assignment, $fromDay));
            }
            $byDistance[$distance][] = $solution;
        }
        ksort($byDistance);

        $selected = [];
        foreach ($byDistance as $solutions) {
            $remaining = $limit - count($selected);
            if ($remaining <= 0) {
                break;
            }
            array_push($selected, ...$this->solutionDiversity()->selectDiverse($solutions, $remaining));
        }

        return $selected;
    }

    /** @param list<array<string, mixed>> $assignments */
    private function solutionContainsRoomTba(array $assignments): bool
    {
        foreach ($assignments as $assignment) {
            if ($assignment['_room_tba'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $assignments */
    private function solutionContainsOnlineFallback(array $assignments): bool
    {
        foreach ($assignments as $assignment) {
            if ($assignment['_lecture_online_fallback'] ?? false) {
                return true;
            }
        }

        return false;
    }

    public function searchLimitReached(): bool
    {
        return $this->searchLimitReached;
    }

    public function iterationsUsed(): int
    {
        return $this->iterations;
    }

    /** @return array<int, int> dead ends of the last search, keyed by course id */
    public function deadEndsByCourseId(): array
    {
        return $this->deadEndsByCourseId;
    }

    private function recordDeadEnd(int $courseId): void
    {
        $this->deadEndsByCourseId[$courseId] = ($this->deadEndsByCourseId[$courseId] ?? 0) + 1;
    }

    public function generationMetrics(): SchedulingGenerationMetrics
    {
        $fallbackUsage = $this->snapshotAutoCaptured
            ? ['auto_captured_snapshot' => 1]
            : [];

        return new SchedulingGenerationMetrics(
            operation: 'section_schedule_generation',
            snapshotQueryCount: (int) ($this->inputSnapshot?->metadata['snapshot_query_count'] ?? 0),
            snapshotElapsedMs: (float) ($this->inputSnapshot?->metadata['snapshot_elapsed_ms'] ?? 0.0),
            variableCount: $this->metricsVariableCount,
            candidateCountBefore: $this->metricsCandidateCountBefore,
            candidateCountAfter: $this->metricsCandidateCountAfter,
            iterations: $this->iterations,
            searchLimitReached: $this->searchLimitReached,
            solverAttempts: 1,
            elapsedMs: $this->metricsStartedAt > 0.0
                ? max(0.0, (microtime(true) - $this->metricsStartedAt) * 1000)
                : 0.0,
            fallbackUsage: $fallbackUsage,
        );
    }

    private function backtrack(
        int $variableIndex,
        array $variables,
        Sections $section,
        array $assignments,
        array &$solutions,
        array &$solutionSignatures,
        int $solutionLimit,
    ): void {
        if (count($solutions) >= $solutionLimit) {
            return;
        }

        if ($this->hasExceededSearchLimits()) {
            $this->searchLimitReached = true;

            return;
        }

        if ($variableIndex >= count($variables)) {
            $signature = $this->solutionDiversity()->signature($assignments);

            if (! isset($solutionSignatures[$signature])) {
                $solutionSignatures[$signature] = true;
                $solutions[] = $assignments;
            }

            return;
        }

        $variable = $variables[$variableIndex];

        $domain = $this->rankDomainForTentativeCompactness(
            $variable['domain'],
            $assignments,
            (int) $section->id,
        );

        $hasRoomTbaCandidates = collect($domain)->contains(
            static fn (array $candidate): bool => (bool) ($candidate['_room_tba'] ?? false),
        );
        $candidateGroups = $this->weekdayFirstCandidateGroups($domain, $hasRoomTbaCandidates, (int) $section->id);
        $placeable = 0;

        foreach ($candidateGroups as $candidates) {
            $solutionsBeforeGroup = count($solutions);

            foreach ($candidates as $candidate) {
                $this->iterations++;

                if ($this->hasExceededSearchLimits()) {
                    $this->searchLimitReached = true;

                    return;
                }

                if ($this->conflictsWithTentativeAssignments(
                    candidate: $candidate,
                    assignments: $assignments,
                    sectionId: (int) $section->id,
                    departmentId: (int) $section->department_id,
                )) {
                    continue;
                }

                if (! $this->passesFastCandidateGuards(
                    candidate: $candidate,
                    section: $section,
                )) {
                    continue;
                }

                $placeable++;
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
                );

                if (count($solutions) >= $solutionLimit) {
                    return;
                }
            }

            if (count($solutions) > $solutionsBeforeGroup) {
                return;
            }
        }

        if ($placeable === 0) {
            $this->recordDeadEnd((int) $variable['course_id']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $domain
     * @return list<list<array<string, mixed>>>
     */
    private function weekdayFirstCandidateGroups(array $domain, bool $hasRoomTbaCandidates, int $sectionId = 0): array
    {
        return $this->candidateGroupsByDayPriority(
            domain: $domain,
            hasRoomTbaCandidates: $hasRoomTbaCandidates,
            dayPriority: [0, 1, 2],
            sectionId: $sectionId,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $domain
     * @param  list<int>  $dayPriority
     * @return list<list<array<string, mixed>>>
     */
    private function candidateGroupsByDayPriority(
        array $domain,
        bool $hasRoomTbaCandidates,
        array $dayPriority,
        int $sectionId = 0,
    ): array {
        if (count($domain) < 2) {
            return [$domain];
        }

        $byAllocation = [];
        foreach ($domain as $candidate) {
            $priority = $this->candidateAllocationPriority($candidate, $sectionId);
            if ($hasRoomTbaCandidates && ($candidate['_room_tba'] ?? false)) {
                $priority = 100 + $this->candidateSearchDayTier($candidate);
            }
            $byAllocation[$priority][] = $candidate;
        }
        ksort($byAllocation);

        $groups = [];
        foreach ($byAllocation as $tierCandidates) {
            $dayBuckets = array_fill(0, 3, []);
            foreach ($tierCandidates as $candidate) {
                $dayBuckets[$this->candidateSearchDayTier($candidate)][] = $candidate;
            }
            foreach ($dayPriority as $dayTier) {
                if ($dayBuckets[$dayTier] !== []) {
                    $groups[] = match (true) {
                        $this->lateWeekCapacityPreference => $dayBuckets[$dayTier],
                        $this->searchFromDay !== null => self::orderFromDay($dayBuckets[$dayTier], $this->searchFromDay),
                        default => self::interleaveByDay($dayBuckets[$dayTier]),
                    };
                }
            }
        }

        return $groups === [] ? [$domain] : $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private static function interleaveByDay(array $candidates): array
    {
        $byDay = [];
        foreach ($candidates as $candidate) {
            $day = (string) ($candidate['blocks'][0]['day'] ?? '');
            $byDay[$day][] = $candidate;
        }

        if (count($byDay) < 2) {
            return $candidates;
        }

        $interleaved = [];
        while ($byDay !== []) {
            foreach ($byDay as $day => $dayCandidates) {
                $interleaved[] = array_shift($dayCandidates);
                if ($dayCandidates === []) {
                    unset($byDay[$day]);

                    continue;
                }
                $byDay[$day] = $dayCandidates;
            }
        }

        return $interleaved;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private static function orderFromDay(array $candidates, string $fromDay): array
    {
        $byDistance = [];
        foreach ($candidates as $candidate) {
            $byDistance[self::candidateDayDistance($candidate, $fromDay)][] = $candidate;
        }
        ksort($byDistance);

        $ordered = [];
        foreach ($byDistance as $dayCandidates) {
            $byStart = [];
            foreach ($dayCandidates as $candidate) {
                $byStart[(int) ($candidate['blocks'][0]['start_slot'] ?? 0)][] = $candidate;
            }
            while ($byStart !== []) {
                foreach ($byStart as $start => $startCandidates) {
                    $ordered[] = array_shift($startCandidates);
                    if ($startCandidates === []) {
                        unset($byStart[$start]);

                        continue;
                    }
                    $byStart[$start] = $startCandidates;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $placement  a candidate or an assignment
     */
    private static function candidateDayDistance(array $placement, string $fromDay): int
    {
        $nearest = PHP_INT_MAX;

        foreach ($placement['blocks'] ?? [] as $block) {
            $nearest = min($nearest, SchedulingPolicy::searchDayRank((string) ($block['day'] ?? ''), $fromDay));
        }

        return $nearest;
    }

    private function candidateSearchDayTier(array $candidate): int
    {
        $tier = 0;
        $prefersLateWeek = $this->protectsSplitCapacity && $this->prefersLateWeekPlacement($candidate);
        $lateWeekDays = [...SchedulingPolicy::SINGLE_MEETING_PREFERRED_DAYS, 'Sunday'];

        foreach ($candidate['blocks'] ?? [] as $block) {
            $day = (string) ($block['day'] ?? '');

            if ($prefersLateWeek && ! in_array($day, $lateWeekDays, true)) {
                $tier = 1;
            }
        }

        return $tier;
    }

    private function prefersLateWeekPlacement(array $candidate): bool
    {
        $blocks = $candidate['blocks'] ?? [];
        if (count($blocks) !== 1 || ($candidate['_room_tba'] ?? false)) {
            return false;
        }

        $block = $blocks[0];

        if ((string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site') !== 'on-site') {
            return false;
        }

        $roomId = array_key_exists('room_id', $block)
            ? $block['room_id']
            : ($candidate['room_id'] ?? null);
        if ($roomId === null) {
            return false;
        }

        return (string) ($block['room_type'] ?? $candidate['room_type'] ?? '') === 'lecture';
    }

    /**
     * @param  list<array<string, mixed>>  $domain
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function rankDomainForTentativeCompactness(array $domain, array $assignments, int $sectionId = 0): array
    {
        if (count($domain) < 2) {
            return $domain;
        }

        $dayLoads = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] ?? [] as $block) {
                $day = (string) ($block['day'] ?? '');
                if ($day !== '') {
                    $dayLoads[$day] = ($dayLoads[$day] ?? 0) + 1;
                }
            }
        }

        $ranked = [];
        foreach ($domain as $index => $candidate) {
            $ranked[] = [
                'candidate' => $candidate,
                'allocation' => $this->candidateAllocationPriority($candidate, $sectionId),
                'preferred_room' => $this->candidatePreferredRoomRank($candidate),
                'mixed_mode_overlap' => $this->candidateMixedModeCourseOverlaps($candidate, $sectionId),
                'day_pair' => $this->candidateDayPairLoadRank($candidate, $dayLoads, $sectionId),
                'penalty' => $this->candidateTentativeGapPenalty($candidate, $assignments)
                    + $this->candidateDayBalancePenalty($candidate, $dayLoads, $sectionId)
                    + $this->candidateSplitPairBreakPenalty($candidate, $assignments),
                'index' => $index,
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
                ?: $left['mixed_mode_overlap'] <=> $right['mixed_mode_overlap']
                ?: $left['preferred_room'] <=> $right['preferred_room']
                ?: $left['day_pair'] <=> $right['day_pair']
                ?: $left['penalty'] <=> $right['penalty']
                ?: $left['index'] <=> $right['index'],
        );

        return array_column($ranked, 'candidate');
    }

    private function candidateMixedModeCourseOverlaps(array $candidate, int $sectionId): int
    {
        $courseId = (int) ($candidate['course_id'] ?? 0);
        $overlaps = 0;

        foreach ($candidate['blocks'] ?? [] as $block) {
            $online = (string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site') === 'online';
            $day = (string) ($block['day'] ?? '');
            $startMinutes = $this->timeToMinutes((string) ($block['start_time'] ?? ''));
            $endMinutes = $this->timeToMinutes((string) ($block['end_time'] ?? ''));

            foreach ($this->existingScheduleIndex["c:{$courseId}:{$day}"] ?? [] as $existing) {
                if ($existing['online'] !== $online
                    && (int) ($existing['section_id'] ?? 0) !== $sectionId
                    && $this->entryOverlaps($existing, $startMinutes, $endMinutes)) {
                    $overlaps++;
                }
            }
        }

        return $overlaps;
    }

    /**
     * @param  array<string, int>  $dayLoads
     */
    private function candidateDayBalancePenalty(array $candidate, array $dayLoads, int $sectionId): int
    {
        $blocks = $candidate['blocks'] ?? [];
        if ($blocks === []) {
            return 0;
        }

        if (
            ! empty($candidate['preferred_pattern'])
            || ! empty($candidate['_pattern_fallback'])
            || ! empty($candidate['_single_session_fallback'])
        ) {
            return 0;
        }

        $courseId = (int) ($candidate['course_id'] ?? 0);
        $cycle = 7;
        $anchor = abs(($sectionId * 17) + ($courseId * 31)) % $cycle;
        $penalty = 0;

        foreach ($blocks as $block) {
            $day = (string) ($block['day'] ?? '');
            $dayIndex = $this->dayIndex($day);

            $penalty += (($dayLoads[$day] ?? 0) * 700);
            $distance = ($dayIndex - $anchor + $cycle) % $cycle;
            $penalty += $distance * 8;
        }

        return $penalty;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    private function candidateSplitPairBreakPenalty(array $candidate, array $assignments): int
    {
        if (! $this->protectsSplitCapacity || ! $this->prefersLateWeekPlacement($candidate)) {
            return 0;
        }

        $block = $candidate['blocks'][0];
        $day = (string) ($block['day'] ?? '');
        $pairDay = null;
        foreach (SchedulingPolicy::autoSplitDayPairs() as [$first, $second]) {
            $pairDay = match ($day) {
                $first => $second,
                $second => $first,
                default => $pairDay,
            };
        }

        if ($pairDay === null) {
            return 0;
        }

        $roomId = (int) (array_key_exists('room_id', $block) ? $block['room_id'] : $candidate['room_id']);

        if ($this->overlapCountAtLeast(
            "r:{$roomId}:{$pairDay}",
            $this->timeToMinutes((string) ($block['start_time'] ?? '')),
            $this->timeToMinutes((string) ($block['end_time'] ?? '')),
            1,
        )) {
            return 0;
        }

        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] ?? [] as $assignedBlock) {
                $assignedRoomId = SolverInput::nullableRoomId(
                    array_key_exists('room_id', $assignedBlock)
                        ? $assignedBlock['room_id']
                        : ($assignment['room_id'] ?? null)
                );

                if (($assignedBlock['day'] ?? null) === $pairDay
                    && $assignedRoomId === $roomId
                    && $this->nonOverlappingSlotGap($block, $assignedBlock) === null) {
                    return 0;
                }
            }
        }

        return SchedulingPolicy::SOFT_SPLIT_PAIR_BREAK_PENALTY;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    private function candidateTentativeGapPenalty(array $candidate, array $assignments): int
    {
        $penalty = 0;

        foreach ($candidate['blocks'] ?? [] as $candidateBlock) {
            $candidateRoomId = SolverInput::nullableRoomId(
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

                    $assignedRoomId = SolverInput::nullableRoomId(
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
                if ($this->isClassroomCandidateBlock($candidate, $candidateBlock)) {
                    $penalty += $this->classroomAwkwardGapPenalty($bestRoomGap);
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

    private function isClassroomCandidateBlock(array $candidate, array $block): bool
    {
        $roomId = SolverInput::nullableRoomId(
            array_key_exists('room_id', $block)
                ? $block['room_id']
                : ($candidate['room_id'] ?? null)
        );

        if ($roomId === null || $this->isVirtualCandidateBlock($candidate, $block)) {
            return false;
        }

        $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? ($this->roomTypes[$roomId] ?? ''));

        return $roomType !== 'laboratory'
            && ($this->roomTypes[$roomId] ?? null) !== 'laboratory'
            && ($block['meeting_type'] ?? null) !== 'laboratory';
    }

    private function candidatePreferredRoomRank(array $candidate): int
    {
        $roomId = $this->preferredRoomIdsByCourseId[(int) ($candidate['course_id'] ?? 0)] ?? null;
        if ($roomId === null) {
            return 0;
        }

        if ((int) ($candidate['room_id'] ?? 0) === $roomId) {
            return 0;
        }
        foreach ($candidate['blocks'] ?? [] as $block) {
            if ((int) ($block['room_id'] ?? 0) === $roomId) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function requirementCustomSlots(array $requirements): ?int
    {
        if (count($requirements) !== 1 || ! (bool) ($requirements[0]['custom_duration'] ?? false)) {
            return null;
        }
        $slots = (int) ($requirements[0]['duration_slots'] ?? 0);

        return $slots > 0 ? $slots : null;
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function requirementComponentSlots(array $requirements, string $componentType): ?int
    {
        foreach ($requirements as $requirement) {
            if (($requirement['component_type'] ?? null) === $componentType
                && (bool) ($requirement['custom_duration'] ?? false)) {
                $slots = (int) ($requirement['duration_slots'] ?? 0);

                return $slots > 0 ? $slots : null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $requirements */
    private function requirementPreferredRoomId(array $requirements): ?int
    {
        foreach ($requirements as $requirement) {
            $roomId = (int) ($requirement['preferred_room_id'] ?? 0);
            if ($roomId > 0) {
                return $roomId;
            }
        }

        return null;
    }

    private function classroomAwkwardGapPenalty(int $gapSlots): int
    {
        if ($gapSlots <= 0) {
            return 0;
        }

        $bestRemainder = SchedulingPolicy::classroomBestRemainderAfterSchedulableBlocks($gapSlots);
        $filledSlots = $gapSlots - $bestRemainder;

        return ($gapSlots === 5 ? self::CLASSROOM_FIVE_SLOT_GAP_SOFT_PENALTY : 0)
            + ($gapSlots === 6 ? self::CLASSROOM_SIX_SLOT_GAP_SOFT_PENALTY : 0)
            + ($filledSlots * self::CLASSROOM_GAP_SCHEDULABLE_SLOT_SOFT_PENALTY)
            + ($bestRemainder * self::CLASSROOM_GAP_LEFTOVER_SLOT_SOFT_PENALTY);
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
        array $selectedLectureLabCourseIds = [],
        array $balancedSplitCourseIds = [],
        array $hybridSplitCourseIds = [],
        array $forcedDaysByCourseId = [],
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        array $requirementsByCourseId = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
    ): array {
        $variables = [];
        $roomsSignature = $this->roomsSignature($rooms);
        $this->preferredRoomIdsByCourseId = [];

        foreach ($courses as $course) {
            $courseDeliveryMode = $deliveryModesByCourseId[(int) $course->id] ?? $deliveryMode;
            $requirements = $requirementsByCourseId[(int) $course->id] ?? [];
            if ($this->requirementsRequireFieldDelivery($requirements)) {
                $courseDeliveryMode = 'field';
            }
            $preferredRoomId = $this->requirementPreferredRoomId($requirements);
            if ($preferredRoomId !== null) {
                $this->preferredRoomIdsByCourseId[(int) $course->id] = $preferredRoomId;
            }
            $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
            $lecHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            $hasBothComponents = $isMajor
                && in_array((int) $course->id, $selectedLectureLabCourseIds, true)
                && $lecHours > 0
                && $labHours > 0;
            $courseIsHybrid = $isHybrid && $hasBothComponents
                && ! SchedulingPolicy::isIntegratedOnSite($deliveryModesByCourseId, (int) $course->id);

            $preferredPattern = SolverInput::normalizePreferredPattern(
                $preferredPatternsByCourseId[(int) $course->id] ?? null,
            );
            $requiresBalancedSplit = in_array((int) $course->id, $balancedSplitCourseIds, true);
            $requiresHybridSplit = in_array((int) $course->id, $hybridSplitCourseIds, true);

            $consecutiveRule = $this->consecutiveRulesByCourseId[(int) $course->id] ?? null;
            if ($consecutiveRule !== null) {
                $hasBothComponents = false;
                $courseIsHybrid = false;
                $requiresBalancedSplit = false;
                $requiresHybridSplit = false;
                $preferredPattern = null;
            }

            $lectureComponentSlots = null;
            $laboratoryComponentSlots = null;
            if ($hasBothComponents) {
                $lectureComponentSlots = $this->requirementComponentSlots($requirements, 'lecture')
                    ?? SchedulingPolicy::lectureComponentSlots($course);
                $laboratoryComponentSlots = $this->requirementComponentSlots($requirements, 'laboratory')
                    ?? $this->laboratoryComponentSlots($course);
                $durationSlots = $lectureComponentSlots + $laboratoryComponentSlots;
            } elseif ($requiresHybridSplit) {
                $durationSlots = 2 * SchedulingPolicy::hybridSplitMeetingSlots();
            } else {
                $durationSlots = $this->requirementCustomSlots($requirements)
                    ?? $this->getDurationSlots($course);
            }

            $forcedDay = $forcedDaysByCourseId[(int) $course->id] ?? null;

            $consecutiveDayCount = $consecutiveRule['day_count'] ?? 0;
            if ($throwOnEmptyDomain && $consecutiveRule !== null) {
                $this->assertConsecutiveDaysPlaceable($course, $sectionId, $consecutiveRule, $forcedDay);
            }

            $domainCacheKey = $this->domainCacheKey(
                courseId: (int) $course->id,
                roomsSignature: $roomsSignature,
                parts: [
                    $courseDeliveryMode,
                    $courseIsHybrid ? 1 : 0,
                    $hasBothComponents ? 1 : 0,
                    $preferredPattern ?? '',
                    $requiresBalancedSplit ? 1 : 0,
                    $requiresHybridSplit ? 1 : 0,
                    $durationSlots,
                    $forcedDay ?? '',
                    $this->allowedDays ?? [],
                    $this->sundayClassesEnabled ? 1 : 0,
                    $this->allowFridaySaturdaySplit ? 1 : 0,
                    SchedulingPolicy::fieldDayEndTime(),
                    array_key_exists((int) $course->id, $deliveryModesByCourseId) ? 1 : 0,
                    $requirementsByCourseId[(int) $course->id] ?? null,
                    $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                    $consecutiveDayCount,
                    $consecutiveRule['preferred_start_day'] ?? '',
                    $consecutiveRule['meeting_days'] ?? null,
                ],
            );

            $cached = $this->domainCache[$domainCacheKey] ?? null;
            if ($cached !== null) {
                $domain = $cached['domain'];
                $emptyAfterRequirements = $cached['empty_after_requirements'];
                $emptyAfterForcedDay = $cached['empty_after_forced_day'] ?? false;
                $emptyAfterDays = $cached['empty_after_days'] ?? false;
                $emptyAfterSunday = $cached['empty_after_sunday'] ?? false;
            } else {
            $domain = match (true) {
                $consecutiveRule !== null => $this->buildConsecutiveDaysDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    meetingSlots: $durationSlots,
                    dayCount: $consecutiveDayCount,
                    deliveryMode: $courseDeliveryMode,
                    runs: SchedulingPolicy::consecutiveRuleRuns($consecutiveRule, $this->sundayClassesEnabled, $this->allowedDays),
                ),
                $hasBothComponents => $this->buildDefaultLectureLabDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $courseIsHybrid,
                    anchoredSchedule: $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                    lectureSlots: $lectureComponentSlots,
                    laboratorySlots: $laboratoryComponentSlots,
                ),
                $requiresHybridSplit && $preferredPattern === null => $this->buildFlexibleHybridSplitDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                ),
                $requiresHybridSplit => $this->buildHybridSplitPatternDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                ),
                $requiresBalancedSplit && $preferredPattern === null => $this->buildFlexibleBalancedSplitDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                ),
                $courseDeliveryMode === 'online' && $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                ),
                $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                ),
                default => $this->buildPatternDomainWithFallbacks(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $courseIsHybrid,
                    requireBalancedDurations: $requiresBalancedSplit,
                ),
            };

            if (array_key_exists((int) $course->id, $deliveryModesByCourseId)
                && ! $hasBothComponents
                && ! $requiresHybridSplit) {
                $domain = array_values(array_filter($domain, static function (array $candidate) use ($courseDeliveryMode): bool {
                    foreach ($candidate['blocks'] ?? [] as $block) {
                        if ((string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site') !== $courseDeliveryMode) {
                            return false;
                        }
                    }

                    return true;
                }));
            }

            if (isset($requirementsByCourseId[(int) $course->id])) {
                $domain = $this->filterDomainByRequirements(
                    $domain,
                    $requirementsByCourseId[(int) $course->id],
                );
            }

            $emptyAfterRequirements = $domain === [] && isset($requirementsByCourseId[(int) $course->id]);

            $emptyAfterForcedDay = false;
            if ($forcedDay !== null && $domain !== [] && $consecutiveRule === null) {
                $domain = $this->filterDomainByForcedDay($domain, $forcedDay);
                $emptyAfterForcedDay = $domain === [];
            }

            $emptyAfterDays = false;
            if ($this->allowedDays !== null && $domain !== []) {
                $domain = $this->filterDomainByDays($domain, $this->allowedDays);
                $emptyAfterDays = $domain === [];
            }

            $emptyAfterSunday = false;
            if (! $this->sundayClassesEnabled && $domain !== []) {
                $domain = $this->filterDomainByDays($domain, SchedulingPolicy::teachingDays(false));
                $emptyAfterSunday = $domain === [];
            }

            $this->domainCache[$domainCacheKey] = [
                'domain' => $domain,
                'empty_after_requirements' => $emptyAfterRequirements,
                'empty_after_forced_day' => $emptyAfterForcedDay,
                'empty_after_days' => $emptyAfterDays,
                'empty_after_sunday' => $emptyAfterSunday,
            ];
            }

            $shuffleSeed = abs($sectionId * 2053 + (int) $course->id * 97 + $seed);
            $domain = $this->seededShuffle($domain, $shuffleSeed);

            $blockedBySunday = ! $this->sundayClassesEnabled && (
                $emptyAfterSunday
                || ($emptyAfterForcedDay && $forcedDay === 'Sunday')
                || ($emptyAfterDays && $this->allowedDays === ['Sunday'])
            );
            if ($throwOnEmptyDomain && $blockedBySunday) {
                throw new RuntimeException(sprintf(
                    '%s / %s can only be scheduled on Sunday, but Sunday classes are not enabled for this department. Ask the department secretary to enable Sunday classes, or choose another day.',
                    $this->sectionLabel($sectionId),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                ));
            }

            if ($throwOnEmptyDomain && $emptyAfterDays && $this->allowedDays !== null) {
                throw new RuntimeException(sprintf(
                    '%s / %s cannot be scheduled on the Preferred Days (%s). %s',
                    $this->sectionLabel($sectionId),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                    implode(', ', $this->allowedDays),
                    count($this->allowedDays) < 2
                        ? 'Its meetings need two different days; add another Preferred Day.'
                        : 'Add more Preferred Days, or clear them to allow any day.',
                ));
            }

            if ($throwOnEmptyDomain && $emptyAfterForcedDay) {
                throw new RuntimeException(sprintf(
                    '%s / %s has a Required Day of %s, but this course cannot be scheduled on that day. Change or clear its Required Day in Setup Courses.',
                    $this->sectionLabel($sectionId),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                    (string) $forcedDay,
                ));
            }

            if ($throwOnEmptyDomain && $emptyAfterRequirements) {
                throw new RuntimeException(sprintf(
                    '%s / %s has no eligible scheduling candidates for the configured department profile.',
                    $this->sectionLabel($sectionId),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                ));
            }

            $ranked = [];
            foreach ($domain as $rankIndex => $rankCandidate) {
                $ranked[] = [
                    'allocation' => $this->candidateAllocationPriority($rankCandidate, $sectionId),
                    'preferred_room' => $this->candidatePreferredRoomRank($rankCandidate),
                    'day_pair' => $this->candidateDayPairRotationRank($rankCandidate, $sectionId),
                    'availability' => $this->candidateRoomAvailabilityPenalty($rankCandidate),
                    'concentration' => $this->candidateRoomConcentrationPenalty($rankCandidate),
                    'hybrid_order' => $this->candidateHybridSplitOrderRank($rankCandidate, $sectionId),
                    'index' => $rankIndex,
                    'candidate' => $rankCandidate,
                ];
            }
            usort(
                $ranked,
                static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
                    ?: $left['preferred_room'] <=> $right['preferred_room']
                    ?: $left['day_pair'] <=> $right['day_pair']
                    ?: $left['availability'] <=> $right['availability']
                    ?: $left['concentration'] <=> $right['concentration']
                    ?: $left['hybrid_order'] <=> $right['hybrid_order']
                    ?: $left['index'] <=> $right['index'],
            );
            $domain = array_column($ranked, 'candidate');

            $variables[] = [
                'course_id' => (int) $course->id,
                'scheduling_priority' => $this->courseSchedulingPriority($course),
                'is_field' => $this->isFieldCourse($course),
                'is_split_lecture_lab' => $hasBothComponents,
                'is_split_session' => $requiresBalancedSplit || $requiresHybridSplit,
                'physical_room_options' => $this->countPhysicalRoomOptions($domain),
                'duration_slots' => $durationSlots,
                'preferred_pattern' => $consecutiveRule !== null
                    ? SchedulingPolicy::consecutivePattern($consecutiveDayCount)
                    : $preferredPattern,
                'forced_day' => $forcedDay,
                'delivery_mode' => $courseDeliveryMode,
                'is_hybrid' => $courseIsHybrid,
                'domain' => $domain,
            ];
        }

        return $variables;
    }

    /**
     * @param  array{day_count: int, preferred_start_day: string|null, meeting_days?: list<string>|null}  $rule
     */
    private function assertConsecutiveDaysPlaceable(Course $course, int $sectionId, array $rule, ?string $forcedDay): void
    {
        $label = $this->sectionLabel($sectionId).' / '.(string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id));
        $dayCount = (int) $rule['day_count'];
        $startDay = $rule['preferred_start_day'] ?? null;
        $meetingDays = $rule['meeting_days'] ?? null;

        if ($forcedDay !== null) {
            throw new RuntimeException(sprintf(
                '%s has a Required Day of %s and is also set to meet on %d consecutive days. Clear its Required Day in Setup Courses, or tick its meeting days instead.',
                $label,
                $forcedDay,
                $dayCount,
            ));
        }

        $runs = SchedulingPolicy::consecutiveDayRuns($dayCount, $this->sundayClassesEnabled, $this->allowedDays);
        if ($meetingDays !== null && SchedulingPolicy::consecutiveRuleRuns($rule, $this->sundayClassesEnabled, $this->allowedDays) === []) {
            throw new RuntimeException(sprintf(
                '%s is set to meet %s, but %s. Tick other meeting days in Setup Courses%s.',
                $label,
                implode(', ', $meetingDays),
                in_array('Sunday', $meetingDays, true) && ! $this->sundayClassesEnabled
                    ? 'Sunday classes are not enabled for this department'
                    : sprintf('the Preferred Days (%s) leave out some of those days', implode(', ', $this->allowedDays ?? [])),
                $this->allowedDays !== null ? ', or add those days to the Preferred Days' : '',
            ));
        }
        if ($meetingDays !== null) {
            return;
        }

        if ($startDay !== null && ! in_array($startDay, array_column($runs, 0), true)) {
            $ticked = SchedulingPolicy::consecutiveDayRuns($dayCount, true)[SchedulingPolicy::dayIndex($startDay)] ?? [$startDay];
            throw new RuntimeException(sprintf(
                '%s is set to meet %s, but %s. Tick other meeting days in Setup Courses%s.',
                $label,
                implode(', ', $ticked),
                match (true) {
                    in_array('Sunday', $ticked, true) && ! $this->sundayClassesEnabled => 'Sunday classes are not enabled for this department',
                    $this->allowedDays !== null => sprintf('the Preferred Days (%s) leave out some of those days', implode(', ', $this->allowedDays)),
                    default => 'those days run past the end of the teaching week',
                },
                $this->allowedDays !== null ? ', or add those days to the Preferred Days' : '',
            ));
        }

        if ($runs === []) {
            throw new RuntimeException(sprintf(
                '%s needs %d consecutive days, but %s. %s',
                $label,
                $dayCount,
                $this->allowedDays !== null
                    ? sprintf('the Preferred Days (%s) have no %d back-to-back days', implode(', ', $this->allowedDays), $dayCount)
                    : sprintf('the %s teaching week is shorter than that', $this->sundayClassesEnabled ? 'Monday-Sunday' : 'Monday-Saturday'),
                $this->allowedDays !== null
                    ? 'Add the missing days to the Preferred Days, or choose fewer consecutive days.'
                    : 'Choose fewer consecutive days.',
            ));
        }
    }

    private function buildConsecutiveDaysDomain(
        Course $course,
        Collection $matchingRooms,
        int $meetingSlots,
        int $dayCount,
        string $deliveryMode,
        array $runs,
    ): array {
        $placements = [];
        foreach ($this->buildSingleDayDomain($course, $matchingRooms, $meetingSlots, $deliveryMode, false) as $single) {
            $block = $single['blocks'][0];
            unset($block['meeting_type']);
            $key = implode('|', [
                (string) ($single['mode'] ?? ''),
                (string) ($single['room_type'] ?? ''),
                (string) ($single['room_id'] ?? 'tba'),
                ! empty($single['_room_tba']) ? 'tba' : 'room',
                (int) $block['start_slot'],
            ]);
            $placements[$key]['candidate'] ??= $single;
            $placements[$key]['blocks'][(string) $block['day']] = $block;
        }

        $pattern = SchedulingPolicy::consecutivePattern($dayCount);
        $domain = [];
        foreach ($runs as $run) {
            foreach ($placements as $placement) {
                $blocks = [];
                foreach ($run as $day) {
                    if (! isset($placement['blocks'][$day])) {
                        continue 2;
                    }
                    $blocks[] = $placement['blocks'][$day];
                }

                $domain[] = [
                    ...$placement['candidate'],
                    'preferred_pattern' => $pattern,
                    'blocks' => $blocks,
                ];
            }
        }

        return $domain;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function domainCacheKey(int $courseId, string $roomsSignature, array $parts): string
    {
        return $courseId.'|'.$roomsSignature.'|'
            .md5(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param Collection<int, Rooms> $rooms */
    private function roomsSignature(Collection $rooms): string
    {
        $parts = [];
        foreach ($rooms as $room) {
            $parts[] = ((int) $room->id).':'.((string) $room->room_type).':'
                .(((bool) ($room->allow_lecture_usage ?? false)) ? 1 : 0);
        }
        sort($parts);

        return md5(implode(',', $parts));
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
            $blockRoomId = SolverInput::nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );
            $blockMode = $block['mode'] ?? $candidate['mode'] ?? 'on-site';

            if ($this->hasExistingScheduleConflict(
                roomId: $blockRoomId,
                sectionId: $sectionId,
                courseId: (int) $candidate['course_id'],
                day: $block['day'],
                startTime: $block['start_time'],
                endTime: $block['end_time'],
                facultyId: isset($candidate['faculty_id']) ? SolverInput::nullableRoomId($candidate['faculty_id']) : null,
                mode: $blockMode,
                departmentId: $departmentId,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function seededShuffle(array $items, int $seed): array
    {
        $n = count($items);
        if ($n <= 1) {
            return $items;
        }

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
     * @return list<array{0: string, 1: string}> Each entry is [day, mode].
     */
    private function allowedDayModePairsForCourse(Course $course): array
    {
        $days = SchedulingPolicy::teachingDays($this->sundayClassesEnabled);

        if ($this->isFieldCourse($course)) {
            return array_map(
                static fn (string $day): array => [$day, 'field'],
                $days,
            );
        }

        $pairs = [];
        foreach ($days as $day) {
            $pairs[] = [$day, 'on-site'];
            $pairs[] = [$day, 'online'];
        }

        return $pairs;
    }

    private function buildSingleDayDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $deliveryMode,
        bool $isHybrid,
    ): array {
        $startSlots = SchedulingPolicy::generatedStartSlotsForDuration($durationSlots);

        if (empty($startSlots)) {
            return [];
        }

        $domain = [];
        $isLabCourse = $this->isMajorLabCourse($course);
        $hasLectureAndLab = $this->hasLectureAndLabHours($course);
        $allowLectureInVacantLab = $this->isMajorFullLectureCourse($course);
        $singleBlockMeetingType = $this->singleBlockMeetingTypeForCourse($course);
        $isField = $deliveryMode === 'field' || $this->isFieldCourse($course);
        $dayModePairs = $isField && ! $this->isFieldCourse($course)
            ? array_map(
                static fn (string $day): array => [$day, 'field'],
                SchedulingPolicy::WEEKDAYS,
            )
            : $this->allowedDayModePairsForCourse($course);

        foreach ($dayModePairs as [$day, $mode]) {
            if ($isLabCourse && $mode === 'online') {
                continue;
            }
            if ($hasLectureAndLab && $mode === 'online' && $deliveryMode !== 'online') {
                continue;
            }

            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField => 'field',
                default => (string) $course->room_type_required,
            };

            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = $this->labRoomTypes();
                } elseif ($targetRoomType === 'lecture') {
                    $roomTypes = $allowLectureInVacantLab
                        ? ['lecture', 'laboratory']
                        : ['lecture'];
                }
            }

            foreach ($startSlots as $startSlot) {
                $endSlot = $startSlot + $durationSlots;

                if ($isField && $this->endsAfterFieldDayWindow($endSlot)) {
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
                        '_lecture_online_fallback' => $deliveryMode !== 'online',
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
                        fn (Rooms $room): bool => $room->room_type === $roomType
                            && ($roomType !== 'laboratory' || ! $allowLectureInVacantLab || (bool) $room->allow_lecture_usage),
                    );

                    foreach ($roomsForType as $room) {
                        $domain[] = [
                            'course_id' => (int) $course->id,
                            'room_id' => (int) $room->id,
                            'room_type' => $roomType,
                            'preferred_pattern' => null,
                            'mode' => $mode,
                            'is_hybrid' => $mode === 'field' ? false : $isHybrid,
                            '_lab_fallback' => $isLabCourse && SchedulingPolicy::isLabClassroomFallback($roomType, $this->labRoomDepartmentId()),
                            '_lecture_lab_room_fallback' => ! $isLabCourse && $roomType === 'laboratory',
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

                if ($mode === 'on-site' && $isLabCourse) {
                    $domain[] = [
                        'course_id' => (int) $course->id,
                        'room_id' => null,
                        'room_type' => 'laboratory',
                        'preferred_pattern' => null,
                        'mode' => 'on-site',
                        'is_hybrid' => $isHybrid,
                        '_room_tba' => true,
                        '_lab_fallback' => false,
                        'blocks' => [array_merge($this->makeBlock(day: $day, startSlot: $startSlot, endSlot: $endSlot), [
                            'room_id' => null,
                            'room_type' => 'laboratory',
                            'mode' => 'on-site',
                            'meeting_type' => $singleBlockMeetingType,
                            '_room_tba' => true,
                        ])],
                    ];
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

    /**
     * @param  list<string>  $allowedDays
     */
    private function filterDomainByDays(array $domain, array $allowedDays): array
    {
        $allowed = array_flip($allowedDays);

        return array_values(array_filter(
            $domain,
            static function (array $candidate) use ($allowed): bool {
                foreach ($candidate['blocks'] ?? [] as $block) {
                    if (! isset($allowed[(string) ($block['day'] ?? '')])) {
                        return false;
                    }
                }

                return true;
            },
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

        return array_intersect_key(
            $this->snapshot()->forcedDaysByCourseId,
            array_fill_keys(array_map('intval', $courseIds), true),
        );
    }

    private function buildDefaultLectureLabDomain(
        Course $course,
        Collection $matchingRooms,
        string $deliveryMode,
        bool $isHybrid,
        ?array $anchoredSchedule = null,
        ?int $lectureSlots = null,
        ?int $laboratorySlots = null,
    ): array {
        if ($this->isFieldCourse($course)) {
            return [];
        }

        $lectureSlots ??= SchedulingPolicy::lectureComponentSlots($course);
        $labSlots = $laboratorySlots ?? $this->laboratoryComponentSlots($course);

        if ($lectureSlots <= 0 || $labSlots <= 0) {
            return [];
        }

        $labRoomTypes = $this->labRoomTypes();
        $labRooms = $matchingRooms->filter(
            static fn (Rooms $room): bool => in_array($room->room_type, $labRoomTypes, true),
        );

        $lectureOptions = $this->splitLectureOptions(
            matchingRooms: $matchingRooms,
            isHybrid: $isHybrid,
            forceOnline: $deliveryMode === 'online',
        );

        $labOptions = $labRooms
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => (string) $room->room_type,
                'mode' => 'on-site',
            ])
            ->values()
            ->all();
        $labOptions[] = [
            'room_id' => null,
            'room_type' => 'laboratory',
            'mode' => 'on-site',
            '_room_tba' => true,
        ];

        $dayPairs = $this->splitLectureLabDayPairs(
            course: $course,
            isHybrid: $isHybrid,
        );

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

        foreach ($dayPairs as $dayPairIndex => [$day1, $day2]) {
            foreach ($componentOrders as [$firstComponent, $secondComponent]) {
                $day1StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($firstComponent['slots']);
                $day2StartSlots = SchedulingPolicy::generatedStartSlotsForDuration($secondComponent['slots']);

                if (empty($day1StartSlots) || empty($day2StartSlots)) {
                    continue;
                }

                $firstOptions = $firstComponent['type'] === 'laboratory' ? $labOptions : $lectureOptions;
                $secondOptions = $secondComponent['type'] === 'laboratory' ? $labOptions : $lectureOptions;

                $isSaturdayPair = $day1 === 'Saturday' || $day2 === 'Saturday';
                if ($isSaturdayPair) {
                    $startPairs = [];
                    foreach ($day1StartSlots as $d1) {
                        foreach ($day2StartSlots as $d2) {
                            $startPairs[] = [(int) $d1, (int) $d2];
                        }
                    }
                } else {
                    $startPairs = $this->rankedSplitStartPairs(
                        firstStartSlots: $day1StartSlots,
                        secondStartSlots: $day2StartSlots,
                        limit: null,
                    );
                }

                foreach ($startPairs as $startPairIndex => [$day1Start, $day2Start]) {
                    $day1End = $day1Start + $firstComponent['slots'];
                    $day2End = $day2Start + $secondComponent['slots'];

                    if ($isHybrid && $day1 === $day2) {
                        continue;
                    }

                    if ($day1 === $day2 && $day1Start < $day2End && $day2Start < $day1End) {
                        continue;
                    }

                    $rotation = ($dayPairIndex * 7) + $startPairIndex;
                    $preferredRoomId = $this->preferredRoomIdsByCourseId[(int) $course->id] ?? null;
                    $windowedFirst = $this->boundedRoomOptions($firstOptions, $rotation, $preferredRoomId);
                    $windowedSecond = $this->boundedRoomOptions($secondOptions, $rotation, $preferredRoomId);

                    foreach ($windowedFirst as $option1) {
                        foreach ($windowedSecond as $option2) {
                            $domain[] = [
                                'course_id' => (int) $course->id,
                                'room_id' => $option1['room_id'],
                                'room_type' => $option1['room_type'],
                                'preferred_pattern' => null,
                                'mode' => $option1['mode'],
                                'is_hybrid' => $isHybrid,
                                '_split_lecture_online_default' => true,
                                '_all_saturday_split' => $day1 === 'Saturday' && $day2 === 'Saturday',
                                '_room_tba' => (bool) (($option1['_room_tba'] ?? false) || ($option2['_room_tba'] ?? false)),
                                '_lecture_online_fallback' => (bool) (($option1['_lecture_online_fallback'] ?? false) || ($option2['_lecture_online_fallback'] ?? false)),
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

    private function splitLectureLabDayPairs(
        Course $course,
        bool $isHybrid = false,
    ): array
    {
        $onSiteDays = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            array_filter(
                $this->allowedDayModePairsForCourse($course),
                static fn (array $pair): bool => $pair[1] === 'on-site',
            ),
        )));

        $pairs = [];
        foreach (SchedulingPolicy::autoSplitDayPairs() as $days) {
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
            ['Monday', 'Saturday'],
            ['Tuesday', 'Saturday'],
            ['Wednesday', 'Saturday'],
            ['Thursday', 'Saturday'],
            ['Friday', 'Saturday'],
            ['Monday', 'Sunday'],
            ['Tuesday', 'Sunday'],
            ['Wednesday', 'Sunday'],
            ['Thursday', 'Sunday'],
            ['Friday', 'Sunday'],
            ['Saturday', 'Sunday'],
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

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function boundedRoomOptions(array $options, int $rotation, ?int $keepRoomId = null): array
    {
        $rooms = [];
        $fallbacks = [];
        foreach ($options as $option) {
            if (($option['room_id'] ?? null) === null) {
                $fallbacks[] = $option;
            } else {
                $rooms[] = $option;
            }
        }

        $count = count($rooms);
        if ($count > self::SPLIT_ROOM_OPTIONS_PER_SLOT) {
            $window = [];
            for ($offset = 0; $offset < self::SPLIT_ROOM_OPTIONS_PER_SLOT; $offset++) {
                $window[] = $rooms[abs($rotation + $offset) % $count];
            }
            if ($keepRoomId !== null && ! in_array($keepRoomId, array_column($window, 'room_id'), true)) {
                foreach ($rooms as $room) {
                    if ($room['room_id'] === $keepRoomId) {
                        $window[] = $room;
                        break;
                    }
                }
            }
            $rooms = $window;
        }

        return [...$rooms, ...$fallbacks];
    }

    private function splitLectureOptions(
        Collection $matchingRooms,
        bool $isHybrid,
        bool $forceOnline = false,
    ): array
    {
        if ($isHybrid || $forceOnline) {
            return [[
                'room_id' => null,
                'room_type' => 'online',
                'mode' => 'online',
                '_lecture_online_fallback' => false,
            ]];
        }

        $options = $matchingRooms
            ->filter(static fn (Rooms $room): bool => $room->room_type === 'lecture')
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => 'lecture',
                'mode' => 'on-site',
                '_lecture_online_fallback' => false,
            ])
            ->values()
            ->all();

        $options[] = [
            'room_id' => null,
            'room_type' => 'online',
            'mode' => 'online',
            '_lecture_online_fallback' => true,
        ];

        return $options;
    }

    private function filterLectureLabDomainByAnchor(array $domain, array $anchoredSchedule): array
    {
        $anchorDay = (string) ($anchoredSchedule['day'] ?? '');
        $anchorStart = (string) ($anchoredSchedule['start_time'] ?? '');
        $anchorEnd = (string) ($anchoredSchedule['end_time'] ?? '');
        $anchorRoomId = SolverInput::nullableRoomId($anchoredSchedule['room_id'] ?? null);

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

                    $blockRoomId = SolverInput::nullableRoomId(
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
    ): array {
        $domain = [];

        foreach ($this->balancedSplitDayPairs($course) as [$day1, $day2]) {
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
                ),
            );
        }

        return $domain;
    }

    private function buildFlexibleHybridSplitDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
    ): array {
        $domain = [];
        foreach ($this->balancedSplitDayPairs($course) as [$day1, $day2]) {
            $domain = array_merge(
                $domain,
                $this->buildHybridSplitPatternDomain(
                    course: $course,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    preferredPattern: sprintf('days:%d-%d', $this->dayIndex($day1), $this->dayIndex($day2)),
                ),
            );
        }

        return $domain;
    }

    private function buildHybridSplitPatternDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
    ): array {
        if ($durationSlots < 2 || $durationSlots % 2 !== 0 || ! SchedulingPolicy::hybridSplitEligible($course)) {
            return [];
        }

        [$day1, $day2] = $this->patternDays($preferredPattern);
        $allowedDays = array_unique(array_column(
            $this->allowedDayModePairsForCourse($course),
            0,
        ));
        if (! in_array($day1, $allowedDays, true) || ! in_array($day2, $allowedDays, true) || $day1 === $day2) {
            return [];
        }

        $halfSlots = intdiv($durationSlots, 2);
        $starts = SchedulingPolicy::generatedStartSlotsForDuration($halfSlots);
        if ($starts === []) {
            return [];
        }

        $physicalOptions = $matchingRooms
            ->filter(static fn (Rooms $room): bool => $room->room_type === 'lecture'
                || SchedulingPolicy::laboratoryServesLecture($course, $room))
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => (string) $room->room_type,
                'mode' => 'on-site',
            ])
            ->values()
            ->all();
        if ($physicalOptions === []) {
            return [];
        }

        $onlineOption = [
            'room_id' => null,
            'room_type' => 'online',
            'mode' => 'online',
        ];
        $domain = [];
        foreach ($starts as $start1) {
            foreach ([$start1] as $start2) {
                foreach ([false, true] as $onlineFirst) {
                    $first = $onlineFirst ? $onlineOption : null;
                    $second = $onlineFirst ? null : $onlineOption;
                    $firstOptions = $onlineFirst ? [$first] : $physicalOptions;
                    $secondOptions = $onlineFirst ? $physicalOptions : [$second];
                    foreach ($firstOptions as $option1) {
                        foreach ($secondOptions as $option2) {
                            $physical = $onlineFirst ? $option2 : $option1;
                            $domain[] = [
                                'course_id' => (int) $course->id,
                                'room_id' => $physical['room_id'],
                                'room_type' => $physical['room_type'],
                                'preferred_pattern' => $preferredPattern,
                                'mode' => $physical['mode'],
                                'is_hybrid' => true,
                                '_hybrid_online_first' => $onlineFirst,
                                'blocks' => [
                                    array_merge($this->makeBlock($day1, (int) $start1, (int) $start1 + $halfSlots), [
                                        'room_id' => $option1['room_id'],
                                        'room_type' => $option1['room_type'],
                                        'mode' => $option1['mode'],
                                        'meeting_type' => 'lecture',
                                    ]),
                                    array_merge($this->makeBlock($day2, (int) $start2, (int) $start2 + $halfSlots), [
                                        'room_id' => $option2['room_id'],
                                        'room_type' => $option2['room_type'],
                                        'mode' => $option2['mode'],
                                        'meeting_type' => 'lecture',
                                    ]),
                                ],
                            ];
                        }
                    }
                }
            }
        }

        return $domain;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function balancedSplitDayPairs(Course $course): array
    {
        $courseDays = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            $this->allowedDayModePairsForCourse($course),
        )));
        $days = array_values(array_filter(
            SchedulingPolicy::teachingDays($this->sundayClassesEnabled),
            fn (string $day): bool => in_array($day, $courseDays, true)
                && ($this->allowedDays === null || in_array($day, $this->allowedDays, true)),
        ));

        $pairs = array_values(array_filter(
            SchedulingPolicy::autoSplitDayPairs(),
            static fn (array $pair): bool => in_array($pair[0], $days, true) && in_array($pair[1], $days, true),
        ));
        if ($this->allowFridaySaturdaySplit
            && in_array('Friday', $days, true)
            && in_array('Saturday', $days, true)) {
            $pairs[] = ['Friday', 'Saturday'];
        }
        if ($pairs !== [] || $this->allowedDays === null) {
            return $pairs;
        }

        $spaced = [];
        $adjacent = [];
        foreach ($days as $i => $day1) {
            foreach (array_slice($days, $i + 1) as $day2) {
                if ($this->dayIndex($day2) - $this->dayIndex($day1) > 1) {
                    $spaced[] = [$day1, $day2];
                } else {
                    $adjacent[] = [$day1, $day2];
                }
            }
        }

        return [...$spaced, ...$adjacent];
    }

    private function buildPatternDomainWithFallbacks(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
        string $deliveryMode,
        bool $isHybrid,
        bool $requireBalancedDurations = false,
    ): array {
        $primaryDomain = $this->buildPatternDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            preferredPattern: $preferredPattern,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            requireBalancedDurations: $requireBalancedDurations,
        );

        $alternativePatternDomain = [];
        if ($durationSlots >= 2) {
            $flexibleSplitDomain = $this->buildFlexibleBalancedSplitDomain(
                course: $course,
                matchingRooms: $matchingRooms,
                durationSlots: $durationSlots,
                deliveryMode: $deliveryMode,
                isHybrid: $isHybrid,
            );

            foreach ($flexibleSplitDomain as $candidate) {
                if (($candidate['preferred_pattern'] ?? null) !== $preferredPattern) {
                    $candidate['_pattern_fallback'] = true;
                    $alternativePatternDomain[] = $candidate;
                }
            }
        }

        return array_merge($primaryDomain, $alternativePatternDomain);
    }

    private function buildPatternDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
        string $deliveryMode,
        bool $isHybrid,
        bool $requireBalancedDurations = false,
    ): array {
        if ($durationSlots < 2) {
            return [];
        }

        [$day1, $day2] = $this->patternDays($preferredPattern);

        $allowedDays = array_unique(
            array_column($this->allowedDayModePairsForCourse($course), 0),
        );

        if (! in_array($day1, $allowedDays, true) || ! in_array($day2, $allowedDays, true)) {
            return [];
        }

        $domain = [];
        $isField = $deliveryMode === 'field' || $this->isFieldCourse($course);
        $isLabCourse = $this->isMajorLabCourse($course);
        $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
        $lecHours = (int) ($course->lecture_hours ?? 0);
        $labHours = (int) ($course->lab_hours ?? 0);
        $hasBothComponents = ! $requireBalancedDurations && $isMajor && $lecHours > 0 && $labHours > 0;

        if ($isHybrid && $hasBothComponents && $day1 === $day2) {
            return [];
        }

        $modes = match (true) {
            $isField => ['field'],
            $hasBothComponents => ['on-site'],
            default => ['on-site', 'online'],
        };

        foreach ($modes as $mode) {
            if ($isLabCourse && $mode === 'online') {
                continue;
            }
            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField => 'field',
                default => (string) $course->room_type_required,
            };

            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = $this->labRoomTypes();
                } elseif ($targetRoomType === 'lecture') {
                    $roomTypes = $this->isMajorFullLectureCourse($course)
                        ? ['lecture', 'laboratory']
                        : ['lecture'];
                }
            }

            $lectureSlots = $lecHours * 2;
            $labSlots = $labHours * 6;

            $durations = [];
            if ($hasBothComponents) {
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

                $startPairs = $this->rankedSplitStartPairs(
                    firstStartSlots: $day1StartSlots,
                    secondStartSlots: $day2StartSlots,
                    limit: null,
                );

                if ($requireBalancedDurations) {
                    $startPairs = array_values(array_filter(
                        $startPairs,
                        static fn (array $pair): bool => $pair[0] === $pair[1],
                    ));
                }

                foreach ($startPairs as [$day1Start, $day2Start]) {
                    $day1End = $day1Start + $day1Duration;
                    $day2End = $day2Start + $day2Duration;

                    if ($isField && (
                        $this->endsAfterFieldDayWindow($day1End)
                        || $this->endsAfterFieldDayWindow($day2End)
                    )) {
                        continue;
                    }

                    if ($hasBothComponents && $mode === 'on-site') {
                        $labRoomTypes = $this->labRoomTypes();
                        $labOptions = $matchingRooms
                            ->filter(static fn (Rooms $room): bool => in_array($room->room_type, $labRoomTypes, true))
                            ->map(static fn (Rooms $room): array => [
                                'room_id' => (int) $room->id,
                                'room_type' => (string) $room->room_type,
                                'mode' => 'on-site',
                            ])
                            ->values()
                            ->all();
                        $labOptions[] = [
                            'room_id' => null,
                            'room_type' => 'laboratory',
                            'mode' => 'on-site',
                            '_room_tba' => true,
                        ];
                        $lectureOptions = $this->splitLectureOptions(
                            matchingRooms: $matchingRooms,
                            isHybrid: $isHybrid,
                            forceOnline: $deliveryMode === 'online',
                        );
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
                                    '_split_lecture_online_default' => true,
                                    '_room_tba' => (bool) (($option1['_room_tba'] ?? false) || ($option2['_room_tba'] ?? false)),
                                    '_lecture_online_fallback' => (bool) (($option1['_lecture_online_fallback'] ?? false) || ($option2['_lecture_online_fallback'] ?? false)),
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
                                '_lecture_online_fallback' => $deliveryMode !== 'online',
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
                            $allowLectureInVacantLab = $this->isMajorFullLectureCourse($course);
                            $roomsForType = $matchingRooms->filter(
                                static fn (Rooms $room): bool => $room->room_type === $roomType
                                    && ($roomType !== 'laboratory' || ! $allowLectureInVacantLab || (bool) $room->allow_lecture_usage),
                            );

                            foreach ($roomsForType as $room) {
                                $domain[] = [
                                    'course_id' => (int) $course->id,
                                    'room_id' => (int) $room->id,
                                    'room_type' => $roomType,
                                    'preferred_pattern' => $preferredPattern,
                                    'mode' => $mode,
                                    'is_hybrid' => $mode === 'field' ? false : $isHybrid,
                                    '_lab_fallback' => $isLabCourse && SchedulingPolicy::isLabClassroomFallback($roomType, $this->labRoomDepartmentId()),
                                    '_lecture_lab_room_fallback' => ! $isLabCourse && $roomType === 'laboratory',
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

                        if ($mode === 'on-site' && $isLabCourse && ! $hasBothComponents) {
                            $domain[] = [
                                'course_id' => (int) $course->id,
                                'room_id' => null,
                                'room_type' => 'laboratory',
                                'preferred_pattern' => $preferredPattern,
                                'mode' => 'on-site',
                                'is_hybrid' => $isHybrid,
                                '_room_tba' => true,
                                '_lab_fallback' => false,
                                'blocks' => [
                                    array_merge($this->makeBlock(day: $day1, startSlot: $day1Start, endSlot: $day1End), [
                                        'room_id' => null, 'room_type' => 'laboratory', 'mode' => 'on-site',
                                        'meeting_type' => 'laboratory', '_room_tba' => true,
                                    ]),
                                    array_merge($this->makeBlock(day: $day2, startSlot: $day2Start, endSlot: $day2End), [
                                        'room_id' => null, 'room_type' => 'laboratory', 'mode' => 'on-site',
                                        'meeting_type' => 'laboratory', '_room_tba' => true,
                                    ]),
                                ],
                            ];
                        }
                    }
                }
            }
        }

        return $domain;
    }

    /**
     * @param  list<int>  $firstStartSlots
     * @param  list<int>  $secondStartSlots
     * @return list<array{0: int, 1: int}>
     */
    private function rankedSplitStartPairs(
        array $firstStartSlots,
        array $secondStartSlots,
        ?int $limit = null,
    ): array
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

        $rankedPairs = array_map(
            static fn (array $pair): array => [$pair['first'], $pair['second']],
            $limit === null ? $pairs : array_slice($pairs, 0, $limit),
        );

        return $rankedPairs;
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
        return $this->slotToTime($endSlot) > SchedulingPolicy::fieldDayEndTime();
    }

    private function conflictsWithTentativeAssignments(
        array $candidate,
        array $assignments,
        ?int $sectionId = null,
        ?int $departmentId = null,
    ): bool {
        foreach ($candidate['blocks'] as $candidateBlock) {
            $day = $candidateBlock['day'];

            foreach ($assignments as $assigned) {
                foreach ($assigned['blocks'] as $assignedBlock) {
                    if ($day !== $assignedBlock['day']) {
                        continue;
                    }

                    $overlaps =
                        $candidateBlock['start_slot'] < $assignedBlock['end_slot']
                        && $assignedBlock['start_slot'] < $candidateBlock['end_slot'];

                    if ($overlaps) {
                        return true;
                    }

                }
            }

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

        $candidateRoomType = $candidate['room_type'] ?? null;

        foreach ($candidate['blocks'] as $block) {
            $blockRoomId = SolverInput::nullableRoomId(
                array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null)
            );
            $blockMode = $block['mode'] ?? $candidate['mode'] ?? 'on-site';
            $blockRoomType = $block['room_type'] ?? $candidateRoomType;

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
                (int) $section->semester_id,
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
                courseId: (int) $candidate['course_id'],
                day: $block['day'],
                startTime: $block['start_time'],
                endTime: $block['end_time'],
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
            'semester_id' => (int) $section->semester_id,
            'section_id' => (int) $section->id,
            'department_id' => (int) $section->department_id,
        ]);
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
                $blockRoomId = SolverInput::nullableRoomId(
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
                $isClassroomRoomBlock = $isPhysicalRoomBlock
                    && ($block['meeting_type'] ?? null) !== 'laboratory'
                    && $blockRoomType !== 'laboratory'
                    && ($this->roomTypes[$blockRoomId] ?? null) !== 'laboratory';

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
                        'is_classroom' => $isClassroomRoomBlock,
                    ];
                    $physicalRoomBlockTotal++;

                    $score += ($this->existingRoomUseCounts[$blockRoomId] ?? 0) * 3;
                }

                $blockDurations[] = $block['end_slot'] - $block['start_slot'];

                $prefersLateWeek = $this->prefersLateWeekPlacement($assignment)
                    || $this->isRegularFridaySaturdayPair($assignment);
                $isWeekend = in_array($block['day'], ['Saturday', 'Sunday'], true);

                if ($isWeekend && ! $prefersLateWeek) {
                    $score += 200;
                }

                if ($assignment['_weekday_physical_available'] ?? false) {
                    $migratedToWeekend = $isWeekend && ! $prefersLateWeek;

                    if ($migratedToWeekend) {
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

        $score += $this->roomFairness()->penalty($generatedDeliveryCountsBySection, $this->existingSectionDeliveryCounts);

        if ($physicalRoomBlockTotal > 0) {
            $uniquePhysicalRooms = count($physicalRoomBlockCounts);
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

                        if (($previous['is_classroom'] ?? false) && ($roomDayBlock['is_classroom'] ?? false)) {
                            $score += $this->classroomAwkwardGapPenalty($gapSlots);
                        }
                    }
                }

                $previous = $roomDayBlock;
            }
        }

        foreach ($byDay as $dayName => $dayAssignments) {
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

        foreach ($assignments as $assignment) {
            if ($assignment['_pattern_fallback'] ?? false) {
                $score += 1500;
            }

            if ($assignment['_single_session_fallback'] ?? false) {
                $score += 3000;
            }

            if (
                ($assignment['is_hybrid'] ?? false)
                &&
                ($assignment['_split_lecture_online_default'] ?? false)
                && $this->candidateContainsLaboratoryBlock($assignment)
                && ! $this->hasOnlineLectureBlock($assignment)
            ) {
                $score += 10000;
            }

            if ($assignment['_lecture_lab_room_fallback'] ?? false) {
                $score += 250;
            }

            $score += $this->candidateMixedModeCourseOverlaps($assignment, (int) ($assignment['section_id'] ?? 0))
                * SchedulingPolicy::SOFT_MIXED_MODE_COURSE_OVERLAP_PENALTY;

            foreach ($assignment['blocks'] as $block) {
                if (
                    ($block['mode'] ?? $assignment['mode'] ?? '') === 'online'
                    && ($block['meeting_type'] ?? null) !== 'lecture'
                ) {
                    $score += SchedulingPolicy::SOFT_ONLINE_FALLBACK_PENALTY;
                }
            }
        }

        if ($courses !== null) {
            foreach ($assignments as $assignment) {
                $courseObj = $courses[(int) $assignment['course_id']] ?? null;
                if ($courseObj === null || ! $this->isMajorLabCourse($courseObj)) {
                    continue;
                }

                if ($assignment['_lab_fallback'] ?? false) {
                    $score += SchedulingPolicy::SOFT_LAB_FALLBACK_PENALTY;
                }
            }
        }

        return $score;
    }

    private function toPublicScheduleRows(array $assignments): array
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            $hasMultipleBlocks = count($assignment['blocks']) > 1;
            $splitGroupId = $hasMultipleBlocks ? (string) Str::uuid() : null;

            foreach ($assignment['blocks'] as $index => $block) {
                $row = [
                    'semester_id' => (int) $assignment['semester_id'],
                    'section_id' => (int) $assignment['section_id'],
                    'course_id' => (int) $assignment['course_id'],
                    'faculty_id' => null,
                    'room_id' => SolverInput::nullableRoomId(
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

                if ($assignment['_single_session_fallback'] ?? false) {
                    $row['split_session_fallback'] = true;
                }

                if (($assignment['_lecture_online_fallback'] ?? false) && $row['mode'] === 'online') {
                    $row['lecture_online_fallback'] = true;
                }

                if ($hasMultipleBlocks) {
                    $row['split_group_id'] = $splitGroupId;
                    $row['meeting_index'] = $index + 1;

                    $courseId = (int) $assignment['course_id'];
                    $courseObj = $this->loadedCoursesById[$courseId] ?? null;
                    if (SchedulingPolicy::consecutiveDayCount($assignment['preferred_pattern'] ?? null) !== null) {
                        $row['meeting_type'] = null;
                    } elseif (! empty($block['meeting_type'])) {
                        $row['meeting_type'] = $block['meeting_type'];
                    } elseif ($courseObj && $courseObj->lab_hours > 0) {
                        $blockSlots = $block['end_slot'] - $block['start_slot'];
                        if ($blockSlots === $this->laboratoryComponentSlots($courseObj)) {
                            $row['meeting_type'] = 'laboratory';
                        } elseif ($blockSlots === (int) $courseObj->lecture_hours * SchedulingPolicy::LECTURE_SLOTS_PER_UNIT) {
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
        $this->deadEndsByCourseId = [];
        $this->metricsStartedAt = microtime(true);
        $this->metricsVariableCount = 0;
        $this->metricsCandidateCountBefore = 0;
        $this->metricsCandidateCountAfter = 0;
        $this->databaseValidityCache = [];
        $this->existingScheduleIndex = [];
        $this->existingRoomUseCounts = [];
        $this->existingRoomDayUseSlots = [];
        $this->existingSectionDeliveryCounts = [];
        $this->roomFairness()->reset();
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

        if (! $section->academicSemester) {
            throw new InvalidArgumentException(sprintf(
                'Section %d is not linked to an academic semester.',
                $section->id,
            ));
        }

        if ($section->academicSemester->semester !== $section->semester) {
            throw new InvalidArgumentException(sprintf(
                'Section %d semester does not match its academic semester.',
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

        if ($this->requirementsByCourseId !== []) {
            $types = [];
            foreach ($courses as $course) {
                foreach ($this->requirementsByCourseId[(int) $course->id] ?? [] as $requirement) {
                    foreach ((array) ($requirement['eligible_room_types'] ?? []) as $roomType) {
                        if (! in_array($roomType, $types, true)) {
                            $types[] = (string) $roomType;
                        }
                    }
                }
            }
            if (! in_array('online', $types, true)) {
                $types[] = 'online';
            }

            return $types;
        }

        $types = $courses
            ->map(fn (Course $course): string => $this->targetRoomTypeForCourse($course, $deliveryMode))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $hasLabCourse = $courses->contains(
            fn (Course $course): bool => $this->isMajorLabCourse($course),
        );
        if ($hasLabCourse && ! in_array('lecture', $types, true)) {
            $types[] = 'lecture';
        }

        $hasMajorFullLectureCourse = $courses->contains(
            fn (Course $course): bool => $this->isMajorFullLectureCourse($course),
        );
        if ($hasMajorFullLectureCourse && ! in_array('laboratory', $types, true)) {
            $types[] = 'laboratory';
        }

        if (! in_array('online', $types, true)) {
            $types[] = 'online';
        }

        return $types;
    }

    /** @param list<array<string, mixed>> $requirements */
    private function filterDomainByRequirements(array $domain, array $requirements): array
    {
        $eligible = [];
        $allowedModes = [];
        $allowLectureLabFallback = false;
        foreach ($requirements as $requirement) {
            foreach ((array) ($requirement['eligible_room_types'] ?? []) as $type) {
                $eligible[(string) $type] = true;
            }
            foreach ((array) ($requirement['allowed_delivery_modes'] ?? []) as $mode) {
                $allowedModes[(string) $mode] = true;
            }
            $allowLectureLabFallback = $allowLectureLabFallback
                || (bool) ($requirement['allow_lecture_laboratory_fallback'] ?? false);
        }

        return array_values(array_filter($domain, function (array $candidate) use ($eligible, $allowedModes, $allowLectureLabFallback): bool {
            foreach ($candidate['blocks'] ?? [] as $block) {
                $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site');
                if ($allowedModes !== [] && ! isset($allowedModes[$mode])) {
                    return false;
                }
                $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? '');
                if (isset($eligible[$roomType])) {
                    continue;
                }
                $blockRoomId = array_key_exists('room_id', $block) ? $block['room_id'] : ($candidate['room_id'] ?? null);
                if ($roomType === 'laboratory' && $blockRoomId === null && $mode === 'on-site') {
                    continue;
                }
                if ($roomType === 'online' && isset($allowedModes['online'])) {
                    continue;
                }
                if ($allowLectureLabFallback && $roomType === 'laboratory' && isset($eligible['lecture'])) {
                    continue;
                }

                return false;
            }

            return true;
        }));
    }

    /** @param list<array<string, mixed>> $requirements */
    private function requirementsRequireFieldDelivery(array $requirements): bool
    {
        if (count($requirements) !== 1) {
            return false;
        }

        return ($requirements[0]['component_type'] ?? null) === 'field'
            && in_array('field', (array) ($requirements[0]['allowed_delivery_modes'] ?? []), true);
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
        return SchedulingPolicy::isFieldCourse($course, fieldCourseCodes: $this->inputSnapshot?->fieldCourseCodes ?? []);
    }

    private function isMajorLabCourse(Course $course): bool
    {
        if ($this->isFieldCourse($course)) {
            return false;
        }

        return SchedulingPolicy::isLaboratoryCourse($course);
    }

    /**
     * @return list<string>
     */
    private function labRoomTypes(): array
    {
        return SchedulingPolicy::labRoomTypes($this->labRoomDepartmentId());
    }

    private function labRoomDepartmentId(): ?int
    {
        $departmentId = (int) ($this->inputSnapshot?->departmentId ?? 0);

        return $departmentId > 0 ? $departmentId : null;
    }

    private function laboratoryComponentSlots(Course $course): int
    {
        return SchedulingPolicy::laboratoryComponentSlots($course, $this->departmentLabSettings);
    }

    private function hasLectureAndLabHours(Course $course): bool
    {
        return (int) ($course->lecture_hours ?? 0) > 0
            && (int) ($course->lab_hours ?? 0) > 0;
    }

    private function isMajorFullLectureCourse(Course $course): bool
    {
        if ($this->isFieldCourse($course)) {
            return false;
        }

        return SchedulingPolicy::isLectureOnlyMajor($course);
    }

    private function courseSchedulingPriority(Course $course): int
    {
        if ($this->isMajorLabCourse($course)) {
            return 0;
        }

        return $this->isMajorFullLectureCourse($course) ? 1 : 2;
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

    private function candidateHybridSplitOrderRank(array $candidate, int $sectionId): int
    {
        if (! array_key_exists('_hybrid_online_first', $candidate)) {
            return 0;
        }

        $preferOnlineFirst = (($sectionId + (int) ($candidate['course_id'] ?? 0)) % 2) === 1;

        return (bool) $candidate['_hybrid_online_first'] === $preferOnlineFirst ? 0 : 1;
    }

    private function candidateRegularDayPairIndex(array $candidate): ?int
    {
        $blocks = $candidate['blocks'] ?? [];
        if (count($blocks) !== 2) {
            return null;
        }

        $days = [(string) ($blocks[0]['day'] ?? ''), (string) ($blocks[1]['day'] ?? '')];
        sort($days);

        return match ($days) {
            ['Monday', 'Wednesday'] => 0,
            ['Thursday', 'Tuesday'] => 1,
            ['Friday', 'Saturday'] => 2,
            default => null,
        };
    }

    private function isRegularFridaySaturdayPair(array $candidate): bool
    {
        if ($this->candidateRegularDayPairIndex($candidate) !== 2) {
            return false;
        }

        if (($candidate['preferred_pattern'] ?? null) === 'FS' && empty($candidate['_pattern_fallback'])) {
            return true;
        }

        return $this->allowFridaySaturdaySplit && ! empty($candidate['preferred_pattern']);
    }

    private function isLateWeekSaturdayMeeting(array $candidate): bool
    {
        return $this->protectsSplitCapacity
            && ($candidate['blocks'][0]['day'] ?? null) === 'Saturday'
            && $this->prefersLateWeekPlacement($candidate);
    }

    private function candidateDayPairRotationRank(array $candidate, int $sectionId): int
    {
        if (empty($candidate['preferred_pattern'])) {
            return 0;
        }

        $pairIndex = $this->candidateRegularDayPairIndex($candidate);
        $pairCount = $this->allowFridaySaturdaySplit ? 3 : 2;
        if ($pairIndex === null || $pairIndex >= $pairCount) {
            return 0;
        }

        $anchor = abs(($sectionId * 17) + ((int) ($candidate['course_id'] ?? 0) * 31)) % $pairCount;

        return ($pairIndex - $anchor + $pairCount) % $pairCount;
    }

    /**
     * @param  array<string, int>  $dayLoads
     */
    private function candidateDayPairLoadRank(array $candidate, array $dayLoads, int $sectionId): int
    {
        $rotation = $this->candidateDayPairRotationRank($candidate, $sectionId);
        if (empty($candidate['preferred_pattern']) || $this->candidateRegularDayPairIndex($candidate) === null) {
            return $rotation;
        }

        $pairLoad = 0;
        foreach ($candidate['blocks'] as $block) {
            $pairLoad += $dayLoads[(string) ($block['day'] ?? '')] ?? 0;
        }

        return ($pairLoad * 3) + $rotation;
    }

    private function candidateAllocationPriority(array $candidate, int $sectionId): int
    {
        $isPatternFallback = (bool) ($candidate['_pattern_fallback'] ?? false);
        $isSingleSessionFallback = (bool) ($candidate['_single_session_fallback'] ?? false);

        $mode = (string) ($candidate['mode'] ?? 'on-site');

        $containsWeekend = $this->candidateContainsWeekendBlock($candidate)
            && ! $this->isRegularFridaySaturdayPair($candidate)
            && ! $this->isLateWeekSaturdayMeeting($candidate);

        if ($mode === 'field' || $this->candidateContainsFieldBlock($candidate)) {
            return $containsWeekend ? 3 : 0;
        }

        if ($candidate['_room_tba'] ?? false) {
            return 7;
        }

        if (($candidate['_split_lecture_online_default'] ?? false) && $this->candidateContainsLaboratoryBlock($candidate)) {
            if (($candidate['_all_saturday_split'] ?? false)) {
                return 1;
            }
            if ($this->hasOnlineLectureBlock($candidate)) {
                return (bool) ($candidate['is_hybrid'] ?? false) ? 0 : 8;
            }

            return $containsWeekend ? 3 : 1;
        }

        if ($candidate['_lecture_lab_room_fallback'] ?? false) {
            return $containsWeekend ? 5 : 3;
        }

        if ($this->candidateContainsLaboratoryBlock($candidate)) {
            return $mode === 'online'
                ? 8
                : ($containsWeekend ? 3 : 0);
        }

        if ($mode === 'online') {
            $onlineTier = 10;
            if ($isPatternFallback) {
                $onlineTier = 12;
            } elseif ($isSingleSessionFallback) {
                $onlineTier = 14;
            }

            return $onlineTier;
        }

        if ($candidate['_lab_fallback'] ?? false) {
            return 5;
        }

        $physicalTier = 0;
        if ($isPatternFallback) {
            $physicalTier = 1;
        } elseif ($isSingleSessionFallback) {
            $physicalTier = 2;
        }

        return $containsWeekend ? $physicalTier + 3 : $physicalTier;
    }

    /**
     * @param  list<array<string, mixed>>  $domain
     */
    private function countPhysicalRoomOptions(array $domain): int
    {
        $roomIds = [];

        foreach ($domain as $candidate) {
            foreach ($candidate['blocks'] ?? [] as $block) {
                $roomId = array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($candidate['room_id'] ?? null);

                if ($roomId !== null) {
                    $roomIds[(int) $roomId] = true;
                }
            }
        }

        return $roomIds === [] ? PHP_INT_MAX : count($roomIds);
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
            $roomId = SolverInput::nullableRoomId(
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
            $roomId = SolverInput::nullableRoomId(
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

    private function preloadExistingSchedules(
        int $semesterId,
        int $sectionId,
        int $departmentId,
        array $replaceCourseIds = [],
        array $tentativeSchedules = [],
    ): void {
        $this->existingScheduleIndex = [];
        $this->existingRoomUseCounts = [];
        $this->existingRoomDayUseSlots = [];
        $this->existingSectionDeliveryCounts = [];

        $replaceCourseIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $courseId): int => (int) $courseId, $replaceCourseIds),
            static fn (int $courseId): bool => $courseId > 0,
        )));

        $scheduleRows = $this->semesterScheduleRowsCache[$semesterId] ??= $this->snapshotScheduleRows($semesterId);

        $schedules = collect($scheduleRows)
            ->filter(function (array $schedule) use ($sectionId, $replaceCourseIds): bool {
                if ($replaceCourseIds === []) {
                    return true;
                }

                return (int) $schedule['section_id'] !== $sectionId
                    || ! in_array((int) $schedule['course_id'], $replaceCourseIds, true)
                    || ! in_array((string) ($schedule['status'] ?? ''), ['draft', 'completed', 'revision'], true);
            })
            ->map(static fn (array $schedule): Schedule => new Schedule($schedule));

        foreach ($tentativeSchedules as $row) {
            if (! is_array($row) || (int) ($row['semester_id'] ?? $semesterId) !== $semesterId) {
                continue;
            }
            $schedules->push(new Schedule([
                'room_id' => $row['room_id'] ?? null,
                'section_id' => $row['section_id'] ?? null,
                'course_id' => $row['course_id'] ?? null,
                'faculty_id' => $row['faculty_id'] ?? null,
                'department_id' => $row['department_id'] ?? $departmentId,
                'day' => $row['day'] ?? null,
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'mode' => $row['mode'] ?? null,
            ]));
        }

        $knownRoomTypeIds = array_fill_keys(array_keys($this->roomTypes), true);
        $missingRoomTypeIds = $schedules
            ->pluck('room_id')
            ->filter()
            ->map(static fn (mixed $roomId): int => (int) $roomId)
            ->unique()
            ->reject(static fn (int $roomId): bool => isset($knownRoomTypeIds[$roomId]))
            ->values();

        $snapshotRooms = $this->snapshot()->roomsById;
        foreach ($missingRoomTypeIds as $roomId) {
            if (isset($snapshotRooms[$roomId]['room_type'])) {
                $this->roomTypes[$roomId] = (string) $snapshotRooms[$roomId]['room_type'];
            }
        }

        foreach ($schedules as $schedule) {
            $timeRange = [
                'start_time' => (string) $schedule->start_time,
                'end_time' => (string) $schedule->end_time,
                'start_minutes' => $this->timeToMinutes((string) $schedule->start_time),
                'end_minutes' => $this->timeToMinutes((string) $schedule->end_time),
            ];

            if ($schedule->room_id !== null) {
                $roomId = (int) $schedule->room_id;
                $roomType = $this->roomTypes[$roomId] ?? null;
                if (! Rooms::isSharedType($roomType)) {
                    $this->existingScheduleIndex["r:{$roomId}:{$schedule->day}"][] = $timeRange;
                }
                $this->existingRoomUseCounts[$roomId] = ($this->existingRoomUseCounts[$roomId] ?? 0) + 1;
                $this->existingRoomDayUseSlots["{$roomId}:{$schedule->day}"] =
                    ($this->existingRoomDayUseSlots["{$roomId}:{$schedule->day}"] ?? 0)
                    + max(0, $this->timeToMinutes((string) $schedule->end_time) - $this->timeToMinutes((string) $schedule->start_time));
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
            $this->existingScheduleIndex["c:{$schedule->course_id}:{$schedule->day}"][] = $timeRange + [
                'section_id' => (int) $schedule->section_id,
                'online' => ($schedule->mode ?? null) === 'online',
            ];

            if (! empty($schedule->faculty_id)) {
                $this->existingScheduleIndex["f:{$schedule->faculty_id}:{$schedule->day}"][] = $timeRange;
            }
        }
    }

    /**
     * @return array<int, list<array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}>>
     */
    private function grantWindowsForSection(Sections $section): array
    {
        $windows = [];
        foreach ($this->snapshot()->roomsById as $roomId => $attributes) {
            foreach ((array) ($attributes['grant_windows'] ?? []) as $window) {
                $windows[(int) $roomId][] = RoomAccessPolicy::window(
                    (string) $window['day'],
                    (string) $window['start_time'],
                    (string) $window['end_time'],
                );
            }
        }

        return $windows;
    }

    private function blockRoomsOutsideGrantWindows(): void
    {
        foreach ($this->roomGrantWindows as $roomId => $windows) {
            foreach (RoomAccessPolicy::blockedRanges($windows, SchedulingPolicy::PERSISTABLE_DAYS) as $day => $ranges) {
                foreach ($ranges as $range) {
                    $this->existingScheduleIndex["r:{$roomId}:{$day}"][] = [
                        'start_time' => '',
                        'end_time' => '',
                        'start_minutes' => $range['start_minutes'],
                        'end_minutes' => $range['end_minutes'],
                    ];
                }
            }
        }
    }

    private function blockLentWindows(): void
    {
        foreach ($this->snapshot()->roomsById as $roomId => $attributes) {
            foreach ((array) ($attributes['lent_windows'] ?? []) as $window) {
                $this->existingScheduleIndex["r:{$roomId}:{$window['day']}"][] = [
                    'start_time' => '',
                    'end_time' => '',
                    'start_minutes' => RoomAccessPolicy::minutes((string) $window['start_time']),
                    'end_minutes' => RoomAccessPolicy::minutes((string) $window['end_time']),
                ];
            }
        }
    }

    private function blockRoomsOnOtherProgramsDays(Sections $section): void
    {
        if ($section->program_id === null) {
            return;
        }

        foreach ($this->snapshot()->roomsById as $roomId => $attributes) {
            foreach ((array) ($attributes['program_days'] ?? []) as $day => $share) {
                if ((int) ($share['program_id'] ?? 0) === (int) $section->program_id) {
                    continue;
                }

                $this->existingScheduleIndex["r:{$roomId}:{$day}"][] = [
                    'start_time' => '',
                    'end_time' => '',
                    'start_minutes' => 0,
                    'end_minutes' => 24 * 60,
                ];
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function snapshotScheduleRows(int $semesterId): array
    {
        return array_values(array_filter(
            $this->snapshot()->persistedSchedules,
            static fn (array $schedule): bool => (int) ($schedule['semester_id'] ?? $semesterId) === $semesterId,
        ));
    }

    /**
     * @param  int|null  $facultyId  When provided, the instructor index is checked to
     *                               ensure the faculty member is not already teaching another class at the same
     *                               day and time, regardless of delivery mode.
     */
    private function hasExistingScheduleConflict(
        ?int $roomId,
        int $sectionId,
        int $courseId,
        string $day,
        string $startTime,
        string $endTime,
        ?int $facultyId = null,
        string $mode = 'on-site',
        int $departmentId = 0,
    ): bool {
        $startMinutes = $this->timeToMinutes($startTime);
        $endMinutes = $this->timeToMinutes($endTime);

        if ($roomId !== null && ! Rooms::isSharedType($this->roomTypes[$roomId] ?? null)
            && $this->overlapCountAtLeast("r:{$roomId}:{$day}", $startMinutes, $endMinutes, 1)) {
            return true;
        }

        if ($this->overlapCountAtLeast("s:{$sectionId}:{$day}", $startMinutes, $endMinutes, 1)) {
            return true;
        }

        if ($mode === 'online') {
            foreach ($this->existingScheduleIndex["c:{$courseId}:{$day}"] ?? [] as $existing) {
                if ($existing['online']
                    && (int) ($existing['section_id'] ?? 0) !== $sectionId
                    && $this->entryOverlaps($existing, $startMinutes, $endMinutes)) {
                    return true;
                }
            }
        }

        if ($facultyId !== null
            && $this->overlapCountAtLeast("f:{$facultyId}:{$day}", $startMinutes, $endMinutes, 1)) {
            return true;
        }

        return false;
    }

    private function overlapCountAtLeast(string $key, int $startMinutes, int $endMinutes, int $threshold): bool
    {
        $entries = $this->existingScheduleIndex[$key] ?? [];
        if ($entries === []) {
            return false;
        }

        $count = 0;
        foreach ($entries as $existing) {
            if ($this->entryOverlaps($existing, $startMinutes, $endMinutes)) {
                $count++;
                if ($count >= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $existing */
    private function entryOverlaps(array $existing, int $startMinutes, int $endMinutes): bool
    {
        $existingStart = $existing['start_minutes'] ?? $this->timeToMinutes((string) $existing['start_time']);
        $existingEnd = $existing['end_minutes'] ?? $this->timeToMinutes((string) $existing['end_time']);

        return $startMinutes < $existingEnd && $existingStart < $endMinutes;
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', SchedulingPolicy::normalizeTime($time)));

        return ($hours * 60) + $minutes;
    }
}
