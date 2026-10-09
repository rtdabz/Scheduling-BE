<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Semester;
use App\Services\FacultyDesignationService;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\TeachingHistoryArchive;
use App\Support\ApiCache;
use App\Support\ProfilePicture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FacultyController extends Controller
{
    private const LOAD_FIELDS = ['max_units', 'deload_units', 'overload_units'];

    private const SECRETARY_ONLY_LOAD_FIELDS = ['deload_units'];

    private const DEFAULT_MAX_UNITS = 21;

    private const DESIGNATION_FIELDS = ['designation_ids', 'designation_id'];

    private const NAME_SUFFIXES = Faculty::NAME_SUFFIXES;

    public function __construct(
        private readonly FacultyLoadService $facultyLoad,
        private readonly FacultyDesignationService $designations,
        private readonly TeachingHistoryArchive $archive,
    ) {}

    public function index(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);
        $semesterId = $this->activeSemesterId();

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        $faculty = Cache::remember(
            ApiCache::key('faculty.index', [
                'department_id' => $departmentId,
                'semester_id' => $semesterId,
                'program_id' => $programId,
            ]),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->facultyLoad->get($departmentId, $semesterId, $programId),
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
            'suffix' => ['nullable', Rule::in(self::NAME_SUFFIXES)],
            'employment_type' => 'required|in:full-time,part-time',
            'max_units' => 'sometimes|integer|min:0',
            'overload_units' => 'nullable|integer|min:0',
            'deload_units' => 'nullable|integer|min:0',
            'department_id' => 'required|exists:departments,id',
            'program_id' => $this->programRule($departmentId ?? $request->input('department_id')),
            'status' => 'nullable|in:active,inactive',
            'profile_picture' => ProfilePicture::rules(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (($duplicate = $this->duplicateNameResponse(
            $validator->validated(),
            (int) ($departmentId ?? $validator->validated()['department_id']),
        )) !== null) {
            return $duplicate;
        }

        $designationIds = $this->designations->idsFrom($request);
        $this->designations->validate(
            $designationIds,
            maxUnits: (int) ($validator->validated()['max_units'] ?? self::DEFAULT_MAX_UNITS),
        );

        $payload = $validator->validated();
        unset($payload['deload_units']);
        if (($payload['overload_units'] ?? null) === null) {
            unset($payload['overload_units']);
        }
        $payload += [
            'max_units' => self::DEFAULT_MAX_UNITS,
            'overload_units' => 0,
            'deload_units' => 0,
        ];
        if ($departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        $faculty = DB::transaction(function () use ($payload, $designationIds): Faculty {
            $faculty = Faculty::create($payload);
            if ($designationIds !== []) {
                $this->designations->sync($faculty, $designationIds);
            }

            return $faculty;
        });
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

    public function teachingHistory(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        $rows = DB::table('schedules')
            ->join('semesters', 'schedules.semester_id', '=', 'semesters.id')
            ->join('courses', 'schedules.course_id', '=', 'courses.id')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->where('schedules.faculty_id', $faculty->id)
            ->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereNull('schedules.deleted_at')
            ->whereNull('semesters.deleted_at')
            ->select([
                'semesters.id as semester_id',
                'semesters.academic_year',
                'semesters.semester',
                'semesters.is_active',
                'schedules.course_id',
                'schedules.section_id',
                'courses.course_code',
                'courses.course_name',
                'courses.units',
                'sections.section_name',
            ])
            ->distinct()
            ->get();

        $rows = $rows
            ->concat($this->archive->rows(
                [(int) $faculty->id],
                null,
                $rows->pluck('semester_id')->map('intval')->unique()->values()->all(),
            ))
            ->sort(static fn (object $left, object $right): int => [$right->academic_year, $right->semester, $left->course_code, $left->section_name]
                <=> [$left->academic_year, $left->semester, $right->course_code, $right->section_name])
            ->values();

        $semesters = $rows->groupBy('semester_id')->map(function ($semesterRows) {
            $first = $semesterRows->first();

            return [
                'semester_id' => (int) $first->semester_id,
                'academic_year' => $first->academic_year,
                'semester' => $first->semester,
                'is_active' => (bool) $first->is_active,
                'total_units' => (int) $semesterRows->sum('units'),
                'section_count' => $semesterRows->unique('section_id')->count(),
                'courses' => $semesterRows->groupBy('course_id')->map(fn ($courseRows) => [
                    'course_id' => (int) $courseRows->first()->course_id,
                    'course_code' => $courseRows->first()->course_code,
                    'course_name' => $courseRows->first()->course_name,
                    'units' => (int) $courseRows->first()->units,
                    'sections' => $courseRows->pluck('section_name')->values(),
                ])->values(),
            ];
        })->values();

        return response()->json([
            'faculty_id' => $faculty->id,
            'semesters' => $semesters,
        ]);
    }

    public function update(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        $departmentId = $this->resolveDepartmentId($request);
        $loadOnly = $this->isLoadOnlyEditor($request);
        $submitsDesignation = $this->designations->submitted($request);

        if ($submitsDesignation && ! ($request->user()?->hasCapability('faculty.manage_designations') ?? false)) {
            return response()->json([
                'message' => 'You are not permitted to change an instructor designation.',
                'errors' => ['designation_ids' => ['Requires the Manage Designations capability.']],
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
            'max_units' => 'sometimes|required|integer|min:0',
            'overload_units' => 'sometimes|nullable|integer|min:0',
            'deload_units' => 'sometimes|nullable|integer|min:0',
        ];

        if ($loadOnly) {
            $rejected = array_diff(
                array_keys($request->all()),
                [...self::LOAD_FIELDS, ...self::DESIGNATION_FIELDS],
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
                'suffix' => ['nullable', Rule::in(self::NAME_SUFFIXES)],
                'employment_type' => 'sometimes|required|in:full-time,part-time',
                'department_id' => 'sometimes|required|exists:departments,id',
                'program_id' => $this->programRule(
                    $departmentId
                        ?? $request->input('department_id')
                        ?? $faculty->department_id
                ),
                'status' => 'sometimes|required|in:active,inactive',
                'profile_picture' => ProfilePicture::rules(sometimes: true),
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

        $renames = array_intersect_key($payload, array_flip(['first_name', 'middle_name', 'last_name', 'suffix', 'department_id']));
        if ($renames !== [] && ($duplicate = $this->duplicateNameResponse(
            [...$faculty->only(['first_name', 'middle_name', 'last_name', 'suffix']), ...$payload],
            (int) ($payload['department_id'] ?? $faculty->department_id),
            (int) $faculty->id,
        )) !== null) {
            return $duplicate;
        }

        $designationIds = $submitsDesignation ? $this->designations->idsFrom($request) : [];
        if ($submitsDesignation) {
            $this->designations->validate($designationIds, $faculty, (int) ($payload['max_units'] ?? $faculty->max_units));
        }

        DB::transaction(function () use ($faculty, $payload, $submitsDesignation, $designationIds): void {
            $faculty->update($payload);
            if ($submitsDesignation) {
                $this->designations->sync($faculty, $designationIds);
            }
        });
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json($this->present($faculty->refresh()));
    }

    public function destroy(Request $request, Faculty $faculty)
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        if ($faculty->user()->exists()) {
            return response()->json([
                'message' => 'Archive the linked user account first before archiving this faculty profile.',
            ], 409);
        }

        $released = $this->liveScheduleIds($faculty);

        DB::transaction(fn () => $faculty->delete());
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'Instructor archived successfully',
            'released_schedule_count' => count($released),
            'released_schedule_ids' => $released,
        ]);
    }

    private function present(Faculty $faculty): Faculty
    {
        return $this->facultyLoad
            ->decorate($faculty, $this->activeSemesterId())
            ->load(['department', 'program', 'availabilities', 'designations.parent']);
    }

    private function activeSemesterId(): ?int
    {
        $activeSemester = Semester::where('is_active', true)->first();

        return $activeSemester ? (int) $activeSemester->id : null;
    }

    /** @return array<int, int> */
    private function liveScheduleIds(Faculty $faculty): array
    {
        $semesterId = $this->activeSemesterId();
        if ($semesterId === null) {
            return [];
        }

        return DB::table('schedules')
            ->where('faculty_id', $faculty->id)
            ->where('semester_id', $semesterId)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function guardDepartment(Request $request, Faculty $faculty): ?JsonResponse
    {
        $departmentId = $this->resolveDepartmentId($request);
        if ($departmentId !== null && (int) $faculty->department_id !== $departmentId) {
            return response()->json(['message' => 'Instructor not found in your department.'], 404);
        }

        $user = $request->user();
        if ($user?->role === 'program_head' && (int) $faculty->program_id !== (int) ($user->program_id ?? 0)) {
            return response()->json(['message' => 'Instructor not found in your program.'], 404);
        }

        return null;
    }

    private function isLoadOnlyEditor(Request $request): bool
    {
        return ! ($request->user()?->isVpaa() ?? false);
    }

    /**
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

    /**
     * @param  array<string, mixed>  $name
     */
    private function duplicateNameResponse(array $name, int $departmentId, ?int $ignoreId = null): ?JsonResponse
    {
        $normalize = static fn (mixed $value): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($value ?? ''))));
        $wanted = array_map($normalize, [
            $name['first_name'] ?? '', $name['middle_name'] ?? '', $name['last_name'] ?? '', $name['suffix'] ?? '',
        ]);

        $match = Faculty::withTrashed()
            ->where('department_id', $departmentId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$wanted[2]])
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$wanted[0]])
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'deleted_at'])
            ->first(fn (Faculty $faculty): bool => array_map($normalize, [
                $faculty->first_name, $faculty->middle_name, $faculty->last_name, $faculty->suffix,
            ]) === $wanted);

        if ($match === null) {
            return null;
        }

        $message = $match->trashed()
            ? 'An archived instructor already has this name in this department. Restore them from Archives instead of adding them again.'
            : 'An instructor with this name already exists in this department.';

        return response()->json(['message' => $message, 'errors' => ['first_name' => [$message]]], 422);
    }
}
