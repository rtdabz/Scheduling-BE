<?php

namespace App\Services\Scheduling\Schedule;

final readonly class BatchConflict
{
    public const RULE_SECTION = 'section_conflict';
    public const RULE_SUBJECT_SECTION_TIME = 'subject_section_time_conflict';
    public const RULE_ROOM = 'room_conflict';
    public const RULE_FACULTY = 'faculty_conflict';

    public function __construct(
        public string $rule,
        public int|string $index,
        public int|string|null $otherIndex = null,
        public ?string $courseCode = null,
        public ?string $otherCourseCode = null,
        public ?string $day = null,
        public ?string $overlapStart = null,
        public ?string $overlapEnd = null,
    ) {}
}
