<?php

namespace App\Services;

use App\Models\Faculty;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FacultyLoadService
{
    public function get(?int $departmentId, ?int $semesterId, ?int $programId = null): Collection
    {
        $faculties = $this->faculties($departmentId, $programId);

        if ($semesterId === null) {
            return $faculties->each(fn (Faculty $faculty) => $this->applyRows($faculty, collect()));
        }

        return $this->decorateMany($faculties, $semesterId);
    }

    public function getAcrossSemesters(?int $departmentId, ?int $programId = null): Collection
    {
        $faculties = $programId === null
            ? $this->faculties($departmentId, null)
            : $this->programReportFaculties($departmentId, $programId);
        $byFaculty = $this->assignmentRows(null, $faculties->pluck('id')->all())->groupBy('faculty_id');

        return $faculties->each(function (Faculty $faculty) use ($byFaculty): void {
            $this->applyRows($faculty, $byFaculty->get($faculty->id, collect()));
        });
    }

    private function programReportFaculties(?int $departmentId, int $programId): Collection
    {
        $teaching = $this->programTeachers([$programId])[$programId] ?? [];

        return Faculty::query()
            ->with(['department', 'program', 'availabilities', 'user', 'designations.parent'])
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->where(fn ($query) => $query
                ->where('program_id', $programId)
                ->orWhere(fn ($shared) => $shared->whereNull('program_id')->whereIn('id', $teaching)))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @param  array<int, int>  $programIds
     * @return array<int, array<int, int>>
     */
    public function programTeachers(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        return DB::table('schedules')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->whereNull('schedules.deleted_at')
            ->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereNotNull('schedules.faculty_id')
            ->whereIn('sections.program_id', $programIds)
            ->distinct()
            ->get(['sections.program_id', 'schedules.faculty_id'])
            ->groupBy('program_id')
            ->map(fn ($rows) => $rows->pluck('faculty_id')->map('intval')->values()->all())
            ->mapWithKeys(fn ($ids, $programId) => [(int) $programId => $ids])
            ->all();
    }

    private function faculties(?int $departmentId, ?int $programId): Collection
    {
        return Faculty::query()
            ->with(['department', 'program', 'availabilities', 'user', 'designations.parent'])
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function decorate(Faculty $faculty, ?int $semesterId): Faculty
    {
        $rows = $semesterId === null
            ? collect()
            : $this->assignmentRows($semesterId, [$faculty->id]);

        $this->applyRows($faculty, $rows);

        return $faculty;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Faculty>|Collection  $faculties
     */
    public function decorateMany($faculties, ?int $semesterId, bool $includeHeld = false)
    {
        if ($faculties->isEmpty()) {
            return $faculties;
        }

        $rows = $semesterId === null
            ? collect()
            : $this->assignmentRows($semesterId, $faculties->pluck('id')->all(), $includeHeld);

        $byFaculty = $rows->groupBy('faculty_id');

        return $faculties->each(function (Faculty $faculty) use ($byFaculty): void {
            $this->applyRows($faculty, $byFaculty->get($faculty->id, collect()));
        });
    }

    /**
     * @param  array<int, array{section_id: int, course_id: int, units: int}>  $incoming
     * @return array<string, mixed>
     */
    public function projectLoad(Faculty $faculty, ?int $semesterId, array $incoming): array
    {
        $rows = $semesterId === null
            ? collect()
            : $this->assignmentRows($semesterId, [$faculty->id], true);

        $currentUnits = [];
        foreach ($rows as $row) {
            $currentUnits["{$row->section_id}:{$row->course_id}"] = (int) $row->units;
        }

        $projectedUnits = $currentUnits;
        foreach ($incoming as $pair) {
            $projectedUnits["{$pair['section_id']}:{$pair['course_id']}"] = (int) $pair['units'];
        }

        $current = array_sum($currentUnits);
        $projected = array_sum($projectedUnits);
        $added = $projected - $current;
        $basic = SchedulingPolicy::facultyBasicLoad($faculty);
        $tier = SchedulingPolicy::facultyLoadTier($faculty, $projected);

        return [
            'faculty_id' => (int) $faculty->id,
            'faculty_name' => trim("{$faculty->first_name} {$faculty->last_name}"),
            'current_units' => $current,
            'added_units' => $added,
            'projected_units' => $projected,
            'basic_load' => $basic,
            'overload_units' => (int) ($faculty->overload_units ?? 0),
            'unit_ceiling' => SchedulingPolicy::facultyUnitCeiling($faculty),
            'tier' => $tier,
            'tier_label' => SchedulingPolicy::loadTierLabel($tier),
            'exceeds_ceiling' => SchedulingPolicy::facultyUnitCeiling($faculty) > 0
                && $added > 0
                && $tier === SchedulingPolicy::LOAD_TIER_BEYOND_CEILING,
        ];
    }

    /**
     * @param  array<int, int>  $facultyIds
     */
    private function assignmentRows(?int $semesterId, array $facultyIds, bool $includeHeld = false): \Illuminate\Support\Collection
    {
        if ($facultyIds === []) {
            return collect();
        }

        return DB::table('schedules')
            ->join('courses', 'schedules.course_id', '=', 'courses.id')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->when($semesterId !== null, fn ($query) => $query->where('schedules.semester_id', $semesterId))
            ->when(
                ! $includeHeld || $semesterId === null,
                fn ($query) => $query->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES),
            )
            ->whereNull('schedules.deleted_at')
            ->whereIn('schedules.faculty_id', $facultyIds)
            ->select([
                'schedules.id as schedule_id',
                'schedules.faculty_id',
                'schedules.section_id',
                'schedules.course_id',
                'courses.units',
                'courses.course_code',
                'courses.course_name',
                'sections.section_name',
            ])
            ->distinct()
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function applyRows(Faculty $faculty, \Illuminate\Support\Collection $rows): void
    {
        $assignedUnits = $rows
            ->unique(fn ($row) => "{$row->section_id}:{$row->course_id}")
            ->sum('units');

        $assignedCourses = $rows
            ->unique('course_id')
            ->map(fn ($row) => [
                'id' => $row->course_id,
                'course_code' => $row->course_code,
                'course_name' => $row->course_name,
                'subject_code' => $row->course_code,
                'subject_name' => $row->course_name,
            ])
            ->values();

        $faculty->setAttribute('assigned_units', (int) $assignedUnits);
        $faculty->setAttribute('assigned_courses', $assignedCourses);
        $faculty->setAttribute('assigned_subjects', $assignedCourses);
        $faculty->setAttribute('assigned_classes', $rows
            ->unique('section_id')
            ->map(fn ($row) => [
                'id' => $row->section_id,
                'section_name' => $row->section_name,
            ])
            ->values());

        $faculty->setAttribute('live_schedule_count', $rows->unique('schedule_id')->count());
        $faculty->setAttribute('required_units', SchedulingPolicy::facultyRequiredUnits($faculty));
        $faculty->setAttribute('unit_ceiling', SchedulingPolicy::facultyUnitCeiling($faculty));
    }
}
