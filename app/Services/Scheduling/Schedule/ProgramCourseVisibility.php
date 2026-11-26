<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Schedule;
use App\Models\Semester;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Selection/access rules only: never scope saved timetable or conflict queries with this policy. */
final class ProgramCourseVisibility
{
    public const ASSIGNED_ELSEWHERE = 'This course is assigned to another program. Use Course Assignment to review its teaching assignment.';

    /** Both programs must have a current, active Program Head; otherwise retain legacy visibility. */
    private function transfers(?int $semesterId): Builder
    {
        $semesterId ??= Semester::query()->where('is_active', true)->value('id');

        $query = DB::table('course_teaching_assignments as assignment')
            ->join('courses as course', 'course.id', '=', 'assignment.course_id')
            ->join('programs as source', function ($join): void {
                $join->on('source.id', '=', DB::raw('COALESCE(assignment.teaching_source_program_id, course.program_id)'));
            })
            ->join('programs as target', 'target.id', '=', 'assignment.teaching_program_id')
            ->where('assignment.semester_id', $semesterId ?? 0)
            ->whereColumn('source.id', '!=', 'target.id')
            ->whereColumn('target.department_id', 'assignment.teaching_department_id')
            ->whereNull('course.deleted_at')
            ->whereNull('source.deleted_at')
            ->whereNull('target.deleted_at');

        foreach (['source', 'target'] as $program) {
            $query->whereExists(function (Builder $head) use ($program): void {
                $head->selectRaw('1')->from('users')
                    ->where('users.role', 'program_head')
                    ->where('users.is_active', true)
                    ->whereNull('users.deleted_at')
                    ->whereColumn('users.program_id', $program.'.id')
                    ->whereColumn('users.department_id', $program.'.department_id');
            });
        }

        return $query;
    }

    /** @return list<int> */
    public function hiddenCourseIds(Request $request, ?int $semesterId = null): array
    {
        $actor = $request->user();
        if ($actor?->role === 'program_head') {
            $query = $this->transfers($semesterId)->where('target.id', '!=', (int) $actor->program_id);
        } elseif ($actor?->isSecretaryOrDirector() === true && $actor->department_id !== null) {
            // An office account has no program of its own. A headed source program's handed-off
            // course is no longer an office selection, including a handoff within the same college.
            $query = $this->transfers($semesterId)->where('source.department_id', $actor->department_id);
        } else {
            return [];
        }

        return $query->pluck('assignment.course_id')->map('intval')->all();
    }

    /** Courses the source program must no longer select for a new timetable entry. @return list<int> */
    public function excludedCourseIdsForProgram(?int $programId, int $semesterId): array
    {
        return $programId === null ? [] : $this->transfers($semesterId)
            ->where('source.id', $programId)->pluck('assignment.course_id')->map('intval')->all();
    }

    /** @param iterable<Schedule> $schedules @return list<int> */
    public function hiddenInstructorScheduleIds(Request $request, iterable $schedules): array
    {
        $actor = $request->user();
        if ($actor?->role !== 'program_head' && $actor?->isSecretaryOrDirector() !== true) {
            return [];
        }

        $rows = collect($schedules);
        // Read the persisted semester assignment, rather than the active projection or a cached
        // course payload. Receiving ownership does not depend on a recorded source program.
        $assignments = DB::table('course_teaching_assignments')
            ->whereIn('semester_id', $rows->pluck('semester_id')->unique())
            ->whereIn('course_id', $rows->pluck('course_id')->unique())
            ->get(['semester_id', 'course_id', 'teaching_department_id', 'teaching_program_id'])
            ->keyBy(fn ($assignment): string => $assignment->semester_id.':'.$assignment->course_id);
        $writablePrograms = app(ScheduleAuthorizationService::class)->writableProgramIds($request);
        $hidden = [];
        foreach ($rows as $schedule) {
            $returned = $schedule->delegation_returned_from_department_id !== null;
            $assignment = $assignments->get($schedule->semester_id.':'.$schedule->course_id);
            $departmentId = $returned ? $schedule->section_teaching_department_id : $assignment?->teaching_department_id;
            $programId = $returned ? $schedule->section_teaching_program_id : $assignment?->teaching_program_id;
            // Only the receiving office/program can staff an explicit assignment. An office
            // acts for unheaded programs only, including returned and legacy-source classes.
            if (($departmentId !== null && (int) $departmentId !== (int) $actor->department_id)
                || ($programId !== null && ! in_array((int) $programId, $writablePrograms, true))) {
                $hidden[] = (int) $schedule->id;

                continue;
            }
            if (! $returned && $programId === null && $actor->role === 'program_head') {
                // Shared-room rows remain readable for conflicts, but are not instructor choices
                // for another program. Department-level and unassigned courses keep their rules.
                $attributes = $schedule->getAttributes();
                $course = isset($attributes['course']) ? (array) $attributes['course'] : $schedule->course?->getAttributes();
                $section = isset($attributes['section']) ? (array) $attributes['section'] : null;
                $ownerProgramId = $course['program_id'] ?? $schedule->program_id ?? $section['program_id'] ?? $schedule->section?->program_id;
                if ((int) $ownerProgramId !== (int) $actor->program_id) {
                    $hidden[] = (int) $schedule->id;
                }
            }
        }

        return $hidden;
    }
}
