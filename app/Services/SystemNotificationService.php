<?php

namespace App\Services;

use App\Models\Departments;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\SystemNotification;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Support\Collection;

class SystemNotificationService
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $roles
     */
    public function notifyRoles(
        array $roles,
        string $type,
        string $title,
        string $message,
        ?User $actor = null,
        ?int $departmentId = null,
        ?int $termId = null,
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        $users = $this->usersForRoles($roles, $departmentId);
        $this->createForUsers($users, $type, $title, $message, $actor, $departmentId, $termId, $remarks, $metadata);
    }

    /**
     * @param Collection<int, User> $users
     * @param array<string, mixed> $metadata
     */
    public function createForUsers(
        Collection $users,
        string $type,
        string $title,
        string $message,
        ?User $actor = null,
        ?int $departmentId = null,
        ?int $termId = null,
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        $users->unique('id')->each(function (User $user) use (
            $type,
            $title,
            $message,
            $actor,
            $departmentId,
            $termId,
            $remarks,
            $metadata,
        ): void {
            SystemNotification::create([
                'user_id' => $user->id,
                'actor_id' => $actor?->id,
                'department_id' => $departmentId,
                'term_id' => $termId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'remarks' => $remarks,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * @param array<int, string> $roles
     * @return Collection<int, User>
     */
    private function usersForRoles(array $roles, ?int $departmentId = null): Collection
    {
        return User::query()
            ->whereIn('role', $roles)
            ->when($departmentId !== null, function ($query) use ($roles, $departmentId) {
                $hasInstitutionRole = in_array('vpaa', $roles, true);
                $query->where(function ($roleQuery) use ($departmentId, $hasInstitutionRole) {
                    $roleQuery->where('department_id', $departmentId);

                    if ($hasInstitutionRole) {
                        $roleQuery->orWhere('role', 'vpaa');
                    }
                });
            })
            ->get();
    }

    public function departmentWorkflowMessage(
        string $action,
        Departments $department,
        ?Terms $term,
        ?User $actor,
        int $updatedCount,
        ?string $remarks = null,
    ): string {
        $actorName = $actor?->name ?? 'System';
        $semester = $term?->semester ? strtoupper($term->semester) . ' semester' : 'active semester';
        $academicYear = $term?->academic_year ? " AY {$term->academic_year}" : '';
        $count = "{$updatedCount} schedule" . ($updatedCount === 1 ? '' : 's');

        $message = "{$actorName} {$action} {$department->department_name} for {$semester}{$academicYear}. {$count} updated.";

        if ($remarks) {
            $message .= " Remarks: {$remarks}";
        }

        return $message;
    }

    public function notifyInstructorAssignmentProgress(Schedule $schedule, User $actor): void
    {
        $schedule->loadMissing(['course.department', 'course.teachingDepartment', 'term']);

        $this->notifyDelegatedCourseCompletion($schedule, $actor);

        $courseDepartmentId = (int) ($schedule->course?->department_id ?? 0);
        if ($courseDepartmentId === 0) {
            return;
        }

        $activeTerm = Terms::query()->where('is_active', true)->first();
        $termId = (int) ($activeTerm?->id ?? $schedule->term_id);

        $query = Schedule::query()
            ->where('term_id', $termId)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereHas('course', function ($courseQuery) use ($courseDepartmentId) {
                $courseQuery->where('department_id', $courseDepartmentId);
            });

        $total = (clone $query)->count();
        $unassigned = (clone $query)->whereNull('faculty_id')->count();

        $department = $schedule->course?->department;
        if (!$department) {
            return;
        }

        $term = $activeTerm ?? $schedule->term;

        $this->notifyRoles(
            ['secretary', 'program_head', 'dean', 'vpaa'],
            'instructor_assigned',
            'Instructor assignment updated',
            $this->departmentWorkflowMessage(
                'assigned an instructor in',
                $department,
                $term,
                $actor,
                1,
            ),
            $actor,
            $courseDepartmentId,
            $termId,
            null,
            [
                'schedule_id' => $schedule->id,
                'course_id' => $schedule->course_id,
                'faculty_id' => $schedule->faculty_id,
                'assigned_count' => max(0, $total - $unassigned),
                'total_count' => $total,
            ],
        );

        if ($total > 0 && $unassigned === 0) {
            $this->notifyRoles(
                ['secretary', 'program_head', 'dean', 'vpaa'],
                'instructor_assignment_completed',
                'Instructor assignment completed',
                $this->departmentWorkflowMessage(
                    'completed instructor assignments for',
                    $department,
                    $term,
                    $actor,
                    $total,
                ),
                $actor,
                $courseDepartmentId,
                $termId,
                null,
                [
                    'assigned_count' => $total,
                    'total_count' => $total,
                ],
            );
        }
    }

    private function notifyDelegatedCourseCompletion(Schedule $schedule, User $actor, ?array $completedScheduleIds = null): void
    {
        $course = $schedule->course;
        $sourceDepartmentId = (int) ($course?->department_id ?? 0);
        $receivingDepartmentId = (int) ($course?->teaching_department_id ?? 0);
        if ($sourceDepartmentId === 0 || $receivingDepartmentId === 0 || $sourceDepartmentId === $receivingDepartmentId) return;

        $courseIds = Course::query()
            ->where('department_id', $sourceDepartmentId)
            ->where('teaching_department_id', $receivingDepartmentId)
            ->where('status', 'active')
            ->pluck('id');
        if ($courseIds->isEmpty()) return;

        // A Done action represents the exact schedules shown in the receiving
        // department's timetable. Use that batch when supplied so unrelated
        // schedules cannot suppress or delay the completion notification.
        if ($completedScheduleIds !== null) {
            $completed = Schedule::query()
                ->whereIn('id', $completedScheduleIds)
                ->whereIn('course_id', $courseIds)
                ->get(['id', 'course_id', 'faculty_id', 'faculty_assignment_done']);
            if ($completed->isEmpty() || $completed->contains(fn (Schedule $item) => $item->faculty_id === null || ! (bool) $item->faculty_assignment_done)) return;
            $courseIds = $completed->pluck('course_id')->unique()->values();
        }

        $termId = (int) ($schedule->term_id ?? 0);
        if ($completedScheduleIds === null) {
            $pending = Schedule::query()->where('term_id', $termId)->whereIn('course_id', $courseIds)
                ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)->whereNull('faculty_id')->exists();
            if ($pending) return;
        }

        $source = $course->department?->department_name ?? 'The source department';
        $receiving = $course->teachingDepartment?->department_name ?? 'The receiving department';
        $message = "{$receiving} completed instructor assignments for the {$courseIds->count()} course" . ($courseIds->count() === 1 ? '' : 's') . '.';
        $this->notifyRoles(['secretary', 'program_head', 'dean'], 'cross_department_instructor_assignments_completed', 'Cross-department assignments completed', $message, $actor, $sourceDepartmentId, $termId, null, ['course_ids' => $courseIds->values()->all(), 'receiving_department_id' => $receivingDepartmentId, 'link' => '/secretary/cross-department-assignments']);
    }

    public function notifyCrossDepartmentCompletion(Schedule $schedule, User $actor, ?array $completedScheduleIds = null): void
    {
        $this->notifyDelegatedCourseCompletion($schedule, $actor, $completedScheduleIds);
    }
}
