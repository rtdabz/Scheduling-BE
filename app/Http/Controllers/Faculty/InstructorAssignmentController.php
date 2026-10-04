<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\FacultyLoadService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;
use App\Services\Scheduling\Schedule\InstructorRecommender;
use App\Services\Scheduling\Schedule\ManualHybridFacultyAssignmentResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InstructorAssignmentController extends Controller
{
    use ConfirmsFacultyOverload;

    private const ASSIGNABLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES;

    private const VISIBLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ManualHybridFacultyAssignmentResolver $manualHybridAssignments,
        private readonly ScheduleHistoryRecorder $historyRecorder,
        private readonly InstructorRecommender $instructorRecommender,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        $cacheKey = ApiCache::key('instructor_assignments.index', [
            'department_id' => $departmentId,
            'program_id' => $programId,
            'version' => 4,
        ]);

        $data = Cache::remember($cacheKey, ApiCache::LOOKUP_TTL_SECONDS, function () use ($departmentId, $programId) {
            $activeSemester = Semester::query()->where('is_active', true)->first();

            if (! $activeSemester) {
                return [
                    'active_semester' => null,
                    'current_department_id' => $departmentId,
                    'departments' => [],
                    'subjects' => [],
                    'faculties' => [],
                    'schedules' => [],
                    'incoming_courses' => [],
                ];
            }

            $incomingCourses = Course::query()
                ->with(['department:id,department_code,department_name', 'teachingSourceProgram:id,code,major'])
                ->where('status', 'active')
                ->delegatedTo($departmentId, $programId)
                ->orderBy('course_code')
                ->get(['id', 'course_code', 'course_name', 'units', 'year_level', 'department_id', 'teaching_department_id', 'program_id', 'teaching_program_id', 'teaching_source_program_id']);

            $schedules = Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->where('semester_id', $activeSemester->id)
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->whereHas('course', fn ($query) => $query->where('status', 'active'))
                ->where(function ($query) use ($departmentId) {
                    $query->where('department_id', $departmentId)
                        ->orWhereHas(
                            'course',
                            fn ($course) => $course->where('teaching_department_id', $departmentId),
                        );
                })
                ->when($programId !== null, fn ($query) => $query->whereHas(
                    'course',
                    fn ($course) => $course
                        ->where('program_id', $programId)
                        ->orWhere('teaching_program_id', $programId),
                ))
                ->orderBy('department_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

            $faculties = Faculty::query()
                ->with(['department', 'program', 'availabilities'])
                ->where('department_id', $departmentId)
                ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            $this->facultyLoad->decorateMany($faculties, (int) $activeSemester->id);

            $courses = $schedules->pluck('course')->filter()->unique('id')->values();

            $departments = Departments::query()
                ->whereIn('id', $schedules->pluck('department_id')->filter()->unique())
                ->orderBy('id')
                ->get();
            $schedules->each(fn (Schedule $schedule) => $schedule->department?->makeHidden('logo'));
            $courses->each(fn (Course $course) => $course->department?->makeHidden('logo'));
            $faculties->each(fn (Faculty $faculty) => $faculty->department?->makeHidden('logo'));

            return [
                'active_semester' => $activeSemester,
                'current_department_id' => $departmentId,
                'departments' => $departments,
                'courses' => $courses,
                'subjects' => $courses,
                'faculties' => $faculties,
                'schedules' => $schedules,
                'incoming_courses' => $incomingCourses,
            ];
        });

        return response()->json($data);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'faculty_id' => 'present|nullable|integer|exists:faculties,id',
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        $teachingDepartmentId = $schedule->course
            ? (SchedulingPolicy::isMajorCourse($schedule->course)
                ? SchedulingPolicy::majorTeachingDepartmentId($schedule->course, (int) $schedule->department_id)
                : SchedulingPolicy::assignedTeachingDepartmentId($schedule->course) ?? (int) $schedule->department_id)
            : null;

        if (! $schedule->course) {
            return response()->json([
                'message' => 'Only the college that offers this course can assign its instructor.',
            ], 403);
        }

        $isMajor = SchedulingPolicy::isMajorCourse($schedule->course);

        if ($request->user()?->role === 'program_head') {
            $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);
            if ($requiredProgramId === null || $requiredProgramId !== (int) ($request->user()?->program_id ?? 0)) {
                return response()->json([
                    'message' => 'Program Heads can only assign courses assigned to their program.',
                ], 403);
            }
        }

        if ((int) $teachingDepartmentId !== $departmentId) {
            return response()->json([
                'message' => $isMajor
                    ? 'Only the department that offers this major can assign its instructor.'
                    : 'Only the college that offers this course can assign its instructor.',
            ], 403);
        }

        if (! in_array($schedule->status, self::ASSIGNABLE_STATUSES, true)) {
            return response()->json([
                'message' => $schedule->status === 'finalized'
                    ? 'A finalized schedule cannot be reassigned.'
                    : 'Instructor assignment is available only after VPAA approval.',
            ], 422);
        }

        $facultyId = $validated['faculty_id'] === null ? null : (int) $validated['faculty_id'];
        $faculty = $facultyId === null ? null : Faculty::query()->findOrFail($facultyId);
        if ($faculty !== null && ((int) $faculty->department_id !== $departmentId || $faculty->status !== 'active')) {
            return response()->json([
                'message' => 'The selected instructor must be active and belong to the college that teaches this course.',
            ], 422);
        }

        if (
            $request->user()?->role === 'program_head'
            && $faculty !== null
            && (int) $faculty->program_id !== (int) ($request->user()?->program_id ?? 0)
        ) {
            return response()->json([
                'message' => 'Program Heads can only assign instructors from their assigned program.',
            ], 422);
        }

        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);
        if ($faculty !== null && $requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
            $schedule->course->loadMissing(['program', 'teachingProgram']);
            $requiredProgram = $schedule->course->teachingProgram ?? $schedule->course->program;
            $programLabel = $requiredProgram?->code ?? $requiredProgram?->name;

            return response()->json([
                'message' => $programLabel !== null
                    ? (SchedulingPolicy::isMajorCourse($schedule->course)
                        ? "This major belongs to the {$programLabel} program, so only instructors of that program can be assigned."
                        : "This course is assigned to the {$programLabel} program, so only instructors of that program can be assigned.")
                    : 'This course is assigned to a program the selected instructor is not assigned to.',
            ], 422);
        }

        $linkedSchedules = $this->linkedMeetingBlocks($schedule);
        $linkedScheduleIds = $linkedSchedules->pluck('id')->all();
        $violations = [];

        foreach ($linkedSchedules as $linkedSchedule) {
            $attempt = array_merge($linkedSchedule->toArray(), [
                'faculty_id' => $facultyId,
                'ignore_schedule_id' => $linkedScheduleIds,
            ]);
            $violations = array_merge($violations, $this->ruleEngine->validateInstructorAssignment($attempt));
        }

        $overriddenIds = [];
        if ($violations !== []) {
            if (
                $facultyId === null
                || ! $request->boolean(FacultyConflictOverride::REQUEST_FLAG)
                || ! FacultyConflictOverride::onlyOverridable($violations)
            ) {
                return response()->json(
                    FacultyConflictOverride::refusal('The instructor assignment conflicts with an existing schedule.', $violations),
                    422,
                );
            }

            $overriddenIds = array_merge($linkedScheduleIds, FacultyConflictOverride::partnerIds($violations));
        }

        $activeSemesterId = $this->activeSemesterId();
        if ($faculty !== null) {
            $incoming = array_values(array_filter([$this->loadPairForSchedule($schedule)]));
            $projection = $this->withAssignmentLabel(
                $this->facultyLoad->projectLoad($faculty, $activeSemesterId, $incoming),
                $this->assignmentLabelForSchedule($schedule),
            );

            if (! $request->boolean('confirm_overload')) {
                $confirmation = $this->overloadConfirmationResponse([$projection]);

                if ($confirmation !== null) {
                    return $confirmation;
                }
            }
        }

        $previousFacultyId = $schedule->faculty_id;
        $updatedSchedules = DB::transaction(function () use (
            $request,
            $linkedSchedules,
            $linkedScheduleIds,
            $facultyId,
            $previousFacultyId,
            $departmentId,
            $overriddenIds,
        ) {
            $before = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->where(fn ($query) => $facultyId === null
                    ? $query->whereNotNull('faculty_id')
                    : $query->whereNull('faculty_id')->orWhere('faculty_id', '!=', $facultyId))
                ->update(['faculty_conflict_override' => false]);
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update(['faculty_id' => $facultyId]);
            FacultyConflictOverride::flag($overriddenIds);

            $after = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            $action = $facultyId === null ? 'instructor_assignment_released' : 'instructor_assigned';
            $version = $this->historyRecorder->record($action, $before, $after, $request->user()?->id, $linkedSchedules->first()->semester_id, $departmentId, 'instructor_assignment');
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $linkedSchedules->first()->semester_id,
                'section_id' => $linkedSchedules->first()->section_id,
                'department_id' => $departmentId,
                'action' => $action,
                'history_version_id' => $version->id,
                'metadata' => [
                    'schedule_id' => $linkedSchedules->first()->id,
                    'schedule_ids' => $linkedScheduleIds,
                    'course_id' => $linkedSchedules->first()->course_id,
                    'previous_faculty_id' => $previousFacultyId,
                    'faculty_id' => $facultyId,
                    'reason' => $facultyId === null ? 'manual_removal' : 'manual_assignment',
                    'offering_department_id' => $linkedSchedules->first()->department_id,
                ],
                'created_at' => now(),
            ]);

            return Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->whereIn('id', $linkedScheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
        });

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        if ($faculty !== null && $request->user()) {
            $this->notifications->notifyInstructorAssignmentProgress($updatedSchedules->first(), $request->user());
        }

        $load = $faculty === null
            ? null
            : $this->facultyLoad->projectLoad($faculty->refresh(), $activeSemesterId, []);

        return response()->json([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
            'warnings' => [],
            'load' => $load,
        ]);
    }

    public function recommendations(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:10',
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if (! $this->userCanManageInstructor($request, $schedule, $departmentId)) {
            return response()->json(['message' => 'You cannot assign an instructor to this class.'], 403);
        }

        $semesterId = $this->activeSemesterId();
        if (! in_array($schedule->status, self::ASSIGNABLE_STATUSES, true) || $semesterId === null) {
            return response()->json(['options' => []]);
        }

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : SchedulingPolicy::requiredTeachingProgramId($schedule->course);
        $faculties = Faculty::query()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($schedule->faculty_id !== null, fn ($query) => $query->whereKeyNot($schedule->faculty_id))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'options' => $this->instructorRecommender->recommend(
                $this->linkedMeetingBlocks($schedule),
                $faculties,
                $semesterId,
                (int) ($validated['limit'] ?? 3),
            ),
        ]);
    }

    public function clearSection(Request $request, Sections $section): JsonResponse
    {
        return $this->clearSectionInstructors($request, collect([$section]));
    }

    public function clearSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct', 'exists:sections,id'],
        ]);

        return $this->clearSectionInstructors(
            $request,
            Sections::query()->whereIn('id', $validated['section_ids'])->get(),
        );
    }

    /**
     * @param  Collection<int, Sections>  $sections
     */
    private function clearSectionInstructors(Request $request, Collection $sections): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $activeSemesterId = $this->activeSemesterId();
        if ($activeSemesterId === null || $sections->contains(
            static fn (Sections $section): bool => (int) $section->semester_id !== $activeSemesterId
        )) {
            return response()->json(['message' => 'Instructor assignments can only be cleared for the active semester.'], 422);
        }

        $sectionIds = $sections->pluck('id')->map('intval')->values()->all();
        $section = $sections->count() === 1 ? $sections->first() : null;

        $sectionSchedules = Schedule::query()
            ->with('course')
            ->where('semester_id', $activeSemesterId)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->where('faculty_assignment_done', false)
            ->whereNotNull('faculty_id')
            ->get();
        $targetSchedules = $sectionSchedules
            ->filter(fn (Schedule $schedule): bool => $this->userCanManageInstructor($request, $schedule, $departmentId))
            ->values();

        if ($targetSchedules->isEmpty()) {
            return response()->json([
                'message' => $section !== null
                    ? 'This section has no instructor assignments that your account can clear.'
                    : 'These sections have no instructor assignments that your account can clear.',
            ], 422);
        }

        $scheduleIds = $targetSchedules->pluck('id')->map('intval')->values()->all();
        $facultyIds = $targetSchedules->pluck('faculty_id')->filter()->map('intval')->unique()->values();
        $previousFacultyIds = $targetSchedules->mapWithKeys(
            static fn (Schedule $schedule): array => [(string) $schedule->id => (int) $schedule->faculty_id]
        )->all();

        $coursesCleared = $targetSchedules
            ->map(static fn (Schedule $schedule): string => $schedule->section_id.'-'.$schedule->course_id)
            ->unique()
            ->count();

        $updatedSchedules = DB::transaction(function () use (
            $request,
            $targetSchedules,
            $coursesCleared,
            $scheduleIds,
            $previousFacultyIds,
            $facultyIds,
            $departmentId,
            $activeSemesterId,
            $section,
            $sectionIds,
        ) {
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update(['faculty_id' => null, 'faculty_conflict_override' => false]);
            $after = Schedule::query()->whereIn('id', $scheduleIds)->get();
            $version = $this->historyRecorder->record(
                'instructor_assignment_released',
                $before,
                $after,
                $request->user()?->id,
                $activeSemesterId,
                $departmentId,
                'instructor_assignment',
            );
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $activeSemesterId,
                'section_id' => $section?->id,
                'department_id' => $departmentId,
                'action' => 'instructor_assignment_released',
                'history_version_id' => $version->id,
                'metadata' => [
                    'reason' => $section !== null ? 'section_clear' : 'sections_clear',
                    'section_ids' => $sectionIds,
                    'schedule_ids' => $scheduleIds,
                    'course_ids' => $targetSchedules->pluck('course_id')->map('intval')->unique()->values()->all(),
                    'previous_faculty_ids' => $previousFacultyIds,
                    'faculty_ids' => $facultyIds->all(),
                    'schedules_updated' => count($scheduleIds),
                    'courses_cleared' => $coursesCleared,
                    'offering_department_ids' => $targetSchedules->pluck('department_id')->map('intval')->unique()->values()->all(),
                ],
                'created_at' => now(),
            ]);

            return Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->whereIn('id', $scheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
        });

        $affectedFaculties = Faculty::query()
            ->with(['department', 'program', 'availabilities'])
            ->whereIn('id', $facultyIds->all())
            ->get();
        $this->facultyLoad->decorateMany($affectedFaculties, $activeSemesterId);
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => $section !== null
                ? 'All eligible instructor assignments for the section were cleared.'
                : 'All eligible instructor assignments for the selected sections were cleared.',
            'section_id' => $section?->id,
            'section_ids' => $sectionIds,
            'schedules_updated' => $updatedSchedules->count(),
            'courses_cleared' => $coursesCleared,
            'schedules' => $updatedSchedules,
            'faculties' => $affectedFaculties,
        ]);
    }

    private function linkedMeetingBlocks(Schedule $schedule)
    {
        $hybridComponents = $this->manualHybridAssignments->resolve($schedule)
            ->filter(fn (Schedule $component): bool => in_array($component->status, self::ASSIGNABLE_STATUSES, true))
            ->values();

        if ($hybridComponents->count() > 1) {
            return $hybridComponents;
        }

        return Schedule::query()
            ->where('semester_id', $schedule->semester_id)
            ->where('section_id', $schedule->section_id)
            ->where('course_id', $schedule->course_id)
            ->where('department_id', $schedule->department_id)
            ->where('preferred_pattern', $schedule->preferred_pattern)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->get();
    }

    private function userCanManageInstructor(Request $request, Schedule $schedule, int $departmentId): bool
    {
        if ($schedule->course === null) {
            return false;
        }

        $teachingDepartmentId = SchedulingPolicy::isMajorCourse($schedule->course)
            ? SchedulingPolicy::majorTeachingDepartmentId($schedule->course, (int) $schedule->department_id)
            : SchedulingPolicy::assignedTeachingDepartmentId($schedule->course) ?? (int) $schedule->department_id;
        if ((int) $teachingDepartmentId !== $departmentId) {
            return false;
        }

        if ($request->user()?->role !== 'program_head') {
            return true;
        }

        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);

        return $requiredProgramId !== null
            && $requiredProgramId === (int) ($request->user()?->program_id ?? 0);
    }
}
