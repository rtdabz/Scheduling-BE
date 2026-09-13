<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\Department\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CSPSolver
{
    /**
     * Physical rooms offered to each component of a split candidate at any one
     * day/start pair. See boundedRoomOptions().
     */
    private const SPLIT_ROOM_OPTIONS_PER_SLOT = 6;

    private const SOFT_FIELD_EVENING_PENALTY = 6;

    private const SPLIT_LECTURE_LAB_START_PAIR_LIMIT = 6;

    /** @var list<int> */
    private const CLASSROOM_SCHEDULABLE_BLOCK_SLOTS = [3, 4, 6];

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

    /**
     * The teaching periods a section can be restricted to are defined once, on
     * SchedulingPolicy::PREFERRED_PERIOD_WINDOWS.
     *
     * Unlike the per-course time preference above, a period is a hard window: a
     * section assigned to one is only ever offered candidates that fit inside it,
     * so the generator cannot place an 8:00 AM class for an afternoon cohort. A
     * window that cannot hold the section's courses fails the run rather than
     * quietly spilling outside it, which is why the feasibility pre-check reads
     * the same definition and refuses up front.
     */

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

    public function setTentativeSchedules(array $schedules): void
    {
        $this->tentativeSchedules = $schedules;
    }

    public function setInputSnapshot(?SchedulingSnapshot $snapshot): void
    {
        if ($snapshot === null && (bool) config('app.require_scheduling_snapshot', false)) {
            throw new InvalidArgumentException('A SchedulingSnapshot is required when REQUIRE_SCHEDULING_SNAPSHOT is enabled.');
        }

        // Year-level generation re-supplies the same snapshot before every
        // solver attempt. Clearing unconditionally would throw away the term
        // rows and the built domains on each call, which is exactly the work
        // these caches exist to avoid.
        $unchanged = $this->inputSnapshot !== null
            && $snapshot !== null
            && $this->inputSnapshot->fingerprint === $snapshot->fingerprint;

        $this->inputSnapshot = $snapshot;

        if (! $unchanged) {
            $this->termScheduleRowsCache = [];
            $this->domainCache = [];
        }
    }

    public function usesLegacyDatabaseFallback(): bool
    {
        return $this->inputSnapshot === null;
    }

    private function assertSnapshotRequirement(): void
    {
        if ($this->inputSnapshot === null && (bool) config('app.require_scheduling_snapshot', false)) {
            throw new InvalidArgumentException('A SchedulingSnapshot is required when REQUIRE_SCHEDULING_SNAPSHOT is enabled.');
        }
    }

    public function beginGenerationContext(): void
    {
        $this->loadedCoursesById = [];
        $this->termScheduleRowsCache = [];
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

    /** @var array<int, string> */
    private array $generationForcedDaysByCourseId = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $requirementsByCourseId = [];

    /** @var array<int, string> Course id => 'morning'|'afternoon'|'evening'. */
    private array $timePreferencesByCourseId = [];

    /** The section's hard teaching window, or null when it may use any time. */
    private ?string $preferredPeriod = null;

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
    private array $termScheduleRowsCache = [];

    /**
     * Built candidate sets keyed by domainCacheKey(), reused across the many
     * solver attempts a single generation run makes. Cleared per generation
     * context, never across runs.
     *
     * @var array<string, array{domain: list<array<string, mixed>>, empty_after_requirements: bool}>
     */
    private array $domainCache = [];

    private ?SchedulingSnapshot $inputSnapshot = null;

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
        return $this->departmentRoomFairness;
    }

    /** @return array<int, string> */
    public function generationForcedDaysByCourseId(): array
    {
        return $this->generationForcedDaysByCourseId;
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

    private float $metricsStartedAt = 0.0;

    private int $metricsVariableCount = 0;

    private int $metricsCandidateCountBefore = 0;

    private int $metricsCandidateCountAfter = 0;

    private DepartmentResourceSlotLimitService $resourceLimits;

    public function __construct(?DepartmentResourceSlotLimitService $resourceLimits = null)
    {
        $this->resourceLimits = $resourceLimits ?? new DepartmentResourceSlotLimitService;
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
     *     timeoutSeconds?: float|int|string,
     *     throw_on_empty_domain?: bool|int|string,
     *     allow_room_tba_fallback?: bool|int|string
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
     *     timeoutSeconds?: float|int|string,
     *     throw_on_empty_domain?: bool|int|string,
     *     allow_room_tba_fallback?: bool|int|string
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
            requirementsByCourseId: $schema['requirements_by_course_id'],
            timePreferencesByCourseId: $schema['time_preferences_by_course_id'],
            preferredPeriod: $schema['preferred_period'],
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
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        array $requirementsByCourseId = [],
        array $timePreferencesByCourseId = [],
        ?string $preferredPeriod = null,
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
            anchoredSchedulesByCourseId: $anchoredSchedulesByCourseId,
            deliveryModesByCourseId: $deliveryModesByCourseId,
            requirementsByCourseId: $requirementsByCourseId,
            timePreferencesByCourseId: $timePreferencesByCourseId,
            preferredPeriod: $preferredPeriod,
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
        array $anchoredSchedulesByCourseId = [],
        array $deliveryModesByCourseId = [],
        array $requirementsByCourseId = [],
        array $timePreferencesByCourseId = [],
        ?string $preferredPeriod = null,
        ?int $seed = null,
        array $tentativeSchedules = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
        bool $allowOnlineFallback = true,
    ): array {
        $this->assertSnapshotRequirement();

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
        $this->tentativeSchedules = $tentativeSchedules;
        $this->generationForcedDaysByCourseId = [];
        $this->requirementsByCourseId = $this->normalizeRequirements($requirementsByCourseId, $courseIds);
        $this->timePreferencesByCourseId = $this->normalizeTimePreferences($timePreferencesByCourseId, $courseIds);
        $this->preferredPeriod = $this->normalizePreferredPeriod($preferredPeriod);

        $courseIds = $this->normalizeCourseIds($courseIds);

        if ($courseIds === []) {
            return [];
        }

        /** @var Sections $section */
        if ($this->inputSnapshot !== null && $this->inputSnapshot->termId > 0) {
            $sectionAttributes = $this->inputSnapshot->sectionsById[$sectionId] ?? null;
            if (! is_array($sectionAttributes)) {
                throw new RuntimeException('The requested section is not present in the scheduling snapshot.');
            }
            $section = new Sections($sectionAttributes);
            // Restore guarded identity/scheduling fields explicitly when
            // reconstructing a section from snapshot attributes.
            $section->id = (int) ($sectionAttributes['id'] ?? $sectionId);
            $section->term_id = (int) ($sectionAttributes['term_id'] ?? $this->inputSnapshot->termId);
            $section->department_id = (int) ($sectionAttributes['department_id'] ?? $this->inputSnapshot->departmentId);
            $section->year_level = (string) ($sectionAttributes['year_level'] ?? '');
            $section->semester = (string) ($sectionAttributes['semester'] ?? '');
            $term = new Terms($this->inputSnapshot->term);
            $term->id = (int) ($this->inputSnapshot->term['id'] ?? $this->inputSnapshot->termId);
            $term->semester = (string) ($this->inputSnapshot->term['semester'] ?? '');
            $section->setRelation('term', $term);
        } else {
            $section = Sections::query()
                ->with('term')
                ->findOrFail($sectionId);
        }
        $resourceLimits = $this->inputSnapshot !== null
            && $this->inputSnapshot->departmentId === (int) $section->department_id
            ? [
                'online' => max(1, (int) ($this->inputSnapshot->resourceLimits['online'] ?? 1)),
                'field' => max(1, (int) ($this->inputSnapshot->resourceLimits['field'] ?? 1)),
            ]
            : $this->resourceLimits->forDepartment((int) $section->department_id);

        $this->validateSectionForScheduling($section);

        if ($this->inputSnapshot !== null && $this->inputSnapshot->termId === (int) $section->term_id) {
            // Snapshot entries begin as attribute arrays, so transform them
            // with a base collection before wrapping the resulting Course
            // models in the Eloquent collection contract below.
            $courses = collect($this->inputSnapshot->coursesById);
            $courses = $courses
                ->only(array_map('intval', $courseIds))
                ->map(function (array $attributes): Course {
                    $course = new Course($attributes);
                    // The primary key is guarded by the model and is not
                    // restored by mass assignment. Preserve it explicitly so
                    // snapshot courses remain addressable by course ID.
                    $course->id = (int) ($attributes['id'] ?? 0);

                    return $course;
                })
                ->keyBy('id');
            // Base Collection::map() is returned when the callback changes
            // array snapshots into Course models. Re-wrap the final map so
            // downstream solver helpers receive the required Eloquent type.
            $courses = new Collection($courses->all());
        } else {
            $courses = Course::query()
                ->whereIn('id', $courseIds)
                ->get()
                ->keyBy('id');

            // The section's own curriculum, not the department's first active
            // one — those stopped being the same thing once a department could
            // run an old and a new curriculum side by side. Resolved through the
            // shared resolver rather than read off the column, so this path
            // adopts an unassigned section's only curriculum exactly as the
            // preflight and the snapshot do; a disagreement here would let the
            // solver place a course the validator goes on to refuse.
            $curriculumId = null;
            try {
                $curriculumId = (int) app(SectionCurriculumResolver::class)->forSection($section)->id;
            } catch (InvalidArgumentException) {
                // No curriculum to resolve. The stored course metadata stands in,
                // and the preflight has already reported this to the user.
            }

            if ($curriculumId !== null) {
                $pivotMap = DB::table('curriculum_course')
                    ->where('curriculum_id', $curriculumId)
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
        }

        $this->ensureAllCoursesExist(
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

        $this->roomGrantWindows = $this->grantWindowsForSection($section);

        if ($this->inputSnapshot !== null && $this->inputSnapshot->departmentId === (int) $section->department_id) {
            $rooms = collect($this->inputSnapshot->roomsById)
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
        } else {
            $rooms = Rooms::query()
                ->where('status', 'available')
                ->whereIn('room_type', $requiredRoomTypes)
                ->where(function ($query) use ($section): void {
                    $query
                        ->whereNull('department_id')
                        ->orWhere('department_id', $section->department_id);
                    if ($this->roomGrantWindows !== []) {
                        $query->orWhereIn('id', array_keys($this->roomGrantWindows));
                    }
                })
                ->orderBy('room_code')
                ->get();
        }

        foreach ($requiredRoomTypes as $rt) {
            if (($rt === 'field' || $rt === 'online') && ! $rooms->contains('room_type', $rt)) {
                $existingVirtual = $rooms->firstWhere('room_code', strtoupper($rt));
                if ($existingVirtual === null && ($this->inputSnapshot === null || $this->inputSnapshot->departmentId !== (int) $section->department_id)) {
                    $existingVirtual = Rooms::query()->where('room_code', strtoupper($rt))->first();
                }
                $virtualRoom = new Rooms([
                    'room_code' => strtoupper($rt),
                    'room_type' => $rt,
                    'status' => 'available',
                    'department_id' => null,
                    'max_concurrent_classes' => $rt === 'online'
                        ? $resourceLimits['online']
                        : $resourceLimits['field'],
                ]);
                $virtualRoom->id = $existingVirtual ? $existingVirtual->id : ($rt === 'field' ? 99999 : 99998);
                $rooms->push($virtualRoom);
            }
        }

        $this->roomCapacities = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                // For online/field rooms use their actual max_concurrent_classes
                // (which now reflects the active-section-count scaling for virtual
                // rooms, and the DB-configured value for real rooms).
                (int) $room->id => max(1, (int) ($room->max_concurrent_classes ?? 1)),
            ])
            ->all();
        $this->roomTypes = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                (int) $room->id => (string) $room->room_type,
            ])
            ->all();

        $this->prepareDepartmentRoomFairness(
            section: $section,
            rooms: $rooms,
        );

        $this->preloadExistingSchedules(
            termId: (int) $section->term_id,
            sectionId: (int) $section->id,
            departmentId: (int) $section->department_id,
            replaceCourseIds: $courseIds,
            tentativeSchedules: $this->tentativeSchedules,
        );
        $this->blockRoomsOutsideGrantWindows();

        $solverSeed = $seed !== null ? (int) $seed : random_int(1, 1000000);

        $settings = $this->inputSnapshot !== null && $this->inputSnapshot->departmentId === (int) $section->department_id
            ? $this->inputSnapshot->departmentSettings
            : [];
        $department = $settings === [] ? Departments::query()->find((int) $section->department_id) : null;
        $lectureLabScheduleOverrideEnabled = (bool) ($settings['lecture_lab_schedule_override_enabled'] ?? $department?->lecture_lab_schedule_override_enabled ?? false);
        $this->departmentLabSettings = $settings !== [] ? $settings : $department;
        $fieldEveningScheduleEnabled = (bool) ($settings['field_evening_schedule_enabled'] ?? $department?->field_evening_schedule_enabled ?? false);
        $sundayOnlineOnlyEnabled = (bool) ($settings['sunday_online_only_enabled'] ?? $department?->sunday_online_only_enabled ?? true);
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
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
            selectedLectureLabCourseIds: $selectedLectureLabCourseIds,
            balancedSplitCourseIds: $balancedSplitCourseIds,
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
            $this->minimumOnlineTargetForSection((int) $section->id),
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
        $ranked = $this->selectDiverseSolutions($scored, $maxSolutions);

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
        $fallbackUsage = $this->inputSnapshot === null
            ? ['legacy_database_loader' => 1]
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
                'penalty' => $this->candidateTentativeGapPenalty($candidate, $assignments)
                    + $this->candidateDayBalancePenalty($candidate, $dayLoads, $sectionId)
                    + $this->candidateTimePreferencePenalty($candidate),
                'index' => $index,
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
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
        $roomId = $this->nullableRoomId(
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
        $band = $this->computeTimeBand($startSlot);

        return match ($preference) {
            'morning' => $band === 'morning' || $band === 'midday',
            'afternoon' => $band === 'afternoon',
            'evening' => $band === 'evening',
            default => true,
        };
    }

    /**
     * @param  array<int|string, mixed>  $timePreferencesByCourseId
     * @param  list<int|string>  $validCourseIds
     * @return array<int, string>
     */
    private function normalizeTimePreferences(array $timePreferencesByCourseId, array $validCourseIds): array
    {
        if ($timePreferencesByCourseId === []) {
            return [];
        }

        $allowed = ['morning', 'afternoon', 'evening'];
        $valid = array_map('intval', $validCourseIds);
        $normalized = [];

        foreach ($timePreferencesByCourseId as $courseId => $preference) {
            $courseId = (int) $courseId;
            if (! in_array($courseId, $valid, true)) {
                continue;
            }

            $preference = is_string($preference) ? strtolower(trim($preference)) : '';
            if (in_array($preference, $allowed, true)) {
                $normalized[$courseId] = $preference;
            }
        }

        return $normalized;
    }

    private function classroomAwkwardGapPenalty(int $gapSlots): int
    {
        if ($gapSlots <= 0) {
            return 0;
        }

        $bestRemainder = $this->classroomBestRemainderAfterSchedulableBlocks($gapSlots);
        $filledSlots = $gapSlots - $bestRemainder;

        return ($gapSlots === 5 ? self::CLASSROOM_FIVE_SLOT_GAP_SOFT_PENALTY : 0)
            + ($gapSlots === 6 ? self::CLASSROOM_SIX_SLOT_GAP_SOFT_PENALTY : 0)
            + ($filledSlots * self::CLASSROOM_GAP_SCHEDULABLE_SLOT_SOFT_PENALTY)
            + ($bestRemainder * self::CLASSROOM_GAP_LEFTOVER_SLOT_SOFT_PENALTY);
    }

    private function classroomBestRemainderAfterSchedulableBlocks(int $gapSlots): int
    {
        $reachable = array_fill(0, $gapSlots + 1, false);
        $reachable[0] = true;

        for ($slots = 1; $slots <= $gapSlots; $slots++) {
            foreach (self::CLASSROOM_SCHEDULABLE_BLOCK_SLOTS as $blockSlots) {
                if ($slots >= $blockSlots && $reachable[$slots - $blockSlots]) {
                    $reachable[$slots] = true;
                    break;
                }
            }
        }

        for ($usedSlots = $gapSlots; $usedSlots >= 0; $usedSlots--) {
            if ($reachable[$usedSlots]) {
                return $gapSlots - $usedSlots;
            }
        }

        return $gapSlots;
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
        array $requirementsByCourseId = [],
        bool $throwOnEmptyDomain = true,
        bool $allowRoomTbaFallback = true,
    ): array {
        $variables = [];
        $roomsSignature = $this->roomsSignature($rooms);

        foreach ($courses as $course) {
            $courseDeliveryMode = $deliveryModesByCourseId[(int) $course->id] ?? $deliveryMode;
            $requirements = $requirementsByCourseId[(int) $course->id] ?? [];
            if ($this->requirementsRequireFieldDelivery($requirements)) {
                $courseDeliveryMode = 'field';
            }
            $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
            $lecHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            $hasBothComponents = $lectureLabScheduleOverrideEnabled
                && $isMajor
                && in_array((int) $course->id, $selectedLectureLabCourseIds, true)
                && $lecHours > 0
                && $labHours > 0;
            $courseIsHybrid = $isHybrid && $hasBothComponents;

            $preferredPattern = $this->normalizePreferredPattern(
                $preferredPatternsByCourseId[(int) $course->id] ?? null,
            );
            $requiresBalancedSplit = in_array((int) $course->id, $balancedSplitCourseIds, true);

            if ($hasBothComponents) {
                $durationSlots = ($lecHours * SchedulingPolicy::LECTURE_SLOTS_PER_UNIT)
                    + $this->laboratoryComponentSlots($course);
            } else {
                $durationSlots = $this->getDurationSlots($course);
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

            $domainCacheKey = $this->domainCacheKey(
                courseId: (int) $course->id,
                roomsSignature: $roomsSignature,
                parts: [
                    $courseDeliveryMode,
                    $courseIsHybrid ? 1 : 0,
                    $hasBothComponents ? 1 : 0,
                    $preferredPattern ?? '',
                    $requiresBalancedSplit ? 1 : 0,
                    $durationSlots,
                    $forcedDay ?? '',
                    $this->preferredPeriod ?? '',
                    $fieldEveningScheduleEnabled ? 1 : 0,
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
            } else {
            $domain = match (true) {
                $hasBothComponents && $preferredPattern === null => $this->buildDefaultLectureLabDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: $courseIsHybrid,
                    anchoredSchedule: $anchoredSchedulesByCourseId[(int) $course->id] ?? null,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $courseDeliveryMode === 'online' && $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $requiresBalancedSplit && $preferredPattern === null => $this->buildFlexibleBalancedSplitDomainWithFallbacks(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
                $preferredPattern === null => $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $courseDeliveryMode,
                    isHybrid: false,
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
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
                    fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                    sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
                ),
            };

            if (array_key_exists((int) $course->id, $deliveryModesByCourseId) && ! $hasBothComponents) {
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

            if ($forcedDay !== null) {
                $domain = $this->filterDomainByForcedDay($domain, $forcedDay);
            }

            $emptyAfterPeriod = false;
            if ($this->preferredPeriod !== null && $domain !== []) {
                [$windowFrom, $windowTo] = $this->preferredPeriodSlots($this->preferredPeriod);
                $domain = $this->filterDomainByWindow($domain, $windowFrom, $windowTo);
                $emptyAfterPeriod = $domain === [];
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
            ];
            }

            // The shuffle is seeded per attempt, so it stays outside the cache.
            // It is a linear pass and costs far less than rebuilding.
            $shuffleSeed = abs($sectionId * 2053 + (int) $course->id * 97 + $seed);
            $domain = $this->seededShuffle($domain, $shuffleSeed);

            // Named separately from the requirements failure: the fix is to
            // widen or clear the section's period, not to change the course.
            if ($throwOnEmptyDomain && $emptyAfterPeriod && $this->preferredPeriod !== null) {
                throw new RuntimeException(sprintf(
                    '%s / %s cannot be scheduled inside the %s period. Choose a wider period for this section, or clear its preferred meeting time.',
                    (string) ($sectionId > 0 ? (Sections::query()->find($sectionId)?->section_name ?? 'Section') : 'Section'),
                    (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id)),
                    $this->preferredPeriodLabel($this->preferredPeriod),
                ));
            }

            if ($throwOnEmptyDomain && $emptyAfterRequirements) {
                throw new RuntimeException(sprintf(
                    '%s / %s has no eligible scheduling candidates for the configured department profile.',
                    (string) ($sectionId > 0 ? (Sections::query()->find($sectionId)?->section_name ?? 'Section') : 'Section'),
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
                    'availability' => $this->candidateRoomAvailabilityPenalty($rankCandidate),
                    'concentration' => $this->candidateRoomConcentrationPenalty($rankCandidate),
                    'index' => $rankIndex,
                    'candidate' => $rankCandidate,
                ];
            }
            usort(
                $ranked,
                static fn (array $left, array $right): int => $left['allocation'] <=> $right['allocation']
                    ?: $left['availability'] <=> $right['availability']
                    ?: $left['concentration'] <=> $right['concentration']
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
                .((int) ($room->max_concurrent_classes ?? 1)).':'
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
            $blockRoomId = $this->nullableRoomId(
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
     * Keeps only candidates whose every meeting fits inside the section's
     * teaching window. A meeting that starts inside the window but runs past
     * its end is rejected too: the point of the restriction is that a cohort
     * is never on campus outside its period.
     */
    private function filterDomainByWindow(array $domain, int $from, int $to): array
    {
        return array_values(array_filter(
            $domain,
            static function (array $candidate) use ($from, $to): bool {
                foreach ($candidate['blocks'] ?? [] as $block) {
                    $start = (int) ($block['start_slot'] ?? 0);
                    $end = (int) ($block['end_slot'] ?? 0);
                    if ($start < $from || $end > $to) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * The period's slot bounds for the institution's current operating hours.
     *
     * The window is stated as wall-clock time, so it stays put when the
     * opening time moves: a morning cohort finishes at 11:30 whether the
     * campus opens at 07:00 or 08:00. Bounds are clamped into the schedulable
     * range, and a period entirely outside it collapses to an empty window,
     * which the caller reports as an unschedulable period.
     *
     * @return array{int, int}
     */
    private function preferredPeriodSlots(string $period): array
    {
        return SchedulingPolicy::preferredPeriodSlotRange($period);
    }

    private function normalizePreferredPeriod(mixed $period): ?string
    {
        return SchedulingPolicy::normalizePreferredPeriod($period);
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

        // Snapshot-aware solver runs must use the exact forced-day state that
        // was validated and fingerprinted by the application boundary. The
        // database query remains only for legacy direct CspSolver callers.
        if ($this->inputSnapshot !== null && $this->inputSnapshot->departmentId === $departmentId) {
            return array_intersect_key(
                $this->inputSnapshot->forcedDaysByCourseId,
                array_fill_keys(array_map('intval', $courseIds), true),
            );
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

        $lectureSlots = (int) ($course->lecture_hours ?? 0) * SchedulingPolicy::LECTURE_SLOTS_PER_UNIT;
        $labSlots = $this->laboratoryComponentSlots($course);

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
                    $windowedFirst = $this->boundedRoomOptions($firstOptions, $rotation);
                    $windowedSecond = $this->boundedRoomOptions($secondOptions, $rotation);

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
    private function boundedRoomOptions(array $options, int $rotation): array
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

    /**
     * A flexible minor/GEC split is a preference, not a hard requirement.
     * Keep every valid MW/TTh split candidate, then add a full-duration
     * candidate at the end of the domain.  The latter is deliberately marked
     * and ranked below split candidates; it is selected only when the complete
     * constraint search cannot place the split (room, time, persisted conflict,
     * delivery, or laboratory requirements are still enforced normally).
     */
    private function buildFlexibleBalancedSplitDomainWithFallbacks(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $deliveryMode,
        bool $fieldEveningScheduleEnabled = false,
        bool $sundayOnlineOnlyEnabled = true,
    ): array {
        $domain = $this->buildFlexibleBalancedSplitDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            deliveryMode: $deliveryMode,
            isHybrid: false,
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
        );

        foreach ($this->buildSingleDayDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            deliveryMode: $deliveryMode,
            isHybrid: false,
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
        ) as $candidate) {
            $candidate['_single_session_fallback'] = true;
            $domain[] = $candidate;
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

    private function buildPatternDomainWithFallbacks(
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
        $primaryDomain = $this->buildPatternDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            preferredPattern: $preferredPattern,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            requireBalancedDurations: $requireBalancedDurations,
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
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
                fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
                sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
            );

            foreach ($flexibleSplitDomain as $candidate) {
                if (($candidate['preferred_pattern'] ?? null) !== $preferredPattern) {
                    $candidate['_pattern_fallback'] = true;
                    $alternativePatternDomain[] = $candidate;
                }
            }
        }

        // 2. Single 3-hour / full-duration session on an available day
        $singleSessionDomain = $this->buildSingleDayDomain(
            course: $course,
            matchingRooms: $matchingRooms,
            durationSlots: $durationSlots,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            fieldEveningScheduleEnabled: $fieldEveningScheduleEnabled,
            sundayOnlineOnlyEnabled: $sundayOnlineOnlyEnabled,
        );

        foreach ($singleSessionDomain as &$candidate) {
            $candidate['_single_session_fallback'] = true;
        }
        unset($candidate);

        return array_merge($primaryDomain, $alternativePatternDomain, $singleSessionDomain);
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
        return $this->slotToTime($endSlot) > SchedulingPolicy::FIELD_DAY_END_TIME;
    }

    private function conflictsWithTentativeAssignments(
        array $candidate,
        array $assignments,
        ?int $sectionId = null,
        ?int $departmentId = null,
    ): bool {
        // Persisted and year-level tentative rows are indexed separately. The
        // recursive assignments in this section are not in that index yet, so
        // enforce the same department-scoped ONLINE concurrency limit here.
        // This matters for hybrid candidates whose lecture block is online.
        if ($this->onlineCapacityConflictsWithAssignments(
            candidate: $candidate,
            assignments: $assignments,
            departmentId: $departmentId,
        )) {
            return true;
        }

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

    private function onlineCapacityConflictsWithAssignments(
        array $candidate,
        array $assignments,
        ?int $departmentId = null,
    ): bool {
        $candidateDepartmentId = $departmentId ?? (int) ($candidate['department_id'] ?? 0);
        if ($candidateDepartmentId <= 0) {
            return false;
        }

        $onlineCandidateBlocks = array_values(array_filter(
            $candidate['blocks'] ?? [],
            static function (array $block) use ($candidate): bool {
                $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? 'on-site');
                $roomType = (string) ($block['room_type'] ?? $candidate['room_type'] ?? '');

                return $mode === 'online' || $roomType === 'online';
            },
        ));
        if ($onlineCandidateBlocks === []) {
            return false;
        }

        $onlineCapacity = $this->inputSnapshot !== null && $this->inputSnapshot->departmentId === $candidateDepartmentId
            ? max(1, (int) ($this->inputSnapshot->resourceLimits['online'] ?? 1))
            : max(1, $this->resourceLimits->online($candidateDepartmentId));
        $existingOnlineBlocks = [];

        foreach ($assignments as $assignment) {
            $assignmentDepartmentId = (int) ($assignment['department_id'] ?? $candidateDepartmentId);
            if ($assignmentDepartmentId !== $candidateDepartmentId) {
                continue;
            }

            foreach ($assignment['blocks'] ?? [] as $block) {
                $mode = (string) ($block['mode'] ?? $assignment['mode'] ?? 'on-site');
                $roomType = (string) ($block['room_type'] ?? $assignment['room_type'] ?? '');
                if ($mode === 'online' || $roomType === 'online') {
                    $existingOnlineBlocks[] = $block;
                }
            }
        }

        foreach ($onlineCandidateBlocks as $candidateBlock) {
            $overlapCount = 0;
            foreach ($existingOnlineBlocks as $existingBlock) {
                if (($candidateBlock['day'] ?? null) !== ($existingBlock['day'] ?? null)) {
                    continue;
                }

                $overlaps = (int) ($candidateBlock['start_slot'] ?? 0) < (int) ($existingBlock['end_slot'] ?? 0)
                    && (int) ($existingBlock['start_slot'] ?? 0) < (int) ($candidateBlock['end_slot'] ?? 0);
                if ($overlaps) {
                    $overlapCount++;
                    if ($overlapCount >= $onlineCapacity) {
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
                courseId: (int) $candidate['course_id'],
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
     *   evening   → slots 18–26 (16:00–20:30 with the default window)
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

                    // Prefer rooms that are still empty or lightly used in the current term.
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

                if ($assignment['_single_session_fallback'] ?? false) {
                    $row['split_session_fallback'] = true;
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
        $this->metricsStartedAt = microtime(true);
        $this->metricsVariableCount = 0;
        $this->metricsCandidateCountBefore = 0;
        $this->metricsCandidateCountAfter = 0;
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
            'requirements_by_course_id' => $input['requirements_by_course_id']
                ?? $input['requirementsByCourseId']
                ?? [],
            'time_preferences_by_course_id' => $input['time_preferences_by_course_id']
                ?? $input['timePreferencesByCourseId']
                ?? [],
            'preferred_period' => $this->normalizePreferredPeriod(
                $input['preferred_period'] ?? $input['preferredPeriod'] ?? null,
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

    /** @return array<int, list<array<string, mixed>>> */
    private function normalizeRequirements(array $requirementsByCourseId, array $validCourseIds): array
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

        $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';

        return $isMajor
            && (int) ($course->lecture_hours ?? 0) > 0
            && (int) ($course->lab_hours ?? 0) === 0
            && (string) ($course->room_type_required ?? 'lecture') === 'lecture';
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

        // Room demand is per cohort, so it has to be counted against the
        // curriculum each section actually follows. Counting the whole
        // department against one curriculum understates lab demand for every
        // section still on the old one, and the solver then over-commits rooms.
        $curriculumIds = $sections
            ->pluck('curriculum_id')
            ->filter()
            ->map('intval')
            ->unique()
            ->values()
            ->all();

        if ($curriculumIds === []) {
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
            static fn (Sections $section): string => (string) ($section->curriculum_id ?? 0)
                .'|'.(string) $section->year_level
                .'|'.(string) ($semesterMap[(string) $section->semester] ?? $section->semester),
        );

        $courses = DB::table('curriculum_course')
            ->join('courses', 'courses.id', '=', 'curriculum_course.course_id')
            ->whereIn('curriculum_course.curriculum_id', $curriculumIds)
            ->where('courses.status', 'active')
            ->get([
                'curriculum_course.curriculum_id',
                'curriculum_course.year_level',
                'curriculum_course.semester',
                'courses.lecture_hours',
                'courses.lab_hours',
                'courses.room_type_required',
                'courses.course_code',
            ]);

        foreach ($courses as $course) {
            $periodKey = (string) $course->curriculum_id.'|'.(string) $course->year_level.'|'.(string) $course->semester;
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
        // Estimate meeting capacity from the actual operating window and the
        // shortest standard schedulable block. This is a fairness heuristic,
        // not a per-section daily course limit.
        $teachingDays = count(SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $minimumBlockSlots = min(self::CLASSROOM_SCHEDULABLE_BLOCK_SLOTS);
        $meetingsPerRoomDay = max(1, intdiv(SchedulingPolicy::totalSlots(), $minimumBlockSlots));
        $demandShare = ($roomCount * $teachingDays * $meetingsPerRoomDay) / max(1, $totalDemand);

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
        int $termId,
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

        $scheduleRows = $this->termScheduleRowsCache[$termId] ??= $this->snapshotScheduleRows($termId);

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
            if (! is_array($row) || (int) ($row['term_id'] ?? $termId) !== $termId) {
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

        if ($missingRoomTypeIds->isNotEmpty()) {
            Rooms::query()
                ->whereIn('id', $missingRoomTypeIds->all())
                ->pluck('room_type', 'id')
                ->each(function (string $roomType, int|string $roomId): void {
                    $this->roomTypes[(int) $roomId] = $roomType;
                });
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
     * on the room records so they are part of its fingerprint; the legacy
     * database path reads the approved requests directly.
     *
     * @return array<int, list<array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}>>
     */
    private function grantWindowsForSection(Sections $section): array
    {
        if ($this->inputSnapshot !== null && $this->inputSnapshot->departmentId === (int) $section->department_id) {
            $windows = [];
            foreach ($this->inputSnapshot->roomsById as $roomId => $attributes) {
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

        return app(RoomAccessPolicy::class)->grantWindowsFor((int) $section->department_id, (int) $section->term_id);
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
            $copies = max(1, $this->roomCapacities[$roomId] ?? 1);
            foreach (RoomAccessPolicy::blockedRanges($windows, SchedulingPolicy::PERSISTABLE_DAYS) as $day => $ranges) {
                foreach ($ranges as $range) {
                    for ($copy = 0; $copy < $copies; $copy++) {
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
    }

    /** @return list<array<string, mixed>> */
    private function snapshotScheduleRows(int $termId): array
    {
        if ($this->inputSnapshot !== null && $this->inputSnapshot->termId === $termId) {
            return array_values(array_filter(
                $this->inputSnapshot->persistedSchedules,
                static fn (array $schedule): bool => (int) ($schedule['term_id'] ?? $termId) === $termId,
            ));
        }

        return Schedule::query()
            ->where('term_id', $termId)
            ->get(['room_id', 'section_id', 'course_id', 'faculty_id', 'department_id', 'day', 'start_time', 'end_time', 'mode', 'status'])
            ->map(static fn (Schedule $schedule): array => $schedule->getAttributes())
            ->all();
    }

    /**
     * Returns true if any persisted schedule conflicts with the given time window
     * for the candidate room, target section, online subject, or assigned instructor.
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
        int $courseId,
        string $day,
        string $startTime,
        string $endTime,
        bool $skipRoomConflictCheck = false,
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

        if ($mode === 'online') {
            $onlineCapacity = $this->inputSnapshot !== null && $this->inputSnapshot->departmentId === $departmentId
                ? max(1, (int) ($this->inputSnapshot->resourceLimits['online'] ?? 1))
                : $this->resourceLimits->online($departmentId);
            if ($this->overlapCountAtLeast("online:{$departmentId}:{$day}", $startMinutes, $endMinutes, $onlineCapacity)) {
                return true;
            }
        }

        if (! $skipRoomConflictCheck && $roomId !== null) {
            $roomType = $this->roomTypes[$roomId] ?? null;
            $capacity = $roomType === 'field'
                ? ($this->inputSnapshot !== null && $this->inputSnapshot->departmentId === $departmentId
                    ? max(1, (int) ($this->inputSnapshot->resourceLimits['field'] ?? 1))
                    : $this->resourceLimits->field($departmentId))
                : ($this->roomCapacities[$roomId] ?? 1);

            $roomKey = in_array($roomType, ['field', 'online'], true)
                ? "r:{$roomId}:{$departmentId}:{$day}"
                : "r:{$roomId}:{$day}";

            if ($this->overlapCountAtLeast($roomKey, $startMinutes, $endMinutes, max(1, $capacity))) {
                return true;
            }
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
