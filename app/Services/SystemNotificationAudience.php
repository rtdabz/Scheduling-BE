<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Shared delivery and read authorization for the scheduling notification feed. */
class SystemNotificationAudience
{
    private const DEPARTMENT = ['secretary', 'director', 'program_head'];

    private const DEPARTMENT_REVIEW = [...self::DEPARTMENT, 'dean'];

    private const FINAL_REVIEW = [...self::DEPARTMENT_REVIEW, 'vpaa'];

    private const ROOM_LENDING = ['secretary', 'director', 'vpaa'];

    // Outcomes also reach the Program Head whose program sent the request (scoped by `program_ids`).
    private const ROOM_LENDING_OUTCOME = [...self::ROOM_LENDING, 'program_head'];

    // Schedule generation is deliberately absent: the feed carries workflow
    // events (submissions, approvals, returns, assignments), not generator runs.
    // Unregistered types fail closed, which also hides older generation notices.
    private const ROLES = [
        'schedule_activity' => self::DEPARTMENT,
        'program_rooms_updated' => self::DEPARTMENT,
        'instructor_assigned' => self::DEPARTMENT,
        'instructor_assignment_released' => self::DEPARTMENT,
        'instructor_assignment_completed' => self::DEPARTMENT,
        'incoming_cross_department_course' => self::DEPARTMENT,
        'incoming_cross_department_courses' => self::DEPARTMENT,
        'cross_department_instructor_assignments_completed' => self::DEPARTMENT,
        'cross_department_instructors_released' => self::DEPARTMENT,
        'cross_department_sections_returned' => self::DEPARTMENT,
        'room_recovery' => self::DEPARTMENT,
        'room_recovery_applied' => self::DEPARTMENT,
        'schedule_attention_required' => ['dean', 'vpaa'],
        'schedule_submitted' => self::DEPARTMENT_REVIEW,
        'schedule_returned_by_dean' => self::DEPARTMENT_REVIEW,
        'schedule_approved_by_dean' => self::FINAL_REVIEW,
        'schedule_approved_by_vpaa' => self::FINAL_REVIEW,
        'schedule_returned_by_vpaa' => self::FINAL_REVIEW,
        'schedule_withdrawn' => self::FINAL_REVIEW,
        'room_transfer_submitted' => ['dean'],
        'room_transfer_dean_approved' => ['vpaa'],
        'room_transfer_approved' => self::DEPARTMENT_REVIEW,
        'room_transfer_rejected' => self::DEPARTMENT_REVIEW,
        // The owner decides room lending; VPAA receives informational progress notices.
        'room_request_submitted' => self::ROOM_LENDING,
        'room_request_approved' => self::ROOM_LENDING_OUTCOME,
        'room_request_rejected' => self::ROOM_LENDING_OUTCOME,
        'room_request_cancelled' => self::ROOM_LENDING,
        'room_request_revoked' => self::ROOM_LENDING_OUTCOME,
        'room_request_borrowed' => self::ROOM_LENDING,
        'room_request_returned' => self::ROOM_LENDING,
    ];

    public static function rolesFor(string $type): array
    {
        return self::ROLES[$type] ?? [];
    }

    public static function allows(User $user, string $type, ?int $departmentId, array $metadata): bool
    {
        if (! $user->is_active || ! in_array($user->role, self::rolesFor($type), true)) {
            return false;
        }
        if (isset($metadata['audience_roles']) && ! in_array($user->role, $metadata['audience_roles'], true)) {
            return false;
        }
        if ($user->role === 'vpaa' && str_starts_with($type, 'room_request_') && ($metadata['requires_action'] ?? false)) {
            return false;
        }
        if ($user->role !== 'vpaa' && ($departmentId === null || (int) $user->department_id !== $departmentId)) {
            return false;
        }
        if ($user->role === 'vpaa' && $type === 'schedule_withdrawn' && ($metadata['withdrawal_stage'] ?? null) !== 'vpaa_review') {
            return false;
        }
        if ($type === 'schedule_attention_required' && ! in_array($metadata['approval_stage'] ?? null, [$user->role, 'approved'], true)) {
            return false;
        }
        if ($user->role === 'program_head') {
            return $user->program_id !== null
                && $user->program()->where('department_id', $user->department_id)->exists()
                && (($metadata['program_ids'] ?? null) === null || in_array((int) $user->program_id, $metadata['program_ids'], true));
        }

        return true;
    }

