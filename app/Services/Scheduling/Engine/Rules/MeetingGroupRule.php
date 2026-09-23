<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * hybrid_component_count, hybrid_components, minor_split_component_count,
 * minor_split_eligibility, minor_split_pattern, minor_split_duration,
 * split_group_same_time, split_group_day_separation.
 *
 * Linked meetings (one split_group_id) that cannot be judged one row at a time.
 * The shape rules cover the explicit Generator configurations -- Hybrid and
 * Split Session; day separation applies to every linked group.
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
            $rows = $group->values()->all();
            $first = $rows[0] ?? null;
            if (! is_array($first)) {
                continue;
            }

            $isHybrid = collect($rows)->contains(static fn (array $row): bool => (bool) ($row['is_hybrid'] ?? false));
            $pattern = SchedulingPolicy::normalizePreferredPattern($first['preferred_pattern'] ?? null);
            $kind = match (true) {
                $isHybrid => 'hybrid',
                SchedulingPolicy::isFixedMeetingPattern($pattern) => 'minor_split',
                default => 'linked',
            };

            $course = null;
            $splitSettings = null;
            if ($kind !== 'linked') {
                $courseId = RuleSupport::courseId($first);
                $course = $courseId > 0 ? $this->lookups->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
                if ($course === null) {
                    continue;
                }
                if ($kind === 'minor_split') {
                    $sectionId = (int) ($first['section_id'] ?? 0);
                    $section = $sectionId > 0
                        ? $this->lookups->remember('section:'.$sectionId, fn () => Sections::with('department')->find($sectionId))
                        : null;
                    $splitSettings = SchedulingPolicy::balancedSplitSettings($section?->department);
                }
            }

            foreach (self::groupMismatches($kind, $course, $rows, $pattern, $splitSettings) as $mismatch) {
                $violations[] = [...$mismatch, 'split_group_id' => $groupId];
            }
        }

        return $violations;
    }

    /**
     * The meeting-group decision for one linked group. The one implementation;
     * the constraint kernel calls it too with snapshot rows and settings.
     *
     * $kind is 'hybrid', 'minor_split' or 'linked' (any other linked group,
     * which is only held to day separation). $rows need day, start_time,
     * end_time, mode and meeting_type. A course is required unless 'linked'.
     *
     * @param  Course|array<string, mixed>|null  $course
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|Departments|null  $splitSettings  Split Session settings (minor_split only)
     * @return list<array{rule: string, message: string}>
     */
    public static function groupMismatches(string $kind, Course|array|null $course, array $rows, ?string $pattern, array|Departments|null $splitSettings = null): array
    {
        $mismatches = [];
        $count = count($rows);
        $column = static fn (string $key): array => array_map(static fn (array $row): mixed => $row[$key] ?? null, $rows);
        $sorted = static function (array $values): array {
            sort($values);

            return $values;
        };

        if ($kind === 'hybrid' && $course !== null) {
            $hasLaboratoryComponent = (int) (is_array($course) ? ($course['lab_hours'] ?? 0) : ($course->lab_hours ?? 0)) > 0;
            if ($count !== 2) {
                $mismatches[] = ['rule' => 'hybrid_component_count', 'message' => 'Hybrid scheduling requires exactly one online lecture and one on-site laboratory meeting.'];
            } else {
                $validShape = $hasLaboratoryComponent
                    ? $sorted($column('meeting_type')) === ['laboratory', 'lecture']
                    : $column('meeting_type') === ['lecture', 'lecture'] && $sorted($column('mode')) === ['on-site', 'online'];
                if (! $validShape) {
                    $mismatches[] = ['rule' => 'hybrid_components', 'message' => $hasLaboratoryComponent
                        ? 'Hybrid Laboratory requires one online lecture and one on-site laboratory meeting.'
                        : 'Hybrid Split requires one online and one on-site lecture meeting.'];
                }
            }
        }

        if ($kind === 'minor_split' && $course !== null) {
            if ($count !== 2) {
                $mismatches[] = ['rule' => 'minor_split_component_count', 'message' => 'Split Session scheduling requires exactly two linked meetings.'];
            } elseif (! SchedulingPolicy::balancedSplitEligible($course, $splitSettings)) {
                // An ineligible course has no Split Session shape to judge.
                $mismatches[] = ['rule' => 'minor_split_eligibility', 'message' => 'Split Session is available only for minor courses or lecture-only majors.'];
            } else {
                if (SchedulingPolicy::isFixedMeetingPattern($pattern)) {
                    $expectedDays = $sorted(SchedulingPolicy::FIXED_MEETING_PATTERNS[$pattern]);
                    if ($sorted($column('day')) !== $expectedDays) {
                        $mismatches[] = ['rule' => 'minor_split_pattern', 'message' => "Split Session {$pattern} meetings must use the configured day pair."];
                    }
                }

                $totalMinutes = array_sum(array_map(
                    static fn (array $row): int => max(0, SchedulingPolicy::timeToMinutes((string) ($row['end_time'] ?? '00:00')) - SchedulingPolicy::timeToMinutes((string) ($row['start_time'] ?? '00:00'))),
                    $rows,
                ));
                // A ceiling, not an exact total: Setup Courses may shorten a
                // Split Session (Custom Time Duration), but never stretch it
                // past the course's contact hours.
                $units = is_array($course) ? ($course['units'] ?? 0) : ($course->units ?? 0);
                if ($totalMinutes <= 0 || $totalMinutes > max(1, SchedulingPolicy::unitMinutes($units))) {
                    $mismatches[] = ['rule' => 'minor_split_duration', 'message' => 'Split Session meeting durations must not add up to more than the course contact hours.'];
                }
            }
        }

        // Hybrid Split and Split Session are one class met on two days, so both
        // meetings keep one time slot. Only Integrated Hybrid's lecture and
        // laboratory have lengths -- and so times -- of their own.
        $sameTimeShape = $course !== null && $count === 2 && match ($kind) {
            'minor_split' => true,
            'hybrid' => (int) (is_array($course) ? ($course['lab_hours'] ?? 0) : ($course->lab_hours ?? 0)) === 0,
            default => false,
        };
        if ($sameTimeShape) {
            $slots = array_unique(array_map(
                static fn (array $row): string => SchedulingPolicy::timeToMinutes((string) ($row['start_time'] ?? '00:00'))
                    .'-'.SchedulingPolicy::timeToMinutes((string) ($row['end_time'] ?? '00:00')),
                $rows,
            ));
            if (count($slots) > 1) {
                $mismatches[] = ['rule' => 'split_group_same_time', 'message' => 'Both meetings of a Split Session or Hybrid Split must use the same start and end time.'];
            }
        }

        if ($count > 1 && count(array_unique($column('day'))) !== $count) {
            $mismatches[] = ['rule' => 'split_group_day_separation', 'message' => 'Split meetings for the same course must be scheduled on different days.'];
        }

        return $mismatches;
    }
}
