<?php

namespace App\Services;

use App\Models\Faculty;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FacultyLoadService
{
    public function get(?int $departmentId, ?int $termId): Collection
    {
        $faculties = Faculty::query()
            ->with('department')
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        if ($termId === null || $faculties->isEmpty()) {
            return $faculties->each(function (Faculty $faculty): void {
                $faculty->setAttribute('assigned_units', 0);
                $faculty->setAttribute('assigned_subjects', []);
                $faculty->setAttribute('assigned_classes', []);
            });
        }

        $assignments = DB::table('schedules')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->where('schedules.term_id', $termId)
            ->whereIn('schedules.faculty_id', $faculties->pluck('id'))
            ->select([
                'schedules.faculty_id',
                'schedules.section_id',
                'schedules.subject_id',
                'subjects.units',
                'subjects.subject_code',
                'subjects.subject_name',
                'sections.section_name',
            ])
            ->distinct()
            ->get()
            ->groupBy('faculty_id');

        return $faculties->each(function (Faculty $faculty) use ($assignments): void {
            $rows = $assignments->get($faculty->id, collect());
            $assignedUnits = $rows
                ->unique(fn ($row) => "{$row->section_id}:{$row->subject_id}")
                ->sum('units');

            $faculty->setAttribute('assigned_units', (int) $assignedUnits);
            $faculty->setAttribute('assigned_subjects', $rows
                ->unique('subject_id')
                ->map(fn ($row) => [
                    'id' => $row->subject_id,
                    'subject_code' => $row->subject_code,
                    'subject_name' => $row->subject_name,
                ])
                ->values());
            $faculty->setAttribute('assigned_classes', $rows
                ->unique('section_id')
                ->map(fn ($row) => [
                    'id' => $row->section_id,
                    'section_name' => $row->section_name,
                ])
                ->values());
        });
    }
}
