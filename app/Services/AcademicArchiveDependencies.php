<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;

final class AcademicArchiveDependencies
{
    /** @return list<string> */
    public function blockers(Departments|Program $parent): array
    {
        $isProgram = $parent instanceof Program;
        $column = $isProgram ? 'program_id' : 'department_id';
        $queries = [
            'users' => $parent->users(),
            'faculty' => $parent->faculties(),
            'courses' => Course::where($column, $parent->id),
            'sections' => Sections::where($column, $parent->id),
            'curricula' => Curriculum::where($column, $parent->id)->where('status', '!=', 'archived'),
            'schedules' => Schedule::where($column, $parent->id),
            'teaching assignments' => Course::where($isProgram ? 'teaching_program_id' : 'teaching_department_id', $parent->id),
            'rooms' => Rooms::where($isProgram ? 'home_program_id' : 'department_id', $parent->id),
        ];
        if (! $isProgram) {
            $queries['programs'] = $parent->programs();
            $queries['room requests'] = RoomRequest::whereIn('status', [RoomRequest::STATUS_PENDING, RoomRequest::STATUS_APPROVED])
                ->whereHas('academicSemester', fn ($query) => $query->where('is_active', true))
                ->where(fn ($query) => $query->where('requesting_department_id', $parent->id)->orWhere('owner_department_id', $parent->id));
        } else {
            $queries['delegated source courses'] = Course::where('teaching_source_program_id', $parent->id);
        }

        return array_keys(array_filter($queries, fn ($query) => $query->exists()));
    }
}
