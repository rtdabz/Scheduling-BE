<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverSystemNotifications;
use App\Models\Faculty;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Models\User;
use App\Services\RoomRecoveryService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\ScheduleSemesterArchiver;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ActorIdentity;
use App\Support\ApiCache;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomRequestController extends Controller
{
    private const LENDABLE_ROOM_TYPES = ['lecture'];

    public const BORROWING_CLOSED_MESSAGE = "This room is not yet available for borrowing. The owning department's schedules are awaiting VPAA approval.";

    public const BORROWING_SUSPENDED_MESSAGE = 'This room is temporarily unavailable for borrowing while the owning department updates its schedules.';

    public function __construct(
        private readonly ScheduleHistoryRecorder $history,
        private readonly RevisionChangeRecorder $revisionChanges,
        private readonly CrossDepartmentApprovalGate $approvalGate,
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'status' => ['nullable', Rule::in($this->statuses())],
        ]);

        $departmentId = (int) $user->department_id;

        $requests = RoomRequest::query()
            ->with($this->relations())
            ->when($user->role === 'program_head', fn ($query) => $query
                // A Program Head sends requests for their own program and decides none.
                ->where('requesting_department_id', $departmentId)
                ->where('requesting_program_id', (int) ($user->program_id ?? 0)), fn ($query) => $query
                ->where(fn ($scope) => $scope
                    ->where('requesting_department_id', $departmentId)
                    ->orWhere('owner_department_id', $departmentId)))
            ->when(isset($validated['semester_id']), fn ($query) => $query->where('semester_id', (int) $validated['semester_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($requests->map(fn (RoomRequest $roomRequest): array => $this->present($roomRequest)
            + ['can_cancel' => $this->mayActForRequestingProgram($request, $roomRequest)])->values());
    }

    public function occupancy(Request $request, int $room): JsonResponse
    {
        $roomModel = Rooms::query()->with('department')->findOrFail($room);
        $semester = $this->resolveSemester($request->input('semester_id'));

        $schedules = Schedule::query()
            ->with([
                'course:id,course_code,course_name,course_category,units,lecture_hours,lab_hours',
                'section:id,section_name',
                'department:id,department_code',
                'faculty:id,first_name,last_name,middle_name,suffix,honorific_prefix,credentials',
            ])
            ->where('room_id', $roomModel->id)
            ->where('semester_id', $semester->id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get(['id', 'semester_id', 'course_id', 'section_id', 'faculty_id', 'room_id', 'department_id', 'day', 'start_time', 'end_time', 'mode', 'status']);

        $grants = $this->approvedWindows($roomModel->id, $semester->id);

        // Only VPAA-approved classes are published to the room timetable. Unapproved classes
        // inside an approved borrowing window stay in `schedules` (their time already shows as
        // the grant) so the give-back / revoke impact still counts every class that will move.
        $isVpaaApproved = fn (Schedule $schedule): bool => in_array((string) $schedule->status, ScheduleSemesterArchiver::VPAA_APPROVED_STATUSES, true);
        $occupyingClasses = $schedules->filter($isVpaaApproved)->values();
        $schedules = $schedules
            ->filter(fn (Schedule $schedule): bool => $isVpaaApproved($schedule) || $this->insideGrant($schedule, $grants))
            ->values();

        $masked = $this->maskedScheduleIds($request->user(), $roomModel, $schedules);
        $borrowing = $this->borrowingStatus($roomModel, $semester->id);

        return response()->json([
            'room' => $this->presentRoom($roomModel),
            'semester' => $this->presentSemester($semester),
            // Free time is lendable only once the owner's timetable has final VPAA approval.
            'borrowing' => $borrowing,
            'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
            'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
            'occupied' => [
                ...$occupyingClasses->map(fn (Schedule $schedule): array => [
                    'kind' => 'class',
                    'day' => (string) $schedule->day,
                    'start_time' => substr((string) $schedule->start_time, 0, 5),
                    'end_time' => substr((string) $schedule->end_time, 0, 5),
                    'department_code' => isset($masked[$schedule->id]) ? null : $schedule->department?->department_code,
                    'label' => isset($masked[$schedule->id])
                        ? 'Occupied'
                        : trim(($schedule->course?->course_code ?? '').' '.($schedule->section?->section_name ?? '')),
                ])->all(),
                ...$grants->map(fn (object $window): array => [
                    'kind' => 'grant',
                    'day' => (string) $window->day,
                    'start_time' => substr((string) $window->start_time, 0, 5),
                    'end_time' => substr((string) $window->end_time, 0, 5),
                    'department_code' => $window->department_code,
                    'label' => 'Lent to '.$window->department_code,
                ])->all(),
            ],
            'schedules' => $schedules->map(fn (Schedule $schedule): array => isset($masked[$schedule->id])
                ? [
                    // Another department's class: only the occupied time is shared.
                    'id' => $schedule->id,
                    'semester_id' => $schedule->semester_id,
                    'section_id' => null,
                    'course_id' => null,
                    'faculty_id' => null,
                    'room_id' => $schedule->room_id,
                    'department_id' => $schedule->department_id,
                    'day' => (string) $schedule->day,
                    'start_time' => (string) $schedule->start_time,
                    'end_time' => (string) $schedule->end_time,
                    'mode' => $schedule->mode,
                    'status' => $schedule->status,
                    'is_masked' => true,
                    'section' => null,
                    'course' => null,
                    'faculty' => null,
                ]
                : [
                    'id' => $schedule->id,
                    'semester_id' => $schedule->semester_id,
                    'section_id' => $schedule->section_id,
                    'course_id' => $schedule->course_id,
                    'faculty_id' => $schedule->faculty_id,
                    'room_id' => $schedule->room_id,
                    'department_id' => $schedule->department_id,
                    'day' => (string) $schedule->day,
                    'start_time' => (string) $schedule->start_time,
                    'end_time' => (string) $schedule->end_time,
                    'mode' => $schedule->mode,
                    'status' => $schedule->status,
                    'is_masked' => false,
                    'section' => $schedule->section?->only(['id', 'section_name']),
                    'course' => $schedule->course?->only(['id', 'course_code', 'course_name', 'course_category', 'units', 'lecture_hours', 'lab_hours']),
                    'faculty' => $schedule->faculty?->only(['id', ...Faculty::NAME_COLUMNS]),
                ])->values()->all(),
        ]);
    }

    /**
     * @param  Collection<int, object>  $grants
     */
    private function insideGrant(Schedule $schedule, Collection $grants): bool
    {
        $start = substr((string) $schedule->start_time, 0, 5);
        $end = substr((string) $schedule->end_time, 0, 5);

        return $grants->contains(fn (object $window): bool => (int) $window->department_id === (int) $schedule->department_id
            && (string) $window->day === (string) $schedule->day
            && substr((string) $window->start_time, 0, 5) < $end
            && $start < substr((string) $window->end_time, 0, 5));
    }

    /**
     * Schedules whose class details the viewer may not see. The room's owner and the
     * VPAA see every class in it; anyone else sees only their own department's classes.
     *
     * @param  Collection<int, Schedule>  $schedules
     * @return array<int, true>
     */
    private function maskedScheduleIds(User $user, Rooms $room, Collection $schedules): array
    {
        $viewerDepartmentId = $user->department_id === null ? null : (int) $user->department_id;
        if ($user->role === 'vpaa'
            || ($viewerDepartmentId !== null && $room->department_id !== null && (int) $room->department_id === $viewerDepartmentId)) {
            return [];
        }

        return $schedules
            ->filter(fn (Schedule $schedule): bool => $viewerDepartmentId === null || (int) $schedule->department_id !== $viewerDepartmentId)
            ->mapWithKeys(fn (Schedule $schedule): array => [(int) $schedule->id => true])
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->department_id === null) {
            abort(422, 'Your account is not assigned to a department, so it cannot request rooms.');
        }

        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id,deleted_at,NULL',
            'semester_id' => 'nullable|integer|exists:semesters,id,deleted_at,NULL',
            'purpose' => 'required|string|max:1000',
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('department_id', $user->department_id)],
            'windows' => 'required|array|min:1|max:21',
            'windows.*.day' => ['required', 'string', Rule::in(SchedulingPolicy::PERSISTABLE_DAYS)],
            'windows.*.start_time' => 'required|date_format:H:i',
            'windows.*.end_time' => 'required|date_format:H:i',
        ]);

        $programId = $this->requestingProgramId($request, $validated['program_id'] ?? null);
        $semester = $this->resolveSemester($validated['semester_id'] ?? null);
        $windows = $this->normalizeWindows($validated['windows']);
        $departmentId = (int) $user->department_id;

        $roomRequest = $this->schedulingTransaction(function () use ($validated, $semester, $windows, $departmentId, $programId, $user): RoomRequest {
            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->find((int) $validated['room_id']);
            if ($room === null) {
                throw ValidationException::withMessages(['room_id' => 'This room has just been archived and can no longer be requested.']);
            }
            $this->assertLendable($room, $departmentId);
            $this->assertBorrowingOpen($room, $semester->id);

            $duplicate = RoomRequest::query()
                ->where('room_id', $room->id)
                ->where('semester_id', $semester->id)
                ->where('requesting_department_id', $departmentId)
                ->when($programId === null, fn ($query) => $query->whereNull('requesting_program_id'), fn ($query) => $query->where('requesting_program_id', $programId))
                ->where('status', RoomRequest::STATUS_PENDING)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'room_id' => ($programId === null ? 'Your department' : 'This program')." already has a pending request for {$room->room_code} this semester.",
                ]);
            }

            $this->assertVacant($room, $semester->id, $windows);

            $roomRequest = RoomRequest::create([
                'room_id' => $room->id,
                'semester_id' => $semester->id,
                'requesting_department_id' => $departmentId,
                'requesting_program_id' => $programId,
                'owner_department_id' => (int) $room->department_id,
                'status' => RoomRequest::STATUS_PENDING,
                'purpose' => trim((string) $validated['purpose']),
                'requested_by' => $user->id,
            ]);
            $roomRequest->windows()->createMany($windows);
            $this->recordDecision($roomRequest, $user, 'room_request_submitted', null);

            return $roomRequest;
        });

        $roomRequest->load($this->relations());
        $this->deliverNotifications(fn (): array => [
            $this->submittedNotification($roomRequest, $user),
            $this->vpaaNotification($roomRequest, $user, 'room_request_submitted'),
        ]);

        return response()->json([
            'message' => sprintf('Room request sent to %s for approval.', $roomRequest->ownerDepartment?->department_code ?? 'the room\'s department'),
            'data' => $this->present($roomRequest),
        ], 201);
    }

    public function cancel(Request $request, int $roomRequest): JsonResponse
    {
        $user = $request->user();
        $model = RoomRequest::query()->findOrFail($roomRequest);

        if ((int) $model->requesting_department_id !== (int) $user->department_id) {
            abort(403, 'Only the requesting department can cancel this request.');
        }
        if (! $this->mayActForRequestingProgram($request, $model)) {
            abort(403, $user->role === 'program_head'
                ? 'Only the Program Head of the requesting program can cancel this request.'
                : ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE);
        }

        return $this->close($model, $user, RoomRequest::STATUS_CANCELLED, null, [RoomRequest::STATUS_PENDING, RoomRequest::STATUS_APPROVED]);
    }

    public function approve(Request $request, int $roomRequest): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate(['remarks' => 'nullable|string|max:1000']);

        $model = $this->schedulingTransaction(function () use ($roomRequest, $validated, $user): RoomRequest {
            /** @var RoomRequest $model */
            $model = RoomRequest::query()->with('windows')->lockForUpdate()->findOrFail($roomRequest);
            $this->assertOwnerReviewer($model, $user);
            $this->assertStatus($model, [RoomRequest::STATUS_PENDING]);

            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->find($model->room_id);
            if ($room === null) {
                throw ValidationException::withMessages([
                    'room_request' => 'This room has been archived, so the request can no longer be approved. Reject it instead.',
                ]);
            }
            // Only the room's current owner may lend it; a reassignment after submission makes the request stale.
            if ((int) $room->department_id !== (int) $model->owner_department_id) {
                throw ValidationException::withMessages([
                    'room_request' => "{$room->room_code} no longer belongs to your department, so this request cannot be approved. "
                        .'The requesting department must cancel it and send a new request to the current owner.',
                ]);
            }
            $this->assertLendable($room, (int) $model->requesting_department_id);
            $this->assertBorrowingOpen($room, (int) $model->semester_id);

            $this->assertVacant(
                $room,
                (int) $model->semester_id,
                $model->windows->map(fn ($window): array => [
                    'day' => (string) $window->day,
                    'start_time' => substr((string) $window->start_time, 0, 5),
                    'end_time' => substr((string) $window->end_time, 0, 5),
                ])->all(),
                ignoreRequestId: (int) $model->id,
            );

            $previousStatus = $model->status;
            $model->update([
                'status' => RoomRequest::STATUS_APPROVED,
                'review_remarks' => isset($validated['remarks']) ? trim((string) $validated['remarks']) : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
            $this->recordDecision($model, $user, 'room_request_approved', $previousStatus, $model->review_remarks);

            return $model;
        });

        $model->load($this->relations());
        $this->flushSchedulingCaches();
        $this->deliverNotifications(fn (): array => [
            $this->reviewedNotification($model, $user, 'approved'),
            $this->vpaaNotification($model, $user, 'room_request_borrowed'),
        ]);

        return response()->json([
            'message' => 'Room request approved.',
            'data' => $this->present($model),
        ]);
    }

    public function reject(Request $request, int $roomRequest): JsonResponse
    {
        $model = RoomRequest::query()->findOrFail($roomRequest);
        $this->assertOwnerReviewer($model, $request->user());
        $validated = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->close(
            $model,
            $request->user(),
            RoomRequest::STATUS_REJECTED,
            trim((string) $validated['remarks']),
            [RoomRequest::STATUS_PENDING],
        );
    }

    public function revoke(Request $request, int $roomRequest): JsonResponse
    {
        $model = RoomRequest::query()->findOrFail($roomRequest);
        $this->assertOwnerReviewer($model, $request->user());
        $validated = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->close(
            $model,
            $request->user(),
            RoomRequest::STATUS_REVOKED,
            trim((string) $validated['remarks']),
            [RoomRequest::STATUS_APPROVED],
        );
    }

    /**
     * @param  list<string>  $allowedFrom
     */
    private function close(RoomRequest $model, User $user, string $status, ?string $remarks, array $allowedFrom): JsonResponse
    {
        $wasApproved = false;
        /** @var Collection<int, Schedule> $released */
        $released = collect();

        $this->schedulingTransaction(function () use (&$model, $user, $status, $remarks, $allowedFrom, &$wasApproved, &$released): void {
            $model = RoomRequest::query()->lockForUpdate()->findOrFail($model->id);
            $this->assertStatus($model, $allowedFrom);
            $wasApproved = $model->status === RoomRequest::STATUS_APPROVED;

            if ($wasApproved) {
                $released = $this->endGrant($model, $user, $status, $remarks);
            }

            $previousStatus = $model->status;
            $changes = ['status' => $status];
            if ($status !== RoomRequest::STATUS_CANCELLED) {
                $changes['review_remarks'] = $remarks;
                if (! $wasApproved) {
                    $changes += ['reviewed_by' => $user->id, 'reviewed_at' => now()];
                }
            }
            $model->update($changes);
            $event = match ($status) {
                RoomRequest::STATUS_REJECTED => 'room_request_rejected',
                RoomRequest::STATUS_REVOKED => 'room_request_revoked',
                default => 'room_request_cancelled',
            };
            $this->recordDecision($model, $user, $event, $previousStatus, $remarks);
        });

        $model->load($this->relations());
        if ($wasApproved) {
            $this->flushSchedulingCaches();
        }

        $releasedNote = $this->releasedNote($model, $released);

        $this->deliverNotifications(fn (): array => [
            $status === RoomRequest::STATUS_CANCELLED
                ? $this->cancelledNotification($model, $user)
                : $this->reviewedNotification($model, $user, $status, $releasedNote),
            $this->vpaaNotification($model, $user, $wasApproved ? 'room_request_returned' : 'room_request_'.$status),
        ]);

        return response()->json([
            'message' => trim(match ($status) {
                RoomRequest::STATUS_REJECTED => 'Room request rejected.',
                RoomRequest::STATUS_REVOKED => 'Room grant revoked.',
                default => $wasApproved ? 'Room given back.' : 'Room request cancelled.',
            }.' '.$releasedNote),
            'released_schedule_count' => $released->count(),
            'released_schedule_ids' => $released->pluck('id')->map('intval')->values()->all(),
            'data' => $this->present($model),
        ]);
    }

    private function recordDecision(RoomRequest $model, User $actor, string $event, ?string $previousStatus, ?string $remarks = null): void
    {
        $decision = ['event' => $event, 'occurred_at' => now()->toISOString(),
            'actor' => ActorIdentity::snapshot($actor), 'before_status' => $previousStatus,
            'after_status' => $model->status, 'remarks' => $remarks];
        $snapshots = $model->actor_snapshots ?? [];
        $snapshots['decisions'][] = $decision;
        $model->forceFill(['actor_snapshots' => $snapshots])->save();
        SchedulingAuditLog::create([
            'user_id' => $actor->id, 'semester_id' => $model->semester_id,
            'department_id' => $model->requesting_department_id, 'action' => $event,
            'metadata' => ['target' => ['type' => 'room_request', 'id' => $model->id],
                'room_request_id' => $model->id, 'decision' => $decision],
        ]);
    }

    /**
     * The program a new request is sent for. A Program Head always requests for their own program.
     * The department office requests for a program without a Program Head; it may leave the
     * program out (a department-level request) only while no program of its department has one.
     */
    private function requestingProgramId(Request $request, mixed $programId): ?int
    {
        $user = $request->user();
        if ($user->role === 'program_head') {
            if (! $this->authorization->programIsWritable($request, $user->program_id === null ? null : (int) $user->program_id)) {
                abort(403, 'Your account is not linked to a program yet.');
            }
            if ($programId !== null && (int) $programId !== (int) $user->program_id) {
                abort(403, 'Program Heads can only request rooms for their own program.');
            }

            return (int) $user->program_id;
        }

        if ($programId === null) {
            if ($this->authorization->headManagedProgramIds($request) !== []) {
                throw ValidationException::withMessages([
                    'program_id' => 'Choose the program this room is for. Programs with a Program Head send their own room requests.',
                ]);
            }

            return null;
        }
        if (! $this->authorization->officeMayManageProgram($request, $programId)) {
            abort(403, 'This program is managed by its Program Head, who sends its room requests.');
        }

        return (int) $programId;
    }

    /** Whether the user may act (cancel) for the program that sent this request. */
    private function mayActForRequestingProgram(Request $request, RoomRequest $model): bool
    {
        $user = $request->user();
        if ((int) $model->requesting_department_id !== (int) $user->department_id) {
            return false;
        }
        if ($user->role === 'program_head') {
            return $model->requesting_program_id !== null
                && $this->authorization->programIsWritable($request, (int) $model->requesting_program_id);
        }

        return $user->isSecretaryOrDirector()
            && ($model->requesting_program_id !== null || $this->authorization->headManagedProgramIds($request) === [])
            && $this->authorization->officeMayManageProgram($request, $model->requesting_program_id);
    }

    private function assertOwnerReviewer(RoomRequest $model, User $user): void
    {
        if ($model->owner_department_id === null || (int) $model->owner_department_id !== (int) $user->department_id) {
            abort(403, 'Only the department that owns this room can decide on this request.');
        }
    }

    private function assertLendable(Rooms $room, int $departmentId): void
    {
        if (! in_array((string) $room->room_type, self::LENDABLE_ROOM_TYPES, true)) {
            throw ValidationException::withMessages([
                'room_id' => 'Only lecture rooms can be requested. Laboratories cannot be borrowed.',
            ]);
        }

        if ((string) $room->status !== 'available') {
            throw ValidationException::withMessages([
                'room_id' => "{$room->room_code} is not available for scheduling.",
            ]);
        }

        if ($room->department_id === null) {
            throw ValidationException::withMessages([
                'room_id' => "{$room->room_code} is a shared room; your department can already schedule into it.",
            ]);
        }

        if ((int) $room->department_id === $departmentId) {
            throw ValidationException::withMessages([
                'room_id' => "{$room->room_code} already belongs to your department.",
            ]);
        }
    }

    /**
     * The owner's own timetable comes first: its free time can be lent only after every
     * one of its sections has final VPAA approval for that semester. Shared rooms have no owner.
     */
    /**
     * A recall, partial or full, leaves the owner short of full approval, so it closes
     * borrowing as well; recalled classes never count as vacant time. The reason only picks
     * the wording: "owner_updating" once the owner has been VPAA-approved this semester or
     * has classes back in revision, "awaiting_approval" before its first approval.
     *
     * @return array{open: bool, reason: 'awaiting_approval'|'owner_updating'|null, message: string|null}
     */
    private function borrowingStatus(Rooms $room, int $semesterId): array
    {
        $ownerId = $room->department_id === null ? null : (int) $room->department_id;
        if ($ownerId === null || $this->approvalGate->isOpen($ownerId, $semesterId)) {
            return ['open' => true, 'reason' => null, 'message' => null];
        }

        $updating = Schedule::query()
            ->where('department_id', $ownerId)
            ->where('semester_id', $semesterId)
            ->where('status', 'revision')
            ->exists()
            || $this->approvalGate->isAwaitingReapproval($ownerId, $semesterId);

        return $updating
            ? ['open' => false, 'reason' => 'owner_updating', 'message' => self::BORROWING_SUSPENDED_MESSAGE]
            : ['open' => false, 'reason' => 'awaiting_approval', 'message' => self::BORROWING_CLOSED_MESSAGE];
    }

    private function assertBorrowingOpen(Rooms $room, int $semesterId): void
    {
        $status = $this->borrowingStatus($room, $semesterId);
        if (! $status['open']) {
            throw ValidationException::withMessages(['room_id' => $status['message']]);
        }
    }

    /**
     * @param  list<array{day: string, start_time: string, end_time: string}>  $windows
     */
    private function assertVacant(Rooms $room, int $semesterId, array $windows, ?int $ignoreRequestId = null): void
    {
        $days = array_values(array_unique(array_column($windows, 'day')));

        $schedules = Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->where('room_id', $room->id)
            ->where('semester_id', $semesterId)
            ->whereIn('day', $days)
            ->get(['id', 'course_id', 'section_id', 'day', 'start_time', 'end_time']);

        $grants = $this->approvedWindows($room->id, $semesterId, $ignoreRequestId);

        $conflicts = [];
        foreach ($windows as $window) {
            $start = RoomAccessPolicy::minutes($window['start_time']);
            $end = RoomAccessPolicy::minutes($window['end_time']);
            $label = sprintf('%s %s-%s', substr($window['day'], 0, 3), $window['start_time'], $window['end_time']);

            foreach ($schedules as $schedule) {
                if ((string) $schedule->day === $window['day']
                    && RoomAccessPolicy::minutes((string) $schedule->start_time) < $end
                    && $start < RoomAccessPolicy::minutes((string) $schedule->end_time)) {
                    $conflicts[] = sprintf(
                        '%s overlaps %s %s (%s-%s)',
                        $label,
                        $schedule->course?->course_code,
                        $schedule->section?->section_name,
                        substr((string) $schedule->start_time, 0, 5),
                        substr((string) $schedule->end_time, 0, 5),
                    );
                }
            }

            foreach ($grants as $grant) {
                if ((string) $grant->day === $window['day']
                    && RoomAccessPolicy::minutes((string) $grant->start_time) < $end
                    && $start < RoomAccessPolicy::minutes((string) $grant->end_time)) {
                    $conflicts[] = "{$label} is already lent to {$grant->department_code}";
                }
            }
        }

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'windows' => "{$room->room_code} is not vacant: ".implode('; ', array_slice(array_unique($conflicts), 0, 5)).'.',
            ]);
        }
    }

    /**
     * @param  list<array{day: string, start_time: string, end_time: string}>  $windows
     * @return list<array{day: string, start_time: string, end_time: string}>
     */
    private function normalizeWindows(array $windows): array
    {
        $opening = RoomAccessPolicy::minutes(SchedulingPolicy::openingTime());
        $closing = RoomAccessPolicy::minutes(SchedulingPolicy::closingTime());

        $normalized = [];
        foreach ($windows as $index => $window) {
            $start = RoomAccessPolicy::minutes($window['start_time']);
            $end = RoomAccessPolicy::minutes($window['end_time']);

            if ($end <= $start) {
                throw ValidationException::withMessages([
                    "windows.{$index}.end_time" => 'Each window must end after it starts.',
                ]);
            }

            if ($start < $opening || $end > $closing) {
                throw ValidationException::withMessages([
                    "windows.{$index}.start_time" => sprintf(
                        'Windows must fall within operating hours (%s-%s).',
                        substr(SchedulingPolicy::openingTime(), 0, 5),
                        substr(SchedulingPolicy::closingTime(), 0, 5),
                    ),
                ]);
            }

            foreach ($normalized as $other) {
                if ($other['day'] === $window['day']
                    && RoomAccessPolicy::minutes($other['start_time']) < $end
                    && $start < RoomAccessPolicy::minutes($other['end_time'])) {
                    throw ValidationException::withMessages([
                        "windows.{$index}.start_time" => "Windows on {$window['day']} overlap each other.",
                    ]);
                }
            }

            $normalized[] = [
                'day' => (string) $window['day'],
                'start_time' => (string) $window['start_time'],
                'end_time' => (string) $window['end_time'],
            ];
        }

        return $normalized;
    }

    /** @return Collection<int, object{day: string, start_time: string, end_time: string, department_code: ?string, department_id: int}> */
    private function approvedWindows(int $roomId, int $semesterId, ?int $ignoreRequestId = null): Collection
    {
        return DB::table('room_request_windows')
            ->join('room_requests', 'room_requests.id', '=', 'room_request_windows.room_request_id')
            ->leftJoin('departments', 'departments.id', '=', 'room_requests.requesting_department_id')
            ->where('room_requests.room_id', $roomId)
            ->where('room_requests.semester_id', $semesterId)
            ->where('room_requests.status', RoomRequest::STATUS_APPROVED)
            ->when($ignoreRequestId !== null, fn ($query) => $query->where('room_requests.id', '!=', $ignoreRequestId))
            ->orderBy('room_request_windows.day')
            ->orderBy('room_request_windows.start_time')
            ->get([
                'room_request_windows.day',
                'room_request_windows.start_time',
                'room_request_windows.end_time',
                'departments.department_code',
                'room_requests.requesting_department_id as department_id',
            ]);
    }

    private function schedulingTransaction(callable $callback): mixed
    {
        return app(SchedulingScopeLock::class)->execute(
            Semester::pluck('id')->map('intval')->all(), fn () => DB::transaction($callback),
        );
    }

    /**
     * Move the borrower's classes to temporary Online on revocation or Room TBA on return.
     * Record displacement in recovery, timetable history, revision tracking, and the
     * scheduling audit. Any failure rolls the whole grant transaction back.
     *
     * @return Collection<int, Schedule>
     */
    private function endGrant(RoomRequest $model, User $user, string $status, ?string $remarks): Collection
    {
        $windows = $model->windows->map(fn ($window): array => RoomAccessPolicy::window(
            (string) $window->day,
            (string) $window->start_time,
            (string) $window->end_time,
        ))->all();

        $schedules = Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->where('room_id', $model->room_id)
            ->where('semester_id', $model->semester_id)
            ->where('department_id', $model->requesting_department_id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->lockForUpdate()
            ->get()
            ->filter(fn (Schedule $schedule): bool => RoomAccessPolicy::overlappingWindow(
                $windows,
                (string) $schedule->day,
                (string) $schedule->start_time,
                (string) $schedule->end_time,
            ) !== null)
            ->values();

        $revoked = $status === RoomRequest::STATUS_REVOKED;
        $endedBy = $revoked ? 'owner_revoked' : 'borrower_returned';
        $reason = $remarks ?? 'The borrowing department gave the room back.';
        $ids = $schedules->pluck('id')->map('intval')->values()->all();
        $metadata = [
            'room_request_id' => (int) $model->id,
            'room_id' => (int) $model->room_id,
            'previous_room_id' => (int) $model->room_id,
            'ended_by' => $endedBy,
            'reason' => $reason,
            'released_schedule_ids' => $ids,
        ];

        $before = $schedules->map(fn (Schedule $schedule): array => $schedule->getAttributes())->all();
        app(RoomRecoveryService::class)->recordImpact(
            (int) $model->room_id, $schedules, 'grant_ended',
            $revoked
                ? 'Borrowing access was revoked. Classes meet online until a replacement room is approved.'
                : 'Approved borrowing access ended. A replacement room is required.',
            (int) $user->id,
        );
        $displacedAt = now();
        foreach ($schedules as $schedule) {
            $schedule->room_id = null;
            if ($revoked) {
                $schedule->mode = 'online';
                $schedule->displaced_room_id = (int) $model->room_id;
                $schedule->displaced_at = $displacedAt;
            }
            $schedule->save();
        }

        $versionId = null;
        if ($ids !== []) {
            $after = Schedule::query()->whereIn('id', $ids)->orderBy('id')->get();
            $versionId = $this->history->record(
                'room_grant_ended', $before, $after, (int) $user->id,
                (int) $model->semester_id, (int) $model->requesting_department_id,
                'room_request', $reason, $metadata,
            )->id;
            $this->revisionChanges->recordScheduleChanges($before, $after, (int) $user->id, 'room_grant_ended');
        }

        SchedulingAuditLog::create([
            'user_id' => (int) $user->id,
            'semester_id' => (int) $model->semester_id,
            'department_id' => (int) $model->requesting_department_id,
            'history_version_id' => $versionId,
            'action' => 'room_grant_ended',
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        return $schedules;
    }

    /**
     * Queue notifications for an action that has already committed. The action's
     * response must not depend on delivery, so a failure to queue is reported, not thrown.
     *
     * @param  callable(): list<array<string, mixed>>  $build
     */
    private function deliverNotifications(callable $build): void
    {
        try {
            app(Dispatcher::class)->dispatch(new DeliverSystemNotifications($build()));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** @param Collection<int, Schedule> $released */
    private function releasedNote(RoomRequest $model, Collection $released): string
    {
        if ($released->isEmpty()) {
            return '';
        }

        return sprintf(
            '%d class meeting%s in %s moved to %s: %s.',
            $released->count(),
            $released->count() === 1 ? '' : 's',
            $model->room?->room_code ?? 'the room',
            $model->status === RoomRequest::STATUS_REVOKED ? 'Online until a replacement room is approved' : 'Room TBA',
            $released->take(5)->map(fn (Schedule $schedule): string => sprintf(
                '%s %s %s %s-%s',
                $schedule->course?->course_code,
                $schedule->section?->section_name,
                substr((string) $schedule->day, 0, 3),
                substr((string) $schedule->start_time, 0, 5),
                substr((string) $schedule->end_time, 0, 5),
            ))->implode('; ').($released->count() > 5 ? '; ...' : ''),
        );
    }

    /** @param list<string> $allowed */
    private function assertStatus(RoomRequest $model, array $allowed): void
    {
        if (! in_array($model->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'room_request' => "This request is already {$model->status}.",
            ]);
        }
    }

    private function resolveSemester(mixed $semesterId): Semester
    {
        if ($semesterId !== null && $semesterId !== '') {
            return Semester::query()->findOrFail((int) $semesterId);
        }

        $semester = Semester::query()->where('is_active', true)->first();
        if (! $semester) {
            abort(422, 'There is no active semester to request a room for.');
        }

        return $semester;
    }

    private function flushSchedulingCaches(): void
    {
        ApiCache::forgetGroups(['rooms.index', 'initial.data']);
    }

    /** @return array<string, mixed> */
    private function submittedNotification(RoomRequest $model, User $actor): array
    {
        $room = $model->room?->room_code ?? 'a room';
        $requester = $model->requestingDepartment?->department_code ?? 'A department';
        $windows = RoomAccessPolicy::describe($this->windowArrays($model));

        return $this->notification(
            $this->ownerSecretaries($model),
            'room_request_submitted',
            'Room request awaiting your review',
            "{$requester} requests {$room} ({$windows}) for {$this->semesterLabel($model)}. Purpose: {$model->purpose}",
            $actor,
            $model,
            (int) $model->owner_department_id,
        );
    }

    /** @return array<string, mixed> */
    private function reviewedNotification(RoomRequest $model, User $actor, string $outcome, string $releasedNote = ''): array
    {
        $room = $model->room?->room_code ?? 'the room';
        $windows = RoomAccessPolicy::describe($this->windowArrays($model));

        [$title, $message] = match ($outcome) {
            RoomRequest::STATUS_APPROVED => ['Room request approved', "You may now schedule classes in {$room} during {$windows}."],
            RoomRequest::STATUS_REJECTED => ['Room request rejected', "Your request for {$room} ({$windows}) was rejected."],
            default => ['Room grant revoked', trim("Your department can no longer schedule into {$room} ({$windows}). {$releasedNote}")],
        };

        $recipients = User::query()
            ->where('department_id', $model->requesting_department_id)
            ->whereIn('role', User::DEPARTMENT_OFFICE_ROLES)
            ->get();
        if ($model->requester?->isSecretaryOrDirector()) {
            $recipients->push($model->requester);
        }
        if ($model->requesting_program_id !== null) {
            $recipients = $recipients->merge(User::query()
                ->where('role', 'program_head')
                ->where('is_active', true)
                ->where('department_id', $model->requesting_department_id)
                ->where('program_id', $model->requesting_program_id)
                ->get());
        }

        $notification = $this->notification(
            $recipients,
            'room_request_'.$outcome,
            $title,
            $message,
            $actor,
            $model,
            (int) $model->requesting_department_id,
            $model->review_remarks,
        );
        if ($model->requesting_program_id !== null) {
            $notification['metadata']['program_ids'] = [(int) $model->requesting_program_id];
        }

        return $notification;
    }

    /** @return array<string, mixed> */
    private function cancelledNotification(RoomRequest $model, User $actor): array
    {
        return $this->notification(
            $this->ownerSecretaries($model),
            'room_request_cancelled',
            'Room request cancelled',
            sprintf(
                '%s cancelled its request for %s.',
                $model->requestingDepartment?->department_code ?? 'A department',
                $model->room?->room_code ?? 'a room',
            ),
            $actor,
            $model,
            (int) $model->owner_department_id,
        );
    }

    /** @return array<string, mixed> */
    private function vpaaNotification(RoomRequest $model, User $actor, string $type): array
    {
        $requester = $model->requestingDepartment?->department_code ?? 'A department';
        $owner = $model->ownerDepartment?->department_code ?? 'another department';
        $room = $model->room?->room_code ?? 'a room';
        $windows = RoomAccessPolicy::describe($this->windowArrays($model));

        [$title, $message] = match ($type) {
            'room_request_submitted' => ['Room requested', "{$requester} asked {$owner} to lend {$room} ({$windows})."],
            'room_request_rejected' => ['Room request rejected', "{$owner} declined to lend {$room} to {$requester} ({$windows})."],
            'room_request_cancelled' => ['Room request cancelled', "{$requester} withdrew its request for {$room} from {$owner}."],
            'room_request_borrowed' => ['Room borrowed', "{$requester} is borrowing {$room} from {$owner} ({$windows})."],
            default => ['Room returned', "{$requester} is no longer borrowing {$room} from {$owner} ({$windows})."],
        };

        return $this->notification(
            User::query()->where('role', 'vpaa')->get(),
            $type,
            $title,
            $message,
            $actor,
            $model,
            (int) $model->requesting_department_id,
        );
    }

    /** @return Collection<int, User> */
    private function ownerSecretaries(RoomRequest $model): Collection
    {
        return User::query()
            ->whereIn('role', User::DEPARTMENT_OFFICE_ROLES)
            ->where('department_id', $model->owner_department_id)
            ->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return array<string, mixed>
     */
    private function notification(
        Collection $recipients,
        string $type,
        string $title,
        string $message,
        User $actor,
        RoomRequest $model,
        int $departmentId,
        ?string $remarks = null,
    ): array {
        return [
            'user_ids' => $recipients->pluck('id')->map('intval')->unique()->values()->all(),
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'actor_id' => (int) $actor->id,
            'department_id' => $departmentId,
            'semester_id' => (int) $model->semester_id,
            'remarks' => $remarks,
            'metadata' => [...$this->notificationMetadata($model),
                'event_id' => (string) Str::uuid(),
                'audience_roles' => User::withDepartmentOffice($recipients->pluck('role')->unique()->values()->all()),
                'requires_action' => $type === 'room_request_submitted' && ! $recipients->contains('role', 'vpaa')],
        ];
    }

    private function semesterLabel(RoomRequest $model): string
    {
        $semester = $model->academicSemester;

        return $semester ? "{$semester->semester} semester AY {$semester->academic_year}" : 'the selected semester';
    }

    /** @return array{room_request_id: int, room_id: int, windows: list<array{day: string, start_time: string, end_time: string}>} */
    private function notificationMetadata(RoomRequest $model): array
    {
        return [
            'room_request_id' => (int) $model->id,
            'room_id' => (int) $model->room_id,
            'windows' => $this->windowArrays($model),
        ];
    }

    /** @return list<array{day: string, start_time: string, end_time: string}> */
    private function windowArrays(RoomRequest $model): array
    {
        return $model->windows->map(fn ($window): array => [
            'day' => (string) $window->day,
            'start_time' => (string) $window->start_time,
            'end_time' => (string) $window->end_time,
        ])->values()->all();
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return [
            RoomRequest::STATUS_PENDING,
            RoomRequest::STATUS_APPROVED,
            RoomRequest::STATUS_REJECTED,
            RoomRequest::STATUS_CANCELLED,
            RoomRequest::STATUS_REVOKED,
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['room', 'academicSemester', 'requestingDepartment', 'requestingProgram', 'ownerDepartment', 'requester', 'reviewer', 'windows'];
    }

    /** @return array<string, mixed> */
    private function present(RoomRequest $model): array
    {
        return [
            'id' => (int) $model->id,
            'status' => (string) $model->status,
            'purpose' => $model->purpose,
            'review_remarks' => $model->review_remarks,
            'room' => $model->room ? $this->presentRoom($model->room) : null,
            'academic_semester' => $model->academicSemester ? $this->presentSemester($model->academicSemester) : null,
            'requesting_department' => $this->presentDepartment($model->requestingDepartment),
            'requesting_program' => $model->requestingProgram === null ? null : [
                'id' => (int) $model->requestingProgram->id,
                'code' => (string) $model->requestingProgram->code,
                'name' => (string) $model->requestingProgram->name,
            ],
            'owner_department' => $this->presentDepartment($model->ownerDepartment),
            // The room was reassigned after this request was sent; only the requester can act (cancel and resubmit).
            'owner_changed' => $model->room !== null && (int) $model->room->department_id !== (int) $model->owner_department_id,
            'requester' => $model->actor_snapshots['requested_by'] ?? null,
            'reviewer' => $model->actor_snapshots['reviewed_by'] ?? null,
            'decisions' => $model->actor_snapshots['decisions'] ?? [],
            'reviewed_at' => $model->reviewed_at?->toIso8601String(),
            'created_at' => $model->created_at?->toIso8601String(),
            'windows' => $model->windows->map(fn ($window): array => [
                'day' => (string) $window->day,
                'start_time' => substr((string) $window->start_time, 0, 5),
                'end_time' => substr((string) $window->end_time, 0, 5),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRoom(Rooms $room): array
    {
        return [
            'id' => (int) $room->id,
            'room_code' => (string) $room->room_code,
            'building' => $room->building,
            'room_type' => (string) $room->room_type,
            'department_id' => $room->department_id === null ? null : (int) $room->department_id,
            'is_archived' => $room->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentSemester(Semester $semester): array
    {
        return [
            'id' => (int) $semester->id,
            'academic_year' => (string) $semester->academic_year,
            'semester' => (string) $semester->semester,
            'is_active' => (bool) $semester->is_active,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentDepartment($department): ?array
    {
        return $department ? [
            'id' => (int) $department->id,
            'code' => (string) $department->department_code,
            'name' => (string) $department->department_name,
        ] : null;
    }
}
