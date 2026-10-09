<?php

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Concerns\EnforcesFacultyUnitCeiling;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\FacultyLoadService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\Schedule\BatchConflict;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use App\Services\Scheduling\Schedule\ManualHybridFacultyAssignmentResolver;
use App\Services\Scheduling\Schedule\SameTimePartnerMover;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Schedule\ScheduleConflictCase;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Services\TimeslotService;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    use EnforcesFacultyUnitCeiling;

    private const REPLACEABLE_BATCH_STATUSES = ['draft', 'completed', 'revision'];

    private const PLOTTING_EDITABLE_STATUSES = ['draft', 'completed', 'revision'];

    private const DELETABLE_STATUSES = Schedule::UNLOCKED_STATUSES;

    private const LOCKED_DELETE_MESSAGE = 'This class is locked at its current approval stage and cannot be deleted. Recall it first, then refresh and try again.';

    protected RuleEngine $ruleEngine;

    public function __construct(
        RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly TimeslotService $timeslotService,
        private readonly BatchConflictValidator $batchConflicts,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly ManualHybridFacultyAssignmentResolver $manualHybridAssignments,
        private readonly ScheduleHistoryRecorder $historyRecorder,
        private readonly SchedulingScopeLock $scheduleWriteLock,
        private readonly SameTimePartnerMover $sameTimePartners,
        private readonly ScheduleConflictScanner $conflictScanner,
        private readonly RevisionChangeRecorder $revisionChanges,
        private readonly RecommendationEngine $recommendationEngine,
    ) {
        $this->ruleEngine = $ruleEngine;
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 500), 1), 1000);
        $query = Schedule::with([
            'academicSemester', 'section', 'course', 'faculty', 'room', 'program',
            'department:id,department_name,department_code',
        ]);

        if ($request->has('semester_id') && $request->semester_id) {
            $semesterId = $request->semester_id === 'active'
                ? Semester::where('is_active', true)->value('id')
                : $request->semester_id;
            $query->where('semester_id', $semesterId);
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', (int) $request->query('room_id'));
        }
        if ($request->filled('faculty_id')) {
            $query->where('faculty_id', (int) $request->query('faculty_id'));
        }

        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) {
            return response()->json(['message' => 'You can only view schedules for your department.'], 403);
        }
        $ownInstructorWeek = $request->filled('faculty_id') && ! $request->filled('department_id')
            && $this->authorization->facultyBelongsToDepartment($request, (int) $request->query('faculty_id'));
        if (! $ownInstructorWeek) {
            if (($scope = $this->authorization->requestedDepartment($request, $request->query('department_id'))) !== null) {
                $query->where('department_id', $scope);
            }
            $this->authorization->scopeSchedulesToProgram($query, $request);
        }
        if (($statuses = $this->authorization->visibleScheduleStatuses($request)) !== null) {
            $query->whereIn('status', $statuses);
        }

        $schedules = $query->latest()->limit($perPage)->get();

        return response()->json($schedules);
    }

    public function pendingDepartmentCount(Request $request): JsonResponse
    {
        $targetStatus = $request->user()?->role === 'vpaa'
            ? 'approved_by_dean'
            : 'submitted';

        $count = Schedule::query()
            ->where('status', $targetStatus)
            ->when(
                ($scope = $this->authorization->departmentScope($request)) !== null,
                fn ($query) => $query->where('department_id', $scope),
            )
            ->distinct()
            ->count('department_id');

        return response()->json(['count' => $count]);
    }

    public function store(Request $request)
    {
        if (! $request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'semester_id' => 'required|exists:semesters,id',
            'section_id' => 'required|exists:sections,id',
            'course_id' => 'required|exists:courses,id',
            'faculty_id' => 'nullable|exists:faculties,id',
            'room_id' => 'nullable|exists:rooms,id',
            'department_id' => 'required|exists:departments,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'day' => SchedulingPolicy::allowedDaysRule('required'),
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidRowPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id' => 'nullable|string|max:36',
            'meeting_type' => 'nullable|in:lecture,laboratory',
            'meeting_index' => 'nullable|integer|min:1',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

        $section = Sections::with('program')->findOrFail($validated['section_id']);
        $validated['program_id'] = $validated['program_id'] ?? $section->program_id;
        if ($validated['program_id'] === null) {
            return response()->json(['message' => 'This section cannot be scheduled until it is assigned to a Program.'], 422);
        }
        if ((int) $section->department_id !== (int) $validated['department_id'] || (int) $section->program_id !== (int) $validated['program_id']) {
            return response()->json(['message' => 'The schedule Department, Program, and Section must match.'], 422);
        }
        $validated['curriculum_id'] = $section->curriculum_id;

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }
        if (! $this->authorization->departmentHasProgram((int) $validated['department_id'])) {
            return response()->json(['message' => 'Create at least one Program under this Department before scheduling.'], 422);
        }
        if (! $this->authorization->programIsWritable($request, (int) $validated['program_id'])) {
            return $this->programForbidden();
        }

        $duplicateMessage = $this->delegatedCourseScheduleMessage($validated);
        if ($duplicateMessage !== null) {
            return response()->json(['message' => $duplicateMessage], 422);
        }

        $violations = $this->ruleEngine->validate($validated);

        if (! empty($violations)) {
            return response()->json([
                'message' => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $schedule = Schedule::create($validated);
        $schedule->load(['academicSemester', 'section', 'course', 'faculty', 'room', 'department', 'program']);
        $this->notifyScheduleSaved($request, $schedule, 'created');
        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $schedule->semester_id,
            'section_id' => $schedule->section_id,
            'department_id' => $schedule->department_id,
            'action' => 'schedule_created',
            'metadata' => [
                'schedule_id' => (int) $schedule->id,
                'course_id' => $schedule->course_id,
                'day' => $schedule->day,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ],
            'created_at' => now(),
        ]);
        ApiCache::forgetGroups(['faculty.index', 'initial.data']);

        return response()->json($schedule, 201);
    }

    /**
     * @var array<int, string|null>|null
     */
    private ?array $roomTypes = null;

    /** @var array<string, string|null>|null */
    private ?array $delegatedCourseMessages = null;

    public function batch(Request $request): JsonResponse
    {
        $this->roomTypes = [];
        $this->delegatedCourseMessages = [];

        try {
            return $this->runBatch($request);
        } finally {
            $this->roomTypes = null;
            $this->delegatedCourseMessages = null;
        }
    }

    private function runBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => 'required_without:delete_ids|array',
            'operations.*.id' => 'nullable|integer',
            'operations.*.semester_id' => 'sometimes|integer',
            'operations.*.section_id' => 'sometimes|integer',
            'operations.*.course_id' => 'sometimes|integer',
            'operations.*.subject_id' => 'sometimes|integer',
            'operations.*.faculty_id' => 'nullable|integer',
            'operations.*.room_id' => 'nullable|integer',
            'operations.*.department_id' => 'sometimes|integer',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'operations.*.start_time' => 'sometimes|date_format:H:i',
            'operations.*.end_time' => 'sometimes|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidRowPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'operations.*.split_group_id' => 'nullable|string|max:36',
            'operations.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index' => 'nullable|integer|min:1',
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer',
            'replace_section_ids' => 'sometimes|array',
            'replace_section_ids.*' => 'integer',
            'replace_semester_id' => 'nullable|integer',
        ]);
        $this->assertBatchReferencesExist($validated);

        $deleteIds = $validated['delete_ids'] ?? [];
        $replaceSectionIds = array_values(array_unique(array_map('intval', $validated['replace_section_ids'] ?? [])));
        $replaceSemesterId = isset($validated['replace_semester_id']) ? (int) $validated['replace_semester_id'] : null;
        $validated['operations'] = $validated['operations'] ?? [];
        $validated['operations'] = array_map(static function (array $operation): array {
            if (! isset($operation['course_id']) && isset($operation['subject_id'])) {
                $operation['course_id'] = $operation['subject_id'];
            }

            return $operation;
        }, $validated['operations']);
        $providedOperationFields = collect($validated['operations'])
            ->filter(static fn (array $operation): bool => isset($operation['id']))
            ->mapWithKeys(static fn (array $operation): array => [
                (int) $operation['id'] => array_keys($operation),
            ])
            ->all();
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );

        if ($replaceSectionIds !== []) {
            $operationSemesterIds = collect($validated['operations'])
                ->pluck('semester_id')
                ->filter()
                ->map('intval')
                ->unique()
                ->values()
                ->all();

            if ($replaceSemesterId === null && count($operationSemesterIds) === 1) {
                $replaceSemesterId = (int) $operationSemesterIds[0];
            }

            if ($replaceSemesterId === null) {
                return response()->json([
                    'message' => 'Replacement semester is required when replacing section schedules.',
                ], 422);
            }

            if (! $this->authorization->sectionIdsBelongToDepartment($request, $replaceSectionIds)) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }

            $generatedCourseIdsBySection = collect($validated['operations'])
                ->filter(static fn (array $operation): bool => ! isset($operation['id']) && isset($operation['section_id'], $operation['course_id']))
                ->groupBy(static fn (array $operation): int => (int) $operation['section_id'])
                ->map(static fn ($operations): array => $operations->pluck('course_id')->map('intval')->unique()->values()->all());

            $replaceScheduleIds = Schedule::query()
                ->where('semester_id', $replaceSemesterId)
                ->whereIn('section_id', $replaceSectionIds)
                ->whereIn('status', self::REPLACEABLE_BATCH_STATUSES)
                ->get(['id', 'section_id', 'course_id'])
                ->filter(static function (Schedule $schedule) use ($generatedCourseIdsBySection): bool {
                    $courseIds = $generatedCourseIdsBySection->get((int) $schedule->section_id);

                    return $courseIds === null || in_array((int) $schedule->course_id, $courseIds, true);
                })
                ->pluck('id')
                ->map('intval')
                ->values()
                ->all();

            $deleteIds = array_values(array_unique(array_merge(array_map('intval', $deleteIds), $replaceScheduleIds)));
        }

        $missingCreateFields = [];
        foreach ($validated['operations'] as $index => $operation) {
            if (isset($operation['id'])) {
                continue;
            }

            foreach (['semester_id', 'section_id', 'course_id', 'department_id', 'day', 'start_time', 'end_time'] as $field) {
                if (! array_key_exists($field, $operation) || $operation[$field] === null || $operation[$field] === '') {
                    $missingCreateFields[] = "operations.{$index}.{$field}";
                }
            }
        }

        if ($missingCreateFields !== []) {
            return response()->json([
                'message' => 'Schedule operation is missing required fields.',
                'missing_fields' => $missingCreateFields,
            ], 422);
        }

        $departmentHasProgram = [];
        foreach ($validated['operations'] as $operation) {
            if (
                ! isset($operation['id'])
                && isset($operation['department_id'])
                && ! $this->authorization->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
            if (! isset($operation['id']) && isset($operation['department_id']) && ! ($departmentHasProgram[(int) $operation['department_id']] ??= $this->authorization->departmentHasProgram((int) $operation['department_id']))) {
                return response()->json(['message' => 'Create at least one Program under this Department before scheduling.'], 422);
            }
        }

        foreach ($validated['operations'] as $operation) {
            if (! isset($operation['id'])) {
                if (isset($operation['status']) && $operation['status'] !== 'draft') {
                    return response()->json([
                        'message' => 'New timetable entries must start as draft. Use the approval workflow to advance status.',
                    ], 422);
                }
                $duplicateMessage = $this->delegatedCourseScheduleMessage($operation);
                if ($duplicateMessage !== null) {
                    return response()->json([
                        'message' => $duplicateMessage,
                    ], 422);
                }
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $operationIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (! empty($deleteIds) && ! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $touchedSectionIds = array_merge(
            $replaceSectionIds,
            collect($validated['operations'])->pluck('section_id')->filter()->map('intval')->all(),
        );
        if (! $this->authorization->scheduleIdsWritable($request, array_merge($operationIds, array_map('intval', $deleteIds)))
            || ! $this->authorization->sectionIdsWritable($request, $touchedSectionIds)) {
            return $this->programForbidden();
        }

        $plottingFields = [
            'semester_id', 'section_id', 'course_id', 'subject_id', 'room_id', 'department_id',
            'day', 'start_time', 'end_time', 'mode', 'is_hybrid', 'preferred_pattern',
            'split_group_id', 'meeting_type', 'meeting_index', 'status',
        ];
        $existingBatchSchedules = $operationIds === []
            ? collect()
            : Schedule::query()->whereIn('id', $operationIds)->get()->keyBy('id');
        foreach ($validated['operations'] as $operation) {
            if (! isset($operation['id'])) {
                continue;
            }
            $existing = $existingBatchSchedules->get((int) $operation['id']);
            if ($existing === null) {
                continue;
            }
            $providedFields = $providedOperationFields[(int) $operation['id']] ?? array_keys($operation);
            $statusOnlyUpdate = array_key_exists('status', $operation)
                && $operation['status'] !== $existing->status
                && ! in_array($operation['status'], ['finalized', 'reassignment'], true)
                && SchedulingPolicy::allowsManualStatusChange($existing->status, $operation['status'])
                && collect($providedFields)
                    ->reject(static fn (string $field): bool => in_array($field, ['id', 'status'], true))
                    ->isEmpty();
            if (array_key_exists('status', $operation) && $operation['status'] !== $existing->status && ! $statusOnlyUpdate) {
                return response()->json([
                    'message' => 'Schedule status must be changed through the approval workflow.',
                ], 422);
            }
            $changesPlotting = collect($plottingFields)->contains(
                static fn (string $field): bool => in_array($field, $providedFields, true)
            );
            if ($changesPlotting && ! in_array($existing->status, self::PLOTTING_EDITABLE_STATUSES, true)) {
                return response()->json([
                    'message' => 'This schedule is locked at its current approval stage. Recall it or return it to revision before editing the timetable.',
                ], 422);
            }
        }

        if (! empty($deleteIds)) {
            if (! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
            if (Schedule::query()->whereIn('id', $deleteIds)->whereNotIn('status', self::DELETABLE_STATUSES)->exists()) {
                return response()->json(['message' => self::LOCKED_DELETE_MESSAGE], 422);
            }
        }

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $savedSchedules = [];
        $deletedScheduleIds = [];
        $resolvedConflicts = [];
        $releasedInstructorRows = new \Illuminate\Database\Eloquent\Collection;

        try {
            $this->withScheduleWriteLock($this->conflictScopeSemesterIds($validated['operations'], $deleteIds), function () use ($validated, $deleteIds, $mergedIgnoreIds, &$savedSchedules, &$deletedScheduleIds, &$resolvedConflicts, &$releasedInstructorRows): void {
                DB::transaction(function () use ($validated, $deleteIds, $mergedIgnoreIds, &$savedSchedules, &$deletedScheduleIds, &$resolvedConflicts, &$releasedInstructorRows): void {
                    $sectionsById = [];
                    $allViolations = array_merge(
                        $this->checkIntraBatchConflicts($validated['operations']),
                        $this->ruleEngine->validateConfiguredMeetingGroups($validated['operations']),
                    );

                    $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

                    foreach ($orderedOperations as $orderedOperation) {
                        $index = (int) $orderedOperation['index'];
                        $op = $orderedOperation['operation'];
                        $attemptData = $op;
                        if (isset($attemptData['subject_id']) && ! isset($attemptData['course_id'])) {
                            $attemptData['course_id'] = $attemptData['subject_id'];
                        }

                        $sectionId = (int) ($attemptData['section_id'] ?? 0);
                        $section = $sectionsById[$sectionId] ??= Sections::find($sectionId);
                        if ($section?->program_id === null) {
                            $allViolations[] = ['operation_index' => $index, 'message' => 'This section cannot be scheduled until it is assigned to a Program.'];

                            continue;
                        }
                        $attemptData['program_id'] = $attemptData['program_id'] ?? $section->program_id;

                        $attemptData['ignore_schedule_id'] = $mergedIgnoreIds;

                        $violations = $this->ruleEngine->validate($attemptData);
                        if (! empty($violations)) {
                            foreach ($violations as $violation) {
                                $allViolations[] = array_merge($violation, [
                                    'operation_index' => $index,
                                ]);
                            }
                        }
                    }

                    if (! empty($allViolations)) {
                        throw new ScheduleConflictException($allViolations);
                    }

                    $touchedIds = array_values(array_filter(array_map('intval', $mergedIgnoreIds)));
                    $conflictSemesterId = $touchedIds === []
                        ? 0
                        : (int) Schedule::query()->whereIn('id', $touchedIds)->value('semester_id');
                    $conflictsBefore = $this->conflictsTouching($conflictSemesterId, $touchedIds);

                    $deletedBefore = collect();
                    if (! empty($deleteIds)) {
                        $deletedBefore = Schedule::whereIn('id', $deleteIds)->get();
                        $workingIds = $deletedBefore
                            ->filter(static fn (Schedule $schedule): bool => in_array($schedule->status, self::REPLACEABLE_BATCH_STATUSES, true))
                            ->pluck('id')
                            ->all();
                        $archivedIds = array_values(array_diff(array_map('intval', $deleteIds), $workingIds));
                        if ($workingIds !== []) {
                            ScheduleSplit::withTrashed()->whereIn('schedule_id', $workingIds)->forceDelete();
                            Schedule::withTrashed()->whereIn('id', $workingIds)->forceDelete();
                        }
                        if ($archivedIds !== []) {
                            Schedule::whereIn('id', $archivedIds)->delete();
                            Schedule::retireSplitsFor($archivedIds);
                        }
                        $deletedScheduleIds = array_map('intval', $deleteIds);
                        $releasedInstructorRows = $deletedBefore
                            ->filter(static fn (Schedule $schedule): bool => $schedule->faculty_id !== null)
                            ->values();
                    }

                    $savedIds = [];
                    $updateIds = array_values(array_filter(array_map(
                        static fn (array $op): int => (int) ($op['id'] ?? 0),
                        $validated['operations'],
                    )));
                    $existing = $updateIds === []
                        ? collect()
                        : Schedule::query()->whereIn('id', $updateIds)->get()->keyBy('id');
                    $revisionBefore = $existing
                        ->map(static fn (Schedule $schedule): array => $schedule->getAttributes())
                        ->values()
                        ->merge($deletedBefore->map(static fn (Schedule $schedule): array => $schedule->getAttributes()));

                    foreach ($validated['operations'] as $op) {
                        if (isset($op['subject_id']) && ! isset($op['course_id'])) {
                            $op['course_id'] = $op['subject_id'];
                        }

                        $sectionId = (int) ($op['section_id'] ?? 0);
                        $opSection = $sectionsById[$sectionId] ??= Sections::find($sectionId);
                        if (! isset($op['program_id'])) {
                            $op['program_id'] = $opSection?->program_id;
                        }

                        if (isset($op['id'])) {
                            $schedule = $existing->get((int) $op['id']);
                            if (! $schedule) {
                                throw (new ModelNotFoundException)->setModel(Schedule::class, [$op['id']]);
                            }
                            $targetSectionId = isset($op['section_id']) ? $sectionId : (int) $schedule->section_id;
                            if ($schedule->curriculum_id === null || (int) $schedule->section_id !== $targetSectionId) {
                                $op['curriculum_id'] = ($sectionsById[$targetSectionId] ??= Sections::find($targetSectionId))?->curriculum_id;
                            }
                            $schedule->update($op);
                        } else {
                            $op['curriculum_id'] = $opSection?->curriculum_id;
                            $schedule = Schedule::create($op);
                        }
                        $savedIds[] = (int) $schedule->id;
                    }

                    $resolvedConflicts = $this->recordClearedConflicts(
                        $conflictSemesterId,
                        $conflictsBefore,
                        $savedIds,
                        array_map('intval', $deleteIds),
                    );

                    $savedSchedules = Schedule::query()
                        ->whereIn('id', $savedIds)
                        ->with(Schedule::RESPONSE_RELATIONS)
                        ->get()
                        ->sortBy(static fn (Schedule $schedule): int => array_search((int) $schedule->id, $savedIds, true))
                        ->values()
                        ->all();
                    $this->revisionChanges->recordScheduleChanges($revisionBefore, $savedSchedules, request()->user()?->id, 'batch');

                    $createdBatchIds = array_values(array_diff($savedIds, $updateIds));
                    if ($createdBatchIds !== []) {
                        $firstCreated = collect($savedSchedules)->first(fn ($s) => in_array((int) $s->id, $createdBatchIds, true));
                        SchedulingAuditLog::create([
                            'user_id' => request()->user()?->id,
                            'semester_id' => $firstCreated?->semester_id,
                            'department_id' => $firstCreated?->department_id,
                            'action' => 'schedule_created',
                            'metadata' => [
                                'schedule_ids' => $createdBatchIds,
                                'count' => count($createdBatchIds),
                                'batch' => true,
                            ],
                            'created_at' => now(),
                        ]);
                    }

                    if ($updateIds !== []) {
                        $firstUpdated = collect($savedSchedules)->first(fn ($s) => in_array((int) $s->id, $updateIds, true));
                        SchedulingAuditLog::create([
                            'user_id' => request()->user()?->id,
                            'semester_id' => $firstUpdated?->semester_id,
                            'department_id' => $firstUpdated?->department_id,
                            'action' => 'schedule_updated',
                            'metadata' => [
                                'schedule_ids' => $updateIds,
                                'count' => count($updateIds),
                                'batch' => true,
                            ],
                            'created_at' => now(),
                        ]);
                    }

                    if ($deletedBefore->isNotEmpty()) {
                        $version = $this->historyRecorder->record(
                            'schedule_batch_deleted',
                            $deletedBefore,
                            [],
                            request()->user()?->id,
                            $deletedBefore->first()->semester_id,
                            $deletedBefore->first()->department_id,
                            'batch_delete',
                        );
                        SchedulingAuditLog::create([
                            'user_id' => request()->user()?->id,
                            'semester_id' => $deletedBefore->first()->semester_id,
                            'department_id' => $deletedBefore->first()->department_id,
                            'action' => 'schedule_batch_deleted',
                            'history_version_id' => $version->id,
                            'metadata' => ['schedule_ids' => $deleteIds],
                            'created_at' => now(),
                        ]);
                    }
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        $releasedInstructorRows->loadMissing(['course.department', 'section']);
        $crossDepartmentReleased = $releasedInstructorRows->filter(static fn (Schedule $schedule): bool => $schedule->course?->teaching_department_id !== null
            && (int) $schedule->course->teaching_department_id !== (int) $schedule->department_id);
        if ($crossDepartmentReleased->isNotEmpty() && $request->user() !== null) {
            $this->notifications->notifyCrossDepartmentInstructorsReleased(
                $crossDepartmentReleased,
                $request->user(),
                $validated['operations'] === [] ? 'reset' : 'regenerated',
            );
        }
        $classKey = static fn (Schedule $schedule): string => $schedule->section_id.':'.$schedule->course_id;

        return response()->json([
            'message' => 'Batch schedule operation completed successfully.',
            'schedules' => $savedSchedules,
            'deleted_schedule_ids' => $deletedScheduleIds,
            'resolved_conflicts' => $resolvedConflicts,
            'instructors_released' => $releasedInstructorRows->unique($classKey)->count(),
            'cross_department_instructors_released' => $crossDepartmentReleased->unique($classKey)->count(),
        ]);
    }

    /**
     * @param  list<int>  $semesterIds
     */
    private function withScheduleWriteLock(array $semesterIds, callable $callback): mixed
    {
        return $this->scheduleWriteLock->execute($semesterIds, $callback);
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  list<int|string>  $deleteIds
     * @return list<int>
     */
    private function conflictScopeSemesterIds(array $operations, array $deleteIds): array
    {
        $semesterIds = collect($operations)
            ->pluck('semester_id')
            ->filter()
            ->map('intval');

        if ($deleteIds !== []) {
            $semesterIds = $semesterIds->merge(
                Schedule::query()
                    ->whereIn('id', array_map('intval', $deleteIds))
                    ->pluck('semester_id')
                    ->map('intval'),
            );
        }

        return $semesterIds->unique()->sort()->values()->all();
    }

    public function validateSplits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => 'required|array',
            'operations.*.id' => 'nullable|integer|exists:schedules,id',
            'operations.*.semester_id' => 'required|integer|exists:semesters,id',
            'operations.*.section_id' => 'required|integer|exists:sections,id',
            'operations.*.course_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'nullable|integer|exists:rooms,id',
            'operations.*.department_id' => 'required|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'operations.*.start_time' => 'required|date_format:H:i',
            'operations.*.end_time' => 'required|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20'],
            'operations.*.split_group_id' => 'nullable|string|max:36',
            'operations.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index' => 'nullable|integer|min:1',
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
        ]);

        $deleteIds = $validated['delete_ids'] ?? [];
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );
        $resolvedOps = [];
        $resolvedOpsByOriginalIndex = [];
        $allViolations = [];

        foreach ($validated['operations'] as $operation) {
            if (
                ! isset($operation['id'])
                && isset($operation['department_id'])
                && ! $this->authorization->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $operationIds)
            || ! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

        foreach ($orderedOperations as $orderedOperation) {
            $index = (int) $orderedOperation['index'];
            $op = $orderedOperation['operation'];
            if (isset($op['subject_id']) && ! isset($op['course_id'])) {
                $op['course_id'] = $op['subject_id'];
            }

            $op['ignore_schedule_id'] = $mergedIgnoreIds;

            $violations = $this->validateCandidate($op, $resolvedOps);

            if (empty($violations)) {
                unset($op['ignore_schedule_id']);
                $resolvedOps[] = $op;
                $resolvedOpsByOriginalIndex[$index] = $op;

                continue;
            }

            $timeConflictRules = ['section_conflict', 'subject_section_time_conflict', 'room_conflict', 'faculty_conflict', 'split_group_day_separation'];
            $hasTimeConflict = SchedulingPolicy::consecutiveDayCount($op['preferred_pattern'] ?? null) === null
                && collect($violations)->contains(
                    fn ($v) => in_array($v['rule'] ?? '', $timeConflictRules, true)
                );

            if (! $hasTimeConflict) {
                $hasRoomAssignmentIssue = collect($violations)->contains(
                    fn ($v) => in_array(($v['rule'] ?? ''), ['room_type_match', 'room_exists'], true)
                );

                if ($hasRoomAssignmentIssue) {
                    $swappedOp = $this->attemptRoomSwapResolution($op, $resolvedOps);
                    if ($swappedOp !== null) {
                        unset($swappedOp['ignore_schedule_id']);
                        $resolvedOps[] = $swappedOp;
                        $resolvedOpsByOriginalIndex[$index] = $swappedOp;

                        continue;
                    }
                }

                $courseCode = Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
                foreach ($violations as $v) {
                    $allViolations[] = array_merge($v, [
                        'operation_index' => $index,
                        'course_code' => $courseCode,
                        'day' => $op['day'],
                        'start_time' => $op['start_time'],
                        'end_time' => $op['end_time'],
                    ]);
                }

                continue;
            }

            $resolvedOp = $this->attemptSlotShiftResolution(
                $op,
                $resolvedOps
            );

            if ($resolvedOp !== null) {
                unset($resolvedOp['ignore_schedule_id']);
                $resolvedOps[] = $resolvedOp;
                $resolvedOpsByOriginalIndex[$index] = $resolvedOp;
            } else {
                $courseCode = Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
                $allViolations[] = [
                    'rule' => 'split_unresolvable',
                    'operation_index' => $index,
                    'course_code' => $courseCode,
                    'day' => $op['day'],
                    'start_time' => $op['start_time'],
                    'end_time' => $op['end_time'],
                    'message' => "Could not find a conflict-free time slot for {$courseCode} on {$op['day']} "
                        ."starting at {$op['start_time']}. All slots within operating hours are occupied. "
                        .'Please resolve the conflict manually or change the split day.',
                ];
            }
        }

        if (! empty($allViolations)) {
            return $this->splitValidationResponse([
                'status' => 'conflict',
                'message' => 'One or more split sessions could not be scheduled conflict-free.',
                'violations' => $allViolations,
            ], 422);
        }

        return $this->splitValidationResponse([
            'status' => 'ok',
            'message' => 'All split sessions validated successfully.',
            'operations' => $this->restoreOriginalOperationOrder($resolvedOpsByOriginalIndex),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function splitValidationResponse(array $payload, int $status = 200): JsonResponse
    {
        $result = $this->recommendationEngine->recommend(new RecommendationContext(
            RecommendationSource::LegacySplit,
            ['payload' => $payload],
        ));

        return response()->json($result->legacyPayload, $status);
    }

    private function prioritizeSplitAnchorMeetings(array $operations): array
    {
        return collect($operations)
            ->map(fn (array $operation, int $index): array => [
                'index' => $index,
                'operation' => $operation,
                'priority' => ! empty($operation['split_group_id']) && (int) ($operation['meeting_index'] ?? 1) === 1 ? 0 : 1,
            ])
            ->sortBy([
                ['priority', 'asc'],
                ['index', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function restoreOriginalOperationOrder(array $operationsByOriginalIndex): array
    {
        ksort($operationsByOriginalIndex);

        return array_values($operationsByOriginalIndex);
    }

    private function validateCandidate(array $op, array $resolvedOps): array
    {
        $dbViolations = $this->ruleEngine->validate($op);
        $intraViolations = $this->checkIntraBatchConflicts(array_merge($resolvedOps, [$op]));
        $splitDayViolations = $this->checkSplitGroupDayConflicts($op, $resolvedOps);

        return array_merge($dbViolations, $intraViolations, $splitDayViolations);
    }

    private function checkSplitGroupDayConflicts(array $op, array $resolvedOps): array
    {
        $splitGroupId = (string) ($op['split_group_id'] ?? '');
        if ($splitGroupId === '') {
            return [];
        }

        $day = (string) ($op['day'] ?? '');
        foreach ($resolvedOps as $resolvedOp) {
            if (
                (string) ($resolvedOp['split_group_id'] ?? '') === $splitGroupId
                && (string) ($resolvedOp['day'] ?? '') === $day
            ) {
                return [[
                    'rule' => 'split_group_day_separation',
                    'message' => 'Split meetings for the same course must be scheduled on different days.',
                ]];
            }
        }

        return [];
    }

    private function testAndResolveCandidate(array $candidate, array $resolvedOps): ?array
    {
        $violations = $this->validateCandidate($candidate, $resolvedOps);
        if (empty($violations)) {
            return $candidate;
        }

        $hasRoomAssignmentIssue = collect($violations)->contains(
            fn ($v) => in_array(($v['rule'] ?? ''), ['room_type_match', 'room_exists'], true)
        );

        if ($hasRoomAssignmentIssue) {
            $swapped = $this->attemptRoomSwapResolution($candidate, $resolvedOps);
            if ($swapped !== null) {
                return $swapped;
            }
        }

        return null;
    }

    private function attemptSlotShiftResolution(
        array $op,
        array $resolvedOps
    ): ?array {
        $origStartMins = $this->timeToMinutesLocal($op['start_time']);
        $origEndMins = $this->timeToMinutesLocal($op['end_time']);
        $durationMins = $origEndMins - $origStartMins;
        $candidateStartMinutes = $this->generatedStartMinutesByCloseness($durationMins, $origStartMins);

        foreach ($candidateStartMinutes as $newStartMins) {
            if ($newStartMins === $origStartMins) {
                continue;
            }

            $res = $this->testAndResolveCandidate(
                $this->withTime($op, $newStartMins, $durationMins),
                $resolvedOps
            );
            if ($res !== null) {
                return $res;
            }
        }

        $daySwaps = [
            'Monday' => ['Wednesday', 'Friday', 'Tuesday', 'Thursday', 'Saturday'],
            'Tuesday' => ['Thursday', 'Wednesday', 'Monday', 'Friday', 'Saturday'],
            'Wednesday' => ['Monday', 'Friday', 'Thursday', 'Tuesday', 'Saturday'],
            'Thursday' => ['Tuesday', 'Friday', 'Wednesday', 'Monday', 'Saturday'],
            'Friday' => ['Wednesday', 'Monday', 'Thursday', 'Tuesday', 'Saturday'],
            'Saturday' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'Sunday' => ['Saturday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ];

        $alternateDays = $daySwaps[$op['day']] ?? [];
        foreach ($alternateDays as $altDay) {
            $candidate = $op;
            $candidate['day'] = $altDay;
            $res = $this->testAndResolveCandidate($candidate, $resolvedOps);
            if ($res !== null) {
                return $res;
            }

            foreach ($candidateStartMinutes as $newStartMins) {
                $res = $this->testAndResolveCandidate(
                    $this->withTime($candidate, $newStartMins, $durationMins),
                    $resolvedOps
                );
                if ($res !== null) {
                    return $res;
                }
            }
        }

        return null;
    }

    private function withTime(array $op, int $startMinutes, int $durationMinutes): array
    {
        $op['start_time'] = $this->minutesToTimeString($startMinutes);
        $op['end_time'] = $this->minutesToTimeString($startMinutes + $durationMinutes);

        return $op;
    }

    private function generatedStartMinutesByCloseness(int $durationMinutes, int $originalStartMinutes): array
    {
        $minutes = array_map(
            fn (string $time): int => $this->timeToMinutesLocal($time),
            $this->timeslotService->generateStartTimes($durationMinutes)
        );

        usort(
            $minutes,
            static fn (int $left, int $right): int => abs($left - $originalStartMinutes) <=> abs($right - $originalStartMinutes)
        );

        return array_values(array_unique($minutes));
    }

    private function attemptRoomSwapResolution(array $op, array $resolvedOps = []): ?array
    {
        $courseId = (int) ($op['course_id'] ?? 0);
        $meetingType = $op['meeting_type'] ?? null;
        $mode = $op['mode'] ?? 'on-site';
        $deptId = (int) ($op['department_id'] ?? 0);

        $course = $courseId > 0 ? Course::find($courseId) : null;
        $requiredRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field' => 'field',
            $course !== null && SchedulingPolicy::isFieldCourse($course, $deptId) => 'field',
            $meetingType === 'lecture' => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            $course !== null => SchedulingPolicy::effectiveRoomType($course, $deptId, $meetingType),
            default => 'lecture',
        };

        $candidateRooms = Rooms::query()
            ->where('status', 'available')
            ->where('room_type', $requiredRoomType)
            ->tap(fn ($q) => app(RoomAccessPolicy::class)->scopeReachableRooms(
                $q,
                $deptId,
                isset($op['semester_id']) ? (int) $op['semester_id'] : null,
            ))
            ->orderBy('room_code')
            ->get();

        foreach ($candidateRooms as $room) {
            if ((int) $room->id === (int) ($op['room_id'] ?? 0)) {
                continue;
            }

            $candidate = $op;
            $candidate['room_id'] = (int) $room->id;

            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        if ($course !== null && SchedulingPolicy::allowsRoomTbaFallback($course, $deptId, $meetingType)) {
            $candidate = $op;
            $candidate['room_id'] = null;
            $candidate['mode'] = 'on-site';
            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        if ($course !== null && SchedulingPolicy::allowsOnlineRoomFallback($course, $deptId, $meetingType)) {
            $candidate = $op;
            $candidate['room_id'] = null;
            $candidate['mode'] = 'online';
            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        return null;
    }

    private function timeToMinutesLocal(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return ($h * 60) + $m;
    }

    private function minutesToTimeString(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    private function checkIntraBatchConflicts(array $operations): array
    {
        return array_map(
            static fn (BatchConflict $conflict): array => [
                'rule' => $conflict->rule,
                'operation_index' => $conflict->index,
                'course_code' => $conflict->courseCode ?? 'Course',
                'day' => $conflict->day,
                'message' => match ($conflict->rule) {
                    BatchConflict::RULE_SECTION => sprintf(
                        'Intra-batch Section Conflict: %s and %s overlap for section on %s from %s to %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->day,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                    ),
                    BatchConflict::RULE_SUBJECT_SECTION_TIME => sprintf(
                        'Intra-batch Subject/Section Conflict: %s is assigned to multiple sections at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    BatchConflict::RULE_ROOM => sprintf(
                        'Intra-batch Room Conflict: Room is assigned to both %s and %s at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    BatchConflict::RULE_FACULTY => sprintf(
                        'Intra-batch Faculty Conflict: Instructor is assigned to teach both %s and %s at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    default => 'Intra-batch schedule conflict.',
                },
            ],
            $this->batchConflicts->validate($operations),
        );
    }

    /**
     * @return string|null Error message when the write is not allowed at this stage.
     */
    private function instructorAssignmentStageError(Schedule $schedule, ?int $requestedFacultyId): ?string
    {
        $currentFacultyId = $schedule->faculty_id === null ? null : (int) $schedule->faculty_id;

        if ($requestedFacultyId === null || $requestedFacultyId === $currentFacultyId) {
            return null;
        }

        if ((bool) $schedule->faculty_assignment_done) {
            return 'Instructor assignments are marked done. Choose Edit Assignments before changing them.';
        }

        if (in_array($schedule->status, SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES, true)) {
            return null;
        }

        return $schedule->status === 'finalized'
            ? 'A finalized schedule cannot be reassigned.'
            : 'Instructor assignment is available only after VPAA approval.';
    }

    private function hydrateExistingScheduleOperation(array $operation): array
    {
        if (! isset($operation['id'])) {
            return $operation;
        }

        $schedule = Schedule::query()->find((int) $operation['id']);
        if ($schedule === null) {
            return $operation;
        }

        $persisted = [
            'id' => $schedule->id,
            'semester_id' => $schedule->semester_id,
            'section_id' => $schedule->section_id,
            'course_id' => $schedule->course_id,
            'faculty_id' => $schedule->faculty_id,
            'room_id' => $schedule->room_id,
            'department_id' => $schedule->department_id,
            'day' => $schedule->day,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'mode' => $schedule->mode,
            'is_hybrid' => $schedule->is_hybrid,
            'preferred_pattern' => $schedule->preferred_pattern,
            'split_group_id' => $schedule->split_group_id,
            'meeting_type' => $schedule->meeting_type,
            'meeting_index' => $schedule->meeting_index,
            'status' => $schedule->status,
        ];

        $operation['department_id'] = $schedule->department_id;

        return array_merge($persisted, $operation);
    }

    public function show(Request $request, Schedule $schedule)
    {
        $statuses = $this->authorization->visibleScheduleStatuses($request);
        if ($statuses !== null && ! in_array($schedule->status, $statuses, true)) {
            return response()->json(['message' => 'This schedule has not been approved yet.'], 403);
        }

        return response()->json($schedule->load(['academicSemester', 'section', 'course', 'faculty', 'room', 'department', 'program']));
    }

    public function bySemester(Request $request, int|string $semesterId)
    {
        $schedules = $this->scopedScheduleListQuery($request)
            ->where('semester_id', $semesterId)
            ->latest()
            ->limit(1000)
            ->get();

        return response()->json($schedules);
    }

    public function bySection(Request $request, int|string $sectionId)
    {
        $schedules = $this->scopedScheduleListQuery($request)
            ->where('section_id', $sectionId)
            ->latest()
            ->limit(1000)
            ->get();

        return response()->json($schedules);
    }

    private function scopedScheduleListQuery(Request $request): Builder
    {
        $scope = $this->authorization->departmentScope($request);
        $statuses = $this->authorization->visibleScheduleStatuses($request);

        return Schedule::query()
            ->with(Schedule::RESPONSE_RELATIONS)
            ->when($scope !== null, fn (Builder $query) => $query->where(
                fn (Builder $owned) => $owned
                    ->where('department_id', $scope)
                    ->orWhereHas('course', fn (Builder $course) => $course->where('teaching_department_id', $scope)),
            ))
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('status', $statuses))
            ->tap(fn (Builder $query) => $this->authorization->scopeSchedulesToProgram($query, $request));
    }

    public function update(Request $request, Schedule $schedule)
    {
        if (! $request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'semester_id' => 'sometimes|required|exists:semesters,id',
            'section_id' => 'sometimes|required|exists:sections,id',
            'course_id' => 'sometimes|required|exists:courses,id',
            'faculty_id' => 'nullable|exists:faculties,id',
            'room_id' => 'sometimes|nullable|exists:rooms,id',
            'department_id' => 'sometimes|required|exists:departments,id',
            'day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i|after:start_time',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidRowPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id' => 'nullable|string|max:36',
            'meeting_type' => 'nullable|in:lecture,laboratory',
            'meeting_index' => 'nullable|integer|min:1',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

        if (! $this->authorization->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (
            isset($validated['department_id'])
            && ! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])
        ) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }
        if (! $this->authorization->scheduleIsWritable($request, $schedule)
            || (isset($validated['section_id']) && ! $this->authorization->sectionIdsWritable($request, [(int) $validated['section_id']]))) {
            return $this->programForbidden();
        }

        if (array_key_exists('faculty_id', $validated)) {
            $stageError = $this->instructorAssignmentStageError(
                $schedule,
                $validated['faculty_id'] === null ? null : (int) $validated['faculty_id'],
            );

            if ($stageError !== null) {
                return response()->json(['message' => $stageError], 422);
            }
        }

        $assignedFacultyId = ($validated['faculty_id'] ?? null) !== null
            ? (int) $validated['faculty_id']
            : null;

        if ($assignedFacultyId !== null) {
            $faculty = Faculty::query()->find($assignedFacultyId);
            $pair = $faculty !== null ? $this->loadPairForSchedule($schedule) : null;

            if ($pair !== null) {
                $projection = $this->withAssignmentLabel(
                    $this->facultyLoad->projectLoad($faculty, $this->activeSemesterId(), [$pair]),
                    $this->assignmentLabelForSchedule($schedule),
                );
                $refusal = $this->unitCeilingRefusal([$projection]);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        $manualFacultySchedules = array_key_exists('faculty_id', $validated)
            ? $this->manualHybridAssignments->resolve($schedule)
            : collect([$schedule]);

        if (array_key_exists('faculty_id', $validated)) {
            foreach ($manualFacultySchedules as $relatedSchedule) {
                if ((int) $relatedSchedule->id === (int) $schedule->id) {
                    continue;
                }
                $stageError = $this->instructorAssignmentStageError(
                    $relatedSchedule,
                    $validated['faculty_id'] === null ? null : (int) $validated['faculty_id'],
                );
                if ($stageError !== null) {
                    return response()->json(['message' => $stageError], 422);
                }
            }
        }

        $plottingFields = [
            'semester_id', 'section_id', 'course_id', 'subject_id', 'room_id', 'department_id',
            'day', 'start_time', 'end_time', 'mode', 'is_hybrid', 'preferred_pattern',
            'split_group_id', 'meeting_type', 'meeting_index', 'status',
        ];
        $changesPlotting = collect($plottingFields)->contains(
            static fn (string $field): bool => array_key_exists($field, $validated)
        );
        if ($changesPlotting && ! in_array($schedule->status, self::PLOTTING_EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This schedule is locked at its current approval stage. Recall it or return it to revision before editing the timetable.',
            ], 422);
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== $schedule->status) {
            return response()->json([
                'message' => 'Schedule status must be changed through the approval workflow.',
            ], 422);
        }

        if (array_key_exists('section_id', $validated) && (int) $validated['section_id'] !== (int) $schedule->section_id) {
            $validated['curriculum_id'] = Sections::whereKey($validated['section_id'])->value('curriculum_id');
        }

        $manualFacultyScheduleIds = $manualFacultySchedules
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
        $attemptData = array_merge($schedule->toArray(), $validated, [
            'ignore_schedule_id' => array_key_exists('faculty_id', $validated)
                ? $manualFacultyScheduleIds
                : $schedule->id,
        ]);

        $semesterId = (int) ($validated['semester_id'] ?? $schedule->semester_id);

        $groupPartners = $this->sameTimePartners->partnersFor($schedule, $validated);
        $runPartnerIds = $this->sameTimePartners->runPartnerIds($schedule, $groupPartners);
        if ($runPartnerIds !== []) {
            $attemptData['ignore_schedule_id'] = [...(array) $attemptData['ignore_schedule_id'], ...$runPartnerIds];
        }

        $instructorOnly = ! $changesPlotting && array_key_exists('faculty_id', $validated);

        /** @var list<int> $movedPartnerIds */
        $movedPartnerIds = [];
        $resolvedConflicts = [];

        try {
            $actorId = $request->user()?->id;
            $this->withScheduleWriteLock($semesterId > 0 ? [$semesterId] : [], function () use ($schedule, $validated, $attemptData, $manualFacultySchedules, $manualFacultyScheduleIds, $groupPartners, $instructorOnly, $semesterId, $actorId, &$movedPartnerIds, &$resolvedConflicts): void {
                DB::transaction(function () use ($schedule, $validated, $attemptData, $manualFacultySchedules, $manualFacultyScheduleIds, $groupPartners, $instructorOnly, $semesterId, $actorId, &$movedPartnerIds, &$resolvedConflicts): void {
                    $touchedIds = array_values(array_unique(array_map('intval', [
                        (int) $schedule->id,
                        ...$groupPartners->pluck('id')->all(),
                        ...$manualFacultyScheduleIds,
                    ])));
                    $conflictsBefore = $this->conflictsTouching((int) $semesterId, $touchedIds);
                    $revisionBefore = Schedule::query()->whereIn('id', $touchedIds)->get();

                    $violations = $instructorOnly
                        ? $this->ruleEngine->validateInstructorAssignment($attemptData)
                        : $this->ruleEngine->validate($attemptData);

                    if (! empty($violations)) {
                        throw new ScheduleConflictException($violations, 'Schedule update conflicts with existing entries.');
                    }

                    if ($groupPartners->isNotEmpty()) {
                        $movedPartnerIds = $this->moveSameTimePartners($attemptData, $groupPartners);
                    }

                    $schedule->update($validated);

                    if (array_key_exists('faculty_id', $validated)) {
                        foreach ($manualFacultySchedules as $relatedSchedule) {
                            if ((int) $relatedSchedule->id === (int) $schedule->id) {
                                continue;
                            }
                            $relatedAttempt = array_merge(
                                $relatedSchedule->toArray(),
                                [
                                    'faculty_id' => $validated['faculty_id'],
                                    'ignore_schedule_id' => $manualFacultyScheduleIds,
                                ],
                            );
                            $relatedViolations = $this->ruleEngine->validateInstructorAssignment($relatedAttempt);
                            if (! empty($relatedViolations)) {
                                throw new ScheduleConflictException(
                                    $relatedViolations,
                                    'Instructor assignment conflicts with a related hybrid schedule.',
                                );
                            }
                            $relatedSchedule->update(['faculty_id' => $validated['faculty_id']]);
                        }
                    }

                    $resolvedConflicts = $this->recordClearedConflicts(
                        (int) $semesterId,
                        $conflictsBefore,
                        array_values(array_unique([...$touchedIds, ...$movedPartnerIds])),
                    );
                    $this->revisionChanges->recordScheduleChanges(
                        $revisionBefore,
                        Schedule::query()->whereIn('id', $revisionBefore->modelKeys())->get(),
                        $actorId,
                        'update',
                    );
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        $schedule->load(['academicSemester', 'section', 'course', 'faculty', 'room', 'department']);
        $this->notifyScheduleSaved($request, $schedule, 'updated');
        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $schedule->semester_id,
            'section_id' => $schedule->section_id,
            'department_id' => $schedule->department_id,
            'action' => 'schedule_updated',
            'metadata' => [
                'schedule_id' => (int) $schedule->id,
                'course_id' => $schedule->course_id,
                'day' => $schedule->day,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ],
            'created_at' => now(),
        ]);
        ApiCache::forgetGroups(['faculty.index', 'initial.data']);

        if (array_key_exists('faculty_id', $validated)) {
            return response()->json([
                ...$schedule->toArray(),
                'resolved_conflicts' => $resolvedConflicts,
                'schedule' => $schedule,
                'schedules' => Schedule::query()
                    ->whereIn('id', $manualFacultyScheduleIds)
                    ->with(Schedule::RESPONSE_RELATIONS)
                    ->get(),
            ]);
        }

        if ($movedPartnerIds !== []) {
            return response()->json([
                ...$schedule->toArray(),
                'resolved_conflicts' => $resolvedConflicts,
                'moved_partners' => Schedule::query()
                    ->whereIn('id', $movedPartnerIds)
                    ->with(Schedule::RESPONSE_RELATIONS)
                    ->get(),
            ]);
        }

        return response()->json([...$schedule->toArray(), 'resolved_conflicts' => $resolvedConflicts]);
    }

    /**
     * @param  list<int>  $scheduleIds
     * @return list<ScheduleConflictCase>
     */
    private function conflictsTouching(int $semesterId, array $scheduleIds): array
    {
        return $semesterId > 0 && $scheduleIds !== []
            ? $this->conflictScanner->scan($semesterId, onlyScheduleIds: $scheduleIds)
            : [];
    }

    /**
     * @param  list<ScheduleConflictCase>  $before  from conflictsTouching()
     * @param  list<int>  $savedIds  the rows as they now are
     * @param  list<int>  $removedIds  rows the write deleted
     * @return list<array<string, mixed>>
     */
    private function recordClearedConflicts(int $semesterId, array $before, array $savedIds, array $removedIds = []): array
    {
        if ($before === []) {
            return [];
        }

        $cleared = ScheduleConflictScanner::cleared(
            $before,
            $this->conflictScanner->scan($semesterId, onlyScheduleIds: $savedIds),
            $removedIds,
        );
        if ($cleared === []) {
            return [];
        }

        $records = array_map(static fn (ScheduleConflictCase $case): array => $case->toResolutionRecord(), $cleared);

        SchedulingAuditLog::create([
            'user_id' => request()->user()?->id,
            'semester_id' => $semesterId,
            'department_id' => $cleared[0]->schedule['department_id'] ?? null,
            'section_id' => $cleared[0]->schedule['section_id'] ?? null,
            'action' => 'schedule_conflicts_cleared',
            'metadata' => [
                'resolved_conflicts' => $records,
                'saved_schedule_ids' => $savedIds,
                'deleted_schedule_ids' => $removedIds,
            ],
            'created_at' => now(),
        ]);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $attemptData
     * @param  \Illuminate\Support\Collection<int, Schedule>  $partners
     * @return list<int> the partners moved to the new time
     */
    private function moveSameTimePartners(array $attemptData, \Illuminate\Support\Collection $partners): array
    {
        return $this->sameTimePartners->move($attemptData, $partners);
    }

    public function destroy(Request $request, Schedule $schedule)
    {
        if (! $this->authorization->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }
        if (! $this->authorization->scheduleIsWritable($request, $schedule)) {
            return $this->programForbidden();
        }

        $schedule->load(['academicSemester', 'section', 'course', 'faculty', 'room', 'department', 'split']);
        $deletedSchedule = clone $schedule;

        $splitGroupId = $schedule->split_group_id;

        if ($request->query('delete_group') === 'true' && $splitGroupId) {
            $schedules = Schedule::whereHas('split', function ($q) use ($splitGroupId) {
                $q->where('split_group_id', $splitGroupId);
            })->get();

            if (! $this->authorization->scheduleIdsBelongToDepartment($request, $schedules->pluck('id')->all())) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
            if (! $this->authorization->scheduleIdsWritable($request, $schedules->pluck('id')->all())) {
                return $this->programForbidden();
            }
            if ($schedules->contains(static fn (Schedule $s): bool => ! in_array($s->status, self::DELETABLE_STATUSES, true))) {
                return response()->json(['message' => self::LOCKED_DELETE_MESSAGE], 422);
            }

            foreach ($schedules as $s) {
                $s->delete();
            }
            $removed = $schedules;
        } else {
            if (! in_array($schedule->status, self::DELETABLE_STATUSES, true)) {
                return response()->json(['message' => self::LOCKED_DELETE_MESSAGE], 422);
            }
            $schedule->delete();
            $removed = collect([$schedule]);
        }
        $this->revisionChanges->recordScheduleChanges($removed, [], $request->user()?->id, 'delete');

        $this->notifyScheduleSaved($request, $deletedSchedule, 'deleted');
        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $deletedSchedule->semester_id,
            'section_id' => $deletedSchedule->section_id,
            'department_id' => $deletedSchedule->department_id,
            'action' => 'schedule_deleted',
            'metadata' => [
                'schedule_id' => (int) $deletedSchedule->id,
                'course_id' => $deletedSchedule->course_id,
            ],
            'created_at' => now(),
        ]);
        ApiCache::forgetGroups(['faculty.index', 'initial.data']);

        return response()->json(['message' => 'Schedule archived successfully']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertBatchReferencesExist(array $validated): void
    {
        $tables = [
            'id' => 'schedules', 'semester_id' => 'semesters', 'section_id' => 'sections',
            'course_id' => 'courses', 'subject_id' => 'courses', 'faculty_id' => 'faculties',
            'room_id' => 'rooms', 'department_id' => 'departments',
        ];

        /** @var array<string, array<string, int>> $references table => [error key => id] */
        $references = [];
        foreach ($validated['operations'] ?? [] as $index => $operation) {
            foreach ($tables as $field => $table) {
                if (isset($operation[$field])) {
                    $references[$table]["operations.{$index}.{$field}"] = (int) $operation[$field];
                }
            }
        }
        foreach ($validated['delete_ids'] ?? [] as $index => $id) {
            $references['schedules']["delete_ids.{$index}"] = (int) $id;
        }
        foreach ($validated['replace_section_ids'] ?? [] as $index => $id) {
            $references['sections']["replace_section_ids.{$index}"] = (int) $id;
        }
        if (isset($validated['replace_semester_id'])) {
            $references['semesters']['replace_semester_id'] = (int) $validated['replace_semester_id'];
        }

        $errors = [];
        foreach ($references as $table => $idsByKey) {
            $found = array_flip(DB::table($table)
                ->whereIn('id', array_values(array_unique($idsByKey)))
                ->pluck('id')
                ->map('intval')
                ->all());
            foreach ($idsByKey as $key => $id) {
                if (! isset($found[$id])) {
                    $errors[$key] = __('validation.exists', ['attribute' => str_replace('_', ' ', $key)]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function clearOnlineRoomId(array $payload): array
    {
        $roomId = (int) ($payload['room_id'] ?? 0);
        if ($roomId > 0) {
            $lookup = static fn (): ?string => Rooms::query()
                ->whereKey($roomId)
                ->value('room_type');
            $roomType = match (true) {
                $this->roomTypes === null => $lookup(),
                array_key_exists($roomId, $this->roomTypes) => $this->roomTypes[$roomId],
                default => $this->roomTypes[$roomId] = $lookup(),
            };

            if ($roomType === 'online') {
                $payload['mode'] = 'online';
            }
        }

        if (($payload['mode'] ?? null) === 'online') {
            $payload['room_id'] = null;
        }

        return $payload;
    }

    private function delegatedCourseScheduleMessage(array $payload): ?string
    {
        $courseId = (int) ($payload['course_id'] ?? $payload['subject_id'] ?? 0);
        $targetDepartmentId = (int) ($payload['department_id'] ?? 0);
        $semesterId = (int) ($payload['semester_id'] ?? 0);

        if ($courseId === 0 || $targetDepartmentId === 0 || $semesterId === 0) {
            return null;
        }

        $cacheKey = "{$courseId}:{$targetDepartmentId}:{$semesterId}";
        if ($this->delegatedCourseMessages === null) {
            return $this->resolveDelegatedCourseScheduleMessage($courseId, $targetDepartmentId, $semesterId);
        }
        if (array_key_exists($cacheKey, $this->delegatedCourseMessages)) {
            return $this->delegatedCourseMessages[$cacheKey];
        }

        return $this->delegatedCourseMessages[$cacheKey] = $this->resolveDelegatedCourseScheduleMessage($courseId, $targetDepartmentId, $semesterId);
    }

    private function resolveDelegatedCourseScheduleMessage(int $courseId, int $targetDepartmentId, int $semesterId): ?string
    {
        $course = Course::query()->find($courseId);
        $teachingDepartmentId = (int) ($course?->teaching_department_id ?? 0);
        if ($course === null || $teachingDepartmentId === 0 || $teachingDepartmentId !== $targetDepartmentId) {
            return null;
        }

        $sourceSchedule = Schedule::query()
            ->where('semester_id', $semesterId)
            ->where('course_id', $courseId)
            ->where('department_id', '!=', $targetDepartmentId)
            ->whereNotIn('status', ['rejected', 'revision'])
            ->first();

        if ($sourceSchedule === null) {
            return null;
        }

        return "{$course->course_code} already has a schedule owned by the source department. Assign the instructor to the existing schedule; do not create another schedule.";
    }

    private function payloadBelongsToDepartment(Request $request, int $targetDeptId): bool
    {
        $scope = $this->authorization->departmentScope($request);

        return $scope === null || $scope === $targetDeptId;
    }

    private function scheduleBelongsToDepartment(Request $request, Schedule $schedule): bool
    {
        return $this->payloadBelongsToDepartment($request, (int) $schedule->department_id);
    }

    private function scheduleIdsBelongToDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->authorization->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    private function scheduleIdsAssignableByDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->authorization->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        $delegations = Course::query()
            ->whereNotNull('teaching_department_id')
            ->pluck('teaching_department_id', 'id')
            ->all();

        $delegatedHere = [];
        $delegatedElsewhere = [];
        foreach ($delegations as $courseId => $teachingDepartmentId) {
            if ((int) $teachingDepartmentId === $scope) {
                $delegatedHere[] = (int) $courseId;
            } else {
                $delegatedElsewhere[] = (int) $courseId;
            }
        }

        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where(fn ($row) => $row
                ->when($delegatedHere !== [], fn ($query) => $query->where(
                    fn ($scoped) => $scoped
                        ->whereNull('course_id')
                        ->orWhereNotIn('course_id', $delegatedHere),
                ))
                ->where(fn ($foreign) => $foreign
                    ->where('department_id', '!=', $scope)
                    ->when(
                        $delegatedElsewhere !== [],
                        fn ($query) => $query->orWhereIn('course_id', $delegatedElsewhere),
                    )))
            ->exists();
    }

    private function sectionIdsBelongToDepartment(Request $request, array $sectionIds): bool
    {
        $scope = $this->authorization->departmentScope($request);
        if ($scope === null || $sectionIds === []) {
            return true;
        }

        return ! DB::table('sections')
            ->whereIn('id', array_values(array_unique(array_map('intval', $sectionIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    private function notifyScheduleSaved(Request $request, Schedule $schedule, string $action): void
    {
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        $actor = $request->user();
        if (! $actor) {
            return;
        }

        $courseCode = $schedule->course?->course_code ?? 'Course';
        $sectionName = $schedule->section?->section_name ?? 'Section';

        $this->notifications->notifyRoles(
            ['vpaa', 'dean'],
            'schedule_activity',
            'Schedule '.ucfirst($action),
            "{$actor->name} {$action} schedule for {$courseCode} ({$sectionName}).",
            $actor,
            $schedule->department_id,
            $schedule->semester_id
        );
    }

    public function batchFaculty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.schedule_ids' => 'required|array|min:1',
            'assignments.*.schedule_ids.*' => 'integer|exists:schedules,id',
            'assignments.*.faculty_id' => 'nullable|integer|exists:faculties,id',
        ]);

        $expandedAssignments = [];
        foreach ($validated['assignments'] as $assignment) {
            $assignmentScheduleIds = array_map('intval', $assignment['schedule_ids']);
            foreach (Schedule::query()->whereIn('id', $assignmentScheduleIds)->get() as $schedule) {
                $assignmentScheduleIds = array_merge(
                    $assignmentScheduleIds,
                    $this->manualHybridAssignments->resolve($schedule)->modelKeys(),
                );
            }
            $expandedAssignments[] = [
                ...$assignment,
                'schedule_ids' => array_values(array_unique($assignmentScheduleIds)),
            ];
        }
        $validated['assignments'] = $expandedAssignments;

        $scheduleIds = [];
        foreach ($validated['assignments'] as $assignment) {
            foreach ($assignment['schedule_ids'] as $scheduleId) {
                $scheduleIds[] = (int) $scheduleId;
            }
        }

        if (count($scheduleIds) !== count(array_unique($scheduleIds))) {
            return response()->json([
                'message' => 'A schedule cannot appear in more than one assignment.',
            ], 422);
        }

        if (! $this->authorization->scheduleIdsAssignableByDepartment($request, $scheduleIds)) {
            return response()->json([
                'message' => 'You can only assign instructors for schedules your department owns or teaches.',
            ], 403);
        }

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;
        if ($programId !== null) {
            $facultyIds = collect($validated['assignments'])
                ->pluck('faculty_id')
                ->filter(fn ($facultyId) => $facultyId !== null)
                ->map('intval')
                ->unique()
                ->values();
            $matchingFacultyCount = Faculty::query()
                ->whereIn('id', $facultyIds)
                ->where('program_id', $programId)
                ->count();

            if ($matchingFacultyCount !== $facultyIds->count()) {
                return response()->json([
                    'message' => 'Program Heads can only assign instructors from their assigned program.',
                ], 422);
            }
        }

        $schedules = Schedule::query()
            ->whereIn('id', $scheduleIds)
            ->get()
            ->keyBy(static fn (Schedule $schedule): int => (int) $schedule->id);

        if ($programId !== null) {
            $programScheduleCount = Schedule::query()
                ->whereIn('id', $scheduleIds)
                ->whereHas('course', fn ($course) => $course
                    ->where('program_id', $programId)
                    ->orWhere('teaching_program_id', $programId))
                ->count();

            if ($programScheduleCount !== count($scheduleIds)) {
                return response()->json([
                    'message' => 'Program Heads can only assign courses assigned to their program.',
                ], 403);
            }
        }

        foreach ($validated['assignments'] as $assignment) {
            $facultyId = isset($assignment['faculty_id']) ? (int) $assignment['faculty_id'] : null;

            foreach ($assignment['schedule_ids'] as $scheduleId) {
                $schedule = $schedules->get((int) $scheduleId);
                if ($schedule === null) {
                    continue;
                }

                $stageError = $this->instructorAssignmentStageError($schedule, $facultyId);
                if ($stageError !== null) {
                    return response()->json([
                        'message' => $stageError,
                        'violations' => [[
                            'rule' => 'instructor_assignment_stage',
                            'schedule_id' => (int) $schedule->id,
                            'message' => $stageError,
                        ]],
                    ], 422);
                }
            }
        }

        $rowsByFaculty = [];

        foreach ($validated['assignments'] as $assignment) {
            $facultyId = isset($assignment['faculty_id']) ? (int) $assignment['faculty_id'] : null;

            if ($facultyId === null) {
                continue;
            }

            foreach ($assignment['schedule_ids'] as $scheduleId) {
                $schedule = $schedules->get((int) $scheduleId);

                if ($schedule !== null) {
                    $rowsByFaculty[$facultyId][] = $schedule;
                }
            }
        }

        $projections = [];

        if ($rowsByFaculty !== []) {
            $activeSemesterId = $this->activeSemesterId();
            $faculties = Faculty::query()->whereIn('id', array_keys($rowsByFaculty))->get();

            foreach ($faculties as $faculty) {
                $rows = $rowsByFaculty[(int) $faculty->id] ?? [];
                $pairs = $this->loadPairsForSchedules($rows);

                $projections[] = $this->withAssignmentLabel(
                    $this->facultyLoad->projectLoad($faculty, $activeSemesterId, $pairs),
                    $this->assignmentLabelForClasses($rows, count($pairs)),
                );
            }
        }

        $refusal = $this->unitCeilingRefusal($projections);
        if ($refusal !== null) {
            return $refusal;
        }

        $semesterIds = $schedules
            ->pluck('semester_id')
            ->filter()
            ->map('intval')
            ->unique()
            ->sort()
            ->values()
            ->all();

        try {
            $this->withScheduleWriteLock($semesterIds, function () use ($validated, $schedules): void {
                DB::transaction(function () use ($validated, $schedules): void {
                    foreach ($validated['assignments'] as $assignment) {
                        $facultyId = $assignment['faculty_id'] ?? null;
                        $facultyId = $facultyId === null ? null : (int) $facultyId;

                        foreach ($assignment['schedule_ids'] as $scheduleId) {
                            $schedule = $schedules->get((int) $scheduleId);
                            if ($schedule === null) {
                                throw new ScheduleConflictException(
                                    [[
                                        'rule' => 'missing_schedule',
                                        'schedule_id' => (int) $scheduleId,
                                        'message' => 'A selected schedule no longer exists.',
                                    ]],
                                    'A selected schedule no longer exists.',
                                );
                            }

                            $violations = $this->ruleEngine->validateInstructorAssignment(array_merge(
                                $schedule->toArray(),
                                [
                                    'faculty_id' => $facultyId,
                                    'ignore_schedule_id' => (int) $schedule->id,
                                ],
                            ));

                            if (! empty($violations)) {
                                throw new ScheduleConflictException(
                                    array_map(
                                        static fn (array $violation): array => array_merge($violation, [
                                            'schedule_id' => (int) $schedule->id,
                                        ]),
                                        $violations,
                                    ),
                                    'Instructor assignment conflicts with existing entries.',
                                );
                            }

                            $schedule->update(['faculty_id' => $facultyId]);
                        }
                    }
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'Instructor assignments completed successfully.',
            'schedules' => Schedule::query()
                ->whereIn('id', $scheduleIds)
                ->with(Schedule::RESPONSE_RELATIONS)
                ->get(),
            'schedules_updated' => count($scheduleIds),
        ]);
    }

    public function batchFacultyDone(Request $request): JsonResponse
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:schedules,id'], 'done' => ['required', 'boolean']]);
        if (! $this->authorization->scheduleIdsAssignableByDepartment($request, $validated['ids'])) {
            return response()->json(['message' => 'You can only update instructor assignments for your department.'], 403);
        }
        $schedules = Schedule::query()->whereIn('id', $validated['ids'])->get();
        if ($validated['done'] && $schedules->contains(fn (Schedule $schedule) => $schedule->faculty_id === null)) {
            return response()->json(['message' => 'Assign instructors to every schedule before marking done.'], 422);
        }
        $schedules->each(fn (Schedule $schedule) => $schedule->update(['faculty_assignment_done' => (bool) $validated['done']]));
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);
        if ($validated['done'] && $schedules->isNotEmpty()) {
            $first = $schedules->first()->fresh(['course.department', 'course.teachingDepartment']);
            $this->notifications->notifyCrossDepartmentCompletion($first, $request->user(), $schedules->modelKeys());
        }

        return response()->json(['schedules' => Schedule::query()->whereIn('id', $validated['ids'])->with(Schedule::RESPONSE_RELATIONS)->get()]);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:schedules,id',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('required'),
        ]);

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $validated['ids'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }
        if (! $this->authorization->scheduleIdsWritable($request, $validated['ids'])) {
            return $this->programForbidden();
        }

        $targetSchedules = Schedule::query()
            ->whereIn('id', $validated['ids'])
            ->with(['course', 'section'])
            ->get();

        if ($targetSchedules->isEmpty()) {
            return response()->json(['message' => 'Select at least one schedule before changing its status.'], 422);
        }

        if ($validated['status'] === 'reassignment' && $targetSchedules->contains(
            fn (Schedule $schedule): bool => $schedule->status !== 'finalized'
        )) {
            return response()->json([
                'message' => 'Only finalized schedules can be placed under Reassignment.',
            ], 422);
        }

        if (in_array($validated['status'], ['draft', 'revision'], true) && Schedule::query()
            ->whereIn('id', $validated['ids'])
            ->where('status', 'finalized')
            ->exists()) {
            return response()->json([
                'message' => 'Finalized timetable rows cannot be reopened for plotting. Use Reassignment instead.',
            ], 422);
        }

        $blockedSchedules = $targetSchedules->reject(
            fn (Schedule $schedule): bool => SchedulingPolicy::allowsManualStatusChange($schedule->status, $validated['status'])
        );
        if ($blockedSchedules->isNotEmpty()) {
            return response()->json([
                'message' => 'Some selected classes are at a stage that cannot move to '.str_replace('_', ' ', $validated['status'])
                    .' here. Submit, approve, return or recall them through the approval workflow, then refresh and try again.',
                'blocked_schedule_ids' => $blockedSchedules->modelKeys(),
            ], 422);
        }

        if ($validated['status'] === 'finalized') {
            $scopeSchedules = Schedule::query()
                ->where(function ($query) use ($targetSchedules): void {
                    foreach ($targetSchedules->groupBy(fn (Schedule $schedule): string => $schedule->semester_id.'-'.$schedule->section_id) as $sectionSchedules) {
                        $first = $sectionSchedules->first();
                        $query->orWhere(function ($sectionQuery) use ($first): void {
                            $sectionQuery->where('semester_id', $first->semester_id)
                                ->where('section_id', $first->section_id);
                        });
                    }
                })
                ->with(['course', 'section'])
                ->get();

            $unselectedScheduleIds = $scopeSchedules->pluck('id')->diff($targetSchedules->pluck('id'));
            if ($unselectedScheduleIds->isNotEmpty()) {
                return response()->json([
                    'message' => 'Finalize every scheduled course in the section together so all faculty assignments can be validated.',
                    'unselected_schedule_ids' => $unselectedScheduleIds->values(),
                ], 422);
            }

            $missingInstructors = $scopeSchedules
                ->filter(fn (Schedule $schedule): bool => $schedule->faculty_id === null)
                ->map(function (Schedule $schedule): array {
                    $component = $schedule->meeting_type === null
                        ? null
                        : ucfirst($schedule->meeting_type);

                    return [
                        'schedule_id' => (int) $schedule->id,
                        'course_code' => $schedule->course?->course_code ?? 'Unknown course',
                        'section_name' => $schedule->section?->section_name ?? 'Unknown section',
                        'component' => $component,
                    ];
                })
                ->values();

            if ($missingInstructors->isNotEmpty()) {
                $missingLabels = $missingInstructors
                    ->map(fn (array $item): string => sprintf(
                        '%s (%s%s)',
                        $item['course_code'],
                        $item['section_name'],
                        $item['component'] === null ? '' : ' - '.$item['component'],
                    ))
                    ->unique()
                    ->implode(', ');

                return response()->json([
                    'message' => 'Cannot finalize while instructors are missing: '.$missingLabels.'.',
                    'missing_instructors' => $missingInstructors,
                ], 422);
            }
        }

        if ($validated['status'] === 'finalized' && Schedule::query()
            ->whereIn('id', $targetSchedules->modelKeys())
            ->whereNull('room_id')
            ->where('mode', 'on-site')
            ->whereHas('course', fn ($query) => $query->where('room_type_required', 'laboratory')->orWhere('lab_hours', '>', 0))
            ->exists()) {
            return response()->json([
                'message' => 'Cannot finalize while one or more laboratory schedules still have Room TBA. Assign all rooms first.',
            ], 422);
        }

        $result = DB::transaction(function () use ($validated, $request): array {
            $before = Schedule::whereIn('id', $validated['ids'])->get();
            $updateValues = ['status' => $validated['status'], 'updated_at' => now()];
            if ($validated['status'] === 'finalized') {
                $updateValues['faculty_assignment_done'] = true;
            }
            $updated = Schedule::whereIn('id', $validated['ids'])->update($updateValues);
            if ($validated['status'] === 'reassignment') {
                // Rows taught by another department stay done: the owner cannot
                // reassign them, and reopening them would hide their instructors.
                Schedule::whereIn('id', $validated['ids'])
                    ->whereDoesntHave('course', fn ($query) => $query
                        ->whereNotNull('teaching_department_id')
                        ->whereColumn('courses.teaching_department_id', '!=', 'schedules.department_id'))
                    ->update(['faculty_assignment_done' => false]);
            }
            $schedules = Schedule::whereIn('id', $validated['ids'])->with(Schedule::RESPONSE_RELATIONS)->get();
            $version = $this->historyRecorder->record('schedule_batch_status_updated', $before, $schedules, $request->user()?->id, null, null, 'batch_status', null, ['status' => $validated['status']]);
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $schedules->first()?->semester_id,
                'department_id' => $schedules->first()?->department_id,
                'action' => 'schedule_batch_status_updated',
                'history_version_id' => $version->id,
                'metadata' => ['schedule_ids' => $validated['ids'], 'status' => $validated['status']],
                'created_at' => now(),
            ]);

            return compact('updated', 'schedules');
        });
        $updated = $result['updated'];
        $schedules = $result['schedules'];
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'Batch status update completed successfully.',
            'schedules' => $schedules,
            'schedules_updated' => $updated,
        ]);
    }

    private function programForbidden(): JsonResponse
    {
        return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
    }
}
