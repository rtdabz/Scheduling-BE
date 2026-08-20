<?php

namespace App\Services\Scheduling;

/**
 * One conflict found between candidate schedule rows, or between a candidate row
 * and an already-persisted schedule.
 *
 * Deliberately presentation-free: BatchConflictValidator produces these, and
 * each caller renders them into its own violation payload. ScheduleController
 * labels rows as `operation_index`, ScheduleRecommendationController as
 * `recommendation_row`, and both wordings are part of their API contracts.
 */
final readonly class BatchConflict
{
    public const RULE_SECTION = 'section_conflict';
    public const RULE_SUBJECT_SECTION_TIME = 'subject_section_time_conflict';
    public const RULE_ROOM = 'room_conflict';
    public const RULE_FACULTY = 'faculty_conflict';
    public const RULE_ROOM_CAPACITY = 'room_capacity_conflict';
    public const RULE_ONLINE_CAPACITY = 'online_capacity_conflict';

    public function __construct(
        public string $rule,
        /** Index of the offending candidate row, as supplied by the caller. */
        public int|string $index,
        /** The other row in a pairwise conflict; null for capacity conflicts. */
        public int|string|null $otherIndex = null,
        public ?string $courseCode = null,
        public ?string $otherCourseCode = null,
        public ?string $day = null,
        public ?string $overlapStart = null,
        public ?string $overlapEnd = null,
        /** Configured concurrent-class limit, for capacity conflicts. */
        public ?int $capacity = null,
    ) {}

    public function isPairwise(): bool
    {
        return $this->otherIndex !== null;
    }
}
