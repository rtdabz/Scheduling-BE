<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Recommendations\Providers\PlacementRecommendationProvider;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use InvalidArgumentException;

final class GenerationDraftReviewer
{
    public const MAX_OPTIONS = 5;

    private const MAX_COURSES_WITH_OPTIONS = 40;

    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly SchedulingConstraintKernel $kernel,
        private readonly PlacementRecommendationProvider $placements,
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
                        ? [...$this->placements->meetingFromRow($row), 'modes' => [(string) ($row['mode'] ?? 'on-site')]]
                        : $this->placements->meetingFromRow($row),
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
                'consecutive_rule' => $entry['consecutive_rule'] ?? null,
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
                ? $this->placements->groupOptions($snapshot, $issue, $draftArrays, $preferredDays)
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
     * @param  list<array<string, mixed>>  $rows
     */
    private function shapeOf(array $rows): ?string
    {
        return SessionInterpreter::fromRows($rows)->legacyShape;
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
