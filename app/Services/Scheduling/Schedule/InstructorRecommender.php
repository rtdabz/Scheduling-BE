<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InstructorRecommender
{
    private const MAX_VALIDATIONS = 25;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly FacultyLoadService $facultyLoad,
    ) {}

    /**
     * @param  Collection<int, Schedule>  $meetings  every meeting the instructor would take together
     * @param  iterable<Faculty>  $faculties  the eligible pool, current instructor already excluded
     * @return list<array<string, mixed>> best first
     */
    public function recommend(Collection $meetings, iterable $faculties, int $semesterId, int $take): array
    {
        $primary = $meetings->first();
        if (! $primary instanceof Schedule || $take <= 0) {
            return [];
        }

        $faculties = collect($faculties)->values();
        if ($faculties->isEmpty()) {
            return [];
        }

        $courseId = (int) $primary->course_id;
        $units = (int) (Course::query()->whereKey($courseId)->value('units') ?? 0);
        $pair = ['section_id' => (int) $primary->section_id, 'course_id' => $courseId, 'units' => $units];
        $taught = $this->semestersTaught($courseId, $semesterId, $faculties->pluck('id')->all());

        $ranked = $faculties
            ->map(function (Faculty $faculty) use ($semesterId, $pair, $taught): array {
                $load = $this->facultyLoad->projectLoad($faculty, $semesterId, [$pair]);
                $semesters = $taught[(int) $faculty->id] ?? 0;

                return [
                    'faculty' => $faculty,
                    'load' => $load,
                    'semesters_taught' => $semesters,
                    'score' => ($load['requires_confirmation'] ? 40 : 90)
                        - min(30, (int) $load['projected_units'])
                        + ($semesters > 0 ? 8 : 0),
                ];
            })
            ->sortByDesc('score')
            ->values();

        $meetingIds = $meetings->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $options = [];
        $budget = self::MAX_VALIDATIONS;

        foreach ($ranked as $candidate) {
            if (count($options) >= $take || $budget-- <= 0) {
                break;
            }

            $faculty = $candidate['faculty'];
            if (! $this->isFree($meetings, (int) $faculty->id, $meetingIds)) {
                continue;
            }

            $options[] = $this->option($candidate);
        }

        return $options;
    }

    /**
     * @param  Collection<int, Schedule>  $meetings
     * @param  list<int>  $meetingIds
     */
    private function isFree(Collection $meetings, int $facultyId, array $meetingIds): bool
    {
        foreach ($meetings as $meeting) {
            if ($this->ruleEngine->validateInstructorAssignment([
                ...$meeting->toArray(),
                'faculty_id' => $facultyId,
                'ignore_schedule_id' => $meetingIds,
            ]) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{faculty: Faculty, load: array<string, mixed>, semesters_taught: int, score: int}  $candidate
     * @return array<string, mixed>
     */
    private function option(array $candidate): array
    {
        $faculty = $candidate['faculty'];
        $load = $candidate['load'];
        $semesters = $candidate['semesters_taught'];
        $reasons = ['Free at this time'];

        if ($semesters > 0) {
            $reasons[] = $semesters === 1
                ? 'Taught this course before'
                : "Taught this course in {$semesters} earlier semesters";
        }

        $reasons[] = $load['requires_confirmation']
            ? "Over Basic Load at {$load['projected_units']} units"
            : "{$load['projected_units']} units after this class";

        return [
            'faculty_id' => (int) $faculty->id,
            'faculty_name' => trim("{$faculty->first_name} {$faculty->last_name}"),
            'employment_type' => $faculty->employment_type ?? null,
            'reasons' => $reasons,
            'score' => $candidate['score'],
            'projected_units' => (int) $load['projected_units'],
            'semesters_taught' => $semesters,
            'requires_overload_confirmation' => (bool) $load['requires_confirmation'],
        ];
    }

    /**
     * @param  array<int, int|string>  $facultyIds
     * @return array<int, int> semester count by faculty id
     */
    private function semestersTaught(int $courseId, int $semesterId, array $facultyIds): array
    {
        return DB::table('schedules')
            ->where('course_id', $courseId)
            ->where('semester_id', '!=', $semesterId)
            ->whereIn('faculty_id', $facultyIds)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereNull('deleted_at')
            ->groupBy('faculty_id')
            ->selectRaw('faculty_id, COUNT(DISTINCT semester_id) as semesters')
            ->pluck('semesters', 'faculty_id')
            ->mapWithKeys(static fn ($count, $facultyId): array => [(int) $facultyId => (int) $count])
            ->all();
    }
}
