<?php

namespace App\Http\Controllers;

use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Terms;
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

/**
 * A department borrowing another department's vacant room for a term.
 *
 * The secretary asks for weekly windows in one room; the VPAA approves,
 * rejects or later revokes. Approval is what RoomAccessPolicy reads, so a
 * grant takes effect in the validator and the generator at the same moment.
 */
class RoomRequestController extends Controller
{
    private const REVIEW_CAPABILITY = 'room.review_requests';

    /** Only real, bookable rooms can be lent; ONLINE and FIELD are shared already. */
    private const LENDABLE_ROOM_TYPES = ['lecture', 'laboratory'];

    public function __construct(private readonly SystemNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'term_id' => 'nullable|integer|exists:terms,id',
            'status' => ['nullable', Rule::in($this->statuses())],
            'scope' => 'nullable|in:department,all',
        ]);

        // A reviewer sees every department's requests unless they ask for
        // their own; everyone else only ever sees their department's.
        $seesAll = $user->hasCapability(self::REVIEW_CAPABILITY) && ($validated['scope'] ?? 'all') === 'all';

        $requests = RoomRequest::query()
            ->with($this->relations())
            ->when(! $seesAll, fn ($query) => $query->where('requesting_department_id', (int) $user->department_id))
            ->when(isset($validated['term_id']), fn ($query) => $query->where('term_id', (int) $validated['term_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($requests->map(fn (RoomRequest $roomRequest): array => $this->present($roomRequest))->values());
    }

    /**
     * What already occupies a room in a term, so the request form can show
     * the vacant windows instead of letting the secretary guess.
     */
    public function occupancy(Request $request, int $room): JsonResponse
    {
        $roomModel = Rooms::query()->with('department')->findOrFail($room);
        $term = $this->resolveTerm($request->input('term_id'));

        $schedules = Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name', 'department:id,department_code'])
            ->where('room_id', $roomModel->id)
            ->where('term_id', $term->id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get(['id', 'course_id', 'section_id', 'department_id', 'day', 'start_time', 'end_time']);

        $grants = $this->approvedWindows($roomModel->id, $term->id);

        return response()->json([
            'room' => $this->presentRoom($roomModel),
            'term' => $this->presentTerm($term),
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
            'term_id' => 'nullable|integer|exists:terms,id',
            'purpose' => 'required|string|max:1000',
            'windows' => 'required|array|min:1|max:21',
            'windows.*.day' => ['required', 'string', Rule::in(SchedulingPolicy::PERSISTABLE_DAYS)],
            'windows.*.start_time' => 'required|date_format:H:i',
            'windows.*.end_time' => 'required|date_format:H:i',
        ]);

        $term = $this->resolveTerm($validated['term_id'] ?? null);
        $windows = $this->normalizeWindows($validated['windows']);
        $departmentId = (int) $user->department_id;

        $roomRequest = DB::transaction(function () use ($validated, $term, $windows, $departmentId, $user): RoomRequest {
            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->findOrFail((int) $validated['room_id']);
            $this->assertLendable($room, $departmentId);

            $duplicate = RoomRequest::query()
                ->where('room_id', $room->id)
                ->where('term_id', $term->id)
                ->where('requesting_department_id', $departmentId)
                ->where('status', RoomRequest::STATUS_PENDING)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'room_id' => "Your department already has a pending request for {$room->room_code} this term.",
                ]);
            }

            $this->assertVacant($room, $term->id, $windows);

            $roomRequest = RoomRequest::create([
                'room_id' => $room->id,
                'term_id' => $term->id,
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

        return response()->json([
            'message' => 'Room request submitted to the VPAA.',
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
            $this->assertStatus($model, [RoomRequest::STATUS_PENDING]);

            // Lock the room so two approvals for the same slot serialize.
            /** @var Rooms $room */
            $room = Rooms::query()->lockForUpdate()->findOrFail($model->room_id);
            $this->assertLendable($room, (int) $model->requesting_department_id);

            $this->assertVacant(
                $room,
                (int) $model->term_id,
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

        return response()->json([
            'message' => 'Room request approved.',
            'data' => $this->present($model),
        ]);
    }

    public function reject(Request $request, int $roomRequest): JsonResponse
    {
        $validated = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->close(
            RoomRequest::query()->findOrFail($roomRequest),
            $request->user(),
            RoomRequest::STATUS_REJECTED,
            trim((string) $validated['remarks']),
            [RoomRequest::STATUS_PENDING],
        );
    }

    public function revoke(Request $request, int $roomRequest): JsonResponse
    {
        $validated = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->close(
            RoomRequest::query()->findOrFail($roomRequest),
            $request->user(),
            RoomRequest::STATUS_REVOKED,
            trim((string) $validated['remarks']),
            [RoomRequest::STATUS_APPROVED],
        );
    }

    /**
     * Ends a request. Ending an approved grant is refused while the borrowing
     * department still has classes in the room: those classes would stop
     * validating the moment the grant disappeared.
     *
     * @param  list<string>  $allowedFrom
     */
    private function close(RoomRequest $model, User $user, string $status, ?string $remarks, array $allowedFrom): JsonResponse
    {
        $wasApproved = false;

        DB::transaction(function () use (&$model, $user, $status, $remarks, $allowedFrom, &$wasApproved): void {
            $model = RoomRequest::query()->lockForUpdate()->findOrFail($model->id);
            $this->assertStatus($model, $allowedFrom);
            $wasApproved = $model->status === RoomRequest::STATUS_APPROVED;

            if ($wasApproved) {
                $dependents = $this->dependentSchedules($model);
                if ($dependents->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'room_request' => sprintf(
                            '%s still holds %d class meeting%s in %s under this grant. Move them to another room first: %s.',
                            $model->requestingDepartment?->department_code ?? 'The department',
                            $dependents->count(),
                            $dependents->count() === 1 ? '' : 's',
                            $model->room?->room_code ?? 'the room',
                            $dependents->take(5)->map(fn (Schedule $schedule): string => sprintf(
                                '%s %s %s %s-%s',
                                $schedule->course?->course_code,
                                $schedule->section?->section_name,
                                substr((string) $schedule->day, 0, 3),
                                substr((string) $schedule->start_time, 0, 5),
                                substr((string) $schedule->end_time, 0, 5),
                            ))->implode('; '),
                        ),
                    ]);
                }
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

        if ($status === RoomRequest::STATUS_CANCELLED) {
            $this->notifyCancelled($model, $user);
        } else {
            $this->notifyReviewed($model, $user, $status);
        }

        return response()->json([
            'message' => match ($status) {
                RoomRequest::STATUS_REJECTED => 'Room request rejected.',
                RoomRequest::STATUS_REVOKED => 'Room grant revoked.',
                default => 'Room request cancelled.',
            },
            'data' => $this->present($model),
        ]);
    }

    private function assertLendable(Rooms $room, int $departmentId): void
    {
        if (! in_array((string) $room->room_type, self::LENDABLE_ROOM_TYPES, true)) {
            throw ValidationException::withMessages([
                'room_id' => 'Only lecture rooms and laboratories can be requested.',
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
     * Refuses windows that overlap a class already in the room or another
     * department's approved grant.
     *
     * @param  list<array{day: string, start_time: string, end_time: string}>  $windows
     */
    private function assertVacant(Rooms $room, int $termId, array $windows, ?int $ignoreRequestId = null): void
    {
        $days = array_values(array_unique(array_column($windows, 'day')));

        $schedules = Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->where('room_id', $room->id)
            ->where('term_id', $termId)
            ->whereIn('day', $days)
            ->get(['id', 'course_id', 'section_id', 'day', 'start_time', 'end_time']);

        $grants = $this->approvedWindows($room->id, $termId, $ignoreRequestId);

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
    private function approvedWindows(int $roomId, int $termId, ?int $ignoreRequestId = null): Collection
    {
        return DB::table('room_request_windows')
            ->join('room_requests', 'room_requests.id', '=', 'room_request_windows.room_request_id')
            ->leftJoin('departments', 'departments.id', '=', 'room_requests.requesting_department_id')
            ->where('room_requests.room_id', $roomId)
            ->where('room_requests.term_id', $termId)
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

    /** @return Collection<int, Schedule> */
    private function dependentSchedules(RoomRequest $model): Collection
    {
        return Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->where('room_id', $model->room_id)
            ->where('term_id', $model->term_id)
            ->where('department_id', $model->requesting_department_id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get(['id', 'course_id', 'section_id', 'day', 'start_time', 'end_time']);
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

    private function resolveTerm(mixed $termId): Terms
    {
        if ($termId !== null && $termId !== '') {
            return Terms::query()->findOrFail((int) $termId);
        }

        $term = Terms::query()->where('is_active', true)->first();
        if (! $term) {
            abort(422, 'There is no active term to request a room for.');
        }

        return $term;
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
        $metadata = ['room_request_id' => $model->id, 'room_id' => $model->room_id];

        $this->notifications->createForUsers(
            User::query()->where('role', 'vpaa')->get(),
            'room_request_submitted',
            'Room request awaiting review',
            "{$requester} requests {$room} ({$windows}). Purpose: {$model->purpose}",
            $actor,
            (int) $model->requesting_department_id,
            (int) $model->term_id,
            null,
            [...$metadata, 'link' => '/vpaa/room-requests'],
        );

        if ($model->owner_department_id !== null) {
            $this->notifications->notifyRoles(
                ['secretary', 'dean'],
                'room_request_submitted_owner',
                'Your room was requested',
                "{$requester} asked the VPAA to use {$room} ({$windows}).",
                $actor,
                (int) $model->owner_department_id,
                (int) $model->term_id,
                null,
                $metadata,
            );
        }
    }

    private function notifyReviewed(RoomRequest $model, User $actor, string $outcome): void
    {
        $room = $model->room?->room_code ?? 'the room';
        $windows = RoomAccessPolicy::describe($this->windowArrays($model));

        [$title, $message] = match ($outcome) {
            RoomRequest::STATUS_APPROVED => ['Room request approved', "You may now schedule classes in {$room} during {$windows}."],
            RoomRequest::STATUS_REJECTED => ['Room request rejected', "Your request for {$room} ({$windows}) was rejected."],
            default => ['Room grant revoked', "Your department can no longer schedule into {$room} ({$windows})."],
        };

        $recipients = User::query()
            ->where('department_id', $model->requesting_department_id)
            ->whereIn('role', ['secretary', 'program_head'])
            ->get();
        if ($model->requester) {
            $recipients->push($model->requester);
        }

        $this->notifications->createForUsers(
            $recipients,
            'room_request_'.$outcome,
            $title,
            $message,
            $actor,
            (int) $model->requesting_department_id,
            (int) $model->term_id,
            $model->review_remarks,
            ['room_request_id' => $model->id, 'room_id' => $model->room_id, 'link' => '/secretary/room-requests'],
        );
    }

    private function notifyCancelled(RoomRequest $model, User $actor): void
    {
        $this->notifications->createForUsers(
            User::query()->where('role', 'vpaa')->get(),
            'room_request_cancelled',
            'Room request cancelled',
            sprintf(
                '%s cancelled its request for %s.',
                $model->requestingDepartment?->department_code ?? 'A department',
                $model->room?->room_code ?? 'a room',
            ),
            $actor,
            (int) $model->requesting_department_id,
            (int) $model->term_id,
            null,
            ['room_request_id' => $model->id, 'room_id' => $model->room_id, 'link' => '/vpaa/room-requests'],
        );
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
        return ['room', 'term', 'requestingDepartment', 'ownerDepartment', 'requester', 'reviewer', 'windows'];
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
            'term' => $model->term ? $this->presentTerm($model->term) : null,
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
    private function presentTerm(Terms $term): array
    {
        return [
            'id' => (int) $term->id,
            'academic_year' => (string) $term->academic_year,
            'semester' => (string) $term->semester,
            'is_active' => (bool) $term->is_active,
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
