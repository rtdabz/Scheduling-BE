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
    private const OPERATING_START_MINUTES = 7 * 60;
    private const SLOT_MINUTES = 30;
    private const TOTAL_SLOTS = 28;
 
    private const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];
 
    /** @var array<string, array{0: string, 1: string}> */
    private const PATTERN_DAYS = [
        'MW' => ['Monday', 'Wednesday'],
        'TTh' => ['Tuesday', 'Thursday'],
    ];
 
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
     * @param list<int> $subjectIds
     * @return list<list<array{
     *     subject_id: int,
     *     day: string,
     *     start_time: string,
     *     end_time: string,
     *     room_id: int,
     *     preferred_pattern: string|null
     * }>>
     */
    public function solve(
        int $sectionId,
        array $subjectIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
    ): array {
        $rankedSolutions = $this->solveRanked(
            sectionId: $sectionId,
            subjectIds: $subjectIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );
 
        return array_map(
            static fn (array $solution): array => $solution['schedules'],
            $rankedSolutions,
        );
    }
 
    /**
     * @param list<int> $subjectIds
     * @return list<array{
     *     rank: int,
     *     score: int,
     *     schedules: list<array{
     *         subject_id: int,
     *         day: string,
     *         start_time: string,
     *         end_time: string,
     *         room_id: int,
     *         preferred_pattern: string|null
     *     }>
     * }>
     */
    public function solveRanked(
        int $sectionId,
        array $subjectIds,
        int $maxSolutions = 5,
        int $maxIterations = 250_000,
        float $timeoutSeconds = 8.0,
    ): array {
        $this->validateArguments(
            subjectIds: $subjectIds,
            maxSolutions: $maxSolutions,
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );
 
        $this->resetSearchState(
            maxIterations: $maxIterations,
            timeoutSeconds: $timeoutSeconds,
        );
 
        $subjectIds = $this->normalizeSubjectIds($subjectIds);
 
        if ($subjectIds === []) {
            return [];
        }
 
        /** @var Section $section */
        $section = Sections::query()->findOrFail($sectionId);
 
        $subjects = Subjects::query()
            ->whereIn('id', $subjectIds)
            ->get()
            ->keyBy('id');
 
        $this->ensureAllSubjectsExist(
            subjectIds: $subjectIds,
            subjects: $subjects,
        );
 
        $requiredRoomTypes = $subjects
            ->pluck('room_type_required')
            ->filter()
            ->unique()
            ->values()
            ->all();
 
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
 
        $variables = $this->buildVariables(
            subjects: $subjects,
            rooms: $rooms,
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
        Section $section,
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
            $nextAssignments[] = $candidate;
 
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
    ): array {
        $variables = [];
 
        foreach ($subjects as $subject) {
            $durationSlots = $this->getDurationSlots($subject);
            $preferredPattern = $this->normalizePreferredPattern(
                $subject->preferred_pattern,
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
                )
                : $this->buildPatternDomain(
                    subject: $subject,
                    matchingRooms: $matchingRooms,
                    durationSlots: $durationSlots,
                    preferredPattern: $preferredPattern,
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
                'domain' => $domain,
            ];
        }
 
        return $variables;
    }
 
    private function buildSingleDayDomain(
        Subject $subject,
        Collection $matchingRooms,
        int $durationSlots,
    ): array {
        $latestStartSlot = self::TOTAL_SLOTS - $durationSlots;
 
        if ($latestStartSlot < 0) {
            return [];
        }
 
        $domain = [];
 
        foreach (self::DAYS as $day) {
            for ($startSlot = 0; $startSlot <= $latestStartSlot; $startSlot++) {
                $endSlot = $startSlot + $durationSlots;
 
                foreach ($matchingRooms as $room) {
                    $domain[] = [
                        'subject_id' => (int) $subject->id,
                        'room_id' => (int) $room->id,
                        'preferred_pattern' => null,
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
        Subject $subject,
        Collection $matchingRooms,
        int $durationSlots,
        string $preferredPattern,
    ): array {
        if ($durationSlots < 2) {
            return [];
        }
 
        [$day1, $day2] = self::PATTERN_DAYS[$preferredPattern];
        $domain = [];
 
        for ($day1Duration = 1; $day1Duration < $durationSlots; $day1Duration++) {
            $day2Duration = $durationSlots - $day1Duration;
            $day1LatestStart = self::TOTAL_SLOTS - $day1Duration;
            $day2LatestStart = self::TOTAL_SLOTS - $day2Duration;
 
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
        Section $section,
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
                'day' => $block['day'],
                'start_time' => $block['start_time'],
                'end_time' => $block['end_time'],
                'preferred_pattern' => $candidate['preferred_pattern'],
            ]);
 
            $isValid = $violations === [];
            $this->databaseValidityCache[$cacheKey] = $isValid;
 
            if (!$isValid) {
                return false;
            }
        }
 
        return true;
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
                ] <=> [
                    $right['subject_id'],
                    $this->dayIndex($right['day']),
                    $right['start_slot'],
                    $right['end_slot'],
                    $right['room_id'],
                    $right['preferred_pattern'] ?? '',
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
                    $score += 8;
                }
 
                if ($block['start_slot'] > 22) {
                    $score += ($block['start_slot'] - 22) * 2;
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
 
                    $score += $gapSlots * 3;
 
                    if ($assignment['room_id'] !== $previous['room_id']) {
                        $score += 1;
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
                    'subject_id' => $assignment['subject_id'],
                    'day' => $block['day'],
                    'start_time' => $block['start_time'],
                    'end_time' => $block['end_time'],
                    'room_id' => $assignment['room_id'],
                    'preferred_pattern' => $assignment['preferred_pattern'],
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
 
    private function getDurationSlots(Subject $subject): int
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
 
        return $durationSlots;
    }
 
    private function normalizePreferredPattern(mixed $preferredPattern): ?string
    {
        if ($preferredPattern === null || $preferredPattern === '') {
            return null;
        }
 
        $preferredPattern = (string) $preferredPattern;
 
        if (!array_key_exists($preferredPattern, self::PATTERN_DAYS)) {
            throw new RuntimeException(sprintf(
                'Unsupported preferred pattern "%s".',
                $preferredPattern,
            ));
        }
 
        return $preferredPattern;
    }
 
    private function slotToTime(int $slot): string
    {
        if ($slot < 0 || $slot > self::TOTAL_SLOTS) {
            throw new InvalidArgumentException(sprintf(
                'Slot %d is outside the valid 0-%d range.',
                $slot,
                self::TOTAL_SLOTS,
            ));
        }
 
        $minutes = self::OPERATING_START_MINUTES
            + ($slot * self::SLOT_MINUTES);
 
        return sprintf(
            '%02d:%02d:00',
            intdiv($minutes, 60),
            $minutes % 60,
        );
    }
 
    private function dayIndex(string $day): int
    {
        $index = array_search($day, self::DAYS, true);
 
        if ($index === false) {
            throw new RuntimeException(sprintf(
                'Unsupported scheduling day "%s".',
                $day,
            ));
        }
 
        return $index;
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
    }
}
