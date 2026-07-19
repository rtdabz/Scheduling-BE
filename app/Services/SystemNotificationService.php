<?php

namespace App\Services;

use App\Models\Departments;
use App\Models\Schedule;
use App\Models\SystemNotification;
use App\Models\Terms;
use App\Models\User;
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
        $schedule->loadMissing(['subject.department', 'term']);

        $subjectDepartmentId = (int) ($schedule->subject?->department_id ?? 0);
        if ($subjectDepartmentId === 0) {
            return;
        }

        $activeTerm = Terms::query()->where('is_active', true)->first();
        $termId = (int) ($activeTerm?->id ?? $schedule->term_id);

        $query = Schedule::query()
            ->where('term_id', $termId)
            ->whereIn('status', ['approved', 'faculty_assignment', 'finalized'])
            ->whereHas('subject', function ($subjectQuery) use ($subjectDepartmentId) {
                $subjectQuery->where('department_id', $subjectDepartmentId);
            });

        $total = (clone $query)->count();
        $unassigned = (clone $query)->whereNull('faculty_id')->count();

        $department = $schedule->subject?->department;
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
            $subjectDepartmentId,
            $termId,
            null,
            [
                'schedule_id' => $schedule->id,
                'subject_id' => $schedule->subject_id,
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
                $subjectDepartmentId,
                $termId,
                null,
                [
                    'assigned_count' => $total,
                    'total_count' => $total,
                ],
            );
        }
    }
}
