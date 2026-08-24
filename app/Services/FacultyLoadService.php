<?php

namespace App\Services;

use App\Models\Faculty;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FacultyLoadService
{
    /**
     * Every faculty row for a department, each decorated with its live teaching
     * load for the term.
     */
    public function get(?int $departmentId, ?int $termId, ?int $programId = null): Collection
    {
        $faculties = Faculty::query()
            ->with(['department', 'program', 'availabilities', 'user'])
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        if ($termId === null || $faculties->isEmpty()) {
            return $faculties->each(fn (Faculty $faculty) => $this->applyRows($faculty, collect()));
        }

        $assignments = $this->assignmentRows($termId, $faculties->pluck('id')->all())
            ->groupBy('faculty_id');

        return $faculties->each(function (Faculty $faculty) use ($assignments): void {
            $this->applyRows($faculty, $assignments->get($faculty->id, collect()));
        });
    }

    /**
     * The same load attributes for a single instructor. `store()` and `update()`
     * return a faculty payload the same pages consume as `index()`, so they have
     * to carry the load fields too — a bare model reply made the UI read the
     * absent fields as a zero load and cache that over the real numbers.
     */
    public function decorate(Faculty $faculty, ?int $termId): Faculty
    {
        $rows = $termId === null
            ? collect()
            : $this->assignmentRows($termId, [$faculty->id]);

        $this->applyRows($faculty, $rows);

        return $faculty;
    }

    /**
     * The same load attributes for a whole set of instructors, in one query.
     * Looping decorate() would issue a query per instructor, so callers that
     * already hold a faculty collection use this instead.
     *
     * @param  \Illuminate\Support\Collection<int, Faculty>|Collection  $faculties
     */
    public function decorateMany($faculties, ?int $termId)
    {
        if ($faculties->isEmpty()) {
            return $faculties;
        }

        $rows = $termId === null
            ? collect()
            : $this->assignmentRows($termId, $faculties->pluck('id')->all());

        $byFaculty = $rows->groupBy('faculty_id');

        return $faculties->each(function (Faculty $faculty) use ($byFaculty): void {
            $this->applyRows($faculty, $byFaculty->get($faculty->id, collect()));
        });
    }

    /**
     * What this instructor's load becomes once $incoming is assigned to them,
     * and which band that lands in.
     *
     * Assignment past the Basic Load is allowed — it continues into the overload
     * allowance, then pro bono — so this reports rather than refuses. The caller
     * uses `requires_confirmation` to decide whether to ask the user first.
     *
     * @param  array<int, array{section_id: int, course_id: int, units: int}>  $incoming
     * @return array<string, mixed>
     */
    public function projectLoad(Faculty $faculty, ?int $termId, array $incoming): array
    {
        $rows = $termId === null
            ? collect()
            : $this->assignmentRows($termId, [$faculty->id]);

        // Keyed the same way applyRows() dedupes, so a course split across
        // several meeting blocks counts its units once, and re-assigning a class
        // the instructor already holds adds nothing.
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
            'probono_units' => (int) ($faculty->probono_units ?? 0),
            'unit_ceiling' => SchedulingPolicy::facultyUnitCeiling($faculty),
            'tier' => $tier,
            'tier_label' => SchedulingPolicy::loadTierLabel($tier),
            // Only an assignment that *adds* units can cause an overload: a
            // re-save of the instructor who already holds the class must not
            // prompt. An instructor with no Basic Load configured has no
            // threshold to cross, so there is nothing to confirm.
            'requires_confirmation' => $basic > 0 && $added > 0 && $projected > $basic,
        ];
    }

    /**
     * Live assignment rows for the given instructors. A withdrawn or
     * not-yet-approved row is not a real assignment, so it is not load even when
     * it still carries a faculty_id.
     *
     * @param  array<int, int>  $facultyIds
     */
    private function assignmentRows(int $termId, array $facultyIds): \Illuminate\Support\Collection
    {
        if ($facultyIds === []) {
            return collect();
        }

        return DB::table('schedules')
            ->join('courses', 'schedules.course_id', '=', 'courses.id')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->where('schedules.term_id', $termId)
            ->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
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

        // Deleting an instructor nulls `faculty_id` on every live row it is
        // attached to, so the confirmation has to be able to say how many
        // approved meetings would be left without an instructor.
        $faculty->setAttribute('live_schedule_count', $rows->unique('schedule_id')->count());
        $faculty->setAttribute('required_units', SchedulingPolicy::facultyRequiredUnits($faculty));
        $faculty->setAttribute('unit_ceiling', SchedulingPolicy::facultyUnitCeiling($faculty));
    }
}
