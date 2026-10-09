<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomRequestController extends Controller
{
    private const LENDABLE_ROOM_TYPES = ['lecture'];

    public function __construct(private readonly SystemNotificationService $notifications) {}

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
            ->where(fn ($query) => $query
                ->where('requesting_department_id', $departmentId)
                ->orWhere('owner_department_id', $departmentId))
            ->when(isset($validated['semester_id']), fn ($query) => $query->where('semester_id', (int) $validated['semester_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($requests->map(fn (RoomRequest $roomRequest): array => $this->present($roomRequest))->values());
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
                'faculty:id,first_name,last_name,middle_name',
            ])
            ->where('room_id', $roomModel->id)
            ->where('semester_id', $semester->id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get(['id', 'semester_id', 'course_id', 'section_id', 'faculty_id', 'room_id', 'department_id', 'day', 'start_time', 'end_time', 'mode', 'status']);

        $grants = $this->approvedWindows($roomModel->id, $semester->id);

        return response()->json([
            'room' => $this->presentRoom($roomModel),
            'semester' => $this->presentSemester($semester),
            'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
            'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
            'occupied' => [
                ...$schedules->map(fn (Schedule $schedule): array => [
                    'kind' => 'class',
                    'day' => (string) $schedule->day,
                    'start_time' => substr((string) $schedule->start_time, 0, 5),
                    'end_time' => substr((string) $schedule->end_time, 0, 5),
                    'department_code' => $schedule->department?->department_code,
                    'label' => trim(($schedule->course?->course_code ?? '').' '.($schedule->section?->section_name ?? '')),
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
            'schedules' => $schedules->map(fn (Schedule $schedule): array => [
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
                'section' => $schedule->section?->only(['id', 'section_name']),
                'course' => $schedule->course?->only(['id', 'course_code', 'course_name', 'course_category', 'units', 'lecture_hours', 'lab_hours']),
                'faculty' => $schedule->faculty?->only(['id', 'first_name', 'last_name', 'middle_name']),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->department_id === null) {
            abort(422, 'Your account is not assigned to a department, so it cannot request rooms.');
        }

        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'purpose' => 'required|string|max:1000',
            'windows' => 'required|array|min:1|max:21',
            'windows.*.day' => ['required', 'string', Rule::in(SchedulingPolicy::PERSISTABLE_DAYS)],
            'windows.*.start_time' => 'required|date_format:H:i',
            'windows.*.end_time' => 'required|date_format:H:i',
        ]);

        $semester = $this->resolveSemester($validated['semester_id'] ?? null);
        $windows = $this->normalizeWindows($validated['windows']);
        $departmentId = (int) $user->department_id;

        $roomRequest = DB::transaction(function () use ($validated, $semester, $windows, $departmentId, $user): RoomRequest {
            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->findOrFail((int) $validated['room_id']);
            $this->assertLendable($room, $departmentId);

            $duplicate = RoomRequest::query()
                ->where('room_id', $room->id)
                ->where('semester_id', $semester->id)
                ->where('requesting_department_id', $departmentId)
                ->where('status', RoomRequest::STATUS_PENDING)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'room_id' => "Your department already has a pending request for {$room->room_code} this semester.",
                ]);
            }

            $this->assertVacant($room, $semester->id, $windows);

            $roomRequest = RoomRequest::create([
                'room_id' => $room->id,
                'semester_id' => $semester->id,
                'requesting_department_id' => $departmentId,
                'owner_department_id' => (int) $room->department_id,
                'status' => RoomRequest::STATUS_PENDING,
                'purpose' => trim((string) $validated['purpose']),
                'requested_by' => $user->id,
            ]);
            $roomRequest->windows()->createMany($windows);

            return $roomRequest;
        });

        $roomRequest->load($this->relations());
        $this->notifySubmitted($roomRequest, $user);
        $this->notifyVpaa($roomRequest, $user, 'room_request_submitted');

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

        return $this->close($model, $user, RoomRequest::STATUS_CANCELLED, null, [RoomRequest::STATUS_PENDING, RoomRequest::STATUS_APPROVED]);
    }

    public function approve(Request $request, int $roomRequest): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate(['remarks' => 'nullable|string|max:1000']);

        $model = DB::transaction(function () use ($roomRequest, $validated, $user): RoomRequest {
            /** @var RoomRequest $model */
            $model = RoomRequest::query()->with('windows')->lockForUpdate()->findOrFail($roomRequest);
            $this->assertOwnerReviewer($model, $user);
            $this->assertStatus($model, [RoomRequest::STATUS_PENDING]);

            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->findOrFail($model->room_id);
            $this->assertLendable($room, (int) $model->requesting_department_id);

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

            $model->update([
                'status' => RoomRequest::STATUS_APPROVED,
                'review_remarks' => isset($validated['remarks']) ? trim((string) $validated['remarks']) : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            return $model;
        });

        $model->load($this->relations());
        $this->flushSchedulingCaches();
        $this->notifyReviewed($model, $user, 'approved');
        $this->notifyVpaa($model, $user, 'room_request_borrowed');

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

        DB::transaction(function () use (&$model, $user, $status, $remarks, $allowedFrom, &$wasApproved, &$released): void {
            $model = RoomRequest::query()->lockForUpdate()->findOrFail($model->id);
            $this->assertStatus($model, $allowedFrom);
            $wasApproved = $model->status === RoomRequest::STATUS_APPROVED;

            if ($wasApproved) {
                $released = $this->releaseDependentSchedules($model);
            }

        $model->update([
                'status' => $status,
                'review_remarks' => $status === RoomRequest::STATUS_CANCELLED ? $model->review_remarks : $remarks,
                'reviewed_by' => $status === RoomRequest::STATUS_CANCELLED ? $model->reviewed_by : $user->id,
                'reviewed_at' => $status === RoomRequest::STATUS_CANCELLED ? $model->reviewed_at : now(),
            ]);
        });

        $model->load($this->relations());
        if ($wasApproved) {
            $this->flushSchedulingCaches();
        }

        $releasedNote = $this->releasedNote($model, $released);

        if ($status === RoomRequest::STATUS_CANCELLED) {
            $this->notifyCancelled($model, $user);
        } else {
            $this->notifyReviewed($model, $user, $status, $releasedNote);
        }

        $this->notifyVpaa($model, $user, $wasApproved ? 'room_request_returned' : 'room_request_'.$status);

        return response()->json([
            'message' => trim(match ($status) {
                RoomRequest::STATUS_REJECTED => 'Room request rejected.',
                RoomRequest::STATUS_REVOKED => 'Room grant revoked.',
                default => $wasApproved ? 'Room given back.' : 'Room request cancelled.',
            }.' '.$releasedNote),
            'released_schedule_count' => $released->count(),
            'data' => $this->present($model),
        ]);
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

    /** @return Collection<int, object{day: string, start_time: string, end_time: string, department_code: ?string}> */
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
            ]);
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function releaseDependentSchedules(RoomRequest $model): Collection
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
            ->get()
            ->filter(fn (Schedule $schedule): bool => RoomAccessPolicy::overlappingWindow(
                $windows,
                (string) $schedule->day,
                (string) $schedule->start_time,
                (string) $schedule->end_time,
            ) !== null)
            ->values();

        foreach ($schedules as $schedule) {
            $schedule->room_id = null;
            $schedule->save();
        }

        return $schedules;
    }

    /** @param Collection<int, Schedule> $released */
    private function releasedNote(RoomRequest $model, Collection $released): string
    {
        if ($released->isEmpty()) {
            return '';
        }

        return sprintf(
            '%d class meeting%s in %s moved to Room TBA: %s.',
            $released->count(),
            $released->count() === 1 ? '' : 's',
            $model->room?->room_code ?? 'the room',
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

    private function notifySubmitted(RoomRequest $model, User $actor): void
    {
        $room = $model->room?->room_code ?? 'a room';
        $requester = $model->requestingDepartment?->department_code ?? 'A department';
        $windows = RoomAccessPolicy::describe($this->windowArrays($model));

        $this->notifications->notifyRoles(
            ['secretary'],
            'room_request_submitted',
            'Room request awaiting your review',
            "{$requester} requests {$room} ({$windows}). Purpose: {$model->purpose}",
            $actor,
            (int) $model->owner_department_id,
            (int) $model->semester_id,
            null,
            $this->notificationMetadata($model),
        );
    }

    private function notifyReviewed(RoomRequest $model, User $actor, string $outcome, string $releasedNote = ''): void
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
            ->where('role', 'secretary')
            ->get();
        if ($model->requester?->role === 'secretary') {
            $recipients->push($model->requester);
        }

        $this->notifications->createForUsers(
            $recipients,
            'room_request_'.$outcome,
            $title,
            $message,
            $actor,
            (int) $model->requesting_department_id,
            (int) $model->semester_id,
            $model->review_remarks,
            $this->notificationMetadata($model),
        );
    }

    private function notifyCancelled(RoomRequest $model, User $actor): void
    {
        $this->notifications->notifyRoles(
            ['secretary'],
            'room_request_cancelled',
            'Room request cancelled',
            sprintf(
                '%s cancelled its request for %s.',
                $model->requestingDepartment?->department_code ?? 'A department',
                $model->room?->room_code ?? 'a room',
            ),
            $actor,
            (int) $model->owner_department_id,
            (int) $model->semester_id,
            null,
            $this->notificationMetadata($model),
        );
    }

    private function notifyVpaa(RoomRequest $model, User $actor, string $type): void
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

        $this->notifications->createForUsers(
            User::query()->where('role', 'vpaa')->get(),
            $type,
            $title,
            $message,
            $actor,
            (int) $model->requesting_department_id,
            (int) $model->semester_id,
            null,
            $this->notificationMetadata($model),
        );
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
        return ['room', 'academicSemester', 'requestingDepartment', 'ownerDepartment', 'requester', 'reviewer', 'windows'];
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
            'owner_department' => $this->presentDepartment($model->ownerDepartment),
            'requester' => $model->requester ? ['id' => (int) $model->requester->id, 'name' => (string) $model->requester->name] : null,
            'reviewer' => $model->reviewer ? ['id' => (int) $model->reviewer->id, 'name' => (string) $model->reviewer->name] : null,
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
