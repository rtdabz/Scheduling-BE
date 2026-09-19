<?php

namespace App\Services\Scheduling\Schedule;

/**
 * One conflict between two candidate schedule rows saved together.
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

    public function __construct(
        public string $rule,
        /** Index of the offending candidate row, as supplied by the caller. */
        public int|string $index,
        /** The candidate row it clashes with. */
        public int|string|null $otherIndex = null,
        public ?string $courseCode = null,
        public ?string $otherCourseCode = null,
        public ?string $day = null,
        public ?string $overlapStart = null,
        public ?string $overlapEnd = null,
    ) {}
}
