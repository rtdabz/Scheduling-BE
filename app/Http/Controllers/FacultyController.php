<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Terms;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /**
     * The allowances the Secretary alone maintains. The two units the roster
     * editor enters — the contract ceiling (`max_units`) and the overload
     * granted on top of it — are set with the rest of the roster record, since
     * the Add Instructor form asks for one of them by load type. Deload and pro
     * bono remain the Secretary's to grant.
     */
    private const SECRETARY_ONLY_LOAD_FIELDS = ['deload_units', 'probono_units'];

    /** Fallback ceiling when the roster editor submits no load. */
    private const DEFAULT_MAX_UNITS = 21;

    /**
     * The designation an instructor holds. Not a load field and not part of
     * their identity, so it is permitted alongside either set -- but only for a
     * caller holding `faculty.manage_designations`, and the deload it implies
     * is always read from the designation record rather than the request.
     */
    private const DESIGNATION_FIELD = 'designation_id';

    public function __construct(private readonly FacultyLoadService $facultyLoad) {}

    public function index(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);
        $termId = $this->activeTermId();

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        $faculty = Cache::remember(
            ApiCache::key('faculty.index', [
                'department_id' => $departmentId,
                'term_id' => $termId,
                'program_id' => $programId,
            ]),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->facultyLoad->get($departmentId, $termId, $programId),
        );

        return response()->json($faculty);
    }

    public function store(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'employment_type' => 'required|in:full-time,part-time',
            // The units come from the roster editor, so a part-time instructor
            // is not created carrying a full-time load. Which of the two the
            // form fills depends on the load type it was given. Deload and pro
            // bono are maintained by the Secretary.
            'max_units' => 'sometimes|integer|min:1',
            'overload_units' => 'nullable|integer|min:0',
            'deload_units' => 'nullable|integer|min:0',
            'probono_units' => 'nullable|integer|min:0',
            'department_id' => 'required|exists:departments,id',
            'program_id' => $this->programRule($departmentId ?? $request->input('department_id')),
            'status' => 'nullable|in:active,inactive',
            'profile_picture' => 'nullable|string',
            'designation_id' => 'nullable|exists:designations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only the validated keys are assigned. `user_id` and
        // `administrative_role` are fillable but belong to the user-account link,
        // so passing the raw request through let a caller forge an
        // administrative badge or claim another user's profile.
        $payload = $validator->validated();
        unset($payload['deload_units'], $payload['probono_units']);
        // `overload_units` is nullable in the request but NOT NULL in the
        // table, so an explicit null has to fall through to the default below
        // rather than be inserted.
        if (($payload['overload_units'] ?? null) === null) {
            unset($payload['overload_units']);
        }
        $payload += [
            'max_units' => self::DEFAULT_MAX_UNITS,
            'overload_units' => 0,
            'deload_units' => 0,
            'probono_units' => 0,
        ];
        if ($departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        // The deload a designation carries is copied onto the instructor rather
        // than joined at read time, because SchedulingPolicy::facultyBasicLoad()
        // -- and the snapshot the generator runs against -- read one column.
        $payload['deload_units'] = $this->deloadForDesignation(
            $payload['designation_id'] ?? null,
            $payload['deload_units'],
        );

        $faculty = Faculty::create($payload);
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

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
        $submitsDesignation = $request->has(self::DESIGNATION_FIELD);

        // Assigning a designation moves the instructor's deload, so it is gated
        // on the capability that owns the designation list rather than on the
        // roster-editing role. A VPAA holds it by default; anyone else has to
        // have been granted it.
        if ($submitsDesignation && ! ($request->user()?->hasCapability('faculty.manage_designations') ?? false)) {
            return response()->json([
                'message' => 'You are not permitted to change an instructor designation.',
                'errors' => ['designation_id' => ['Requires the Manage Designations capability.']],
            ], 403);
        }

        if (! $loadOnly) {
            $submittedLoadFields = array_intersect(array_keys($request->all()), self::SECRETARY_ONLY_LOAD_FIELDS);
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
            'designation_id' => 'sometimes|nullable|exists:designations,id',
        ];

        if ($loadOnly) {
            $rejected = array_diff(
                array_keys($request->all()),
                [...self::LOAD_FIELDS, self::DESIGNATION_FIELD],
            );
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

        // Designation wins over a hand-typed deload in the same request: the
        // designation record is the source of the figure, and the request is
        // never trusted for it. Clearing the designation releases the deload
        // back to zero, which is what "no longer a chairperson" means.
        if ($submitsDesignation) {
            $payload['deload_units'] = $this->deloadForDesignation(
                $payload['designation_id'] ?? null,
                0,
            );
        }

        $faculty->update($payload);
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json($this->present($faculty->refresh()));
    }

    public function destroy(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        if ($faculty->user_id !== null) {
            return response()->json([
                'message' => 'Archive the linked user account first before archiving this faculty profile.',
            ], 409);
        }

        // Soft deletion hides the relationship from normal reads while retaining
        // the foreign key so restoration reconnects the assignments.
        $released = $this->liveScheduleIds($faculty);

        DB::transaction(fn () => $faculty->delete());
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'Faculty archived successfully',
            'released_schedule_count' => count($released),
            'released_schedule_ids' => $released,
        ]);
    }

    /**
     * The deload units a designation carries, or $fallback when the instructor
     * holds none. Read from the designation row rather than the request so a
     * caller cannot grant themselves an arbitrary deload -- and so the number
     * always matches what the Designations screen shows.
     */
    private function deloadForDesignation(mixed $designationId, int $fallback): int
    {
        if ($designationId === null) {
            return $fallback;
        }

        $designation = Designation::find($designationId);

        return $designation === null ? $fallback : (int) $designation->deload_units;
    }

    private function present(Faculty $faculty): Faculty
    {
        return $this->facultyLoad
            ->decorate($faculty, $this->activeTermId())
            ->load(['department', 'program', 'availabilities', 'designation']);
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
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function guardDepartment(Request $request, Faculty $faculty): ?JsonResponse
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
     * The VPAA owns the roster -- it is the only account that may create or
     * archive an instructor -- so it is the only full roster editor. Every
     * other account that reaches a write route maintains the load allowances
     * alone and may not reach an instructor's identity, department or program.
     *
     * This used to name the secretary, which was equivalent only while the
     * route was gated on 'role:vpaa,secretary'. Now that the gate is the
     * assignment capability, naming the one privileged role is what keeps a
     * newly granted Program Head or Dean from silently becoming a roster
     * editor.
     */
    private function isLoadOnlyEditor(Request $request): bool
    {
        return ! ($request->user()?->isVpaa() ?? false);
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
