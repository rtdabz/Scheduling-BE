<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GenerationDraftReviewer
{
    public const MAX_OPTIONS = 5;

    private const MAX_COURSES_WITH_OPTIONS = 40;

    private const MAX_ALTERNATIVE_OPTIONS = 2;

    private const SEED_CANDIDATES = 12;

    private const TIER_SAME_DAY = 0;

    private const TIER_WEEKDAY = 1;

    private const TIER_SATURDAY = 2;

    private const TIER_SUNDAY = 3;

    private const PAIRED_DAYS = [
        'Monday' => 'Wednesday',
        'Wednesday' => 'Monday',
        'Tuesday' => 'Thursday',
        'Thursday' => 'Tuesday',
        'Friday' => 'Saturday',
        'Saturday' => 'Friday',
    ];

    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly SchedulingConstraintKernel $kernel,
        private readonly AvailableSlotFinder $finder,
    ) {}

    /**
     * @param  list<int>  $sectionIds  the sections the draft replaces
     * @param  list<array<string, mixed>>  $rows  the draft's rows
     * @param  list<array<string, mixed>>  $unplaced  courses the generator left out:
     *                                                {section_id, course_id, reason?, meetings: list<{meeting_type?, duration_slots, modes?}>}
     * @param  list<string>|null  $preferredDays  the run's Preferred Days, ranked first
     * @return array{issues: list<array<string, mixed>>, checked_rows: int}
     */
    public function review(
        int $semesterId,
        int $departmentId,
        array $sectionIds,
        array $rows,
        array $unplaced = [],
        ?array $preferredDays = null,
    ): array {
        $courseIds = [];
        $replacedClasses = [];
        foreach ([...$rows, ...$unplaced] as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            $courseId = (int) ($row['course_id'] ?? $row['subject_id'] ?? 0);
            if ($courseId > 0) {
                $courseIds[$courseId] = $courseId;
                $replacedClasses[$this->classKey($sectionId, $courseId)] = [$sectionId, $courseId];
            }
        }

        $snapshot = $this->snapshots->capture(
            semesterId: $semesterId,
            departmentId: $departmentId,
            sectionIds: array_values(array_unique(array_map('intval', $sectionIds))),
            courseIds: array_values($courseIds),
            replacedClasses: array_values($replacedClasses),
        );

        /** @var list<array{0: array<string, mixed>, 1: ScheduleRow}> $parsed */
        $parsed = [];
        foreach ($rows as $row) {
            try {
                $parsed[] = [$row, ScheduleRow::fromArray($row)];
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $rowsByClass = [];
        foreach ($parsed as [$row, $scheduleRow]) {
            $rowsByClass[$this->classKey($scheduleRow->sectionId, $scheduleRow->courseId)][] = $row;
        }

        $problemsByClass = [];
        $conflictingRowsByClass = [];
        foreach ($parsed as $index => [$row, $scheduleRow]) {
            $others = [];
            foreach ($parsed as $otherIndex => [, $other]) {
                if ($otherIndex !== $index) {
                    $others[] = $other;
                }
            }

            $messages = [];
            foreach ($this->kernel->evaluateRow($scheduleRow, $snapshot, $others) as $violation) {
                if ($violation->severity === 'hard') {
                    $messages[] = $violation->message;
                }
            }
            if ($messages === []) {
                continue;
            }

            $key = $this->classKey($scheduleRow->sectionId, $scheduleRow->courseId);
            $problemsByClass[$key] = array_values(array_unique([...($problemsByClass[$key] ?? []), ...$messages]));
            $conflictingRowsByClass[$key][] = $row;
        }

        $issues = [];
        foreach ($conflictingRowsByClass as $key => $conflicting) {
            [$sectionId, $courseId] = array_map('intval', explode(':', $key));
            $issues[] = [
                'kind' => 'conflict',
                'section_id' => $sectionId,
                'course_id' => $courseId,
                'problems' => array_slice($problemsByClass[$key], 0, 3),
                'shape' => $this->shapeOf($rowsByClass[$key] ?? []),
                'meetings' => array_map(
                    fn (array $row): array => $this->shapeOf($rowsByClass[$key] ?? []) === 'online_split'
                        ? [...$this->meetingFromRow($row), 'modes' => [(string) ($row['mode'] ?? 'on-site')]]
                        : $this->meetingFromRow($row),
                    $conflicting,
                ),
                'replaces' => $conflicting,
                'keeps' => array_values(array_filter(
                    $rowsByClass[$key] ?? [],
                    static fn (array $row): bool => ! in_array($row, $conflicting, true),
                )),
            ];
        }

        foreach ($unplaced as $entry) {
            $sectionId = (int) ($entry['section_id'] ?? 0);
            $courseId = (int) ($entry['course_id'] ?? 0);
            if ($sectionId <= 0 || $courseId <= 0 || isset($rowsByClass[$this->classKey($sectionId, $courseId)])) {
                continue;
            }

            $meetings = array_values(array_filter(array_map(
                static fn (mixed $meeting): ?array => is_array($meeting) && (int) ($meeting['duration_slots'] ?? 0) > 0
                    ? [
                        'meeting_type' => in_array($meeting['meeting_type'] ?? null, ['lecture', 'laboratory'], true)
                            ? (string) $meeting['meeting_type']
                            : null,
                        'duration_slots' => (int) $meeting['duration_slots'],
                        'modes' => array_values(array_intersect(
                            (array) ($meeting['modes'] ?? AvailableSlotFinder::MODES),
                            AvailableSlotFinder::MODES,
                        )) ?: AvailableSlotFinder::MODES,
                        'current' => null,
                    ]
                    : null,
                (array) ($entry['meetings'] ?? []),
            )));
            if ($meetings === []) {
                continue;
            }

            $issues[] = [
                'kind' => 'unplaced',
                'section_id' => $sectionId,
                'course_id' => $courseId,
                'problems' => [(string) ($entry['reason'] ?? 'The generator found no time and room for this course alongside the rest of the timetable.')],
                'shape' => in_array($entry['shape'] ?? null, ['split', 'online_split'], true) ? (string) $entry['shape'] : null,
                'meetings' => $meetings,
                'replaces' => [],
                'keeps' => [],
            ];
        }

        usort($issues, fn (array $left, array $right): int => [
            $this->sectionName($snapshot, $left['section_id']),
            $this->courseCode($snapshot, $left['course_id']),
        ] <=> [
            $this->sectionName($snapshot, $right['section_id']),
            $this->courseCode($snapshot, $right['course_id']),
        ]);

        $draftArrays = array_map(static fn (array $pair): array => $pair[0], $parsed);
        $reported = [];
        foreach ($issues as $position => $issue) {
            $options = $position < self::MAX_COURSES_WITH_OPTIONS
                ? $this->options($snapshot, $issue, $draftArrays, $preferredDays)
                : [];

            $reported[] = [
                'key' => $this->classKey($issue['section_id'], $issue['course_id']),
                'kind' => $issue['kind'],
                'section_id' => $issue['section_id'],
                'section_name' => $this->sectionName($snapshot, $issue['section_id']),
                'course_id' => $issue['course_id'],
                'course_code' => $this->courseCode($snapshot, $issue['course_id']),
                'course_name' => (string) ($snapshot->coursesById[$issue['course_id']]['course_name'] ?? ''),
                'problems' => $issue['problems'],
                'options' => $options,
            ];
        }

        return ['issues' => $reported, 'checked_rows' => count($parsed)];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $draft
     * @param  list<string>|null  $preferredDays
     * @return list<array<string, mixed>>
     */
    private function options(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $preferredDays): array
    {
        $sectionId = (int) $issue['section_id'];
        $courseId = (int) $issue['course_id'];

        $configured = $this->shapeOptions($snapshot, $issue, $draft, $preferredDays);

        $alternative = [];
        $course = $snapshot->coursesById[$courseId] ?? null;
        if (($issue['shape'] ?? null) !== 'online_split'
            && is_array($course)
            && SchedulingPolicy::hybridSplitEligible($course)) {
            $slots = SchedulingPolicy::hybridSplitMeetingSlots();
            $alternative = $this->shapeOptions($snapshot, [
                ...$issue,
                'shape' => 'online_split',
                'label' => 'Online Split',
                'label_reason' => 'One meeting on site, one online',
                'replaces' => [...$issue['replaces'], ...$issue['keeps']],
                'keeps' => [],
                'meetings' => [
                    ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['on-site'], 'current' => null],
                    ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['online'], 'current' => null],
                ],
            ], $draft, $preferredDays);
        }

        if ($configured === [] && $alternative === [] && is_array($course)
            && ($halfSlots = $this->fullyOnlineMeetingSlots($course)) !== null) {
            $alternative = $this->shapeOptions($snapshot, [
                ...$issue,
                'shape' => 'split',
                'label' => 'Fully Online',
                'label_reason' => 'Both meetings online; no room needed',
                'replaces' => [...$issue['replaces'], ...$issue['keeps']],
                'keeps' => [],
                'meetings' => [
                    ['meeting_type' => 'lecture', 'duration_slots' => $halfSlots, 'modes' => ['online'], 'current' => null],
                    ['meeting_type' => 'lecture', 'duration_slots' => $halfSlots, 'modes' => ['online'], 'current' => null],
                ],
            ], $draft, $preferredDays);
        }

        $altCount = $configured === []
            ? count($alternative)
            : min(self::MAX_ALTERNATIVE_OPTIONS, count($alternative));
        $options = array_slice($configured, 0, self::MAX_OPTIONS - $altCount);
        $options = [...$options, ...array_slice($alternative, 0, self::MAX_OPTIONS - count($options))];
        usort($options, $this->byPriority(...));

        foreach ($options as $rank => $option) {
            unset($option['tier']);
            $options[$rank] = ['id' => $this->classKey($sectionId, $courseId).':'.($rank + 1), 'rank' => $rank + 1, ...$option];
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $course
     */
    private function fullyOnlineMeetingSlots(array $course): ?int
    {
        if ((int) ($course['lab_hours'] ?? 0) !== 0 || (int) ($course['lecture_hours'] ?? 0) <= 0) {
            return null;
        }
        $slots = intdiv(SchedulingPolicy::unitMinutes($course['units'] ?? 0), SchedulingPolicy::SLOT_MINUTES);

        return $slots >= 2 && $slots % 2 === 0 ? intdiv($slots, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $issue  its meetings, the rows they replace and keep, and its shape
     * @param  list<array<string, mixed>>  $draft
     * @param  list<string>|null  $preferredDays
     * @return list<array<string, mixed>>
     */
    private function shapeOptions(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $preferredDays): array
    {
        $sectionId = (int) $issue['section_id'];
        $courseId = (int) $issue['course_id'];
        $replaces = $issue['replaces'];
        $tentative = array_values(array_filter(
            $draft,
            static fn (array $row): bool => ! in_array($row, $replaces, true),
        ));
        $keptDays = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['day'],
            $issue['keeps'],
        )));

        $facultyIds = [];
        foreach ($issue['meetings'] as $meeting) {
            if (($facultyId = (int) ($meeting['current']['faculty_id'] ?? 0)) > 0) {
                $facultyIds[$facultyId] = true;
            }
        }

        $dayScopes = [];
        foreach (array_diff(SchedulingPolicy::PERSISTABLE_DAYS, $keptDays) as $day) {
            $relevant = fn (array $row): bool => (int) ($row['section_id'] ?? 0) === $sectionId
                || ((string) ($row['day'] ?? '') === $day && (
                    isset($snapshot->roomsById[(int) ($row['room_id'] ?? 0)])
                    || isset($facultyIds[(int) ($row['faculty_id'] ?? 0)])
                    || ((string) ($row['mode'] ?? '') === 'online' && (int) ($row['course_id'] ?? 0) === $courseId)
                ));
            $scoped = $snapshot->toArray();
            $scoped['persisted_schedules'] = array_values(array_filter($snapshot->persistedSchedules, $relevant));
            $dayScopes[$day] = [
                SchedulingSnapshot::fromArray($scoped),
                array_values(array_filter($tentative, $relevant)),
                array_values(array_diff(SchedulingPolicy::PERSISTABLE_DAYS, [$day])),
            ];
        }

        $sameTime = in_array($issue['shape'] ?? null, ['split', 'online_split'], true);
        $candidatesByMeeting = [];
        foreach ($issue['meetings'] as $meeting) {
            $slots = [];
            foreach ($dayScopes as [$daySnapshot, $dayTentative, $otherDays]) {
                $found = $this->finder->find(
                    snapshot: $daySnapshot,
                    sectionId: $sectionId,
                    courseId: $courseId,
                    durationSlots: (int) $meeting['duration_slots'],
                    modes: $meeting['modes'],
                    tentativeSchedules: $dayTentative,
                    meetingType: $meeting['meeting_type'],
                    excludedDays: $otherDays,
                );
                $slots = [...$slots, ...$found['slots']];
            }
            if ($sameTime && $issue['keeps'] !== []) {
                $keptStart = SchedulingPolicy::timeToMinutes((string) $issue['keeps'][0]['start_time']);
                $keptEnd = SchedulingPolicy::timeToMinutes((string) $issue['keeps'][0]['end_time']);
                $slots = array_values(array_filter(
                    $slots,
                    static fn (array $slot): bool => SchedulingPolicy::timeToMinutes((string) $slot['start_time']) === $keptStart
                        && SchedulingPolicy::timeToMinutes((string) $slot['end_time']) === $keptEnd,
                ));
            }
            $scored = array_map(
                fn (array $slot): array => $this->scoreSlot($slot, $meeting, $preferredDays),
                $slots,
            );
            usort($scored, $this->byPriority(...));
            if ($scored === []) {
                return [];
            }
            $candidatesByMeeting[] = $scored;
        }

        $options = [];
        foreach ($this->diverseSeeds($candidatesByMeeting[0]) as $seed) {
            $chosen = [$seed];
            $usedDays = [$seed['slot']['day'] => true];
            $score = $seed['score'];
            $tier = $seed['tier'];
            $reasons = $seed['reasons'];

            for ($index = 1; $index < count($candidatesByMeeting); $index++) {
                $best = null;
                $bestReasons = [];
                foreach ($candidatesByMeeting[$index] as $candidate) {
                    if (isset($usedDays[$candidate['slot']['day']])
                        || ($sameTime && $candidate['slot']['start_time'] !== $seed['slot']['start_time'])) {
                        continue;
                    }
                    [$bonus, $bonusReasons] = $this->pairBonus($seed['slot'], $candidate['slot']);
                    $candidate['score'] += $bonus;
                    if ($best === null || $this->byPriority($candidate, $best) < 0) {
                        $best = $candidate;
                        $bestReasons = $bonusReasons;
                    }
                }
                if ($best === null) {
                    continue 2;
                }
                $chosen[] = $best;
                $usedDays[$best['slot']['day']] = true;
                $score += $best['score'];
                $tier = max($tier, $best['tier']);
                $reasons = [...$reasons, ...$bestReasons];
                if ($best['tier'] >= self::TIER_SATURDAY) {
                    $reasons[] = $best['reasons'][0];
                }
            }

            $modes = array_unique(array_map(static fn (array $candidate): string => (string) $candidate['slot']['mode'], $chosen));
            $lectureOnly = ! in_array('laboratory', array_column($issue['meetings'], 'meeting_type'), true);
            if (($issue['shape'] ?? null) !== 'online_split' && $lectureOnly && count($chosen) > 1 && count($modes) > 1) {
                continue;
            }

            $parts = array_map(
                static fn (array $candidate): string => $candidate['slot']['day'].$candidate['slot']['start_time'].($candidate['slot']['room_id'] ?? $candidate['slot']['mode']),
                $chosen,
            );
            sort($parts);
            $signature = implode('|', $parts);
            if (isset($options[$signature])) {
                continue;
            }

            $options[$signature] = [
                'tier' => $tier,
                'score' => (int) round($score / count($chosen)),
                'label' => $issue['label'] ?? null,
                'summary' => implode(' · ', array_map(
                    fn (array $candidate): string => $this->describeSlot($candidate['slot']),
                    $chosen,
                )),
                'reasons' => array_values(array_unique([
                    ...(isset($issue['label_reason']) ? [$issue['label_reason']] : []),
                    ...$reasons,
                ])),
                'rows' => $this->optionRows($snapshot, $issue, $chosen),
            ];
        }

        $options = array_values($options);
        usort($options, $this->byPriority(...));

        return $options;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $meeting
     * @param  list<string>|null  $preferredDays
     * @return array{slot: array<string, mixed>, tier: int, score: int, reasons: list<string>}
     */
    private function scoreSlot(array $slot, array $meeting, ?array $preferredDays): array
    {
        $score = 60;
        $reasons = [];
        $day = (string) $slot['day'];
        $start = SchedulingPolicy::timeToMinutes((string) $slot['start_time']);
        $end = SchedulingPolicy::timeToMinutes((string) $slot['end_time']);
        $current = $meeting['current'] ?? null;

        $tier = match (true) {
            is_array($current) && (string) $current['day'] === $day => self::TIER_SAME_DAY,
            $day === 'Saturday' => self::TIER_SATURDAY,
            $day === 'Sunday' => self::TIER_SUNDAY,
            default => self::TIER_WEEKDAY,
        };
        $reasons[] = match ($tier) {
            self::TIER_SAME_DAY => 'Same day',
            self::TIER_SATURDAY => 'Moves to Saturday',
            self::TIER_SUNDAY => 'Moves to Sunday',
            default => is_array($current) ? 'Another weekday' : 'On a weekday',
        };

        if ($preferredDays !== null && $preferredDays !== []) {
            if (in_array($day, $preferredDays, true)) {
                $score += 10;
                $reasons[] = 'On a Preferred Day';
            } else {
                $score -= 15;
            }
        }

        if ($start < 8 * 60 || $end > 18 * 60) {
            $score -= 8;
        } else {
            $reasons[] = 'Within regular class hours';
        }

        $wantsOnSite = in_array('on-site', $meeting['modes'], true);
        if ($slot['mode'] === 'online' && $wantsOnSite) {
            $score -= 15;
            $reasons[] = 'Held online';
        }

        if (is_array($current)) {
            $shift = abs($start - SchedulingPolicy::timeToMinutes((string) $current['start_time']));
            $score -= min(12, intdiv($shift, 30));
            if ($shift === 0) {
                $reasons[] = 'Same start time';
            }
            if ($slot['room_id'] !== null && (int) ($current['room_id'] ?? 0) === (int) $slot['room_id']) {
                $score += 4;
                $reasons[] = 'Keeps its room';
            }
        }

        return ['slot' => $slot, 'tier' => $tier, 'score' => $score, 'reasons' => $reasons];
    }

    /**
     * @param  array{tier: int, score: int}  $left
     * @param  array{tier: int, score: int}  $right
     */
    private function byPriority(array $left, array $right): int
    {
        return [$left['tier'], $right['score']] <=> [$right['tier'], $left['score']];
    }

    /**
     * @param  list<array{slot: array<string, mixed>, tier: int, score: int, reasons: list<string>}>  $candidates  in priority order
     * @return list<array{slot: array<string, mixed>, tier: int, score: int, reasons: list<string>}>
     */
    private function diverseSeeds(array $candidates): array
    {
        $seeds = [];
        $seenTimes = [];
        $perDay = [];
        foreach ($candidates as $candidate) {
            if (count($seeds) >= self::SEED_CANDIDATES) {
                break;
            }
            $day = (string) $candidate['slot']['day'];
            $time = $day.$candidate['slot']['start_time'];
            $dayLimit = $candidate['tier'] === self::TIER_SAME_DAY ? 3 : 2;
            if (isset($seenTimes[$time]) || ($perDay[$day] ?? 0) >= $dayLimit) {
                continue;
            }
            $seenTimes[$time] = true;
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            $seeds[] = $candidate;
        }

        return $seeds;
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $other
     * @return array{0: int, 1: list<string>}
     */
    private function pairBonus(array $first, array $other): array
    {
        $bonus = 0;
        $reasons = [];
        if ((self::PAIRED_DAYS[$first['day']] ?? null) === $other['day']) {
            $bonus += 8;
            $reasons[] = 'Meets on paired days';
        }
        if ($first['start_time'] === $other['start_time']) {
            $bonus += 6;
            $reasons[] = 'Same time on both days';
        }
        if ($first['room_id'] !== null && $first['room_id'] === $other['room_id']) {
            $bonus += 3;
            $reasons[] = 'Same room on both days';
        }

        return [$bonus, $reasons];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  list<array{slot: array<string, mixed>, score: int, reasons: list<string>}>  $chosen
     * @return list<array<string, mixed>>
     */
    private function optionRows(SchedulingSnapshot $snapshot, array $issue, array $chosen): array
    {
        $sectionId = (int) $issue['section_id'];
        $courseId = (int) $issue['course_id'];
        $isGroup = count($chosen) + count($issue['keeps']) > 1;
        $reshaped = isset($issue['label']);
        $groupId = match (true) {
            ! $isGroup => null,
            $reshaped => (string) Str::uuid(),
            default => (string) ($issue['keeps'][0]['split_group_id'] ?? $issue['replaces'][0]['split_group_id'] ?? Str::uuid()),
        };
        $isHybrid = ($issue['shape'] ?? null) === 'online_split';

        $rows = $issue['keeps'];
        foreach ($chosen as $index => $candidate) {
            $slot = $candidate['slot'];
            $meeting = $issue['meetings'][$index];
            $current = is_array($meeting['current'] ?? null) ? $meeting['current'] : [];
            $rows[] = [
                ...$current,
                'semester_id' => (int) ($current['semester_id'] ?? $snapshot->semesterId),
                'section_id' => $sectionId,
                'course_id' => $courseId,
                'department_id' => (int) ($current['department_id']
                    ?? $snapshot->sectionsById[$sectionId]['department_id']
                    ?? $snapshot->departmentId),
                'faculty_id' => $current['faculty_id'] ?? null,
                'day' => (string) $slot['day'],
                'start_time' => (string) $slot['start_time'],
                'end_time' => (string) $slot['end_time'],
                'mode' => (string) $slot['mode'],
                'room_id' => $slot['mode'] === 'online' ? null : $slot['room_id'],
                'meeting_type' => $meeting['meeting_type'],
                'meeting_index' => $current['meeting_index'] ?? ($isGroup ? $index + 1 : null),
                'split_group_id' => $groupId,
                'is_hybrid' => $isHybrid || (bool) ($current['is_hybrid'] ?? false),
                'preferred_pattern' => $reshaped ? null : ($current['preferred_pattern'] ?? null),
                'status' => 'draft',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function shapeOf(array $rows): ?string
    {
        if (count($rows) !== 2) {
            return null;
        }
        foreach ($rows as $row) {
            if (! in_array($row['meeting_type'] ?? null, [null, 'lecture'], true)) {
                return null;
            }
        }
        $modes = array_map(static fn (array $row): string => (string) ($row['mode'] ?? 'on-site'), $rows);
        sort($modes);
        $hybrid = (bool) ($rows[0]['is_hybrid'] ?? false) || (bool) ($rows[1]['is_hybrid'] ?? false);

        return $hybrid && $modes === ['on-site', 'online'] ? 'online_split' : 'split';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function meetingFromRow(array $row): array
    {
        $mode = (string) ($row['mode'] ?? 'on-site');

        return [
            'meeting_type' => in_array($row['meeting_type'] ?? null, ['lecture', 'laboratory'], true)
                ? (string) $row['meeting_type']
                : null,
            'duration_slots' => max(1, intdiv(
                SchedulingPolicy::timeToMinutes((string) $row['end_time']) - SchedulingPolicy::timeToMinutes((string) $row['start_time']),
                SchedulingPolicy::SLOT_MINUTES,
            )),
            'modes' => $mode === 'field' ? ['field'] : ($mode === 'online' ? ['online', 'on-site'] : ['on-site', 'online']),
            'current' => $row,
        ];
    }

    /** @param  array<string, mixed>  $slot */
    private function describeSlot(array $slot): string
    {
        $where = $slot['mode'] === 'online' ? 'Online' : (string) $slot['room_code'];

        return sprintf(
            '%s %s-%s, %s',
            substr((string) $slot['day'], 0, 3),
            $this->clockTime((string) $slot['start_time']),
            $this->clockTime((string) $slot['end_time']),
            $where,
        );
    }

    private function clockTime(string $time): string
    {
        $minutes = SchedulingPolicy::timeToMinutes($time);
        $hours = intdiv($minutes, 60);

        return sprintf('%d:%02d %s', $hours % 12 ?: 12, $minutes % 60, $hours >= 12 ? 'PM' : 'AM');
    }

    private function classKey(int $sectionId, int $courseId): string
    {
        return $sectionId.':'.$courseId;
    }

    private function sectionName(SchedulingSnapshot $snapshot, int $sectionId): string
    {
        return (string) ($snapshot->sectionsById[$sectionId]['section_name'] ?? ('Section '.$sectionId));
    }

    private function courseCode(SchedulingSnapshot $snapshot, int $courseId): string
    {
        return (string) ($snapshot->coursesById[$courseId]['course_code'] ?? ('Course '.$courseId));
    }
}
