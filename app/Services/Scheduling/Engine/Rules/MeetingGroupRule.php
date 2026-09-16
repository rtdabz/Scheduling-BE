<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * hybrid_component_count, hybrid_components, minor_split_component_count,
 * minor_split_eligibility, minor_split_pattern, minor_split_duration.
 *
 * Linked meetings (one split_group_id) that cannot be judged one row at a time.
 * Legacy custom `days:x-y` groups are intentionally left alone; only the
 * explicit Generator configurations — Hybrid and Split Session — are covered.
 */
final class MeetingGroupRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function check(array $operations): array
    {
        $violations = [];
        $groups = collect($operations)
            ->filter(static fn (array $operation): bool => ! empty($operation['split_group_id']))
            ->groupBy(static fn (array $operation): string => (string) $operation['split_group_id']);

        foreach ($groups as $groupId => $group) {
            $rows = $group->values();
            $first = $rows->first();
            if (! is_array($first)) {
                continue;
            }

            $isHybrid = $rows->contains(static fn (array $row): bool => (bool) ($row['is_hybrid'] ?? false));
            $pattern = SchedulingPolicy::normalizePreferredPattern($first['preferred_pattern'] ?? null);
            $isMinorSplit = ! $isHybrid && in_array($pattern, ['MW', 'TTh'], true);

            if (! $isHybrid && ! $isMinorSplit) {
                continue;
            }

            if ($rows->count() !== 2) {
                $violations[] = [
                    'rule' => $isHybrid ? 'hybrid_component_count' : 'minor_split_component_count',
                    'message' => $isHybrid
                        ? 'Hybrid scheduling requires exactly one online lecture and one on-site laboratory meeting.'
                        : 'Split Session scheduling requires exactly two linked meetings.',
                    'split_group_id' => $groupId,
                ];

                continue;
            }

            $courseId = RuleSupport::courseId($first);
            $course = $courseId > 0 ? $this->lookups->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
            if ($course === null) {
                continue;
            }

            if ($isHybrid) {
                if ($rows->pluck('meeting_type')->sort()->values()->all() !== ['laboratory', 'lecture']) {
                    $violations[] = [
                        'rule' => 'hybrid_components',
                        'message' => 'Hybrid scheduling requires one lecture component and one laboratory component.',
                        'split_group_id' => $groupId,
                    ];
                }

                continue;
            }

            $sectionId = (int) ($first['section_id'] ?? 0);
            $section = $sectionId > 0
                ? $this->lookups->remember('section:'.$sectionId, fn () => Sections::with('department')->find($sectionId))
                : null;
            if (! SchedulingPolicy::balancedSplitEligible($course, SchedulingPolicy::balancedSplitSettings($section?->department))) {
                $violations[] = [
                    'rule' => 'minor_split_eligibility',
                    'message' => 'Split Session is available only for minor courses, or lecture-only majors once Major Lecture Split Sessions is enabled.',
                    'split_group_id' => $groupId,
                ];

                continue;
            }

            $expectedDays = $pattern === 'MW' ? ['Monday', 'Wednesday'] : ['Tuesday', 'Thursday'];
            sort($expectedDays);
            if ($rows->pluck('day')->sort()->values()->all() !== $expectedDays) {
                $violations[] = [
                    'rule' => 'minor_split_pattern',
                    'message' => "Split Session {$pattern} meetings must use the configured day pair.",
                    'split_group_id' => $groupId,
                ];
            }

            $totalMinutes = $rows->sum(static fn (array $row): int => max(0, RuleSupport::durationMinutes(
                (string) ($row['start_time'] ?? ''),
                (string) ($row['end_time'] ?? ''),
            )));
            $expectedMinutes = max(1, (int) round((float) ($course->units ?? 0) * 60));
            if ($totalMinutes !== $expectedMinutes) {
                $violations[] = [
                    'rule' => 'minor_split_duration',
                    'message' => 'Split Session meeting durations must add up to the course contact hours used by the Schedule Generator.',
                    'split_group_id' => $groupId,
                ];
            }
        }

        return $violations;
    }
}
