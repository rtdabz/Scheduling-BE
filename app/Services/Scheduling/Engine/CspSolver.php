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
    /**
     * Physical rooms offered to each component of a split candidate at any one
     * day/start pair. See boundedRoomOptions().
     */
    private const SPLIT_ROOM_OPTIONS_PER_SLOT = 6;

    private const SOFT_FIELD_EVENING_PENALTY = 6;

    private const CLASSROOM_GAP_SCHEDULABLE_SLOT_SOFT_PENALTY = 1800;

    /**
     * Cost of placing a meeting outside the time band its course asked for.
     *
     * Deliberately sized between the day-balance weight (700 per meeting
     * already on that day) and the rotating day tie-breaker (8): a preference
     * decides between otherwise equivalent slots, but never outranks spreading
     * a section across the week or keeping its day compact.
     */
    private const TIME_PREFERENCE_SOFT_PENALTY = 250;

    private const CLASSROOM_GAP_LEFTOVER_SLOT_SOFT_PENALTY = 7000;

    private const CLASSROOM_FIVE_SLOT_GAP_SOFT_PENALTY = 20000;

    private const CLASSROOM_SIX_SLOT_GAP_SOFT_PENALTY = 14000;

    /** @var array<string, bool> */
    private array $databaseValidityCache = [];

    /**
     * Existing persisted schedules indexed for O(1) conflict lookup.
     * Keyed by room, section, faculty, online department capacity, and online
     * subject/day so different sections of one online subject cannot overlap.
     *
     * @var array<string, list<array{start_time: string, end_time: string}>>
     */
    private array $existingScheduleIndex = [];

    /** Candidate schedules selected earlier in a year-level in-memory search. */
    private array $tentativeSchedules = [];

    public function setInputSnapshot(?SchedulingSnapshot $snapshot): void
    {
        $this->snapshotAutoCaptured = false;

        // Year-level generation re-supplies the same snapshot before every
        // solver attempt. Clearing unconditionally would throw away the semester
        // rows and the built domains on each call, which is exactly the work
        // these caches exist to avoid.
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
     * The snapshot is the solver's only data source. Application paths pass
     * the one they validated; a direct caller that passes none gets one
     * captured here the same way, rather than a separate database loader that
     * could read the data differently. An auto-captured snapshot lives for one
     * solve only, so the next call sees the database as it is then.
     *
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

    /** The section's name for an error message, read from the snapshot. */
    private function sectionLabel(int $sectionId): string
    {
        return (string) ($this->inputSnapshot?->sectionsById[$sectionId]['section_name'] ?? 'Section');
    }

    /** The active snapshot; ensureSnapshotFor() has run by the time this is read. */
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
     * Rooms owned by another department that this solve may use through an
     * approved room request, keyed by room id, with their granted windows.
     *
     * @var array<int, list<array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}>>
     */
    private array $roomGrantWindows = [];

    /** @var array<int, int> */
    private array $existingRoomUseCounts = [];

    /** @var array<string, int> */
    private array $existingRoomDayUseSlots = [];

    /**
     * The department this solve is scheduling for. Field-course codes are
     * configured per department and a shared minor has no department of its
     * own, so field-ness must be resolved against the scheduling department
     * rather than the course's owner.
     */
    private int $solveDepartmentId = 0;

    /** @var array<int, array{physical: int, online: int, regular_physical?: int, protected_physical?: int}> */
    private array $existingSectionDeliveryCounts = [];

    private ?DepartmentRoomFairness $roomFairness = null;


    /** @var array<int, string> */
    private array $generationForcedDaysByCourseId = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $requirementsByCourseId = [];

    /** @var array<int, string> Course id => 'morning'|'afternoon'|'evening'. */
    private array $timePreferencesByCourseId = [];

    /**
     * Course id => the room Setup Courses asked for. A ranking preference
     * inside an allocation tier; it never removes a candidate.
     *
     * @var array<int, int>
     */
    private array $preferredRoomIdsByCourseId = [];

    /** The section's hard teaching window, or null when it may use any time. */
    private ?string $preferredPeriod = null;

    /**
     * Course id => the periods that replace the section's for that course
     * only (Configure's Preferred Meeting, one or more periods).
     *
     * @var array<int, list<string>>
     */
    private array $preferredPeriodsByCourseId = [];

    /**
     * Step 1's Preferred Days: the only days this run may place a meeting on,
     * or null when every day is open. A hard window like the period above,
     * applied to every shape (single, Split Session, both Hybrids) after its
     * candidates are built.
     *
     * @var list<string>|null
     */
    private ?array $allowedDays = null;

    /**
     * Setup Courses' "Allow Friday and Saturday as Paired Days": a Split
     * Session or Hybrid Split may also meet Friday + Saturday, after MW and TTh.
     */
    private bool $allowFridaySaturdaySplit = false;

    /**
     * True when the department turned Sunday Online Only off, which opens Sunday
     * to physical classes.
     *
     * Sunday is otherwise a last-resort day: its candidates sit in a search tier
     * the solver only opens when Monday-Saturday cannot complete a timetable, and
     * the day-balance ranking charges a flat penalty for using it at all. A
     * department that teaches on Sunday does not want either, so once the setting
     * is off Sunday is ranked like any other teaching day. It stays a legal-day
     * question for the rule engine either way -- this flag only changes
     * preference, never what is allowed.
     */
    private bool $sundayIsRegularTeachingDay = false;

    /** @var array<int, Course> */
    private array $loadedCoursesById = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $semesterScheduleRowsCache = [];

    /**
     * Built candidate sets keyed by domainCacheKey(), reused across the many
     * solver attempts a single generation run makes. Cleared per generation
     * context, never across runs.
     *
     * @var array<string, array{domain: list<array<string, mixed>>, empty_after_requirements: bool}>
     */
    private array $domainCache = [];

    private ?SchedulingSnapshot $inputSnapshot = null;

    /** True when ensureSnapshotFor() captured the snapshot, so the next solve recaptures it. */
    private bool $snapshotAutoCaptured = false;

    private ?SolutionDiversity $solutionDiversity = null;

    /**
     * The solving department's settings, as Custom Lab Duration needs them.
     *
     * Held for the length of one solve so every split laboratory component --
     * domain, total duration and the meeting type inferred back off a block --
     * measures the laboratory half the same way.
     *
     * @var array<string, mixed>|Departments|null
     */
    private array|Departments|null $departmentLabSettings = null;

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
            timePreferencesByCourseId: $schema['time_preferences_by_course_id'],
            preferredPeriod: $schema['preferred_period'],
            allowedDays: $schema['allowed_days'],
            allowFridaySaturdaySplit: $schema['allow_friday_saturday_split'],
            preferredPeriodsByCourseId: $schema['preferred_periods_by_course_id'],
            seed: $schema['seed'] ?? null,
            tentativeSchedules: $schema['tentative_schedules'],
            throwOnEmptyDomain: $schema['throw_on_empty_domain'],
            allowRoomTbaFallback: $schema['allow_room_tba_fallback'],
            allowOnlineFallback: $schema['allow_online_fallback'],
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
        array $timePreferencesByCourseId = [],
        ?string $preferredPeriod = null,
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
            timePreferencesByCourseId: $timePreferencesByCourseId,
            preferredPeriod: $preferredPeriod,
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
        array $timePreferencesByCourseId = [],
        ?string $preferredPeriod = null,
        ?array $allowedDays = null,
        ?int $seed = null,
        array $tentativeSchedules = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
        bool $allowOnlineFallback = true,
        bool $allowFridaySaturdaySplit = false,
        array $preferredPeriodsByCourseId = [],
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
        $this->timePreferencesByCourseId = SolverInput::normalizeTimePreferences($timePreferencesByCourseId, $courseIds);
        $this->preferredPeriod = SolverInput::normalizePreferredPeriod($preferredPeriod);
        $this->preferredPeriodsByCourseId = [];
        foreach ($preferredPeriodsByCourseId as $courseId => $periods) {
            $periods = SchedulingPolicy::normalizePreferredPeriods($periods);
            if ($periods !== null && (int) $courseId > 0) {
                $this->preferredPeriodsByCourseId[(int) $courseId] = $periods;
            }
        }
        $this->allowedDays = SchedulingPolicy::normalizeAllowedDays($allowedDays);
        $this->allowFridaySaturdaySplit = $allowFridaySaturdaySplit;

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
        // Restore guarded identity/scheduling fields explicitly when
        // reconstructing a section from snapshot attributes.
        $section->id = (int) ($sectionAttributes['id'] ?? $sectionId);
        $section->semester_id = (int) ($sectionAttributes['semester_id'] ?? $snapshot->semesterId);
        $section->department_id = (int) ($sectionAttributes['department_id'] ?? $snapshot->departmentId);
        $section->year_level = (string) ($sectionAttributes['year_level'] ?? '');
        $section->semester = (string) ($sectionAttributes['semester'] ?? '');
        $semester = new Semester($snapshot->semester);
        $semester->id = (int) ($snapshot->semester['id'] ?? $snapshot->semesterId);
        $semester->semester = (string) ($snapshot->semester['semester'] ?? '');
        $section->setRelation('academicSemester', $semester);

        // Every lookup below reads this snapshot. A snapshot for another
        // semester or department would silently judge against the wrong data;
        // the old database fallback hid that mismatch, so now it is refused.
        if ($snapshot->semesterId !== (int) $section->semester_id || $snapshot->departmentId !== (int) $section->department_id) {
            throw new RuntimeException('The scheduling snapshot belongs to a different semester or department than the section being solved.');
        }

        $this->validateSectionForScheduling($section);

        // Snapshot entries begin as attribute arrays, so transform them with a
        // base collection before wrapping the resulting Course models in the
        // Eloquent collection contract below. The snapshot has already applied
        // the section's own curriculum placement to each course.
        $courses = collect($snapshot->coursesById)
            ->only(array_map('intval', $courseIds))
            ->map(function (array $attributes): Course {
                $course = new Course($attributes);
                // The primary key is guarded by the model and is not restored
                // by mass assignment. Preserve it explicitly so snapshot
                // courses remain addressable by course ID.
                $course->id = (int) ($attributes['id'] ?? 0);

                return $course;
            })
            ->keyBy('id');
        // Base Collection::map() is returned when the callback changes array
        // snapshots into Course models. Re-wrap the final map so downstream
        // solver helpers receive the required Eloquent type.
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

        $solverSeed = $seed !== null ? (int) $seed : random_int(1, 1000000);

        $settings = $snapshot->departmentSettings;
        $lectureLabScheduleOverrideEnabled = (bool) ($settings['lecture_lab_schedule_override_enabled'] ?? false);
        $this->departmentLabSettings = $settings;
        $sundayOnlineOnlyEnabled = (bool) ($settings['sunday_online_only_enabled'] ?? true);
        $this->sundayIsRegularTeachingDay = ! $sundayOnlineOnlyEnabled;
        $forcedDaysByCourseId = $this->forcedDaysByCourseId((int) $section->department_id, $courseIds);
        $this->generationForcedDaysByCourseId = $forcedDaysByCourseId;

        $variables = $this->buildVariables(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            sectionId: (int) $section->id,
            seed: $solverSeed,
            lectureLabScheduleOverrideEnabled: $lectureLabScheduleOverrideEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
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
            // Keep TBA out of the physical search entirely. Filtering only
            // returned solutions lets TBA candidates consume CSP iterations
            // before weekday and Saturday real-room combinations are tried.
            foreach ($variables as &$variable) {
                $variable['domain'] = array_values(array_filter(
                    $variable['domain'],
                    static fn (array $candidate): bool => ! ($candidate['_room_tba'] ?? false),
                ));
            }
            unset($variable);
        }

        if (! $allowOnlineFallback) {
            // Same reasoning for a lecture that only went online because every
            // compatible lecture room was taken. Tier ordering alone cannot
            // guarantee this: it ranks candidates within one variable, so an
            // earlier course keeping a room can still push a later course
            // online without ever being reconsidered. Removing the fallback
            // from every domain makes the physical search exhaustive.
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
                // Fixed pattern / forced day courses are the most constrained (MRV heuristic)
                $leftConstrained = ! empty($left['preferred_pattern']) || ! empty($left['forced_day']);
                $rightConstrained = ! empty($right['preferred_pattern']) || ! empty($right['forced_day']);
                if ($leftConstrained !== $rightConstrained) {
                    return $leftConstrained ? -1 : 1;
                }

                // Lecture/laboratory splits need a matched pair of placements, so
                // they are the next-hardest thing to satisfy after a fixed pattern.
                $leftSplit = (bool) ($left['is_split_lecture_lab'] ?? false);
                $rightSplit = (bool) ($right['is_split_lecture_lab'] ?? false);
                if ($leftSplit !== $rightSplit) {
                    return $leftSplit ? -1 : 1;
                }

                $priorityComparison = ($left['scheduling_priority'] ?? 2)
                    <=> ($right['scheduling_priority'] ?? 2);

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                // Courses that can only use a handful of physical rooms are placed
                // before courses that could still go anywhere.
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
                return [];
            }
        }

        // Collect a larger candidate pool so the diversity filter has more
        // material to choose from. We gather up to 20× the requested solutions
        // (capped at 200) to maximise the variety of distinct scheduling choices
        // before applying diversity-aware selection.
        $candidatePoolLimit = min(
            max($maxSolutions * 3, 8),
            18,
        );
        $onlineCapableAssignments = $this->onlineCapableVariableCount($variables);
        // The department's configured online target is the only legitimate
        // demand for online delivery. Previously this also took the number of
        // split lecture/laboratory variables, which made the quota equal the
        // number of split courses whenever the lecture/lab override was
        // enabled -- the second pass was then told to find solutions putting
        // that many lectures online even while physical lecture rooms were
        // still free. Genuinely necessary online placements are still produced
        // by the search itself, because the online tiers open automatically
        // once the physical tiers cannot be satisfied.
        $balancedOnlineAssignments = min(
            $onlineCapableAssignments,
            $this->roomFairness()->minimumOnlineTarget((int) $section->id, $this->existingSectionDeliveryCounts),
        );

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

        if (
            $balancedOnlineAssignments > 0
            && ! $this->hasEnoughOnlineBalancedSolutions(
                solutions: $rawSolutions,
                minimumOnlineAssignments: $balancedOnlineAssignments,
                requiredSolutions: $maxSolutions,
            )
            && ! $this->hasExceededSearchLimits()
        ) {
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

        $resolvedLaboratorySolutions = array_values(array_filter(
            $rawSolutions,
            fn (array $assignments): bool => ! $this->solutionContainsRoomTba($assignments),
        ));
        if ($resolvedLaboratorySolutions !== []) {
            $rawSolutions = $resolvedLaboratorySolutions;
        }

        // The same preference for online: a solution that kept every lecture in
        // a real room beats one that only reached a lecture by going online.
        // This looks at the marker rather than the delivery mode, so a hybrid
        // lecture, an explicitly online course and a Sunday online-only
        // placement are all left alone -- those chose online rather than
        // falling back to it.
        $roomedLectureSolutions = array_values(array_filter(
            $rawSolutions,
            fn (array $assignments): bool => ! $this->solutionContainsOnlineFallback($assignments),
        ));
        if ($roomedLectureSolutions !== []) {
            $rawSolutions = $roomedLectureSolutions;
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
        $ranked = $this->solutionDiversity()->selectDiverse($scored, $maxSolutions);

        // Strip the internal _raw field and assign sequential ranks.
        foreach ($ranked as $index => &$solution) {
            unset($solution['_raw']);
            $solution['rank'] = $index + 1;
        }

        unset($solution);

        return $ranked;
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

    private function hasEnoughOnlineBalancedSolutions(
        array $solutions,
        int $minimumOnlineAssignments,
        int $requiredSolutions,
    ): bool {
        if ($minimumOnlineAssignments <= 0 || $requiredSolutions <= 0) {
            return true;
        }

        $balancedCount = 0;
        foreach ($solutions as $solution) {
            if ($this->onlineLectureAssignmentCount($solution) >= $minimumOnlineAssignments) {
                $balancedCount++;
                if ($balancedCount >= $requiredSolutions) {
                    return true;
                }
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

    public function generationMetrics(): SchedulingGenerationMetrics
    {
        // A direct caller that passed no snapshot got one captured for it;
        // counted so such callers stay visible in generation metrics.
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

            $signature = $this->solutionDiversity()->signature($assignments);

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

        $domain = $this->rankDomainForTentativeCompactness(
            $variable['domain'],
            $assignments,
            (int) $section->id,
        );

        $hasRoomTbaCandidates = collect($domain)->contains(
            static fn (array $candidate): bool => (bool) ($candidate['_room_tba'] ?? false),
        );
        $candidateGroups = $this->weekdayFirstCandidateGroups($domain, $hasRoomTbaCandidates, (int) $section->id);

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

            // A lower-priority day or room fallback is opened only when this
            // entire group cannot produce a complete conflict-free timetable.
            if (count($solutions) > $solutionsBeforeGroup) {
                return;
            }
        }
    }

    /**
     * Keep ordinary generation priorities lexicographic after persisted
     * conflicts have pruned the domain. Soft compactness and day-balancing
     * scores may reorder candidates inside a tier, but cannot move a fallback
     * tier (unsplit single-session, online, Sunday, Room TBA) ahead of a
     * feasible physical placement. Physical rooms are therefore exhausted
     * before the search opens unsplit or online fallbacks.
     *
     * Day tiers refine that ordering within each allocation tier:
     *   0 - the preferred days for this candidate
     *   1 - Monday-Thursday for a single meeting holding a lecture room, which
     *       department policy keeps free for MW/TTh split sessions
     *   2 - Sunday, a last resort -- unless the department turned Sunday Online
     *       Only off, which moves Sunday into the tiers above with every other
     *       teaching day
     * Tier 1 and 2 are only opened when the earlier tiers cannot complete a
     * timetable, so the preference never removes a legal placement.
     *
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

        // Outer tier is the allocation priority already used to sort the
        // domain: preferred physical (0), split/pattern fallbacks (1),
        // single-session unsplit fallbacks (2), weekend physical (3-5),
        // Room TBA (7), then online (10+). A lower-priority tier is only
        // opened when every candidate in the earlier tiers fails to yield a
        // complete conflict-free timetable, which guarantees normal rooms are
        // exhausted before unsplit or online fallbacks are used.
        $byAllocation = [];
        foreach ($domain as $candidate) {
            $priority = $this->candidateAllocationPriority($candidate, $sectionId);
            // Keep the historical Room TBA-last behaviour when both TBA and
            // online candidates exist for the same variable.
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
                    $groups[] = $dayBuckets[$dayTier];
                }
            }
        }

        return $groups === [] ? [$domain] : $groups;
    }

    private function candidateSearchDayTier(array $candidate): int
    {
        $tier = 0;
        // A single meeting holding a real lecture room is steered to the end of
        // the week so Monday-Thursday lecture-room capacity stays open for the
        // MW and TTh split-session patterns. Monday-Thursday becomes day tier 1
        // for these candidates, which the group gate only opens when Friday and
        // Saturday cannot complete the timetable -- so this reorders the search
        // without ever removing a placement.
        $prefersLateWeek = $this->prefersLateWeekPlacement($candidate);
        // A department that teaches on Sunday has Sunday as the true end of its
        // week, so it serves the same purpose Friday and Saturday do here.
        $lateWeekDays = $this->sundayIsRegularTeachingDay
            ? [...SchedulingPolicy::SINGLE_MEETING_PREFERRED_DAYS, 'Sunday']
            : SchedulingPolicy::SINGLE_MEETING_PREFERRED_DAYS;

        foreach ($candidate['blocks'] ?? [] as $block) {
            $day = (string) ($block['day'] ?? '');
            // Tier 2 is the fallback tier the search only opens after
            // Monday-Saturday fails. A department that teaches physically on
            // Sunday gets it ranked with the rest of the week instead.
            if ($day === 'Sunday' && ! $this->sundayIsRegularTeachingDay) {
                return 2;
            }

            if ($prefersLateWeek && ! in_array($day, $lateWeekDays, true)) {
                $tier = 1;
            }
            // Saturday is otherwise part of the normal physical search range.
            // Only Sunday and virtual/TBA resources are fallback tiers.
        }

        return $tier;
    }

    /**
     * True when a candidate is a single meeting that would occupy a real
     * lecture room. Laboratory rooms are deliberately excluded: laboratories
     * are the scarcer resource and keep their existing day distribution.
     * Online, field and Room TBA placements consume no lecture-room capacity,
     * so they are unaffected by the late-week preference as well.
     */
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
     * Re-ranks a variable domain against the partial assignment already built
     * during search. This helps the solver fill adjacent room/section openings
     * before it explores starts that create 30-minute or 1-hour holes.
     *
     * Compactness only reorders candidates inside the same allocation tier.
     * A physical placement always stays ahead of an unsplit single-session or
     * online fallback regardless of gap scores, so normal rooms are exhausted
     * first.
     *
     * @param  list<array<string, mixed>>  $domain
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function rankDomainForTentativeCompactness(array $domain, array $assignments, int $sectionId = 0): array
    {
        if (count($domain) < 2) {
            return $domain;
        }

        // Keep a section's meetings spread across the six teaching days while
        // still preserving the compactness preference below. The rotating
        // anchor prevents every course from starting on Monday; once a day is
        // occupied, the load penalty naturally moves the next candidate to the
        // next available day and eventually wraps back to Monday.
        $dayLoads = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] ?? [] as $block) {
                $day = (string) ($block['day'] ?? '');
                if ($day !== '' && $this->dayIndex($day) < 6) {
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
                'penalty' => $this->candidateTentativeGapPenalty($candidate, $assignments)
                    + $this->candidateDayBalancePenalty($candidate, $dayLoads, $sectionId)
                    + $this->candidateTimePreferencePenalty($candidate),
                'index' => $index,
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
                ?: $left['preferred_room'] <=> $right['preferred_room']
                ?: $left['penalty'] <=> $right['penalty']
                ?: $left['index'] <=> $right['index'],
        );

        return array_column($ranked, 'candidate');
    }

    /**
     * Prefer the least-loaded teaching day, with a rotating tie-breaker. The
     * teaching week is Monday-Saturday, or Monday-Sunday for a department that
     * turned Sunday Online Only off. The tie-breaker is deterministic per
     * section/course so retries remain reproducible while different sections do
     * not all claim Monday first.
     *
     * @param  array<string, int>  $dayLoads
     */
    private function candidateDayBalancePenalty(array $candidate, array $dayLoads, int $sectionId): int
    {
        $blocks = $candidate['blocks'] ?? [];
        if ($blocks === []) {
            return 0;
        }

        // Explicit patterns and their documented fallbacks are already ranked
        // by the CSP policy. Do not let the general distribution preference
        // reorder those contractual choices.
        if (
            ! empty($candidate['preferred_pattern'])
            || ! empty($candidate['_pattern_fallback'])
            || ! empty($candidate['_single_session_fallback'])
        ) {
            return 0;
        }

        $courseId = (int) ($candidate['course_id'] ?? 0);
        // A department that teaches on Sunday balances across a seven-day week,
        // so the rotating tie-breaker rotates over seven days too.
        $cycle = $this->sundayIsRegularTeachingDay ? 7 : 6;
        $anchor = abs(($sectionId * 17) + ($courseId * 31)) % $cycle;
        $penalty = 0;

        foreach ($blocks as $block) {
            $day = (string) ($block['day'] ?? '');
            $dayIndex = $this->dayIndex($day);
            if ($dayIndex >= 6 && ! $this->sundayIsRegularTeachingDay) {
                // Sunday is only a fallback for the modes allowed by the
                // existing policy; it should never outrank Mon-Sat.
                $penalty += 5000;

                continue;
            }

            $penalty += (($dayLoads[$day] ?? 0) * 700);
            $distance = ($dayIndex - $anchor + $cycle) % $cycle;
            $penalty += $distance * 8;
        }

        return $penalty;
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

    /**
     * Soft cost of ignoring a course's requested time band.
     *
     * This is a preference, not a constraint: it only reorders candidates
     * inside one allocation tier, so asking for an afternoon slot can never
     * turn a feasible timetable into a failed run. When no slot in the
     * requested band survives the hard constraints, the solver still places
     * the course elsewhere.
     */
    /** 0 when the candidate meets in the course's preferred room (or none was chosen), else 1. */
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
     * The Setup Courses Custom Time Duration of a single-requirement course.
     *
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
     * The Setup Courses length of one Integrated Hybrid session
     * ('lecture' or 'laboratory'), when one was chosen.
     *
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

    private function candidateTimePreferencePenalty(array $candidate): int
    {
        if ($this->timePreferencesByCourseId === []) {
            return 0;
        }

        $preference = $this->timePreferencesByCourseId[(int) ($candidate['course_id'] ?? 0)] ?? null;
        if ($preference === null) {
            return 0;
        }

        $penalty = 0;
        foreach ($candidate['blocks'] ?? [] as $block) {
            if (! $this->matchesTimePreference($preference, (int) ($block['start_slot'] ?? 0))) {
                $penalty += self::TIME_PREFERENCE_SOFT_PENALTY;
            }
        }

        return $penalty;
    }

    /**
     * The generator offers three bands while scoring uses four. Midday counts
     * as morning here: a class starting at 10:00 is what a user asking for a
     * morning schedule means, and leaving it unmatched would push those
     * courses into the afternoon.
     */
    private function matchesTimePreference(string $preference, int $startSlot): bool
    {
        $band = SolutionDiversity::timeBand($startSlot);

        return match ($preference) {
            'morning' => $band === 'morning' || $band === 'midday',
            'afternoon' => $band === 'afternoon',
            'evening' => $band === 'evening',
            default => true,
        };
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
        bool $sundayOnlineOnlyEnabled = true,
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
            // Integrated On-site keeps both sessions face-to-face; only
            // Integrated Hybrid moves the lecture online.
            $courseIsHybrid = $isHybrid && $hasBothComponents
                && ! SchedulingPolicy::isIntegratedOnSite($deliveryModesByCourseId, (int) $course->id);

            $preferredPattern = SolverInput::normalizePreferredPattern(
                $preferredPatternsByCourseId[(int) $course->id] ?? null,
            );
            $requiresBalancedSplit = in_array((int) $course->id, $balancedSplitCourseIds, true);
            $requiresHybridSplit = in_array((int) $course->id, $hybridSplitCourseIds, true);

            $lectureComponentSlots = null;
            $laboratoryComponentSlots = null;
            if ($hasBothComponents) {
                // Integrated Hybrid: each session's length is the one chosen
                // in Setup Courses, else the course's own. The total below is
                // only for ranking and the cache key; the two are placed as
                // separate meetings.
                $lectureComponentSlots = $this->requirementComponentSlots($requirements, 'lecture')
                    ?? SchedulingPolicy::lectureComponentSlots($course);
                $laboratoryComponentSlots = $this->requirementComponentSlots($requirements, 'laboratory')
                    ?? $this->laboratoryComponentSlots($course);
                $durationSlots = $lectureComponentSlots + $laboratoryComponentSlots;
            } elseif ($requiresHybridSplit) {
                // Two fixed meetings: one online, one face-to-face.
                $durationSlots = 2 * SchedulingPolicy::hybridSplitMeetingSlots();
            } else {
                // A Setup Courses Custom Time Duration arrives on the course's
                // requirement.
                $durationSlots = $this->requirementCustomSlots($requirements)
                    ?? $this->getDurationSlots($course);
            }

            // The candidate set for a course depends only on the course, the
            // rooms and the configuration -- never on the partial assignment or
            // on which attempt this is. Year-level generation solves the same
            // section many times (two section orderings, the Room TBA ladder,
            // the retry strategies and the recursive branch search), so without
            // this cache the same tens of thousands of candidates are rebuilt
            // for every attempt. Ranking stays outside the cache because it
            // reads live room-usage counters that do change per attempt.
            $forcedDay = $forcedDaysByCourseId[(int) $course->id] ?? null;
            $coursePeriods = $this->preferredPeriodsByCourseId[(int) $course->id]
                ?? ($this->preferredPeriod !== null ? [$this->preferredPeriod] : null);

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
                    $coursePeriods === null ? '' : implode(',', $coursePeriods),
                    $this->allowedDays ?? [],
                    $this->allowFridaySaturdaySplit ? 1 : 0,
                    SchedulingPolicy::fieldDayEndTime(),
                    $sundayOnlineOnlyEnabled ? 1 : 0,
                    array_key_exists((int) $course->id, $deliveryModesByCourseId) ? 1 : 0,
                    $requirementsByCourseId[(int) $course->id] ?? null,
                    $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                ],
            );

            $cached = $this->domainCache[$domainCacheKey] ?? null;
            if ($cached !== null) {
                $domain = $cached['domain'];
                $emptyAfterRequirements = $cached['empty_after_requirements'];
                $emptyAfterPeriod = $cached['empty_after_period'] ?? false;
                $emptyAfterForcedDay = $cached['empty_after_forced_day'] ?? false;
                $emptyAfterDays = $cached['empty_after_days'] ?? false;
            } else {
            $domain = match (true) {
                // Lecture and laboratory are always two separate meetings of
                // their own lengths. A preferred pattern used to send this
                // course to the generic pattern builder, which split the
                // combined total across two days as if it were one class.
                $hasBothComponents => $this->buildDefaultLectureLabDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $courseIsHybrid,
                    anchoredSchedule: $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                    lectureSlots: $lectureComponentSlots,
                    laboratorySlots: $laboratoryComponentSlots,
                ),
                $requiresHybridSplit && $preferredPattern === null => $this->buildFlexibleHybridSplitDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $requiresHybridSplit => $this->buildHybridSplitPatternDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $courseDeliveryMode === 'online' && $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $requiresBalancedSplit && $preferredPattern === null => $this->buildFlexibleBalancedSplitDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                default => $this->buildPatternDomainWithFallbacks(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $courseIsHybrid,
                    requireBalancedDurations: $requiresBalancedSplit,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
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
            if ($forcedDay !== null && $domain !== []) {
                $domain = $this->filterDomainByForcedDay($domain, $forcedDay);
                $emptyAfterForcedDay = $domain === [];
            }

            $emptyAfterPeriod = false;
            if ($coursePeriods !== null && $domain !== []) {
                $domain = $this->filterDomainByWindows(
                    $domain,
                    SchedulingPolicy::preferredPeriodsSlotRanges($coursePeriods),
                );
                $emptyAfterPeriod = $domain === [];
            }

            $emptyAfterDays = false;
            if ($this->allowedDays !== null && $domain !== []) {
                $domain = $this->filterDomainByDays($domain, $this->allowedDays);
                $emptyAfterDays = $domain === [];
            }

            // The domain is shuffled and then ordered by allocation priority.
            // A (day, start_slot) sort used to run here as well, but the
            // Fisher-Yates shuffle below discards that ordering entirely before
            // anything reads it, so it was pure cost on a domain that can hold
            // tens of thousands of candidates.

            // Apply a deterministic section+course-seeded shuffle to the domain
            // so each section explores a different ordering of candidates,
            // preventing resource starvation where section 1 always claims the
            // same on-site rooms first.
            $this->domainCache[$domainCacheKey] = [
                'domain' => $domain,
                'empty_after_requirements' => $emptyAfterRequirements,
                'empty_after_period' => $emptyAfterPeriod,
                'empty_after_forced_day' => $emptyAfterForcedDay,
                'empty_after_days' => $emptyAfterDays,
            ];
            }

            // The shuffle is seeded per attempt, so it stays outside the cache.
            // It is a linear pass and costs far less than rebuilding.
            $shuffleSeed = abs($sectionId * 2053 + (int) $course->id * 97 + $seed);
            $domain = $this->seededShuffle($domain, $shuffleSeed);

            // Named separately from the requirements failure: the fix is to
            // widen or clear the section's period, not to change the course.
            if ($throwOnEmptyDomain && $emptyAfterPeriod && $coursePeriods !== null) {
                throw new RuntimeException(sprintf(
                    '%s / %s cannot be scheduled inside the %s period. Choose a wider period for this section, or clear its preferred meeting time.',
                    $this->sectionLabel($sectionId),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                    SchedulingPolicy::preferredPeriodsLabel($coursePeriods),
                ));
            }

            // Step 1's Preferred Days removed every candidate. Named apart from
            // the period so the fix points at the right control.
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

            // The course could meet on other days; the department's forced day is
            // what rules every candidate out (e.g. a field course forced onto
            // Sunday), so point at that setting rather than at room conflicts.
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

            // Enforce domain candidate priority order after shuffle:
            //   0 -> preferred physical room, on-site  (laboratory for lab courses, lecture for lecture courses)
            //   1 -> fallback physical room, on-site   (lecture room fallback for lab courses)
            //   2 -> online delivery mode              (tried last when physical rooms unavailable)
            //
            // The ranking keys are computed once per candidate rather than
            // inside the comparator: candidateAllocationPriority alone walks a
            // candidate's blocks several times, and a comparator re-runs that
            // for every one of the O(n log n) comparisons. The trailing index
            // keeps the shuffled order for full ties, so the result is
            // identical to the previous comparator.
            $ranked = [];
            foreach ($domain as $rankIndex => $rankCandidate) {
                $ranked[] = [
                    'allocation' => $this->candidateAllocationPriority($rankCandidate, $sectionId),
                    'preferred_room' => $this->candidatePreferredRoomRank($rankCandidate),
                    'availability' => $this->candidateRoomAvailabilityPenalty($rankCandidate),
                    'concentration' => $this->candidateRoomConcentrationPenalty($rankCandidate),
                    'hybrid_order' => $this->candidateHybridSplitOrderRank($rankCandidate, $sectionId),
                    'index' => $rankIndex,
                    'candidate' => $rankCandidate,
                ];
            }
            // The preferred room ranks only inside an allocation tier, so it can
            // never pull Saturday, Room TBA or Online ahead of a weekday room.
            usort(
                $ranked,
                static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
                    ?: $left['preferred_room'] <=> $right['preferred_room']
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
                'physical_room_options' => $this->countPhysicalRoomOptions($domain),
                'duration_slots' => $durationSlots,
                'preferred_pattern' => $preferredPattern,
                'forced_day' => $forcedDay,
                'delivery_mode' => $courseDeliveryMode,
                'is_hybrid' => $courseIsHybrid,
                'domain' => $domain,
            ];
        }

        return $variables;
    }

    /**
     * Identity of a course's candidate set. Every input that can change which
     * candidates are produced must appear here; anything ranked or filtered
     * later against live solver state must not.
     *
     * Deliberately not keyed by section: no domain builder or filter takes a
     * section, so two sections offering the same course under the same
     * configuration have the same candidate set. Everything that does vary per
     * section -- requirements, anchored schedules, forced day, delivery mode,
     * split and pattern selections -- is passed in $parts. Section-specific
     * ordering happens after the cache, in the seeded shuffle and the ranking.
     *
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
        // NSTP is field only when the department made it one; then it may
        // use any day. Otherwise it is scheduled like any other minor.
        if ($this->isNstpCourse($course) && $this->isFieldCourse($course)) {
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
        bool $sundayOnlineOnlyEnabled = true,
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
        $dayModePairs = $isField
            ? array_map(
                static fn (string $day): array => [$day, 'field'],
                $this->isNstpCourse($course)
                    ? SchedulingPolicy::DAYS
                    : SchedulingPolicy::WEEKDAYS,
            )
            : $this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled);

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

            // For on-site courses, prioritize room type based on curriculum (lab_hours).
            // A lecture course may fall back to a lecture-capable laboratory, but a
            // laboratory course gets no lecture-room fallback: RuleEngine rejects a
            // laboratory course in a lecture room, so such a candidate could only
            // ever produce a preview that fails to save. Online stays the fallback
            // when no laboratory is free.
            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = ['laboratory'];
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
                            '_lab_fallback' => $isLabCourse && $roomType === 'lecture',
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

    private function onlineLectureAssignmentCount(array $assignments): int
    {
        return count(array_filter(
            $assignments,
            fn (array $assignment): bool => $this->hasOnlineLectureBlock($assignment),
        ));
    }

    /**
     * Keeps only candidates whose every meeting falls on an allowed day, so a
     * Split Session or Hybrid survives only when both its days are allowed.
     *
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

    /**
     * Keeps only candidates whose every meeting fits inside one of the
     * teaching windows (a course's Preferred Meeting can allow several). A
     * meeting that starts inside a window but runs past its end is rejected
     * too: the point of the restriction is that a cohort is never on campus
     * outside its period.
     *
     * @param  list<array{0: int, 1: int}>  $windows
     */
    private function filterDomainByWindows(array $domain, array $windows): array
    {
        return array_values(array_filter(
            $domain,
            static function (array $candidate) use ($windows): bool {
                foreach ($candidate['blocks'] ?? [] as $block) {
                    $start = (int) ($block['start_slot'] ?? 0);
                    $end = (int) ($block['end_slot'] ?? 0);
                    $fits = false;
                    foreach ($windows as [$from, $to]) {
                        if ($start >= $from && $end <= $to) {
                            $fits = true;
                            break;
                        }
                    }
                    if (! $fits) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function filterDomainByWindow(array $domain, int $from, int $to): array
    {
        return $this->filterDomainByWindows($domain, [[$from, $to]]);
    }

    /** Human wording for the window, used when it leaves nothing to place. */
    private function preferredPeriodLabel(string $period): string
    {
        return SchedulingPolicy::preferredPeriodLabel($period);
    }

    private function forcedDaysByCourseId(int $departmentId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        // The exact forced-day state that was validated and fingerprinted.
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
        bool $sundayOnlineOnlyEnabled = true,
        ?int $lectureSlots = null,
        ?int $laboratorySlots = null,
    ): array {
        if ($this->isFieldCourse($course)) {
            return [];
        }

        // Two separate meetings: the lengths chosen in Setup Courses, or
        // each sized from the course itself.
        $lectureSlots ??= SchedulingPolicy::lectureComponentSlots($course);
        $labSlots = $laboratorySlots ?? $this->laboratoryComponentSlots($course);

        if ($lectureSlots <= 0 || $labSlots <= 0) {
            return [];
        }

        $labRooms = $matchingRooms->filter(
            static fn (Rooms $room): bool => $room->room_type === 'laboratory',
        );

        $lectureOptions = $this->splitLectureOptions(
            matchingRooms: $matchingRooms,
            isHybrid: $isHybrid,
            forceOnline: $deliveryMode === 'online',
        );

        $labOptions = $labRooms
            ->map(static fn (Rooms $room): array => [
                'room_id' => (int) $room->id,
                'room_type' => 'laboratory',
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
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
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
                        // Laboratory room selection must see every valid
                        // lecture/lab time pair. Otherwise a TBA candidate in
                        // the first few pairs can win while a real laboratory
                        // remains available in a later pair.
                        limit: null,
                    );
                }

                foreach ($startPairs as $startPairIndex => [$day1Start, $day2Start]) {
                    $day1End = $day1Start + $firstComponent['slots'];
                    $day2End = $day2Start + $secondComponent['slots'];

                    // Lecture/lab split meetings must be distributed across
                    // different days. Saturday is part of the normal physical
                    // range; it is never represented as a same-day fallback.
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
                                // Preserve the fallback marker on the composed
                                // split candidate. Without this, a Room TBA lab
                                // is ranked like a real lab room and may win
                                // merely because it has no room-usage penalty.
                                '_room_tba' => (bool) (($option1['_room_tba'] ?? false) || ($option2['_room_tba'] ?? false)),
                                // Preserve the lecture-online fallback marker too, so the
                                // search and the solution filter can tell a lecture that
                                // chose online from one that only fell back to it after
                                // every compatible lecture room was taken.
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
        bool $sundayOnlineOnlyEnabled,
        bool $isHybrid = false,
    ): array
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
            ['Monday', 'Saturday'],
            ['Tuesday', 'Saturday'],
            ['Wednesday', 'Saturday'],
            ['Thursday', 'Saturday'],
            ['Friday', 'Saturday'],
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
     * A bounded, rotating window of physical room options, with every virtual
     * fallback (online lecture, Room TBA laboratory) always kept.
     *
     * A split candidate pairs one room per component, so enumerating every
     * lecture-room x laboratory-room combination at every day and start pair
     * multiplies out the whole room inventory. A department with 30 lecture
     * rooms and 6 laboratories produced over 150,000 candidates for a single
     * course and exhausted memory before the search even began. Offering each
     * day/start pair a rotating slice instead keeps every room reachable
     * somewhere in the domain while the candidate count stays proportional to
     * the number of time slots rather than to the square of the inventory.
     *
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
            // A course's Preferred Room is offered at every time pair, or the
            // preference could only ever win the pairs its rotation reached.
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

        // Online remains a valid alternative when all compatible lecture
        // rooms are occupied or otherwise unavailable, but it is never the
        // default for a non-Hybrid lecture/laboratory course.
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
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
            );
        }

        return $domain;
    }

    /**
     * Hybrid Split: two equal meetings for a three-unit lecture course, with
     * one online block and one physical block. The course and its delivery
     * choice select this shape; no department switch participates.
     */
    private function buildFlexibleHybridSplitDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        $domain = [];
        foreach ($this->balancedSplitDayPairs($course, $sundayOnlineOnlyEnabled) as [$day1, $day2]) {
            $domain = array_merge(
                $domain,
                $this->buildHybridSplitPatternDomain(
                    course: $course,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    preferredPattern: sprintf('days:%d-%d', $this->dayIndex($day1), $this->dayIndex($day2)),
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
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
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        if ($durationSlots < 2 || $durationSlots % 2 !== 0 || ! SchedulingPolicy::hybridSplitEligible($course)) {
            return [];
        }

        [$day1, $day2] = $this->patternDays($preferredPattern);
        $allowedDays = array_unique(array_column(
            $this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled),
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

        // The face-to-face meeting is a lecture, so it takes the rooms
        // RoomTypeRule accepts for one: a lecture room, or a laboratory flagged
        // for lecture use when the course is a lecture-only major. It gets no
        // Room TBA either -- that fallback belongs to laboratories alone
        // (`allowsRoomTbaFallback`); offering it produced previews the save
        // refused.
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
            foreach ($starts as $start2) {
                foreach ([false, true] as $onlineFirst) {
                    $first = $onlineFirst ? $onlineOption : null;
                    $second = $onlineFirst ? null : $onlineOption;
                    $firstOptions = $onlineFirst ? [$first] : $physicalOptions;
                    $secondOptions = $onlineFirst ? $physicalOptions : [$second];
                    foreach ($firstOptions as $option1) {
                        foreach ($secondOptions as $option2) {
                            // The candidate is labelled by its face-to-face
                            // meeting whichever day it falls on. Labelling it by
                            // the first meeting ranked every Online-first order
                            // in the online tier, so every section met F2F on
                            // its first day and online on its second.
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
     * The day pairs a Split Session or Hybrid Split may meet on.
     *
     * MW and TTh stay the pattern whenever the run allows them. When Step 1's
     * Preferred Days leave neither, the pairs come from the chosen days
     * instead -- spaced pairs (a rest day between) before back-to-back ones --
     * so any two Preferred Days can hold a two-day class, as the year-level
     * pre-check promises. Without Preferred Days the search is unchanged.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function balancedSplitDayPairs(Course $course, bool $sundayOnlineOnlyEnabled): array
    {
        $courseDays = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair[0],
            $this->allowedDayModePairsForCourse($course, $sundayOnlineOnlyEnabled),
        )));
        $days = array_values(array_filter(
            SchedulingPolicy::DAYS,
            fn (string $day): bool => in_array($day, $courseDays, true)
                && ($this->allowedDays === null || in_array($day, $this->allowedDays, true)),
        ));

        $pairs = array_values(array_filter(
            SchedulingPolicy::FIXED_MEETING_PATTERNS,
            static fn (array $pair): bool => in_array($pair[0], $days, true) && in_array($pair[1], $days, true),
        ));
        // Friday + Saturday is an extra pair the run may allow, tried after
        // MW and TTh so it only takes the classes those cannot hold.
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
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        $primaryDomain = $this->buildPatternDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            preferredPattern: $preferredPattern,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            requireBalancedDurations: $requireBalancedDurations,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
        );

        // Alternative recommendations when preferred pattern is occupied:
        // 1. Alternative vacant split day/time pattern with the same required duration
        $alternativePatternDomain = [];
        if ($durationSlots >= 2) {
            $flexibleSplitDomain = $this->buildFlexibleBalancedSplitDomain(
                course: $course,
                matchingRooms: $matchingRooms,
                durationSlots: $durationSlots,
                deliveryMode: $deliveryMode,
                isHybrid: $isHybrid,
                sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
            );

            foreach ($flexibleSplitDomain as $candidate) {
                if (($candidate['preferred_pattern'] ?? null) !== $preferredPattern) {
                    $candidate['_pattern_fallback'] = true;
                    $alternativePatternDomain[] = $candidate;
                }
            }
        }

        // A configured Split remains a Split. A full-duration regular meeting
        // is a user-facing recommendation, never an automatic solver fallback.
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
        // The requirement builder may resolve a field course using the
        // department-scoped field-course settings. Preserve that resolved mode
        // here even when the legacy course-only classifier lacks the context.
        $isField = $deliveryMode === 'field' || $this->isFieldCourse($course);
        $isLabCourse = $this->isMajorLabCourse($course);
        $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
        $lecHours = (int) ($course->lecture_hours ?? 0);
        $labHours = (int) ($course->lab_hours ?? 0);
        $hasBothComponents = $isMajor && $lecHours > 0 && $labHours > 0;

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

            // For on-site courses, prioritize room type based on curriculum (lab_hours).
            // A lecture course may fall back to a lecture-capable laboratory, but a
            // laboratory course gets no lecture-room fallback: RuleEngine rejects a
            // laboratory course in a lecture room, so such a candidate could only
            // ever produce a preview that fails to save. Online stays the fallback
            // when no laboratory is free.
            $roomTypes = [$targetRoomType];
            if ($mode === 'on-site') {
                if ($isLabCourse) {
                    $roomTypes = ['laboratory'];
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
                    // Do not truncate laboratory pairs: all physical lab
                    // slots must be exhausted before Room TBA is considered.
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
                        $labOptions = $matchingRooms
                            ->filter(static fn (Rooms $room): bool => $room->room_type === 'laboratory')
                            ->map(static fn (Rooms $room): array => [
                                'room_id' => (int) $room->id,
                                'room_type' => 'laboratory',
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
                                    // Preserve the lecture-online fallback marker too, so the
                                    // search and the solution filter can tell a lecture that
                                    // chose online from one that only fell back to it after
                                    // every compatible lecture room was taken.
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
                                    '_lab_fallback' => $isLabCourse && $roomType === 'lecture',
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
     * Rank split start-time pairs by proximity. Minor/GEC split sessions use
     * the complete pair list so an occupied early window cannot hide a valid
     * later physical slot. Lecture/laboratory splits may pass the historical
     * bound because each pair is multiplied by rooms and component orders.
     *
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
                        // Section time overlap — always a conflict.
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

        // Lightweight mode/room-type alignment guard using the room_type embedded
        // in the candidate by the domain builder. This is a zero-query safety net
        // that catches any mode/room mismatch (e.g. online room for an on-site course)
        // without hitting the database on every backtracking iteration.
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

                    // Prefer rooms that are still empty or lightly used in the current semester.
                    $score += ($this->existingRoomUseCounts[$blockRoomId] ?? 0) * 3;
                }

                $blockDurations[] = $block['end_slot'] - $block['start_slot'];

                // Saturday is normally discouraged, but a single meeting in a
                // lecture room is exactly what department policy wants late in
                // the week, so scoring must not pull it back onto Mon-Thu after
                // the search deliberately placed it there. Sunday stays
                // discouraged for every candidate.
                $prefersLateWeek = $this->prefersLateWeekPlacement($assignment);

                if ($block['day'] === 'Saturday' && ! $prefersLateWeek) {
                    $score += 200;
                }

                if ($block['day'] === 'Sunday') {
                    $score += 1000;
                }

                if ($assignment['_weekday_physical_available'] ?? false) {
                    $migratedToWeekend = $block['day'] === 'Sunday'
                        || ($block['day'] === 'Saturday' && ! $prefersLateWeek);

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

                        if (($previous['is_classroom'] ?? false) && ($roomDayBlock['is_classroom'] ?? false)) {
                            $score += $this->classroomAwkwardGapPenalty($gapSlots);
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

                // The assignment flag covers both halves of a lecture/lab
                // pair; only the half that actually went online was moved.
                if (($assignment['_lecture_online_fallback'] ?? false) && $row['mode'] === 'online') {
                    $row['lecture_online_fallback'] = true;
                }

                if ($hasMultipleBlocks) {
                    $row['split_group_id'] = $splitGroupId;
                    $row['meeting_index'] = $index + 1;

                    // Determine meeting type: lecture or laboratory
                    $courseId = (int) $assignment['course_id'];
                    $courseObj = $this->loadedCoursesById[$courseId] ?? null;
                    if (! empty($block['meeting_type'])) {
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

        // When any course in the batch has a laboratory preference, also fetch
        // lecture rooms so they are available as a fallback for departments
        // that have no lab rooms or whose labs are fully booked.
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
        return SchedulingPolicy::isFieldCourse($course, $this->solveDepartmentId ?: null);
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

    /**
     * The laboratory half of this course's split, in slots.
     *
     * Reads the department resolved for the current solve, so a department
     * running Custom Lab Duration generates the length it configured instead
     * of the unit-derived default the RuleEngine would otherwise reject.
     */
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
        if ($this->isFieldCourse($course) || $this->isNstpCourse($course)) {
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

    /**
     * Hybrid Split may meet F2F-first or Online-first, and neither is better.
     * The order alternates by section and course, so consecutive sections --
     * and a section's own Hybrid Split courses -- take opposite orders instead
     * of repeating one pattern. It is only a tie-break: rooms, time and every
     * hard constraint rank first, and it is stable across retries.
     */
    private function candidateHybridSplitOrderRank(array $candidate, int $sectionId): int
    {
        if (! array_key_exists('_hybrid_online_first', $candidate)) {
            return 0;
        }

        $preferOnlineFirst = (($sectionId + (int) ($candidate['course_id'] ?? 0)) % 2) === 1;

        return (bool) $candidate['_hybrid_online_first'] === $preferOnlineFirst ? 0 : 1;
    }

    private function candidateAllocationPriority(array $candidate, int $sectionId): int
    {
        $isPatternFallback = (bool) ($candidate['_pattern_fallback'] ?? false);
        $isSingleSessionFallback = (bool) ($candidate['_single_session_fallback'] ?? false);

        $mode = (string) ($candidate['mode'] ?? 'on-site');

        if ($mode === 'field' || $this->candidateContainsFieldBlock($candidate)) {
            return 0;
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

            return $this->candidateContainsWeekendBlock($candidate) ? 3 : 1;
        }

        if ($candidate['_lecture_lab_room_fallback'] ?? false) {
            return $this->candidateContainsWeekendBlock($candidate) ? 5 : 3;
        }

        if ($this->candidateContainsLaboratoryBlock($candidate)) {
            return $mode === 'online'
                ? 8
                : ($this->candidateContainsWeekendBlock($candidate) ? 3 : 0);
        }

        if ($mode === 'online') {
            $onlineTier = 10;
            if ($isPatternFallback) {
                $onlineTier = 12;
            } elseif ($isSingleSessionFallback) {
                $onlineTier = 14;
            }

            // The extra step is the Sunday surcharge. A department that teaches
            // on Sunday ranks it with Saturday instead.
            return $this->candidateContainsWeekendBlock($candidate)
                && ! $this->candidateContainsSaturdayBlock($candidate)
                && ! $this->sundayIsRegularTeachingDay
                ? $onlineTier + 1
                : $onlineTier;
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

        return $this->candidateContainsWeekendBlock($candidate) ? $physicalTier + 3 : $physicalTier;
    }

    /**
     * Distinct physical rooms this domain could still use. A low count marks a
     * limited-room course, which the variable ordering places earlier so it
     * claims a room before the flexible courses consume them.
     *
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

        // A domain with no physical room at all (virtual online/field delivery)
        // is not room-constrained, so it must not sort ahead of a course that is.
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

    private function candidateContainsSaturdayBlock(array $candidate): bool
    {
        foreach ($candidate['blocks'] ?? [] as $block) {
            if (($block['day'] ?? null) === 'Saturday') {
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

    /**
     * Pre-fetches all persisted schedules for the given semester into memory and
     * builds lookup indexes including:
     *   "r:{roomId}:{day}"     → time ranges already booked for that room on that day
     *   "s:{sectionId}:{day}" → time ranges already booked for that section on that day
     *   "f:{facultyId}:{day}" → time ranges already booked for that instructor on that day
     *   "c:{courseId}:{day}"  → online time ranges already used by other sections
     *
     * This single query replaces the repeated per-candidate DB queries that were
     * previously issued inside the backtracking loop.
     */
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
        $this->solveDepartmentId = $departmentId;

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

        // Rooms other schedules already hold may be outside this section's
        // usable rooms; the snapshot captures every referenced room, so their
        // types come from it too.
        $snapshotRooms = $this->snapshot()->roomsById;
        foreach ($missingRoomTypeIds as $roomId) {
            if (isset($snapshotRooms[$roomId]['room_type'])) {
                $this->roomTypes[$roomId] = (string) $snapshotRooms[$roomId]['room_type'];
            }
        }

        foreach ($schedules as $schedule) {
            // Persisted times reach the solver in mixed shapes: the snapshot
            // truncates them to H:i (SchedulingSnapshotRepository) while the
            // legacy database path and every candidate use H:i:s. Comparing
            // those as raw strings is wrong -- "11:00" < "11:00:00" is true --
            // so a class ending at 11:00 appeared to overlap one starting at
            // 11:00 and every back-to-back placement was pruned as a conflict.
            // Precompute minutes once here and compare numerically instead.
            $timeRange = [
                'start_time' => (string) $schedule->start_time,
                'end_time' => (string) $schedule->end_time,
                'start_minutes' => $this->timeToMinutes((string) $schedule->start_time),
                'end_minutes' => $this->timeToMinutes((string) $schedule->end_time),
            ];

            if ($schedule->room_id !== null) {
                $roomId = (int) $schedule->room_id;
                $roomType = $this->roomTypes[$roomId] ?? null;
                // Field and online rooms are shared without a limit, so only a
                // lecture or laboratory room is ever booked out.
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
            if (($schedule->mode ?? null) === 'online') {
                $this->existingScheduleIndex["c:{$schedule->course_id}:{$schedule->day}"][] = $timeRange + [
                    'section_id' => (int) $schedule->section_id,
                ];
            }

            // Index instructor availability so the CSP can avoid recommending
            // slots that conflict with an already-assigned faculty member.
            if (! empty($schedule->faculty_id)) {
                $this->existingScheduleIndex["f:{$schedule->faculty_id}:{$schedule->day}"][] = $timeRange;
            }
        }
    }

    /**
     * Granted windows for the section's department. The snapshot carries them
     * on the room records so they are part of its fingerprint.
     *
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

    /**
     * Books every granted room as occupied outside its windows, so domain
     * pruning drops those placements through the same conflict check that
     * keeps two classes out of one room. Without it the generator would build
     * candidates the RuleEngine refuses.
     */
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

    /** @return list<array<string, mixed>> */
    private function snapshotScheduleRows(int $semesterId): array
    {
        return array_values(array_filter(
            $this->snapshot()->persistedSchedules,
            static fn (array $schedule): bool => (int) ($schedule['semester_id'] ?? $semesterId) === $semesterId,
        ));
    }

    /**
     * Returns true if any persisted schedule conflicts with the given time window
     * for the candidate room, target section, online subject, or assigned instructor.
     *
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
        // Compare on minutes, never on raw strings. Persisted rows arrive as
        // H:i from the snapshot and H:i:s from the legacy database path, and
        // "11:00" < "11:00:00" is true, which made every back-to-back
        // placement look like a conflict.
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
                if ((int) ($existing['section_id'] ?? 0) !== $sectionId
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

    /**
     * True when at least $threshold indexed bookings overlap the given window.
     *
     * This is the innermost check of the whole search -- domain pruning alone
     * runs it hundreds of thousands of times per generation. Counting stops at
     * the threshold, and the semantics are distinct overlapping bookings rather
     * than peak concurrency, matching what the capacity rules expect.
     */
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