    public static function scope(Builder $query, User $user): Builder
    {
        $query->where('user_id', $user->id);
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        $types = array_keys(array_filter(self::ROLES, fn (array $roles): bool => in_array($user->role, $roles, true)));
        $query->whereIn('type', $types)
            ->where(fn ($scope) => $scope->whereNull('metadata->recipient_role')
                ->orWhereIn('metadata->recipient_role', $user->isSecretaryOrDirector() ? User::DEPARTMENT_OFFICE_ROLES : [$user->role]));
        $query->where(fn ($stage) => $stage->where('type', '!=', 'schedule_attention_required')
            ->orWhereIn('metadata->approval_stage', [$user->role, 'approved']));

        if ($user->role !== 'vpaa') {
            if ($user->department_id === null) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('department_id', $user->department_id);
        } else {
            $query->where(fn ($stage) => $stage->where('type', '!=', 'schedule_withdrawn')
                ->orWhere('metadata->withdrawal_stage', 'vpaa_review'));
            $query->where(fn ($action) => $action->where('type', 'not like', 'room_request_%')
                ->orWhereNull('metadata->requires_action')->orWhere('metadata->requires_action', false));
        }
        if ($user->role === 'program_head') {
            if ($user->program_id === null || ! $user->program()->where('department_id', $user->department_id)->exists()) {
                return $query->whereRaw('1 = 0');
            }
            $query->where(fn ($scope) => $scope->whereNull('metadata->recipient_program_id')
                ->orWhere('metadata->recipient_program_id', $user->program_id))
                ->where(fn ($scope) => $scope->whereJsonContains('metadata->program_ids', (int) $user->program_id)
                    ->orWhere(fn ($wide) => $wide->whereNull('metadata->program_ids')->where('metadata->department_wide', true)));
        }

        return $query;
    }

    /** Freeze event scope before schedules are replaced, withdrawn, or teaching ownership changes. */
    public static function metadata(string $type, ?int $departmentId, array $metadata, ?array $programIds = null): array
    {
        if ($programIds === null && array_key_exists('program_ids', $metadata)) {
            $programIds = $metadata['program_ids'];
        } elseif ($programIds === null && isset($metadata['selected_section_ids'])) {
            $programIds = DB::table('sections')->whereIn('id', $metadata['selected_section_ids'])->where('department_id', $departmentId)->pluck('program_id')->all();
        } elseif ($programIds === null && isset($metadata['schedule_submission_id'])) {
            $submission = ScheduleSubmission::find($metadata['schedule_submission_id']);
            $programIds = $submission?->sections()->wherePivot('state', 'included')->pluck('sections.program_id')->all() ?? [];
        } elseif ($programIds === null && isset($metadata['program_id'])) {
            $programIds = [$metadata['program_id']];
        } elseif ($programIds === null && (isset($metadata['schedule_id']) || isset($metadata['schedule_ids']))) {
            $programIds = Schedule::query()->with('section')->where('department_id', $departmentId)
                ->whereIn('id', $metadata['schedule_ids'] ?? [$metadata['schedule_id']])->get()
                ->map(fn (Schedule $schedule) => $schedule->program_id ?? $schedule->section?->program_id)->all();
        } elseif ($programIds === null && (isset($metadata['course_id']) || isset($metadata['course_ids']))) {
            $courses = Course::query()->whereIn('id', $metadata['course_ids'] ?? [$metadata['course_id']])->get();
            $programIds = $courses->map(fn (Course $course) => str_starts_with($type, 'incoming_') || $type === 'cross_department_instructors_released'
                ? $course->teaching_program_id : ($course->teaching_source_program_id ?? $course->program_id))->all();
            // A teaching assignment with no target program belongs to the receiving department office.
            if (in_array(null, $programIds, true)) {
                $programIds = null;
            }
        } elseif ($programIds === null && in_array($type, ['schedule_activity', 'instructor_assigned', 'program_rooms_updated'], true)) {
            // Old activity notices without an entity reference cannot establish a Program Head's scope.
            $programIds = [];
        }

        $metadata['program_ids'] = $programIds === null ? null : array_values(array_unique(array_map('intval', array_filter($programIds))));
        $metadata['department_wide'] = $programIds === null;

        return $metadata;
    }
}
