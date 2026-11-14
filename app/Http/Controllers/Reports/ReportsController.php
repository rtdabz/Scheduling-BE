<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\ScheduleSignatories;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    public function __construct(
        private readonly FacultyLoadService $facultyLoad,
        private readonly ScheduleSignatories $signatories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $departments = Departments::query()
            ->with(['programs' => fn ($query) => $query->orderBy('code')])
            ->when(! $user->isVpaa(), fn (Builder $query) => $query->whereKey((int) $user->department_id))
            ->orderBy('department_name')
            ->get();

        $programScope = $this->viewerProgramId($user);
        $semester = $this->requestedSemester($request);
        $completeSections = Sections::query()
            ->whereIn('id', $this->completeSectionIds($departments->pluck('id')->all(), $semester?->id))
            ->get(['id', 'department_id', 'program_id']);

        $loadedFaculty = Faculty::query()
            ->whereIn('department_id', $departments->pluck('id'))
            ->whereIn('id', $this->facultyIdsWithLoad($semester?->id))
            ->get(['id', 'department_id', 'program_id']);

        $programTeachers = $this->facultyLoad->programTeachers(
            $departments->flatMap(fn (Departments $department) => $department->programs->pluck('id'))->map('intval')->all(),
        );

        $payload = $departments->map(function (Departments $department) use ($completeSections, $loadedFaculty, $programScope, $programTeachers): array {
            $sections = $completeSections->where('department_id', $department->id);
            $faculty = $loadedFaculty->where('department_id', $department->id);

            $programs = $department->programs
                ->when($programScope !== null, fn (Collection $programs) => $programs->where('id', $programScope))
                ->map(fn ($program): array => [
                    'id' => (int) $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                    'complete_section_count' => $sections->where('program_id', $program->id)->count(),
                    'instructor_count' => $faculty->filter(fn ($member): bool => (int) $member->program_id === (int) $program->id
                        || ($member->program_id === null && in_array((int) $member->id, $programTeachers[(int) $program->id] ?? [], true)))->count(),
                ])
                ->values();

            return [
                'id' => (int) $department->id,
                'code' => $department->department_code,
                'name' => $department->department_name,
                'logo' => $department->logo,
                'can_print_department' => $programScope === null,
                'complete_section_count' => $sections->count(),
                'instructor_count' => $faculty->count(),
                'programs' => $programs,
            ];
        })->values();

        return response()->json([
            'semester' => $semester ? $this->semesterPayload($semester) : null,
            'terms' => $this->approvedTerms($departments->pluck('id')->all(), $programScope),
            'departments' => $payload,
        ]);
    }

    public function show(Request $request, int $department): JsonResponse
    {
        $user = $request->user();
        $programId = $request->filled('program_id') ? (int) $request->query('program_id') : null;

        if (! $user->isVpaa() && (int) $user->department_id !== $department) {
            abort(403, 'You can only print reports for your own department.');
        }

        $viewerProgramId = $this->viewerProgramId($user);
        if ($viewerProgramId !== null) {
            if ($programId !== null && $programId !== $viewerProgramId) {
                abort(403, 'You can only print reports for your own program.');
            }
            $programId = $viewerProgramId;
        }

        $departmentRecord = Departments::query()->findOrFail($department);
        if ($programId !== null && ! $departmentRecord->programs()->whereKey($programId)->exists()) {
            abort(404, 'That program does not belong to this department.');
        }

        // Without a term the report spans every term; a term keeps loads and documents to that period.
        $semester = $this->requestedSemester($request);
        $faculties = $this->facultyLoad->getForReport($department, $programId, $semester?->id);
        $sections = Sections::query()
            ->with(['department', 'program', 'academicSemester', 'curriculum'])
            ->whereIn('id', $this->completeSectionIds([$department], $semester?->id))
            ->when($programId !== null, fn (Builder $query) => $query->where('program_id', $programId))
            ->get();

        $schedules = Schedule::query()
            ->with([
                'academicSemester:id,academic_year,semester',
                'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
                'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
                'faculty:id,first_name,last_name,middle_name,suffix,honorific_prefix,credentials,department_id,program_id',
                'room:id,room_code,building,room_type,allow_lecture_usage,status,department_id',
                'department:id,department_name,department_code',
            ])
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->when($semester !== null, fn (Builder $query) => $query->where('semester_id', $semester->id))
            ->where(fn (Builder $scope) => $scope
                ->whereIn('section_id', $sections->pluck('id'))
                ->orWhereIn('faculty_id', $faculties->pluck('id')))
            ->get();
        // Each printed schedule signs with the officials recorded when it was approved, not today's.
        $scheduleSignatories = $this->signatories->attachToSchedules($schedules);

        $departments = Departments::query()
            ->whereIn('id', $schedules->pluck('department_id')->push($department)->unique()->values())
            ->get();

        $users = User::query()
            ->where('is_active', true)
            ->where(fn (Builder $scope) => $scope
                ->where('department_id', $department)
                ->orWhere('role', 'vpaa'))
            ->when($programId !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('role', '!=', 'program_head')
                ->orWhere('program_id', $programId)))
            ->get(['id', 'name', 'role', 'department_id', 'program_id']);

        SchedulingAuditLog::create([
            'user_id' => $user->id,
            'semester_id' => $semester?->id,
            'department_id' => $department,
            'action' => 'schedule_report_generated',
            'metadata' => [
                'department_code' => $departmentRecord->department_code,
                'program_id' => $programId,
                'semester_id' => $semester?->id,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'active_semester' => $semester ? $this->semesterPayload($semester) : null,
            'terms' => $this->approvedTerms([$department], $programId),
            'time_grid' => [
                'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
                'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
                'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
                'slot_count' => SchedulingPolicy::totalSlots(),
            ],
            'rooms' => $schedules->pluck('room')->filter()->unique('id')->values(),
            'room_summary' => $this->roomSummary($department, $programId),
            'courses' => $schedules->pluck('course')->filter()->unique('id')->values(),
            'faculties' => $faculties->filter(fn (Faculty $faculty): bool => (int) $faculty->assigned_units > 0)->values(),
            'sections' => $sections->values(),
            'schedules' => $schedules->values(),
            // Keyed by the submission each row names in `signatories_submission_id`.
            'schedule_signatories' => (object) $scheduleSignatories,
            'departments' => $departments,
            'users' => $users,
        ]);
    }

    public function logDownload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => 'required|string|in:schedule,load,room,conflict',
            'department_id' => 'nullable|integer|exists:departments,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'format' => 'nullable|string|max:10',
        ]);

        $user = $request->user();
        $action = match ($validated['report_type']) {
            'conflict' => 'conflict_report_generated',
            default => 'report_downloaded',
        };

        SchedulingAuditLog::create([
            'user_id' => $user?->id,
            'semester_id' => $validated['semester_id'] ?? $this->activeSemester()?->id,
            'department_id' => $validated['department_id'] ?? $user?->department_id,
            'action' => $action,
            'metadata' => [
                'report_type' => $validated['report_type'],
                'program_id' => $validated['program_id'] ?? null,
                'format' => $validated['format'] ?? 'pdf',
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Report download logged successfully.']);
    }

    private function activeSemester(): ?Semester
    {
        return Semester::query()->where('is_active', true)->first();
    }

    private function requestedSemester(Request $request): ?Semester
    {
        $validated = $request->validate(['semester_id' => 'nullable|integer|exists:semesters,id']);

        return isset($validated['semester_id']) ? Semester::query()->find((int) $validated['semester_id']) : null;
    }

    /** @return array{id: int, academic_year: string, semester: string, is_active: bool} */
    private function semesterPayload(Semester $semester): array
    {
        return [
            'id' => (int) $semester->id,
            'academic_year' => (string) $semester->academic_year,
            'semester' => (string) $semester->semester,
            'is_active' => (bool) $semester->is_active,
        ];
    }

    /**
     * Terms holding VPAA-approved classes in scope, newest first, so a report is printed for one term.
     *
     * @param  array<int, int>  $departmentIds
     * @return array<int, array{id: int, academic_year: string, semester: string, is_active: bool}>
     */
    private function approvedTerms(array $departmentIds, ?int $programId): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $semesterIds = DB::table('schedules')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->whereNull('schedules.deleted_at')
            ->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereIn('sections.department_id', $departmentIds)
            ->when($programId !== null, fn ($query) => $query->where('sections.program_id', $programId))
            ->distinct()
            ->pluck('schedules.semester_id');

        $order = array_flip(SchedulingPolicy::SEMESTERS);

        return Semester::query()
            ->whereIn('id', $semesterIds)
            ->get()
            ->sort(fn (Semester $a, Semester $b): int => [(string) $b->academic_year, $order[$b->semester] ?? -1]
                <=> [(string) $a->academic_year, $order[$a->semester] ?? -1])
            ->map(fn (Semester $semester): array => $this->semesterPayload($semester))
            ->values()
            ->all();
    }

    private function viewerProgramId(User $user): ?int
    {
        return $user->role === 'program_head' && $user->program_id !== null
            ? (int) $user->program_id
            : null;
    }

    /** @return array<string, mixed> */
    private function roomSummary(int $departmentId, ?int $programId): array
    {
        // Department-wide rooms remain visible alongside the selected program's rooms.
        $programScope = fn (Builder $query) => $query
            ->when($programId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope->whereNull('home_program_id')->orWhere('home_program_id', $programId),
            ));

        $rooms = Rooms::query()
            ->where('department_id', $departmentId)
            ->whereNotIn('room_type', Rooms::SHARED_ROOM_TYPES)
            ->tap($programScope)
            ->orderBy('room_code')
            ->get(['id', 'room_code', 'building', 'room_type', 'status', 'department_id']);

        $requests = RoomRequest::query()
            ->with([
                'room:id,room_code',
                'requestingDepartment:id,department_code,department_name',
                'ownerDepartment:id,department_code,department_name',
                'windows',
            ])
            ->where(fn (Builder $query) => $query
                ->where('requesting_department_id', $departmentId)
                ->orWhere('owner_department_id', $departmentId))
            ->whereHas('room', $programScope)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            'total_rooms' => $rooms->count(),
            'total_laboratories' => $rooms->where('room_type', 'laboratory')->count(),
            'unavailable_rooms' => $rooms->where('status', 'not available')->count(),
            'rooms' => $rooms->values(),
            'requests' => $requests->map(fn (RoomRequest $roomRequest): array => [
                'id' => (int) $roomRequest->id,
                'direction' => (int) $roomRequest->requesting_department_id === $departmentId ? 'sent' : 'received',
                'room_code' => $roomRequest->room?->room_code,
                'owner_department' => $roomRequest->ownerDepartment?->department_code
                    ?: $roomRequest->ownerDepartment?->department_name,
                'requester' => $roomRequest->requestingDepartment?->department_code
                    ?: $roomRequest->requestingDepartment?->department_name,
                'requested_by' => $roomRequest->actor_snapshots['requested_by']['name'] ?? 'Actor unavailable',
                'status' => $roomRequest->status,
                'windows' => $roomRequest->windows->map(fn ($window): array => [
                    'day' => (string) $window->day,
                    'start_time' => substr((string) $window->start_time, 0, 5),
                    'end_time' => substr((string) $window->end_time, 0, 5),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * @param  array<int, int>  $departmentIds
     * @return array<int, int>
     */
    private function completeSectionIds(array $departmentIds, ?int $semesterId = null): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $approved = SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES;
        $placeholders = implode(',', array_fill(0, count($approved), '?'));

        return Schedule::query()
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->whereIn('sections.department_id', $departmentIds)
            ->when($semesterId !== null, fn (Builder $query) => $query->where('sections.semester_id', $semesterId))
            ->groupBy('schedules.section_id')
            ->havingRaw("SUM(CASE WHEN schedules.status IN ($placeholders) THEN 0 ELSE 1 END) = 0", $approved)
            ->pluck('schedules.section_id')
            ->map('intval')
            ->all();
    }

    /** @return array<int, int> */
    private function facultyIdsWithLoad(?int $semesterId = null): array
    {
        return DB::table('schedules')
            ->whereNull('deleted_at')
            ->when($semesterId !== null, fn ($query) => $query->where('semester_id', $semesterId))
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereNotNull('faculty_id')
            ->distinct()
            ->pluck('faculty_id')
            ->map('intval')
            ->all();
    }
}
