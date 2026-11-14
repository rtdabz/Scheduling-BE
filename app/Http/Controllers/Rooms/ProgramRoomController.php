<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Semester;
use App\Services\Scheduling\Support\ProgramRoomShares;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProgramRoomController extends Controller
{
    private const MULTIPLE_PROGRAMS_REQUIRED = 'Program Rooms is available when your department has more than one program. Room allocation is unnecessary for a single program.';

    public function show(Request $request): JsonResponse
    {
        $department = $this->resolveDepartment($request);
        // Program Heads also read this endpoint to filter their Facilities room list.
        if ($request->user()->isSecretaryOrDirector() || $request->user()->hasCapability('room.assign_program')) {
            $this->ensureAllocationNeeded($department);
        }

        return response()->json(['data' => $this->payload($department, $request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $department = $this->resolveDepartment($request);
        $this->ensureAllocationNeeded($department);

        $validated = $request->validate([
            'room_sharing_policy' => ['sometimes', 'required', 'string', Rule::in(Departments::ROOM_SHARING_POLICIES)],
            'assignments' => ['sometimes', 'array'],
            'assignments.*.room_id' => ['required', 'integer', 'distinct'],
            'assignments.*.program_id' => ['present', 'nullable', 'integer'],
        ]);

        $assignments = array_values($validated['assignments'] ?? []);
        $rooms = $this->divisibleRooms($department)
            ->whereIn('id', array_map(static fn (array $assignment): int => (int) $assignment['room_id'], $assignments))
            ->get()
            ->keyBy('id');
        $programIds = $this->programs($department)->pluck('id')->map('intval')->all();

        $errors = [];
        foreach ($assignments as $index => $assignment) {
            if (! $rooms->has((int) $assignment['room_id'])) {
                $errors["assignments.$index.room_id"] = 'Only lecture and laboratory rooms of your department can be given a home program.';
            }

            $programId = $assignment['program_id'];
            if ($programId !== null && ! in_array((int) $programId, $programIds, true)) {
                $errors["assignments.$index.program_id"] = 'Choose a program of your department.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($request, $department, $validated, $assignments, $rooms): void {
            $changedRooms = [];
            $affectedPrograms = [];
            $departmentWide = false;
            if (array_key_exists('room_sharing_policy', $validated)) {
                $department->room_sharing_policy = $validated['room_sharing_policy'];
                $departmentWide = $department->isDirty('room_sharing_policy');
                $department->save();
            }

            foreach ($assignments as $assignment) {
                /** @var Rooms $room */
                $room = $rooms->get((int) $assignment['room_id']);
                $previousProgramId = $room->home_program_id;
                $room->home_program_id = $assignment['program_id'] === null ? null : (int) $assignment['program_id'];
                if ($room->isDirty('home_program_id')) {
                    $changedRooms[] = (int) $room->id;
                    $affectedPrograms = [...$affectedPrograms, $previousProgramId, $room->home_program_id];
                    $departmentWide = $departmentWide || $previousProgramId === null || $room->home_program_id === null;
                }
                $room->save();
            }
            if ($departmentWide || $changedRooms !== []) {
                app(SystemNotificationService::class)->notifyRoles(
                    ['secretary', 'program_head'], 'program_rooms_updated', 'Program room assignments updated',
                    $request->user()->name.' updated your department\'s program room assignments or sharing policy.',
                    actor: $request->user(), departmentId: (int) $department->id,
                    metadata: ['room_ids' => $changedRooms, 'room_sharing_policy' => $department->room_sharing_policy,
                        'program_ids' => $departmentWide ? null : array_values(array_unique(array_filter($affectedPrograms)))],
                    programIds: $departmentWide ? null : array_values(array_unique(array_filter($affectedPrograms))),
                );
            }
        });

        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Program rooms updated successfully.',
            'data' => $this->payload($department->fresh(), $request),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Departments $department, Request $request): array
    {
        $programs = $this->programs($department)->get(['id', 'code', 'name']);
        $programIds = $programs->pluck('id')->map('intval')->all();
        $activeSemesterId = (int) Semester::query()->where('is_active', true)->value('id');
        $shares = app(ProgramRoomShares::class)->forDepartment((int) $department->id, $activeSemesterId);

        return [
            'department_id' => (int) $department->id,
            'room_sharing_policy' => (string) ($department->room_sharing_policy ?: Departments::ROOM_SHARING_OPEN),
            'can_manage' => count($programIds) > 1 && (bool) $request->user()?->hasCapability('room.assign_program'),
            'programs' => $programs->map(static fn (Program $program): array => [
                'id' => (int) $program->id,
                'code' => (string) $program->code,
                'name' => $program->name === null ? null : (string) $program->name,
            ])->values()->all(),
            'rooms' => $this->divisibleRooms($department)
                ->orderBy('room_code')
                ->get(['id', 'room_code', 'building', 'room_type', 'status', 'home_program_id'])
                ->map(static fn (Rooms $room): array => [
                    'days' => isset($shares[(int) $room->id])
                        ? array_map(static fn (array $share): array => [
                            'program_id' => $share['program_id'],
                            'borrowable' => $share['borrowable'],
                        ], $shares[(int) $room->id])
                        : null,
                    'id' => (int) $room->id,
                    'room_code' => (string) $room->room_code,
                    'building' => $room->building === null ? null : (string) $room->building,
                    'room_type' => (string) $room->room_type,
                    'status' => (string) $room->status,
                    'home_program_id' => in_array((int) $room->home_program_id, $programIds, true)
                        ? (int) $room->home_program_id
                        : null,
                ])->values()->all(),
        ];
    }

    private function divisibleRooms(Departments $department): Builder
    {
        return Rooms::query()
            ->where('department_id', $department->id)
            ->whereNotIn('room_type', Rooms::SHARED_ROOM_TYPES);
    }

    private function programs(Departments $department): Builder
    {
        return Program::query()
            ->where('department_id', $department->id)
            ->orderBy('code');
    }

    private function ensureAllocationNeeded(Departments $department): void
    {
        abort_if($this->programs($department)->count() <= 1, 403, self::MULTIPLE_PROGRAMS_REQUIRED);
    }

    private function resolveDepartment(Request $request): Departments
    {
        $user = $request->user();

        abort_if(! $user || $user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }
}
