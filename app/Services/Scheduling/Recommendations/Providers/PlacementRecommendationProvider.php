<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations\Providers;

use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Recommendations\ManualPlacementRanker;
use App\Services\Scheduling\Recommendations\PlacementGroupValidator;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;
use App\Services\Scheduling\Recommendations\SessionAlternativePolicy;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Str;

final class PlacementRecommendationProvider implements RecommendationProvider
{
    public const MAX_OPTIONS = 5;

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

    public function __construct(private readonly AvailableSlotFinder $finder, private readonly PlacementGroupValidator $validator) {}

    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $inputs = $context->inputs;
        $placement = $inputs['placement'] ?? null;
        unset($inputs['placement']);
        if (is_array($placement)) {
            if ($placement['session_alternatives'] ?? false) {
                $ignoreIds = $inputs['ignoreScheduleIds'] ?? [];
                $tentative = array_values(array_filter($inputs['tentativeSchedules'] ?? [],
                    static fn (array $row): bool => ! in_array((int) ($row['id'] ?? 0), $ignoreIds, true)));
                $snapshot = PlacementGroupValidator::discoverySnapshot($inputs['snapshot'], $tentative, $ignoreIds);
                $rows = $placement['rows'];
                $options = $this->sessionEnhancements($snapshot, [
                    'section_id' => $inputs['sectionId'], 'course_id' => $inputs['courseId'],
                    'shape' => SessionInterpreter::fromRows($rows)->legacyShape,
                    'meetings' => array_map($this->meetingFromRow(...), $rows),
                    'replaces' => $rows, 'keeps' => [], 'consecutive_rule' => $placement['consecutive_rule'] ?? null,
                ], $tentative, $placement['allowed_days'] ?? null);

                return RecommendationResult::adapt($context, ['recommendations' => $options], $options, 'affected_course_group', 'verified_group');
            }

            return $this->manualGroups($context, $inputs, $placement);
        }
        $result = $this->finder->find(...$inputs);

