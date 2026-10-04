<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;

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
