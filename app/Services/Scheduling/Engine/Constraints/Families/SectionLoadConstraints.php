<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * class_duration. Kernel counterpart of Rules\ClassDurationRule.
 *
 * Unlike RuleEngine, which sees one meeting against what is persisted, the
 * kernel sees the whole candidate set, so a plan's own meetings count against
 * each other. Context is course-level so the per-row findings dedupe into one.
 */
final class SectionLoadConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @param  list<array<string, mixed>|ScheduleRow>  $others  persisted rows (minus replaced ones) plus the other candidate rows
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, array $others, SchedulingSnapshot $snapshot): array
    {
        $sameSection = array_values(array_filter(
            $others,
            static fn (array|ScheduleRow $other): bool => ConstraintSupport::sameSemester($row, $other)
                && ConstraintSupport::intValue($other, 'section_id') === $row->sectionId,
        ));

        $violation = $this->classDuration($row, $course, $sameSection, $snapshot);

        return $violation === null ? [] : [$violation];
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<array<string, mixed>|ScheduleRow>  $sameSection
     */
    private function classDuration(ScheduleRow $row, array $course, array $sameSection, SchedulingSnapshot $snapshot): ?ConstraintViolation
    {
        $allowed = SchedulingPolicy::courseWeeklyCeilingMinutes($course, $snapshot->departmentSettings);
        if ($allowed <= 0) {
            return null;
        }

        $total = self::minutes($row);
        foreach ($sameSection as $other) {
            if (ConstraintSupport::intValue($other, 'course_id') === $row->courseId) {
                $total += self::minutes($other);
            }
        }

        // Same leniency as RuleEngine: data already over the ceiling before
        // this change must not block it unless the change adds time.
        $before = 0;
        foreach ($snapshot->persistedSchedules as $persisted) {
            if ((int) ($persisted['section_id'] ?? 0) === $row->sectionId
                && (int) ($persisted['course_id'] ?? 0) === $row->courseId
                && (int) ($persisted['semester_id'] ?? 0) === $row->semesterId) {
                $before += self::minutes($persisted);
            }
        }

        if ($total <= $allowed || $total <= $before) {
            return null;
        }

        return ConstraintSupport::violation(
            'class_duration',
            sprintf(
                '%s would meet %s minutes a week for this section, but the course carries at most %s.',
                (string) ($course['course_code'] ?? 'This course'),
                $total,
                $allowed,
            ),
            context: [
                'section_id' => $row->sectionId,
                'course_id' => $row->courseId,
                'scheduled_minutes' => $total,
                'allowed_minutes' => $allowed,
            ],
        );
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private static function minutes(array|ScheduleRow $row): int
    {
        $start = $row instanceof ScheduleRow ? $row->startTime : (string) ($row['start_time'] ?? '00:00');
        $end = $row instanceof ScheduleRow ? $row->endTime : (string) ($row['end_time'] ?? '00:00');

        return max(0, SchedulingPolicy::timeToMinutes($end) - SchedulingPolicy::timeToMinutes($start));
    }
}