        return RecommendationResult::adapt(
            $context, $result, $result['slots'], 'requested_placement',
            metadata: ['total' => $result['total'], 'truncated' => $result['truncated']],
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $draft
     * @param  list<string>|null  $preferredDays
     * @return list<array<string, mixed>>
     */
    public function groupOptions(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $preferredDays): array
    {
        $sectionId = (int) $issue['section_id'];
        $courseId = (int) $issue['course_id'];

        $rule = $issue['consecutive_rule'] ?? $snapshot->consecutiveDayRulesFor($sectionId)[$courseId] ?? null;
        $dayCount = $rule['day_count'] ?? SchedulingPolicy::consecutiveDayCount($issue['preferred_pattern'] ?? null);
        foreach ([...$issue['replaces'], ...$issue['keeps']] as $row) {
            if (($markerCount = SchedulingPolicy::consecutiveDayCount($row['preferred_pattern'] ?? null)) !== null) {
                if ($dayCount !== null && (int) $dayCount !== $markerCount) {
                    return [];
                }
                $dayCount = $markerCount;
            }
        }
        $consecutive = $dayCount !== null;
        $configured = $consecutive
            ? $this->consecutiveOptions($snapshot, $issue, $draft, $preferredDays, (int) $dayCount, $rule)
            : $this->shapeOptions($snapshot, $issue, $draft, $preferredDays, false);

        $alternative = [];
        $course = $snapshot->coursesById[$courseId] ?? null;
        if (! $consecutive && is_array($course)
            && SessionAlternativePolicy::draftHybridEligible($course, $issue['shape'] ?? null)) {
            $alternative = $this->shapeOptions($snapshot, [
                ...$issue,
                'shape' => 'online_split',
                'label' => 'Hybrid Split',
                'label_reason' => 'One meeting on site, one online',
                'replaces' => [...$issue['replaces'], ...$issue['keeps']],
                'keeps' => [],
                'meetings' => array_map(static fn (array $meeting): array => [...$meeting, 'current' => null], SessionAlternativePolicy::hybridSplitMeetings()),
            ], $draft, $preferredDays, $consecutive);
        }

        // Configured options may use fallback delivery or days outside the run's
        // preferences. Only a selected-delivery fit inside those days proves that
        // the enhancement probe (and its original-shape search) is unnecessary.
        $configuredFits = false;
        foreach ($configured as $option) {
            if ($preferredDays !== null && array_diff(array_column($option['rows'], 'day'), $preferredDays) !== []) {
                continue;
            }
            foreach ($issue['meetings'] as $index => $meeting) {
                if (isset($meeting['current']['mode'])
                    && $option['rows'][count($issue['keeps']) + $index]['mode'] !== $meeting['current']['mode']) {
                    continue 2;
                }
            }
            $configuredFits = true;
            break;
        }
        $enhancements = $consecutive || $configuredFits
            ? []
            : $this->sessionEnhancements($snapshot, $issue, $draft, $preferredDays);
        $hasOnlineEnhancement = array_filter($enhancements, static fn (array $option): bool => $option['adjustment_type'] === 'enable_balanced_split'
            && array_unique(array_column($option['rows'], 'mode')) === ['online']) !== [];

        if (! $hasOnlineEnhancement && ! $consecutive && $configured === [] && $alternative === [] && is_array($course)
            && ($halfSlots = SessionAlternativePolicy::draftOnlineMeetingSlots($course)) !== null) {
            $alternative = $this->shapeOptions($snapshot, [
                ...$issue,
                'shape' => 'split',
                'label' => 'Online (All)',
                'label_reason' => 'Both meetings online; no room needed',
                'replaces' => [...$issue['replaces'], ...$issue['keeps']],
                'keeps' => [],
                'meetings' => [
                    ['meeting_type' => 'lecture', 'duration_slots' => $halfSlots, 'modes' => ['online'], 'current' => null],
                    ['meeting_type' => 'lecture', 'duration_slots' => $halfSlots, 'modes' => ['online'], 'current' => null],
                ],
            ], $draft, $preferredDays, $consecutive);
        }

        $alternative = [...$enhancements, ...$alternative];
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

    /** Bounded, read-only group probe shared by Manual, Draft Review and Generate. */
    public function sessionEnhancements(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $allowedDays = null): array
    {
        $courseId = (int) $issue['course_id'];
        $course = $snapshot->coursesById[$courseId] ?? null;
        $rows = [...$issue['replaces'], ...$issue['keeps']];
        if ($course === null || ($issue['shape'] ?? null) !== null
            || ($issue['consecutive_rule'] ?? $snapshot->consecutiveDayRulesFor((int) $issue['section_id'])[$courseId] ?? null) !== null) {
            return [];
        }
        foreach ($rows as $row) {
            if (SchedulingPolicy::consecutiveDayCount($row['preferred_pattern'] ?? null) !== null
                || SchedulingPolicy::isFixedMeetingPattern($row['preferred_pattern'] ?? null)) {
                return [];
            }
        }
        $meetings = array_map(static fn (array $meeting): array => [...$meeting,
            'modes' => isset($meeting['current']['mode']) ? [$meeting['current']['mode']] : $meeting['modes']],
            [...$issue['meetings'], ...array_map($this->meetingFromRow(...), $issue['keeps'])]);
        foreach ($meetings as $meeting) {
            if (isset($meeting['current']) && SchedulingPolicy::timeToMinutes($meeting['current']['end_time'])
                - SchedulingPolicy::timeToMinutes($meeting['current']['start_time']) !== $meeting['duration_slots'] * SchedulingPolicy::SLOT_MINUTES) {
                return [];
            }
        }
        $enhancement = SessionAlternativePolicy::enhancement($course, $meetings, $snapshot->departmentSettings, $snapshot->fieldCourseCodes);
        if ($enhancement === null) {
            return [];
        }
        // Test the complete original shape in its selected delivery, not a fallback.
        $original = [...$issue, 'meetings' => $meetings, 'replaces' => $rows, 'keeps' => [], 'allowed_days' => $allowedDays,
            'probe_deadline' => $issue['probe_deadline'] ?? microtime(true) + 2.0];
        if ($this->shapeOptions($snapshot, $original, $draft, $allowedDays, false) !== []) {
            return [];
        }
        $options = array_slice($this->shapeOptions($snapshot, [...$original, ...$enhancement], $draft, $allowedDays, false), 0, self::MAX_ALTERNATIVE_OPTIONS);
        foreach ($options as $index => &$option) {
            $option['id'] = $this->classKey((int) $issue['section_id'], $courseId).':'.$enhancement['adjustment_type'].':'.$index;
            $option['adjustment_type'] = $enhancement['adjustment_type'];
            $option['verification'] = ['status' => 'verified_group', 'complete_timetable_verified' => false];
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $issue  its meetings, the rows they replace and keep, and its shape
     * @param  list<array<string, mixed>>  $draft
     * @param  list<string>|null  $preferredDays
     * @return list<array<string, mixed>>
     */
    private function consecutiveOptions(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $preferredDays, int $dayCount, ?array $rule): array
    {
        $affected = [...$issue['replaces'], ...$issue['keeps']];
        $tentative = array_values(array_filter($draft, static fn (array $row): bool => ! in_array($row, $affected, true)));
        $meeting = $issue['meetings'][0];
        foreach ($issue['meetings'] as $requirement) {
            if ($requirement['duration_slots'] !== $meeting['duration_slots']) {
                return [];
            }
        }
        foreach ($affected as $row) {
            if (SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']) !== $meeting['duration_slots'] * SchedulingPolicy::SLOT_MINUTES) {
                return [];
            }
        }
        $template = $meeting['current'] ?? $affected[0] ?? [];
        $pattern = SchedulingPolicy::consecutivePattern($dayCount);
        $snapshot = $this->withRunRule($snapshot, (int) $issue['section_id'], (int) $issue['course_id'], $rule);
        $found = $this->finder->find(
            snapshot: $snapshot, sectionId: (int) $issue['section_id'], courseId: (int) $issue['course_id'],
            durationSlots: (int) $meeting['duration_slots'], modes: $meeting['modes'],
            tentativeSchedules: $tentative, meetingType: $meeting['meeting_type'], consecutiveDays: $dayCount,
            rowTemplate: [...$template, 'preferred_pattern' => $pattern, 'split_group_id' => 'candidate-run'],
        );
        $options = [];
        foreach ($found['slots'] as $slot) {
            $chosen = [];
            $meetings = [];
            $remaining = $issue['replaces'];
            foreach ($slot['run_days'] as $index => $day) {
                $current = collect($issue['keeps'])->firstWhere('day', $day);
                if ($current === null) {
                    $key = array_search($day, array_column($remaining, 'day'), true);
                    $current = $remaining[$key === false ? 0 : $key] ?? [];
                    if ($remaining !== []) {
                        array_splice($remaining, $key === false ? 0 : $key, 1);
                    }
                }
                $meetings[] = [...$meeting, 'current' => [...$current, 'preferred_pattern' => $pattern, 'meeting_index' => $index + 1]];
                $chosen[] = $this->scoreSlot([...$slot, 'day' => $day], [...$meeting, 'current' => $current ?: null], $preferredDays);
            }
            $rows = $this->optionRows($snapshot, [...$issue, 'keeps' => [], 'meetings' => $meetings, 'preferred_pattern' => $pattern], $chosen);
            foreach ($issue['keeps'] as $kept) {
                $matching = collect($rows)->firstWhere('day', $kept['day']);
                if ($matching === null || $matching['mode'] !== $kept['mode']
                    || (int) $matching['room_id'] !== (int) $kept['room_id']
                    || SchedulingPolicy::timeToMinutes($matching['start_time']) !== SchedulingPolicy::timeToMinutes($kept['start_time'])
                    || SchedulingPolicy::timeToMinutes($matching['end_time']) !== SchedulingPolicy::timeToMinutes($kept['end_time'])) {
                    continue 2;
                }
            }
            if (! $this->validator->passes($snapshot, $rows, $tentative)) {
                continue;
            }
            $options[] = [
                'tier' => max(array_column($chosen, 'tier')),
                'score' => (int) round(array_sum(array_column($chosen, 'score')) / $dayCount),
                'label' => null, 'summary' => implode(' · ', array_map(fn (array $candidate): string => $this->describeSlot($candidate['slot']), $chosen)),
                'reasons' => array_values(array_unique(array_merge(...array_column($chosen, 'reasons')))), 'rows' => $rows,
            ];
        }
        usort($options, $this->byPriority(...));

        return $options;
    }

    private function manualGroups(RecommendationContext $context, array $inputs, array $placement): RecommendationResult
    {
        $snapshot = $inputs['snapshot'];
        $rows = $placement['rows'];
        $index = (int) ($placement['selected_meeting'] ?? 0);
        if (! isset($rows[$index])) {
            throw new \InvalidArgumentException('The selected meeting is outside the affected group.');
        }
        $current = $rows[$index];
        $snapshot = $this->withRunRule($snapshot, (int) $current['section_id'], (int) $current['course_id'], $placement['consecutive_rule'] ?? null);
        $tentative = $inputs['tentativeSchedules'] ?? [];
        $ignoreIds = $inputs['ignoreScheduleIds'] ?? [];
        $scoped = PlacementGroupValidator::discoverySnapshot($snapshot, $tentative, $ignoreIds);
        $discovery = $this->finder->find(...[
            ...$inputs, 'snapshot' => $scoped,
            'rowTemplate' => [...$current, 'preferred_pattern' => SchedulingPolicy::consecutiveDayCount($current['preferred_pattern'] ?? null) !== null ? $current['preferred_pattern'] : null],
        ]);
        $description = SessionInterpreter::fromRows($rows);
        $validate = $this->validator->forContext($snapshot, $tentative, $ignoreIds, $description);
        $placements = [];
        foreach ($discovery['slots'] as $slot) {
            $proposed = $rows;
            if ($description->kind === 'consecutive') {
                if (! isset($slot['run_days']) || count($slot['run_days']) !== count($rows)) {
                    continue;
                }
                foreach ($proposed as $key => &$row) {
                    $row = [...$row, 'day' => $slot['run_days'][$key], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time'], 'mode' => $slot['mode'], 'room_id' => $slot['room_id']];
                }
                unset($row);
            } else {
                if ($description->sameTimeRequired && count($rows) > 1
                    && ($slot['day'] !== $current['day'] || $slot['mode'] !== $current['mode']
                        || (int) $slot['room_id'] !== (int) $current['room_id'])) {
                    continue;
                }
                $proposed[$index] = [...$current, 'day' => $slot['day'], 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time'], 'mode' => $slot['mode'], 'room_id' => $slot['room_id']];
                if ($description->sameTimeRequired) {
                    foreach ($proposed as $key => &$row) {
                        $length = SchedulingPolicy::timeToMinutes($rows[$key]['end_time']) - SchedulingPolicy::timeToMinutes($rows[$key]['start_time']);
                        if ($length % SchedulingPolicy::SLOT_MINUTES !== 0
                            || SchedulingPolicy::totalSlots() < $slot['start_slot'] + intdiv($length, SchedulingPolicy::SLOT_MINUTES)) {
                            unset($row);

                            continue 2;
                        }
                        $row['start_time'] = $slot['start_time'];
                        $row['end_time'] = SchedulingPolicy::slotToTime($slot['start_slot'] + intdiv($length, SchedulingPolicy::SLOT_MINUTES));
                    }
                    unset($row);
                }
                if (count($proposed) === 2 && $description->kind === 'integrated') {
                    $hybrid = in_array('online', array_column($proposed, 'mode'), true);
                    $pattern = 'days:'.SchedulingPolicy::dayIndex($proposed[0]['day']).'-'.SchedulingPolicy::dayIndex($proposed[1]['day']);
                    foreach ($proposed as &$row) {
                        $row['is_hybrid'] = $hybrid;
                        $row['preferred_pattern'] = $pattern;
                    }
                    unset($row);
                }
            }
            if ($validate($proposed)) {
                $placements[] = [...$slot, 'group_rows' => $proposed];
            }
        }
        $rooms = [];
        foreach ($placements as $slot) {
            $key = $slot['mode'].':'.($slot['room_id'] ?? 'virtual');
            $rooms[$key] ??= ['room_id' => $slot['room_id'], 'room_code' => $slot['room_code'], 'room_type' => $slot['room_type'], 'mode' => $slot['mode'], 'slot_count' => 0];
            $rooms[$key]['slot_count']++;
        }
        $rooms = array_values($rooms);
        usort($rooms, static fn (array $left, array $right): int => $right['slot_count'] <=> $left['slot_count'] ?: strcmp($left['room_code'], $right['room_code']));
        $best = (new ManualPlacementRanker)->bestMatches($placements, $scoped, $current, $tentative);
        $pairStarts = $description->sameTimeRequired && $description->kind !== 'consecutive' ? $placements : null;
        if ($pairStarts !== null) {
            usort($pairStarts, static fn (array $left, array $right): int => [
                abs(SchedulingPolicy::timeToMinutes($left['start_time']) - SchedulingPolicy::timeToMinutes($current['start_time'])), $left['start_slot'],
            ] <=> [abs(SchedulingPolicy::timeToMinutes($right['start_time']) - SchedulingPolicy::timeToMinutes($current['start_time'])), $right['start_slot']]);
        }
        $payload = [...$discovery, 'placements' => $placements, 'placement_rooms' => $rooms,
            'placement_total' => $discovery['truncated'] ? null : count($placements),
            'placement_truncated' => $discovery['truncated'], 'best_matches' => $best,
            'recommendations' => [], 'same_time_starts' => $pairStarts];

        return RecommendationResult::adapt($context, $payload, $placements, 'affected_course_group', 'verified_group',
            metadata: ['total' => $discovery['total'], 'truncated' => $discovery['truncated'], 'group_search_truncated' => $discovery['truncated']]);
    }

    public function meetingFromRow(array $row): array
    {
        $mode = $row['mode'];

        return ['meeting_type' => $row['meeting_type'] ?? null,
            'duration_slots' => intdiv(SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']), SchedulingPolicy::SLOT_MINUTES),
            'modes' => $mode === 'field' ? ['field'] : ($mode === 'online' ? ['online', 'on-site'] : ['on-site', 'online']), 'current' => $row];
    }

    private function withRunRule(SchedulingSnapshot $snapshot, int $sectionId, int $courseId, ?array $rule): SchedulingSnapshot
    {
        if ($rule === null) {
            return $snapshot;
        }
        $data = $snapshot->toArray();
        $data['consecutive_day_rules'] = [...$snapshot->consecutiveDayRules, [...$rule, 'section_id' => $sectionId, 'course_id' => $courseId]];

        return SchedulingSnapshot::fromArray($data);
    }

    private function shapeOptions(SchedulingSnapshot $snapshot, array $issue, array $draft, ?array $preferredDays, bool $consecutive): array
    {
        if (in_array($issue['shape'] ?? null, ['split', 'online_split'], true)
            && count($issue['meetings']) + count($issue['keeps']) !== 2) {
            return [];
        }
        foreach ($issue['meetings'] as $meeting) {
            if (isset($meeting['current'])
                && SchedulingPolicy::timeToMinutes($meeting['current']['end_time']) - SchedulingPolicy::timeToMinutes($meeting['current']['start_time']) !== $meeting['duration_slots'] * SchedulingPolicy::SLOT_MINUTES) {
                return [];
            }
        }
        $sectionId = (int) $issue['section_id'];
        $courseId = (int) $issue['course_id'];
        $replaces = $issue['replaces'];
        $tentative = array_values(array_filter(
            $draft,
            static fn (array $row): bool => ! in_array($row, [...$replaces, ...$issue['keeps']], true),
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
            if (isset($issue['allowed_days']) && ! in_array($day, $issue['allowed_days'], true)) {
                continue;
            }
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

        $sameTime = in_array($issue['shape'] ?? null, ['split', 'online_split'], true)
            || $consecutive;
        $candidatesByMeeting = [];
        foreach ($issue['meetings'] as $meeting) {
            $slots = [];
            foreach ($dayScopes as [$daySnapshot, $dayTentative, $otherDays]) {
                if (isset($issue['probe_deadline']) && microtime(true) >= $issue['probe_deadline']) {
                    return [];
                }
                $found = $this->finder->find(
                    snapshot: $daySnapshot,
                    sectionId: $sectionId,
                    courseId: $courseId,
                    durationSlots: (int) $meeting['duration_slots'],
                    modes: $meeting['modes'],
                    tentativeSchedules: $dayTentative,
                    meetingType: $meeting['meeting_type'],
                    excludedDays: $otherDays,
                    rowTemplate: [
                        ...($meeting['current'] ?? $meeting['template'] ?? []),
                        'split_group_id' => count($issue['meetings']) + count($issue['keeps']) > 1 ? 'candidate-group' : null,
                        'is_hybrid' => in_array($issue['shape'] ?? null, ['online_split', 'integrated_hybrid'], true) || (bool) ($meeting['current']['is_hybrid'] ?? false),
                        'preferred_pattern' => isset($issue['label']) ? null : ($meeting['current']['preferred_pattern'] ?? $issue['preferred_pattern'] ?? null),
                    ],
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
            if (isset($issue['probe_deadline']) && microtime(true) >= $issue['probe_deadline']) {
                return [];
            }
            $chosen = [$seed];
            $usedDays = [$seed['slot']['day'] => true];
            $score = $seed['score'];
            $tier = $seed['tier'];
            $reasons = $seed['reasons'];

            for ($index = 1; $index < count($candidatesByMeeting); $index++) {
                $best = null;
                $bestReasons = [];
                foreach ($candidatesByMeeting[$index] as $candidate) {
                    if ($sameTime && isset($issue['allowed_pairs'])
                        && ! array_filter($issue['allowed_pairs'], static fn (array $pair): bool => in_array($seed['slot']['day'], $pair, true) && in_array($candidate['slot']['day'], $pair, true))) {
                        continue;
                    }
                    if (isset($usedDays[$candidate['slot']['day']])
                        || ($sameTime && ($candidate['slot']['start_time'] !== $seed['slot']['start_time']
                            || $candidate['slot']['end_time'] !== $seed['slot']['end_time']))) {
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

            $rows = $this->optionRows($snapshot, $issue, $chosen);
            if (! $this->validator->passes($snapshot, $rows, $tentative)) {
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
                'rows' => $rows,
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
        $isHybrid = in_array($issue['shape'] ?? null, ['online_split', 'integrated_hybrid'], true);

        $rows = $issue['keeps'];
        foreach ($chosen as $index => $candidate) {
            $slot = $candidate['slot'];
            $meeting = $issue['meetings'][$index];
            $current = is_array($meeting['current'] ?? null) ? $meeting['current'] : ($meeting['template'] ?? []);
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
                'preferred_pattern' => $reshaped ? null : ($current['preferred_pattern'] ?? $issue['preferred_pattern'] ?? null),
                'status' => 'draft',
            ];
        }

        if (isset($issue['adjustment_type']) && count($rows) === 2) {
            $pattern = 'days:'.SchedulingPolicy::dayIndex($rows[0]['day']).'-'.SchedulingPolicy::dayIndex($rows[1]['day']);
            foreach ($rows as &$row) {
                $row['preferred_pattern'] = $pattern;
            }
        }

        return $rows;
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
}
