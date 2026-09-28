<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Semester;
use App\Services\Scheduling\Support\ProgramRoomShares;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * How a department's programs share its rooms. Each lecture or laboratory room
 * the department owns may name a home program; a room with none is shared by
 * every program. The department's room_sharing_policy says what a home program
 * means (see 2026_09_25_000001_add_program_room_sharing). Field and online
 * rooms hold any number of classes, so there is nothing to divide there.
 *
 * Anyone in the department may read the arrangement; only `room.assign_program`
 * (the secretary) changes it.
 */
class ProgramRoomController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->resolveDepartment($request), $request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $department = $this->resolveDepartment($request);

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

        DB::transaction(function () use ($department, $validated, $assignments, $rooms): void {
            if (array_key_exists('room_sharing_policy', $validated)) {
                $department->room_sharing_policy = $validated['room_sharing_policy'];
                $department->save();
            }

            foreach ($assignments as $assignment) {
                /** @var Rooms $room */
                $room = $rooms->get((int) $assignment['room_id']);
                $room->home_program_id = $assignment['program_id'] === null ? null : (int) $assignment['program_id'];
                $room->save();
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
        // The division the generator and the validator actually use, for the
        // active semester (which decides whether an owner's days are lendable).
        $activeSemesterId = (int) Semester::query()->where('is_active', true)->value('id');
        $shares = app(ProgramRoomShares::class)->forDepartment((int) $department->id, $activeSemesterId);

        return [
            'department_id' => (int) $department->id,
            'room_sharing_policy' => (string) ($department->room_sharing_policy ?: Departments::ROOM_SHARING_OPEN),
            'can_manage' => (bool) $request->user()?->hasCapability('room.assign_program'),
            'programs' => $programs->map(static fn (Program $program): array => [
                'id' => (int) $program->id,
                'code' => (string) $program->code,
                'name' => $program->name === null ? null : (string) $program->name,
            ])->values()->all(),
            'rooms' => $this->divisibleRooms($department)
                ->orderBy('room_code')
                ->get(['id', 'room_code', 'building', 'room_type', 'status', 'home_program_id'])
                ->map(static fn (Rooms $room): array => [
                    // Owning program per weekday; null when the rooms are not divided.
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
                    // A program archived since it was chosen leaves the room shared.
                    'home_program_id' => in_array((int) $room->home_program_id, $programIds, true)
                        ? (int) $room->home_program_id
                        : null,
                ])->values()->all(),
        ];
    }

    /** Lecture and laboratory rooms the department owns: one class at a time each. */
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

    private function resolveDepartment(Request $request): Departments
    {
        $user = $request->user();

        abort_if(! $user || $user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }
}
