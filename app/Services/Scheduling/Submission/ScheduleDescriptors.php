<?php

namespace App\Services\Scheduling\Submission;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Support\Collection;

/**
 * What a schedule row's ids meant when it was recorded: section, course,
 * room and instructor as named then.
 *
 * History snapshots hold ids only, so a course renamed or re-unitized later, a
 * deleted section, or an archived room used to change -- or blank out -- how an
 * earlier version reads. Kept beside each snapshot, these keep it as it was.
 * Keys match ScheduleSemesterArchiver's snapshot metadata.
 */
class ScheduleDescriptors
{
    /**
     * @param  iterable<Schedule|array>  $rows
     * @return array<int, array<string, mixed>>  Keyed by schedule id.
     */
    public function for(iterable $rows): array
    {
        $rows = collect($rows)->map(static fn ($row): array => $row instanceof Schedule ? $row->getAttributes() : (array) $row)
            ->filter(static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0);
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = static fn (string $key): array => $rows->pluck($key)->filter()->map('intval')->unique()->values()->all();
        $courses = Course::withTrashed()->whereIn('id', $ids('course_id'))->get()->keyBy('id');
        $sections = Sections::query()->whereIn('id', $ids('section_id'))->get()->keyBy('id');
        $rooms = Rooms::withTrashed()->whereIn('id', $ids('room_id'))->get(['id', 'room_code'])->keyBy('id');
        $faculties = Faculty::withTrashed()->whereIn('id', $ids('faculty_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $rows->mapWithKeys(function (array $row) use ($courses, $sections, $rooms, $faculties): array {
            $section = $sections->get((int) ($row['section_id'] ?? 0));
            $faculty = $faculties->get((int) ($row['faculty_id'] ?? 0));

            return [(int) $row['id'] => [
                'section_name' => $section?->section_name,
                'section_year_level' => $section?->year_level,
                'section_semester' => $section?->semester,
                'room_name' => $rooms->get((int) ($row['room_id'] ?? 0))?->room_code,
                'faculty_name' => $faculty ? trim($faculty->first_name.' '.$faculty->last_name) : null,
                ...$this->course($courses->get((int) ($row['course_id'] ?? 0))),
            ]];
        })->all();
    }

    /** @return array<string, mixed> */
    public function course(?Course $course): array
    {
        return [
            'course_code' => $course?->course_code,
            'course_name' => $course?->course_name,
            'course_category' => $course?->course_category,
            'units' => $course?->units,
            'lecture_hours' => $course?->lecture_hours,
            'lab_hours' => $course?->lab_hours,
        ];
    }
}
