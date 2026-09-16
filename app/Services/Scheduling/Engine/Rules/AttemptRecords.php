<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;

/**
 * The records a schedule attempt refers to, once ReferenceIntegrityRule has
 * confirmed they exist. Rules that need the real section, course or room take
 * this instead of looking them up again.
 */
final class AttemptRecords
{
    public function __construct(
        public readonly Semester $semester,
        public readonly Sections $section,
        public readonly Course $course,
        public readonly ?Rooms $room,
        public readonly ?Faculty $faculty,
        public readonly string $mode,
    ) {}

    public function departmentId(): int
    {
        return (int) $this->section->department_id;
    }
}
