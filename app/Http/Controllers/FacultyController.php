<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Terms;
use App\Services\FacultyLoadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FacultyController extends Controller
{
    /**
     * The teaching load allowances. The VPAA owns the roster; the secretary owns
     * these four numbers, so a secretary update is narrowed to exactly this set
     * and may not reach an instructor's identity, department or program.
     */
    private const LOAD_FIELDS = ['max_units', 'deload_units', 'overload_units', 'probono_units'];

    public function __construct(private readonly FacultyLoadService $facultyLoad) {}

    public function index(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        return response()->json($this->facultyLoad->get($departmentId, $this->activeTermId(), $programId));
    }

    public function store(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'employment_type' => 'required|in:full-time,part-time',
            // Load allowances are maintained by the Secretary. VPAA creates the
            // roster record only; the database default is used until the
            // Secretary configures the instructor's load.
            'max_units' => 'sometimes|integer|min:1',
            'overload_units' => 'nullable|integer|min:0',
            'deload_units' => 'nullable|integer|min:0',
            'probono_units' => 'nullable|integer|min:0',
            'department_id' => 'required|exists:departments,id',
            'program_id' => $this->programRule($departmentId ?? $request->input('department_id')),
            'status' => 'nullable|in:active,inactive',
            'profile_picture' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only the validated keys are assigned. `user_id` and
        // `administrative_role` are fillable but belong to the user-account link,
        // so passing the raw request through let a caller forge an
        // administrative badge or claim another user's profile.
        $payload = $validator->validated();
        unset($payload['max_units'], $payload['overload_units'], $payload['deload_units'], $payload['probono_units']);
        $payload += [
            'max_units' => 21,
            'overload_units' => 0,
            'deload_units' => 0,
            'probono_units' => 0,
        ];
        if ($departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        $faculty = Faculty::create($payload);

        return response()->json($this->present($faculty), 201);
    }

    public function show(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        return response()->json($this->present($faculty));
    }

    public function update(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        $departmentId = $this->resolveDepartmentId($request);
        $loadOnly = $this->isLoadOnlyEditor($request);

        if (! $loadOnly) {
            $submittedLoadFields = array_intersect(array_keys($request->all()), self::LOAD_FIELDS);
            if ($submittedLoadFields !== []) {
                return response()->json([
                    'message' => 'Only the Secretary may update teaching load allowances.',
                    'errors' => ['role' => ['Not permitted to change '.implode(', ', $submittedLoadFields).'.']],
                ], 403);
            }
        }

        $rules = [
            'max_units' => 'sometimes|required|integer|min:1',
            'overload_units' => 'sometimes|nullable|integer|min:0',
            'deload_units' => 'sometimes|nullable|integer|min:0',
            'probono_units' => 'sometimes|nullable|integer|min:0',
        ];

        if ($loadOnly) {
            $rejected = array_diff(array_keys($request->all()), self::LOAD_FIELDS);
            if ($rejected !== []) {
                return response()->json([
                    'message' => 'Your role may only update the teaching load allowances: '
                        .implode(', ', self::LOAD_FIELDS).'.',
                    'errors' => ['role' => ['Not permitted to change '.implode(', ', $rejected).'.']],
                ], 403);
            }
        } else {
            $rules += [
                'first_name' => 'sometimes|required|string|max:255',
                'last_name' => 'sometimes|required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'employment_type' => 'sometimes|required|in:full-time,part-time',
                'department_id' => 'sometimes|required|exists:departments,id',
                'program_id' => $this->programRule(
                    $departmentId
                        ?? $request->input('department_id')
                        ?? $faculty->department_id
                ),
                'status' => 'sometimes|required|in:active,inactive',
                'profile_picture' => 'sometimes|nullable|string',
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        if (! $loadOnly && $departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        $faculty->update($payload);

        return response()->json($this->present($faculty->refresh()));
    }

    public function destroy(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        if ($faculty->user_id !== null) {
            return response()->json([
                'message' => 'Remove the linked user account first before deleting this faculty profile.',
            ], 409);
        }

        // `schedules.faculty_id` is nullOnDelete, so the approved rows survive the
        // delete without an instructor. Report how many were released so the
        // caller can tell the user what just happened instead of silently
        // leaving instructor-less classes behind.
        $released = $this->liveScheduleIds($faculty);

        DB::transaction(fn () => $faculty->delete());

        return response()->json([
            'message' => 'Faculty deleted successfully',
            'released_schedule_count' => count($released),
            'released_schedule_ids' => $released,
        ]);
    }

    private function present(Faculty $faculty): Faculty
    {
        return $this->facultyLoad
            ->decorate($faculty, $this->activeTermId())
            ->load(['department', 'program', 'availabilities']);
    }

    private function activeTermId(): ?int
    {
        $activeTerm = Terms::where('is_active', true)->first();

        return $activeTerm ? (int) $activeTerm->id : null;
    }

    /** @return array<int, int> */
    private function liveScheduleIds(Faculty $faculty): array
    {
        $termId = $this->activeTermId();
        if ($termId === null) {
            return [];
        }

        return DB::table('schedules')
            ->where('faculty_id', $faculty->id)
            ->where('term_id', $termId)
            ->whereIn('status', \App\Services\Scheduling\SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function guardDepartment(Request $request, Faculty $faculty): ?\Illuminate\Http\JsonResponse
    {
        $departmentId = $this->resolveDepartmentId($request);
        if ($departmentId !== null && (int) $faculty->department_id !== $departmentId) {
            return response()->json(['message' => 'Faculty member not found in your department.'], 404);
        }

        $user = $request->user();
        if ($user?->role === 'program_head' && (int) $faculty->program_id !== (int) ($user->program_id ?? 0)) {
            return response()->json(['message' => 'Faculty member not found in your program.'], 404);
        }

        return null;
    }

    /**
     * The secretary maintains the load allowances only. Anyone else who reaches a
     * write route is a full roster editor.
     */
    private function isLoadOnlyEditor(Request $request): bool
    {
        return $request->user()?->role === 'secretary';
    }

    /**
     * An instructor's program is what makes them eligible for a major subject
     * tied to that program, so it has to be a program of their own department —
     * a program from elsewhere would describe a major they cannot teach anyway.
     *
     * @return array<int, mixed>
     */
    private function programRule(mixed $departmentId): array
    {
        $rules = ['nullable', 'integer'];

        if ($departmentId === null || $departmentId === '') {
            return [...$rules, 'exists:programs,id'];
        }

        return [
            ...$rules,
            Rule::exists('programs', 'id')->where(
                fn ($query) => $query->where('department_id', (int) $departmentId),
            ),
        ];
    }

    private function resolveDepartmentId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        if ($user->isVpaa()) {
            $requestedDepartmentId = $request->query('department_id');

            return $requestedDepartmentId !== null && $requestedDepartmentId !== ''
                ? (int) $requestedDepartmentId
                : null;
        }

        return $user->department_id !== null ? (int) $user->department_id : null;
    }
}
