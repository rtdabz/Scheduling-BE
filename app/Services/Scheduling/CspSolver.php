<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Subjects;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

class CSPSolver
{
    /** @var array<string, bool> */
    private array $databaseValidityCache = [];

    private int $iterations = 0;
    private int $maxIterations = 250_000;
    private float $startedAt = 0.0;
    private float $timeoutSeconds = 8.0;
    private bool $searchLimitReached = false;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
    ) {
    }

    /**
     * @param array{
     *     section_id?: int|string,
     *     sectionId?: int|string,
     *     subject_ids?: list<int|string>,
     *     subjectIds?: list<int|string>,
     *     mode?: string,
     *     delivery_mode?: string,
     *     deliveryMode?: string,
     *     is_hybrid?: bool|int|string,
     *     isHybrid?: bool|int|string,
     *     preferred_patterns?: array<int|string, string|null>,
     *     preferredPatternsBySubjectId?: array<int|string, string|null>,
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
     *     subject_ids?: list<int|string>,
     *     subjectIds?: list<int|string>,
     *     mode?: string,
     *     delivery_mode?: string,
     *     deliveryMode?: string,
     *     is_hybrid?: bool|int|string,
     *     isHybrid?: bool|int|string,
     *     preferred_patterns?: array<int|string, string|null>,
     *     preferredPatternsBySubjectId?: array<int|string, string|null>,
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
            subjectIds: $schema['subject_ids'],
            maxSolutions: $schema['max_solutions'],
            maxIterations: $schema['max_iterations'],
            timeoutSeconds: $schema['timeout_seconds'],
            deliveryMode: $schema['delivery_mode'],
            isHybrid: $schema['is_hybrid'],
            preferredPatternsBySubjectId: $schema['preferred_patterns'],
        );
    }

    /**
     * @param list<int|string> $subjectIds
     * @param array<int|string, string|null> $preferredPatternsBySubjectId
     */
    public function solve(
        int $sectionId,
        array $subjectIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
        string $deliveryMode = 'on-site',
        bool $isHybrid = false,
        array $preferredPatternsBySubjectId = [],
    ): array {
        $rankedSolutions = $this->solveRanked(
            sectionId: $sectionId,
            subjectIds: $subjectIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsBySubjectId: $preferredPatternsBySubjectId,
        );

        return array_map(
            static fn (array $solution): array => $solution['schedules'],
            $rankedSolutions,
        );
    }

    /**
     * @param list<int|string> $subjectIds
     * @param array<int|string, string|null> $preferredPatternsBySubjectId
     */
    public function solveRanked(
        int $sectionId,
        array $subjectIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
        string $deliveryMode = 'on-site',
        bool $isHybrid = false,
        array $preferredPatternsBySubjectId = [],
    ): array {
        $this->validateArguments(
            subjectIds: $subjectIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsBySubjectId: $preferredPatternsBySubjectId,
        );

        $this->resetSearchState(
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );

        $subjectIds = $this->normalizeSubjectIds($subjectIds);

        if ($subjectIds === []) {
            return [];
        }

        /** @var Sections $section */
        $section = Sections::query()
            ->with('term')
            ->findOrFail($sectionId);

        $this->validateSectionForScheduling($section);

        $subjects = Subjects::query()
            ->whereIn('id', $subjectIds)
            ->get()
            ->keyBy('id');

        $this->ensureAllSubjectsExist(
            subjectIds: $subjectIds,
            subjects: $subjects,
        );

        $this->validateSubjectsForSection(
            section: $section,
            subjects: $subjects,
        );

        $preferredPatternsBySubjectId = $this->normalizePreferredPatternsBySubjectId(
            preferredPatternsBySubjectId: $preferredPatternsBySubjectId,
            validSubjectIds: $subjectIds,
        );

        $requiredRoomTypes = $subjects
            ->pluck('room_type_required')
            ->filter()
            ->unique()
            ->values()
            ->all();

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

        $this->ensureRoomDomainsExist(
            subjects: $subjects,
            rooms: $rooms,
        );

        $variables = $this->buildVariables(
            subjects: $subjects,
            rooms: $rooms,
            deliveryMode: $deliveryMode,
            isHybrid: $isHybrid,
            preferredPatternsBySubjectId: $preferredPatternsBySubjectId,
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

        $candidatePoolLimit = min(
            max($maxSolutions, $maxSolutions * 4),
            100,
        );

        $rawSolutions = [];
        $solutionSignatures = [];

        $this->backtrack(
            variableIndex: 0,
            variables: $variables,
            section: $section,
            assignments: [],
            solutions: $rawSolutions,
            solutionSignatures: $solutionSignatures,
            solutionLimit: $candidatePoolLimit,
        );

        $ranked = array_map(
            function (array $assignments): array {
                return [
                    'rank' => 0,
                    'score' => $this->calculateScore($assignments),
                    'schedules' => $this->toPublicScheduleRows($assignments),
                ];
            },
            $rawSolutions,
        );

        usort(
            $ranked,
            static function (array $left, array $right): int {
                if ($left['score'] !== $right['score']) {
                    return $left['score'] <=> $right['score'];
                }

                return (string) json_encode($left['schedules'])
                    <=> (string) json_encode($right['schedules']);
            },
        );

        $ranked = array_slice($ranked, 0, $maxSolutions);

        foreach ($ranked as $index => &$solution) {
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
        Collection $subjects,
        Collection $rooms,
        string $deliveryMode,
        bool $isHybrid,
        array $preferredPatternsBySubjectId,
    ): array {
        $variables = [];

        foreach ($subjects as $subject) {
            $durationSlots = $this->getDurationSlots($subject);
            $preferredPattern = $this->normalizePreferredPattern(
                $preferredPatternsBySubjectId[(int) $subject->id] ?? null,
            );

            $matchingRooms = $rooms->filter(
                static fn (Rooms $room): bool =>
                    $room->room_type === $subject->room_type_required,
            );

            $domain = $preferredPattern === null
                ? $this->buildSingleDayDomain(
                    subject: $subject,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    deliveryMode: $deliveryMode,
                    isHybrid: $isHybrid,
                )
                : $this->buildPatternDomain(
                    subject: $subject,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
                    deliveryMode: $deliveryMode,
                    isHybrid: $isHybrid,
                );

            usort(
                $domain,
                function (array $left, array $right): int {
                    $leftFirstBlock = $left['blocks'][0];
                    $rightFirstBlock = $right['blocks'][0];

                    return [
                        $this->dayIndex($leftFirstBlock['day']),
                        $leftFirstBlock['start_slot'],
                        $left['blocks'][1]['start_slot'] ?? -1,
                        $left['room_id'],
                    ] <=> [
                        $this->dayIndex($rightFirstBlock['day']),
                        $rightFirstBlock['start_slot'],
                        $right['blocks'][1]['start_slot'] ?? -1,
                        $right['room_id'],
                    ];
                },
            );

            $variables[] = [
                'subject_id' => (int) $subject->id,
                'duration_slots' => $durationSlots,
                'preferred_pattern' => $preferredPattern,
                'delivery_mode' => $deliveryMode,
                'is_hybrid' => $isHybrid,
                'domain' => $domain,
            ];
        }

        return $variables;
    }

    private function buildSingleDayDomain(
        Subjects $subject,
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

        foreach (SchedulingPolicy::DAYS as $day) {
            for ($startSlot = 0; $startSlot <= $latestStartSlot; $startSlot++) {
                $endSlot = $startSlot + $durationSlots;

                foreach ($matchingRooms as $room) {
                    $domain[] = [
                        'subject_id' => (int) $subject->id,
                        'room_id' => (int) $room->id,
                        'preferred_pattern' => null,
                        'mode' => $deliveryMode,
                        'is_hybrid' => $isHybrid,
                        'blocks' => [
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
        Subjects $subject,
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
        $domain = [];

        for ($day1Duration = 1; $day1Duration < $durationSlots; $day1Duration++) {
            $day2Duration = $durationSlots - $day1Duration;
            $day1LatestStart = SchedulingPolicy::TOTAL_SLOTS - $day1Duration;
            $day2LatestStart = SchedulingPolicy::TOTAL_SLOTS - $day2Duration;

            if ($day1LatestStart < 0 || $day2LatestStart < 0) {
                continue;
            }

            for ($day1Start = 0; $day1Start <= $day1LatestStart; $day1Start++) {
                $day1End = $day1Start + $day1Duration;

                for ($day2Start = 0; $day2Start <= $day2LatestStart; $day2Start++) {
                    $day2End = $day2Start + $day2Duration;

                    foreach ($matchingRooms as $room) {
                        $domain[] = [
                            'subject_id' => (int) $subject->id,
                            'room_id' => (int) $room->id,
                            'preferred_pattern' => $preferredPattern,
                            'mode' => $deliveryMode,
                            'is_hybrid' => $isHybrid,
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
    ): bool {
        foreach ($candidate['blocks'] as $candidateBlock) {
            foreach ($assignments as $assigned) {
                foreach ($assigned['blocks'] as $assignedBlock) {
                    if ($candidateBlock['day'] !== $assignedBlock['day']) {
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

    private function passesRuleEngine(
        array $candidate,
        Sections $section,
    ): bool {
        foreach ($candidate['blocks'] as $block) {
            $cacheKey = implode('|', [
                (int) $section->term_id,
                (int) $section->id,
                $candidate['subject_id'],
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

            $violations = $this->ruleEngine->validate([
                'term_id' => (int) $section->term_id,
                'section_id' => (int) $section->id,
                'subject_id' => $candidate['subject_id'],
                'faculty_id' => null,
                'room_id' => $candidate['room_id'],
                'department_id' => (int) $section->department_id,
                'day' => $block['day'],
                'start_time' => $block['start_time'],
                'end_time' => $block['end_time'],
                'preferred_pattern' => $candidate['preferred_pattern'],
                'mode' => $candidate['mode'],
                'is_hybrid' => $candidate['is_hybrid'],
            ]);

            $isValid = $violations === [];
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
                    'subject_id' => $assignment['subject_id'],
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
                    $left['subject_id'],
                    $this->dayIndex($left['day']),
                    $left['start_slot'],
                    $left['end_slot'],
                    $left['room_id'],
                    $left['preferred_pattern'] ?? '',
                    $left['mode'],
                    $left['is_hybrid'] ? 1 : 0,
                ] <=> [
                    $right['subject_id'],
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

    private function calculateScore(array $assignments): int
    {
        $score = 0;
        $byDay = [];

        foreach ($assignments as $assignment) {
            $blockDurations = [];

            foreach ($assignment['blocks'] as $block) {
                $byDay[$block['day']][] = [
                    'subject_id' => $assignment['subject_id'],
                    'room_id' => $assignment['room_id'],
                    'start_slot' => $block['start_slot'],
                    'end_slot' => $block['end_slot'],
                ];

                $blockDurations[] = $block['end_slot'] - $block['start_slot'];

                if ($block['day'] === 'Saturday') {
                    $score += SchedulingPolicy::SOFT_SATURDAY_PENALTY;
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

        foreach ($byDay as $dayAssignments) {
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
                    'subject_id' => (int) $assignment['subject_id'],
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
                    $left['subject_id'],
                    $left['room_id'],
                ] <=> [
                    $this->dayIndex($right['day']),
                    $right['start_time'],
                    $right['subject_id'],
                    $right['room_id'],
                ];
            },
        );

        return $rows;
    }

    private function getDurationSlots(Subjects $subject): int
    {
        $rawSlots = (float) $subject->units * 2;

        if (abs($rawSlots - round($rawSlots)) > 0.00001) {
            throw new RuntimeException(sprintf(
                'Subject %d has units %.2f, which cannot be represented '
                .'using 30-minute scheduling slots.',
                $subject->id,
                $subject->units,
            ));
        }

        $durationSlots = (int) round($rawSlots);

        if ($durationSlots <= 0) {
            throw new RuntimeException(sprintf(
                'Subject %d must have a duration greater than zero.',
                $subject->id,
            ));
        }

        if ($durationSlots > SchedulingPolicy::TOTAL_SLOTS) {
            throw new RuntimeException(sprintf(
                'Subject %d requires %d slots, which exceeds the daily grid.',
                $subject->id,
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
    }

    private function normalizeInputSchema(array $input): array
    {
        $sectionId = $input['section_id'] ?? $input['sectionId'] ?? null;
        $subjectIds = $input['subject_ids'] ?? $input['subjectIds'] ?? null;

        if (!is_int($sectionId) && !ctype_digit((string) $sectionId)) {
            throw new InvalidArgumentException('section_id must be an integer.');
        }

        if (!is_array($subjectIds)) {
            throw new InvalidArgumentException('subject_ids must be an array.');
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
            'subject_ids' => $subjectIds,
            'delivery_mode' => $deliveryMode,
            'is_hybrid' => $isHybrid,
            'preferred_patterns' => $input['preferred_patterns']
                ?? $input['preferredPatternsBySubjectId']
                ?? [],
            'max_solutions' => (int) ($input['max_solutions'] ?? $input['maxSolutions'] ?? 5),
            'max_iterations' => (int) ($input['max_iterations'] ?? $input['maxIterations'] ?? 250_000),
            'timeout_seconds' => (float) ($input['timeout_seconds'] ?? $input['timeoutSeconds'] ?? 8.0),
        ];
    }

    private function normalizeSubjectIds(array $subjectIds): array
    {
        $normalized = array_map(
            static fn (mixed $subjectId): int => (int) $subjectId,
            $subjectIds,
        );

        $normalized = array_values(array_unique($normalized));

        return array_values(array_filter(
            $normalized,
            static fn (int $subjectId): bool => $subjectId > 0,
        ));
    }

    private function ensureAllSubjectsExist(
        array $subjectIds,
        Collection $subjects,
    ): void {
        $foundIds = $subjects
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $missingIds = array_values(array_diff($subjectIds, $foundIds));

        if ($missingIds !== []) {
            throw new InvalidArgumentException(
                'The following subject IDs do not exist: '
                .implode(', ', $missingIds),
            );
        }
    }

    private function validateArguments(
        array $subjectIds,
        int $maxSolutions,
        int $maxIterations,
        float $timeoutSeconds,
        string $deliveryMode,
        bool $isHybrid,
        array $preferredPatternsBySubjectId,
    ): void {
        foreach ($subjectIds as $subjectId) {
            if (!is_int($subjectId) && !ctype_digit((string) $subjectId)) {
                throw new InvalidArgumentException(
                    'Every subject ID must be an integer.',
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

        foreach ($preferredPatternsBySubjectId as $subjectId => $pattern) {
            if (!is_int($subjectId) && !ctype_digit((string) $subjectId)) {
                throw new InvalidArgumentException(
                    'Preferred pattern subject IDs must be integers.',
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

    private function validateSubjectsForSection(
        Sections $section,
        Collection $subjects,
    ): void {
        foreach ($subjects as $subject) {
            if ($subject->status !== 'active') {
                throw new InvalidArgumentException(sprintf(
                    'Subject %d is not active.',
                    $subject->id,
                ));
            }

            if ((string) $subject->year_level !== (string) $section->year_level) {
                throw new InvalidArgumentException(sprintf(
                    'Subject %d year level does not match section %d.',
                    $subject->id,
                    $section->id,
                ));
            }

            if ((string) $subject->semester !== (string) $section->semester) {
                throw new InvalidArgumentException(sprintf(
                    'Subject %d semester does not match section %d.',
                    $subject->id,
                    $section->id,
                ));
            }

            if (!SchedulingPolicy::isValidRoomType((string) $subject->room_type_required)) {
                throw new InvalidArgumentException(sprintf(
                    'Subject %d has unsupported room type "%s".',
                    $subject->id,
                    $subject->room_type_required,
                ));
            }

            if (
                $subject->subject_category === 'major'
                && $subject->department_id !== null
                && (int) $subject->department_id !== (int) $section->department_id
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Major subject %d does not belong to section %d department.',
                    $subject->id,
                    $section->id,
                ));
            }

            $this->getDurationSlots($subject);
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
        Collection $subjects,
        Collection $rooms,
    ): void {
        foreach ($subjects as $subject) {
            $hasMatchingRoom = $rooms->contains(
                static fn (Rooms $room): bool =>
                    $room->room_type === $subject->room_type_required,
            );

            if (!$hasMatchingRoom) {
                throw new RuntimeException(sprintf(
                    'No available %s room exists for subject %d.',
                    $subject->room_type_required,
                    $subject->id,
                ));
            }
        }
    }

    private function normalizePreferredPatternsBySubjectId(
        array $preferredPatternsBySubjectId,
        array $validSubjectIds,
    ): array {
        $validSubjectIdMap = array_fill_keys($validSubjectIds, true);
        $normalized = [];

        foreach ($preferredPatternsBySubjectId as $subjectId => $pattern) {
            $subjectId = (int) $subjectId;

            if (!isset($validSubjectIdMap[$subjectId])) {
                throw new InvalidArgumentException(sprintf(
                    'Preferred pattern references unknown subject ID %d.',
                    $subjectId,
                ));
            }

            $normalized[$subjectId] = $this->normalizePreferredPattern($pattern);
        }

        return $normalized;
    }
}
