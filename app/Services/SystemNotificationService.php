<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SystemNotificationService
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $roles
     * @param  list<int>|null  $programIds
     */
    public function notifyRoles(
        array $roles,
        string $type,
        string $title,
        string $message,
        ?User $actor = null,
        ?int $departmentId = null,
        ?int $semesterId = null,
        ?string $remarks = null,
        array $metadata = [],
        ?array $programIds = null,
    ): void {
        $metadata['audience_roles'] = User::withDepartmentOffice($roles);
        $metadata['event_id'] ??= (string) Str::uuid();
        $metadata = SystemNotificationAudience::metadata($type, $departmentId, $metadata, $programIds);
        $users = $this->usersForRoles($roles, $departmentId, $metadata['program_ids']);
        $this->createForUsers($users, $type, $title, $message, $actor, $departmentId, $semesterId, $remarks, $metadata);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $metadata
     */
    public function createForUsers(
        Collection $users,
        string $type,
        string $title,
        string $message,
        ?User $actor = null,
        ?int $departmentId = null,
        ?int $semesterId = null,
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        $metadata = SystemNotificationAudience::metadata($type, $departmentId, $metadata);
        // Re-read recipients at delivery time: a queued user may have moved or lost access.
        User::query()->whereKey($users->pluck('id')->unique())->where('is_active', true)->get()->each(function (User $user) use (
            $type,
            $title,
            $message,
            $actor,
            $departmentId,
            $semesterId,
            $remarks,
            $metadata,
        ): void {
            if (! SystemNotificationAudience::allows($user, $type, $departmentId, $metadata)) {
                return;
            }

            $recipientMetadata = [...$metadata, 'recipient_role' => $user->role,
                'recipient_program_id' => $user->role === 'program_head' ? (int) $user->program_id : null];
            if (! isset($metadata['event_id'])) {
                $duplicate = SystemNotification::query()
                    ->where('user_id', $user->id)
                    ->where('type', $type)
                    ->where('message', $message)
                    ->where('department_id', $departmentId)
                    ->where('semester_id', $semesterId)
                    ->where('actor_id', $actor?->id)
                    ->where('remarks', $remarks)
                    ->where('created_at', '>=', now()->subMinutes(2))->get(['metadata'])
                    ->contains(fn (SystemNotification $notice): bool => Arr::except($notice->metadata ?? [], ['_actor', '_actor_type', '_references', '_queued_actor']) == Arr::except($recipientMetadata, ['_actor', '_actor_type', '_references', '_queued_actor']));
                if ($duplicate) {
                    return;
                }
            }

            $attributes = [
                'user_id' => $user->id,
                'event_id' => $metadata['event_id'] ?? null,
                'actor_id' => $actor?->id,
                'department_id' => $departmentId,
                'semester_id' => $semesterId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'remarks' => $remarks,
                'metadata' => $recipientMetadata,
            ];
            if (isset($metadata['event_id'])) {
                // The unique key also handles two workers delivering the same event concurrently.
                SystemNotification::firstOrCreate(['user_id' => $user->id, 'event_id' => $metadata['event_id']], $attributes);
            } else {
                SystemNotification::create($attributes);
            }
        });
    }

    /**
     * @param  array<int, string>  $roles
     * @param  list<int>|null  $programIds
     * @return Collection<int, User>
     */
    private function usersForRoles(array $roles, ?int $departmentId = null, ?array $programIds = null): Collection
    {
        // A department is run by a Secretary or a Director; both receive the office's notices.
        $roles = User::withDepartmentOffice($roles);

        return User::query()
            ->where('is_active', true)
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
            ->when($programIds !== null, fn ($query) => $query->where(fn ($programQuery) => $programQuery
                ->where('role', '!=', 'program_head')
                ->orWhereIn('program_id', $programIds)))
            ->get();
    }

    public function departmentWorkflowMessage(
        string $action,
        Departments $department,
        ?Semester $semester,
        ?User $actor,
        int $updatedCount,
        ?string $remarks = null,
    ): string {
        $actorName = $actor?->name ?? 'System';
        $semesterText = $semester?->semester ? strtoupper($semester->semester).' semester' : 'active semester';
        $academicYear = $semester?->academic_year ? " AY {$semester->academic_year}" : '';
        $count = "{$updatedCount} schedule".($updatedCount === 1 ? '' : 's');

        $message = "{$actorName} {$action} {$department->department_name} for {$semesterText}{$academicYear}. {$count} updated.";

        if ($remarks) {
            $message .= " Remarks: {$remarks}";
        }

        return $message;
    }

    public function notifyInstructorAssignmentProgress(Schedule $schedule, User $actor, ?array $scheduleIds = null): void
    {
        $schedule->loadMissing(['course.department', 'course.teachingDepartment', 'academicSemester']);

        if ($schedule->faculty_id !== null) {
            $this->notifyDelegatedCourseCompletion($schedule, $actor);
        }

        $courseDepartmentId = (int) ($schedule->department_id ?? $schedule->course?->department_id ?? 0);
        if ($courseDepartmentId === 0) {
            return;
        }

        $semesterId = (int) $schedule->semester_id;
        $programId = $schedule->program_id ?? $schedule->section?->program_id;

        $query = Schedule::query()
            ->where('semester_id', $semesterId)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->where('department_id', $courseDepartmentId)
            ->when($programId !== null, fn ($query) => $query->whereHas('section', fn ($section) => $section->where('program_id', $programId)));

        $total = (clone $query)->count();
        $unassigned = (clone $query)->whereNull('faculty_id')->count();

        $department = Departments::find($courseDepartmentId);
        if (! $department) {
            return;
        }

        $semester = $schedule->academicSemester;
        $assignmentType = $schedule->faculty_id === null ? 'instructor_assignment_released' : 'instructor_assigned';

        $this->notifyRoles(
            ['secretary', 'program_head'],
            $assignmentType,
            'Instructor assignment updated',
            $this->departmentWorkflowMessage(
                $schedule->faculty_id === null ? 'released instructor assignments in' : 'assigned instructors in',
                $department,
                $semester,
                $actor,
                count($scheduleIds ?? [$schedule->id]),
            ),
            $actor,
            $courseDepartmentId,
            $semesterId,
            null,
            [
                'schedule_id' => $schedule->id,
                'schedule_ids' => $scheduleIds ?? [$schedule->id],
                'course_id' => $schedule->course_id,
                'faculty_id' => $schedule->faculty_id,
                'assigned_count' => max(0, $total - $unassigned),
                'total_count' => $total,
                'program_id' => $programId,
            ],
        );

        $teachingDepartmentId = (int) ($schedule->course?->teaching_department_id ?? 0);
        if ($teachingDepartmentId !== 0 && $teachingDepartmentId !== $courseDepartmentId) {
            $this->notifyRoles(
                ['secretary', 'program_head'], $assignmentType, 'Cross-department instructor assignment updated',
                $actor->name.' '.($schedule->faculty_id === null ? 'released' : 'updated').' instructor assignments for '
                    .($schedule->course?->course_code ?? 'a course')." offered by {$department->department_name}.",
                actor: $actor, departmentId: $teachingDepartmentId, semesterId: $semesterId,
                metadata: ['schedule_ids' => $scheduleIds ?? [$schedule->id], 'course_id' => $schedule->course_id,
                    'source_department_id' => $courseDepartmentId,
                    'program_ids' => $schedule->course->teaching_program_id === null ? null : [(int) $schedule->course->teaching_program_id]],
            );
        }

        if ($total > 0 && $unassigned === 0) {
            $this->notifyRoles(
                ['secretary', 'program_head'],
                'instructor_assignment_completed',
                'Instructor assignment completed',
                $this->departmentWorkflowMessage(
                    'completed instructor assignments for',
                    $department,
                    $semester,
                    $actor,
                    $total,
                ),
                $actor,
                $courseDepartmentId,
                $semesterId,
                null,
                [
                    'assigned_count' => $total,
                    'total_count' => $total,
                    'program_id' => $programId,
                ],
            );
        }
    }

    private function notifyDelegatedCourseCompletion(Schedule $schedule, User $actor, ?array $completedScheduleIds = null): void
    {
        $course = $schedule->course;
        $sourceDepartmentId = (int) ($course?->department_id ?? 0);
        $receivingDepartmentId = (int) ($course?->teaching_department_id ?? 0);
        if ($sourceDepartmentId === 0 || $receivingDepartmentId === 0 || $sourceDepartmentId === $receivingDepartmentId) {
            return;
        }

        $courseIds = Course::query()
            ->where('department_id', $sourceDepartmentId)
            ->where('teaching_department_id', $receivingDepartmentId)
            ->when($course->teaching_source_program_id !== null, fn ($query) => $query->where('teaching_source_program_id', $course->teaching_source_program_id))
            ->where('status', 'active')
            ->pluck('id');
        if ($courseIds->isEmpty()) {
            return;
        }

        if ($completedScheduleIds !== null) {
            $completed = Schedule::query()
                ->whereIn('id', $completedScheduleIds)
                ->whereIn('course_id', $courseIds)
                ->get(['id', 'course_id', 'faculty_id', 'faculty_assignment_done']);
            if ($completed->isEmpty() || $completed->contains(fn (Schedule $item) => $item->faculty_id === null || ! (bool) $item->faculty_assignment_done)) {
                return;
            }
            $courseIds = $completed->pluck('course_id')->unique()->values();
        }

        $semesterId = (int) ($schedule->semester_id ?? 0);
        if ($completedScheduleIds === null) {
            $pending = Schedule::query()->where('semester_id', $semesterId)->whereIn('course_id', $courseIds)
                ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)->whereNull('faculty_id')->exists();
            if ($pending) {
                return;
            }
        }

        $source = $course->department?->department_name ?? 'The source department';
        $receiving = $course->teachingDepartment?->department_name ?? 'The receiving department';
        $message = "{$receiving} completed instructor assignments for the {$courseIds->count()} course".($courseIds->count() === 1 ? '' : 's').'.';
        $this->notifyRoles(['secretary', 'program_head'], 'cross_department_instructor_assignments_completed', 'Cross-department assignments completed', $message, $actor, $sourceDepartmentId, $semesterId, null, ['course_ids' => $courseIds->values()->all(), 'receiving_department_id' => $receivingDepartmentId], $course->teaching_source_program_id === null ? null : [(int) $course->teaching_source_program_id]);
    }

    public function notifyCrossDepartmentCompletion(Schedule $schedule, User $actor, ?array $completedScheduleIds = null): void
    {
        $this->notifyDelegatedCourseCompletion($schedule, $actor, $completedScheduleIds);
    }

    /**
     * @param  Collection<int, Schedule>  $releasedRows
     */
    public function notifyCrossDepartmentInstructorsReleased(Collection $releasedRows, User $actor, string $action): void
    {
        $releasedRows
            ->groupBy(static fn (Schedule $row): string => $row->course->teaching_department_id.'|'.($row->course->teaching_program_id ?? ''))
            ->each(function ($rows) use ($actor, $action): void {
                $first = $rows->first();
                $classes = $rows
                    ->unique(static fn (Schedule $row): string => $row->section_id.':'.$row->course_id)
                    ->map(static fn (Schedule $row): string => trim(($row->course?->course_code ?? 'Course').' ('.($row->section?->section_name ?? 'section').')'))
                    ->values();
                $owner = $first->course?->department?->department_name ?? 'The owning department';
                $label = $classes->take(5)->implode(', ').($classes->count() > 5 ? ' and '.($classes->count() - 5).' more' : '');
                $message = "{$owner} {$action} its schedule, so your instructor assignment"
                    .($classes->count() === 1 ? ' was' : 's were')
                    ." released for {$label}. Assign them again once the new schedule is approved.";
                $this->notifyRoles(
                    ['secretary', 'program_head'],
                    'cross_department_instructors_released',
                    'Cross-department instructors released',
                    $message,
                    $actor,
                    (int) $first->course->teaching_department_id,
                    (int) $first->semester_id ?: null,
                    null,
                    [
                        'program_ids' => $first->course->teaching_program_id === null ? null : [(int) $first->course->teaching_program_id],
                        'schedule_ids' => $rows->pluck('id')->map('intval')->values()->all(),
                        'course_ids' => $rows->pluck('course_id')->map('intval')->unique()->values()->all(),
                        'source_department_id' => (int) $first->department_id,
                    ],
                    $first->course->teaching_program_id === null ? null : [(int) $first->course->teaching_program_id],
                );
            });
    }
}
