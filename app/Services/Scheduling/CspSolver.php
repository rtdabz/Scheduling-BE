<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

class CSPSolver
{
    /** @var array<string, bool> */
    private array $databaseValidityCache = [];

    /**
     * Existing persisted schedules indexed for O(1) conflict lookup.
     * Keyed as "r:{roomId}:{day}" and "s:{sectionId}:{day}".
     *
     * @var array<string, list<array{start_time: string, end_time: string}>>
     */
    private array $existingScheduleIndex = [];

    private int $iterations = 0;
    private int $maxIterations = 250_000;
    private float $startedAt = 0.0;
    private float $timeoutSeconds = 8.0;
    private bool $searchLimitReached = false;

    public function __construct()
    {
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
        );
    }

    /**
     * @param list<int|string> $courseIds
     * @param array<int|string, string|null> $preferredPatternsByCourseId
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
        );

        return array_map(
            static fn (array $solution): array => $solution['schedules'],
            $rankedSolutions,
        );
    }

    /**
     * @param list<int|string> $courseIds
     * @param array<int|string, string|null> $preferredPatternsByCourseId
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

        $courses = Course::query()
            ->whereIn('id', $courseIds)
            ->get()
            ->keyBy('id');

        $this->ensureAllCoursesExist(
            courseIds: $courseIds,
            courses: $courses,
        );

        $this->validateCoursesForSection(
            section: $section,
            courses: $courses,
        );

        $preferredPatternsByCourseId = $this->normalizePreferredPatternsByCourseId(
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
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
            if (($rt === 'field' || $rt === 'online') && !$rooms->contains('room_type', $rt)) {
                $existingVirtual = Rooms::query()->where('room_code', strtoupper($rt))->first();
                $virtualRoom = new Rooms([
                    'room_code' => strtoupper($rt),
                    'room_type' => $rt,
                    'status'    => 'available',
                    'department_id' => null,
                ]);
                $virtualRoom->id = $existingVirtual ? $existingVirtual->id : ($rt === 'field' ? 99999 : 99998);
                $rooms->push($virtualRoom);
            }
        }

        $this->ensureRoomDomainsExist(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
        );

        $variables = $this->buildVariables(
            courses: $courses,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsByCourseId: $preferredPatternsByCourseId,
            sectionId: (int) $section->id,
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
            max($maxSolutions * 20, 40),
            200,
        );

        $rawSolutions = [];
        $solutionSignatures = [];

        $this->preloadExistingSchedules(
            termId: (int) $section->term_id,
            sectionId: (int) $section->id,
        );

        $this->backtrack(
            variableIndex: 0,
            variables: $variables,
            section: $section,
            assignments: [],
            solutions: $rawSolutions,
            solutionSignatures: $solutionSignatures,
            solutionLimit: $candidatePoolLimit,
        );

        // Score every raw solution.
        $scored = array_map(
            function (array $assignments) use ($courses): array {
                return [
                    'rank'      => 0,
                    'score'     => $this->calculateScore($assignments, $courses),
                    'schedules' => $this->toPublicScheduleRows($assignments),
                    '_raw'      => $assignments,
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
    ): void {
        if (count($solutions) >= $solutionLimit) {
            return;
        }

        if ($this->hasExceededSearchLimits()) {
            $this->searchLimitReached = true;
            return;
        }

        if ($variableIndex >= count($variables)) {
            $signature = $this->createSolutionSignature($assignments);

            if (!isset($solutionSignatures[$signature])) {
                $solutionSignatures[$signature] = true;
                $solutions[] = $assignments;
            }

            return;
        }

        $variable = $variables[$variableIndex];

        foreach ($variable['domain'] as $candidate) {
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

            if (!$this->passesRuleEngine(
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
            );

            if (count($solutions) >= $solutionLimit) {
                return;
            }
        }
    }

    private function buildVariables(
        Collection $courses,
        Collection $rooms,
        string $deliveryMode,
        bool $isHybrid,
        array $preferredPatternsByCourseId,
        int $sectionId = 0,
    ): array {
        $variables = [];

        foreach ($courses as $course) {
            $durationSlots = $this->getDurationSlots($course);
            $preferredPattern = $this->normalizePreferredPattern(
                $preferredPatternsByCourseId[(int) $course->id] ?? null,
            );

            $domain = $preferredPattern === null
                ? $this->buildSingleDayDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $deliveryMode,
                    isHybrid: $isHybrid,
                )
                : $this->buildPatternDomain(
                    course: $course,
                    matchingRooms: $rooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    deliveryMode: $deliveryMode,
                    isHybrid: $isHybrid,
                );

            // Sort by (day, start_slot) then interleave on-site and online so
            // the backtracker sees both modes at every time slot — not all
            // on-site first followed by all online.
            usort(
                $domain,
                function (array $left, array $right) use ($sectionId, $course): int {
                    $leftFirstBlock  = $left['blocks'][0];
                    $rightFirstBlock = $right['blocks'][0];

                    $dayDiff   = $this->dayIndex($leftFirstBlock['day'])
                        <=> $this->dayIndex($rightFirstBlock['day']);
                    if ($dayDiff !== 0) {
                        return $dayDiff;
                    }

                    $slotDiff = $leftFirstBlock['start_slot'] <=> $rightFirstBlock['start_slot'];
                    if ($slotDiff !== 0) {
                        return $slotDiff;
                    }

                    // Within the same (day, slot) pair, interleave modes so
                    // on-site and online are not grouped together.
                    // Derive a per-section-course offset to rotate which mode
                    // appears first, preventing every section from always
                    // preferring on-site.
                    $modeOrder  = ['on-site' => 0, 'online' => 1, 'field' => 2];
                    $modeOffset = ($sectionId ^ (int) $course->id) % 2;
                    $leftModeRank  = ($modeOrder[$left['mode']]  ?? 99) ^ $modeOffset;
                    $rightModeRank = ($modeOrder[$right['mode']] ?? 99) ^ $modeOffset;
                    $modeDiff  = $leftModeRank <=> $rightModeRank;
                    if ($modeDiff !== 0) {
                        return $modeDiff;
                    }

                    return $left['room_id'] <=> $right['room_id'];
                },
            );

            // Apply a deterministic section+course-seeded shuffle to the domain
            // so each section explores a different ordering of candidates,
            // preventing resource starvation where section 1 always claims the
            // same on-site rooms first.
            $seed = abs($sectionId * 2053 + (int) $course->id * 97);
            $domain = $this->seededShuffle($domain, $seed);

            $variables[] = [
                'course_id'      => (int) $course->id,
                'duration_slots' => $durationSlots,
                'preferred_pattern' => $preferredPattern,
                'delivery_mode'  => $deliveryMode,
                'is_hybrid'      => $isHybrid,
                'domain'         => $domain,
            ];
        }

        return $variables;
    }

    /**
     * Deterministic Fisher-Yates shuffle seeded with $seed.
     * Produces a stable ordering per (section, course) pair without using
     * PHP's global mt_rand state, which would introduce non-determinism.
     *
     * @param  array<int, array<string, mixed>> $items
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
            $state   = (int) (($a * $state + $c) % $m);
            $j       = $state % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /**
     * Returns the list of (day, mode) pairs that are valid for a given course
     * based on its category, delivery type, and institutional scheduling rules:
     *
     *  - NSTP (ROTC/CWTS)            : Sunday only, field mode.
     *  - PATHFIT / other field (non-NSTP): Monday–Friday, field mode.
     *  - Minor non-field (GEC, GEE, …): Monday–Friday, on-site or online.
     *  - Major                        : Monday–Saturday on-site or online;
     *                                   Sunday online-only.
     *
     * @return list<array{0: string, 1: string}>  Each entry is [day, mode].
     */
    private function allowedDayModePairsForCourse(Course $course): array
    {
        if ($this->isNstpCourse($course)) {
            // NSTP/ROTC/CWTS: Saturday only, always field.
            return [['Saturday', 'field']];
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
            // Minor (GEC, GEE, …): Mon–Fri, on-site or online.
            $pairs = [];
            foreach (SchedulingPolicy::WEEKDAYS as $day) {
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
        $pairs[] = ['Sunday', 'online'];

        return $pairs;
    }

    private function buildSingleDayDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $deliveryMode,
        bool $isHybrid,
    ): array {
        $latestStartSlot = SchedulingPolicy::TOTAL_SLOTS - $durationSlots;

        if ($latestStartSlot < 0) {
            return [];
        }

        $domain = [];

        foreach ($this->allowedDayModePairsForCourse($course) as [$day, $mode]) {
            $isField        = $this->isFieldCourse($course);
            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField           => 'field',
                default            => (string) $course->room_type_required,
            };
            $roomsForMode = $matchingRooms->filter(
                static fn (Rooms $room): bool => $room->room_type === $targetRoomType,
            );

            for ($startSlot = 0; $startSlot <= $latestStartSlot; $startSlot++) {
                $endSlot = $startSlot + $durationSlots;

                foreach ($roomsForMode as $room) {
                    $domain[] = [
                        'course_id'         => (int) $course->id,
                        'room_id'           => (int) $room->id,
                        'preferred_pattern' => null,
                        'mode'              => $mode,
                        'is_hybrid'         => $mode === 'field' ? false : $isHybrid,
                        'blocks'            => [
                            $this->makeBlock(
                                day: $day,
                                startSlot: $startSlot,
                                endSlot: $endSlot,
                            ),
                        ],
                    ];
                }
            }
        }

        return $domain;
    }

    private function buildPatternDomain(
        Course $course,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
        string $deliveryMode,
        bool $isHybrid,
    ): array {
        if ($durationSlots < 2) {
            return [];
        }

        [$day1, $day2] = $this->patternDays($preferredPattern);

        // Determine which days this course may occupy.
        // Extract the unique day set from the allowed (day, mode) pairs.
        $allowedDays = array_unique(
            array_column($this->allowedDayModePairsForCourse($course), 0),
        );

        // If either pattern day falls outside the course's allowed day set,
        // the pattern produces no valid candidates for this course.
        if (!in_array($day1, $allowedDays, true) || !in_array($day2, $allowedDays, true)) {
            return [];
        }

        $domain  = [];
        $isField = $this->isFieldCourse($course);
        // Mirror buildSingleDayDomain: non-field pattern courses also support online.
        $modes   = $isField ? ['field'] : ['on-site', 'online'];

        // For major courses on Saturday (which is in WEEKDAYS_AND_SATURDAY but
        // Sunday is online-only), pattern days are already guaranteed to be in
        // WEEKDAYS_AND_SATURDAY at this point, so all modes apply.
        foreach ($modes as $mode) {
            $targetRoomType = match (true) {
                $mode === 'online' => 'online',
                $isField           => 'field',
                default            => (string) $course->room_type_required,
            };
            $roomsForMode = $matchingRooms->filter(
                static fn (Rooms $room): bool => $room->room_type === $targetRoomType,
            );

            for ($day1Duration = 1; $day1Duration < $durationSlots; $day1Duration++) {
                $day2Duration    = $durationSlots - $day1Duration;
                $day1LatestStart = SchedulingPolicy::TOTAL_SLOTS - $day1Duration;
                $day2LatestStart = SchedulingPolicy::TOTAL_SLOTS - $day2Duration;

                if ($day1LatestStart < 0 || $day2LatestStart < 0) {
                    continue;
                }

                for ($day1Start = 0; $day1Start <= $day1LatestStart; $day1Start++) {
                    $day1End = $day1Start + $day1Duration;

                    for ($day2Start = 0; $day2Start <= $day2LatestStart; $day2Start++) {
                        $day2End = $day2Start + $day2Duration;

                        foreach ($roomsForMode as $room) {
                            $domain[] = [
                                'course_id'         => (int) $course->id,
                                'room_id'           => (int) $room->id,
                                'preferred_pattern' => $preferredPattern,
                                'mode'              => $mode,
                                'is_hybrid'         => $mode === 'field' ? false : $isHybrid,
                                'blocks'            => [
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

        return $domain;
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

    private function conflictsWithTentativeAssignments(
        array $candidate,
        array $assignments,
        ?int $sectionId = null,
    ): bool {
        $dayCounts = [];
        foreach ($assignments as $assigned) {
            foreach ($assigned['blocks'] as $assignedBlock) {
                $dayCounts[$assignedBlock['day']] = ($dayCounts[$assignedBlock['day']] ?? 0) + 1;
            }
        }

        foreach ($candidate['blocks'] as $candidateBlock) {
            $day = $candidateBlock['day'];
            $tentativeCount = ($dayCounts[$day] ?? 0) + 1;
            $existingCount = $sectionId !== null
                ? count($this->existingScheduleIndex["s:{$sectionId}:{$day}"] ?? [])
                : 0;

            if (($tentativeCount + $existingCount) > SchedulingPolicy::MAX_CLASSES_PER_DAY) {
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
                        return true;
                    }
                }
            }

            $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
        }

        return false;
    }

    private function passesRuleEngine(
        array $candidate,
        Sections $section,
    ): bool {
        // Online and field classes share a single virtual room (no physical capacity).
        // Skip room conflict checks for both modes so that multiple sections
        // can schedule these classes at the same wall-clock time without
        // falsely blocking each other. Section-level time conflicts are
        // still enforced by the section index check below.
        $skipRoomCheck = $candidate['mode'] === 'online' || $candidate['mode'] === 'field';
        // Faculty ID may be pre-set on a candidate (rare, but handled for future
        // compatibility). When present, check it against the faculty index built
        // from already-persisted schedules.
        $facultyId = isset($candidate['faculty_id']) && $candidate['faculty_id'] !== null
            ? (int) $candidate['faculty_id']
            : null;

        foreach ($candidate['blocks'] as $block) {
            $cacheKey = implode('|', [
                (int) $section->term_id,
                (int) $section->id,
                $candidate['course_id'],
                $candidate['room_id'],
                $block['day'],
                $block['start_time'],
                $block['end_time'],
                $candidate['preferred_pattern'] ?? 'null',
                $candidate['mode'],
                $candidate['is_hybrid'] ? '1' : '0',
            ]);

            if (array_key_exists($cacheKey, $this->databaseValidityCache)) {
                if (!$this->databaseValidityCache[$cacheKey]) {
                    return false;
                }

                continue;
            }

            // All static integrity checks (term enabled, section/course/room active,
            // room type, room department, delivery alignment, slot grid, operating hours)
            // are guaranteed by the pre-filtering and validation done in solveRanked().
            // The only runtime check needed here is whether a persisted schedule
            // already occupies this room, section slot, or instructor time window.
            $isValid = !$this->hasExistingScheduleConflict(
                roomId: (int) $candidate['room_id'],
                sectionId: (int) $section->id,
                day: $block['day'],
                startTime: $block['start_time'],
                endTime: $block['end_time'],
                skipRoomConflictCheck: $skipRoomCheck,
                facultyId: $facultyId,
            );

            $this->databaseValidityCache[$cacheKey] = $isValid;

            if (!$isValid) {
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
                $signatureRows[] = [
                    'course_id' => $assignment['course_id'],
                    'room_id' => $assignment['room_id'],
                    'preferred_pattern' => $assignment['preferred_pattern'],
                    'mode' => $assignment['mode'],
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
                    $left['room_id'],
                    $left['preferred_pattern'] ?? '',
                    $left['mode'],
                    $left['is_hybrid'] ? 1 : 0,
                ] <=> [
                    $right['course_id'],
                    $this->dayIndex($right['day']),
                    $right['start_slot'],
                    $right['end_slot'],
                    $right['room_id'],
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
     * @param  array<int, array{rank: int, score: int, schedules: array, _raw: array}> $scored
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

        $selected   = [];
        $remaining  = $scored;

        // Seed with the highest-quality solution.
        $selected[] = array_shift($remaining);

        while (count($selected) < $limit && $remaining !== []) {
            $bestIndex     = 0;
            $bestCombined  = PHP_INT_MIN;

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
                    $bestIndex    = $idx;
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
        $daysA    = [];
        $daysB    = [];
        $roomsA   = [];
        $roomsB   = [];
        $bandsA   = [];
        $bandsB   = [];
        $blocksA  = 0;
        $blocksB  = 0;
        $patternA = [];
        $patternB = [];

        foreach ($rawA as $assignment) {
            $roomsA[] = $assignment['room_id'];
            if (!empty($assignment['preferred_pattern'])) {
                $patternA[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysA[]  = $block['day'];
                $bandsA[] = $this->computeTimeBand($block['start_slot']);
                $blocksA++;
            }
        }

        foreach ($rawB as $assignment) {
            $roomsB[] = $assignment['room_id'];
            if (!empty($assignment['preferred_pattern'])) {
                $patternB[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysB[]  = $block['day'];
                $bandsB[] = $this->computeTimeBand($block['start_slot']);
                $blocksB++;
            }
        }

        $daysA  = array_unique($daysA);
        $daysB  = array_unique($daysB);
        $roomsA = array_unique($roomsA);
        $roomsB = array_unique($roomsB);
        $bandsA = array_unique($bandsA);
        $bandsB = array_unique($bandsB);

        $diversity = 0;

        // Day-set symmetric difference (4 pts per distinct day not shared).
        $dayDiff    = array_merge(
            array_diff($daysA, $daysB),
            array_diff($daysB, $daysA),
        );
        $diversity += count(array_unique($dayDiff)) * 4;

        // Time-band symmetric difference (3 pts per distinct band not shared).
        $bandDiff   = array_merge(
            array_diff($bandsA, $bandsB),
            array_diff($bandsB, $bandsA),
        );
        $diversity += count(array_unique($bandDiff)) * 3;

        // Room symmetric difference (2 pts per room not shared).
        $roomDiff   = array_merge(
            array_diff($roomsA, $roomsB),
            array_diff($roomsB, $roomsA),
        );
        $diversity += count(array_unique($roomDiff)) * 2;

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
     *   evening   → slots 18–27 (16:00–21:00)
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

        foreach ($assignments as $assignment) {
            $blockDurations = [];

            foreach ($assignment['blocks'] as $block) {
                $byDay[$block['day']][] = [
                    'course_id' => $assignment['course_id'],
                    'room_id' => $assignment['room_id'],
                    'start_slot' => $block['start_slot'],
                    'end_slot' => $block['end_slot'],
                ];

                $blockDurations[] = $block['end_slot'] - $block['start_slot'];

                if ($block['day'] === 'Saturday' || $block['day'] === 'Sunday') {
                    // Do not penalize Saturday for NSTP/ROTC/CWTS courses since Saturday is their required day
                    $isNstp = false;
                    $courseId = $assignment['course_id'];
                    $courseObj = $courses[$courseId] ?? null;
                    if ($courseObj && $this->isNstpCourse($courseObj)) {
                        $isNstp = true;
                    }

                    if (!$isNstp) {
                        $score += SchedulingPolicy::SOFT_SATURDAY_PENALTY;
                    }
                }

                if ($block['start_slot'] > SchedulingPolicy::SOFT_LATE_START_AFTER_SLOT) {
                    $score += ($block['start_slot'] - SchedulingPolicy::SOFT_LATE_START_AFTER_SLOT)
                        * SchedulingPolicy::SOFT_LATE_SLOT_PENALTY;
                }
            }

            if (count($blockDurations) === 2) {
                $score += abs($blockDurations[0] - $blockDurations[1]);
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
                static fn (array $left, array $right): int =>
                    $left['start_slot'] <=> $right['start_slot'],
            );

            $previous = null;

            foreach ($dayAssignments as $assignment) {
                if ($previous !== null) {
                    $gapSlots = max(
                        0,
                        $assignment['start_slot'] - $previous['end_slot'],
                    );

                    $score += $gapSlots * SchedulingPolicy::SOFT_GAP_SLOT_PENALTY;

                    if ($assignment['room_id'] !== $previous['room_id']) {
                        $score += SchedulingPolicy::SOFT_ROOM_CHANGE_PENALTY;
                    }
                }

                $previous = $assignment;
            }
        }

        return $score;
    }

    private function toPublicScheduleRows(array $assignments): array
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] as $block) {
                $rows[] = [
                    'term_id' => (int) $assignment['term_id'],
                    'section_id' => (int) $assignment['section_id'],
                    'course_id' => (int) $assignment['course_id'],
                    'faculty_id' => null,
                    'room_id' => (int) $assignment['room_id'],
                    'department_id' => (int) $assignment['department_id'],
                    'day' => $block['day'],
                    'start_time' => $block['start_time'],
                    'end_time' => $block['end_time'],
                    'mode' => $assignment['mode'],
                    'is_hybrid' => (bool) $assignment['is_hybrid'],
                    'preferred_pattern' => $assignment['preferred_pattern'],
                    'status' => 'draft',
                ];
            }
        }

        usort(
            $rows,
            function (array $left, array $right): int {
                return [
                    $this->dayIndex($left['day']),
                    $left['start_time'],
                    $left['course_id'],
                    $left['room_id'],
                ] <=> [
                    $this->dayIndex($right['day']),
                    $right['start_time'],
                    $right['course_id'],
                    $right['room_id'],
                ];
            },
        );

        return $rows;
    }

    private function getDurationSlots(Course $course): int
    {
        $rawSlots = (float) $course->units * 2;

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

        if ($durationSlots > SchedulingPolicy::TOTAL_SLOTS) {
            throw new RuntimeException(sprintf(
                'Course %d requires %d slots, which exceeds the daily grid.',
                $course->id,
                $durationSlots,
            ));
        }

        return $durationSlots;
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
    }

    private function normalizeInputSchema(array $input): array
    {
        $sectionId = $input['section_id'] ?? $input['sectionId'] ?? null;
        $courseIds = $input['course_ids'] ?? $input['courseIds'] ?? null;

        if (!is_int($sectionId) && !ctype_digit((string) $sectionId)) {
            throw new InvalidArgumentException('section_id must be an integer.');
        }

        if (!is_array($courseIds)) {
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
            'max_solutions' => (int) ($input['max_solutions'] ?? $input['maxSolutions'] ?? 2),
            'max_iterations' => (int) ($input['max_iterations'] ?? $input['maxIterations'] ?? 250_000),
            'timeout_seconds' => (float) ($input['timeout_seconds'] ?? $input['timeoutSeconds'] ?? 8.0),
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
            if (!is_int($courseId) && !ctype_digit((string) $courseId)) {
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

        if (!SchedulingPolicy::isValidDeliveryMode($deliveryMode)) {
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
            if (!is_int($courseId) && !ctype_digit((string) $courseId)) {
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

        if (!SchedulingPolicy::isValidYearLevel((string) $section->year_level)) {
            throw new InvalidArgumentException(sprintf(
                'Section %d has unsupported year level "%s".',
                $section->id,
                $section->year_level,
            ));
        }

        if (!SchedulingPolicy::isValidSemester((string) $section->semester)) {
            throw new InvalidArgumentException(sprintf(
                'Section %d has unsupported semester "%s".',
                $section->id,
                $section->semester,
            ));
        }

        if (!$section->term) {
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

            if (!SchedulingPolicy::isValidRoomType((string) $course->room_type_required)) {
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
            if (!SchedulingPolicy::isValidRoomType((string) $roomType)) {
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
        foreach ($courses as $course) {
            $targetRoomType = $this->targetRoomTypeForCourse(
                course: $course,
                deliveryMode: $deliveryMode,
            );
            $hasMatchingRoom = $rooms->contains(
                static fn (Rooms $room): bool =>
                    $room->room_type === $targetRoomType,
            );

            if (!$hasMatchingRoom) {
                $virtualRoom = new Rooms();
                $virtualRoom->id = 999000 + rand(100, 999);
                $virtualRoom->room_code = strtoupper($targetRoomType) . ' ROOM';
                $virtualRoom->room_type = $targetRoomType;
                $virtualRoom->status = 'available';
                $rooms->push($virtualRoom);
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

        $types = $courses
            ->map(fn (Course $course): string => $this->targetRoomTypeForCourse($course, $deliveryMode))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!in_array('online', $types, true)) {
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

    private function normalizePreferredPatternsByCourseId(
        array $preferredPatternsByCourseId,
        array $validCourseIds,
    ): array {
        $validCourseIdMap = array_fill_keys($validCourseIds, true);
        $normalized = [];

        foreach ($preferredPatternsByCourseId as $courseId => $pattern) {
            $courseId = (int) $courseId;

            if (!isset($validCourseIdMap[$courseId])) {
                throw new InvalidArgumentException(sprintf(
                    'Preferred pattern references unknown course ID %d.',
                    $courseId,
                ));
            }

            $normalized[$courseId] = $this->normalizePreferredPattern($pattern);
        }

        return $normalized;
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
    private function preloadExistingSchedules(int $termId, int $sectionId): void
    {
        $this->existingScheduleIndex = [];

        $schedules = Schedule::query()
            ->where('term_id', $termId)
            ->get(['room_id', 'section_id', 'faculty_id', 'day', 'start_time', 'end_time']);

        foreach ($schedules as $schedule) {
            $timeRange = [
                'start_time' => (string) $schedule->start_time,
                'end_time'   => (string) $schedule->end_time,
            ];

            $this->existingScheduleIndex["r:{$schedule->room_id}:{$schedule->day}"][]    = $timeRange;
            $this->existingScheduleIndex["s:{$schedule->section_id}:{$schedule->day}"][] = $timeRange;

            // Index instructor availability so the CSP can avoid recommending
            // slots that conflict with an already-assigned faculty member.
            if (!empty($schedule->faculty_id)) {
                $this->existingScheduleIndex["f:{$schedule->faculty_id}:{$schedule->day}"][] = $timeRange;
            }
        }
    }

    /**
     * Returns true if any persisted schedule conflicts with the given time window
     * for either the candidate room, the target section, or an assigned instructor.
     *
     * @param bool $skipRoomConflictCheck When true (online mode), the room-level
     *   index check is skipped. Online classes have no physical room capacity, so
     *   multiple sections may run online classes concurrently without conflict.
     *   The section-level check is always applied to prevent a single section from
     *   double-booking itself at the same time slot.
     * @param int|null $facultyId When provided, the instructor index is checked to
     *   ensure the faculty member is not already teaching another class at the same
     *   day and time, regardless of delivery mode.
     */
    private function hasExistingScheduleConflict(
        int $roomId,
        int $sectionId,
        string $day,
        string $startTime,
        string $endTime,
        bool $skipRoomConflictCheck = false,
        ?int $facultyId = null,
    ): bool {
        if (!$skipRoomConflictCheck) {
            foreach ($this->existingScheduleIndex["r:{$roomId}:{$day}"] ?? [] as $existing) {
                if ($startTime < $existing['end_time'] && $existing['start_time'] < $endTime) {
                    return true;
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
}
