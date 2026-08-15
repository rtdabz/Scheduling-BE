<?php

namespace App\Http\Controllers;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Schedule;
use App\Models\ScheduleRecommendation;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\ScheduleCandidateOptimizer;
use App\Services\Scheduling\ScheduleGenerationPreflightService;
use App\Services\Scheduling\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\Scheduling\SplitScheduleService;
use App\Services\Scheduling\YearLevelScheduleGenerationService;
use App\Services\SystemNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ScheduleRecommendationController extends Controller
{
    private const REPLACEABLE_SCHEDULE_STATUSES = ['draft', 'completed', 'revision'];

    private const YEAR_LEVEL_PREVIEW_EXECUTION_SECONDS = 150;

    public function __construct(
        private readonly CSPSolver $cspSolver,
        private readonly ScheduleCandidateOptimizer $candidateOptimizer,
        private readonly SplitScheduleService $splitScheduleService,
        private readonly YearLevelScheduleGenerationService $yearLevelGenerator,
        private readonly ScheduleGenerationPreflightService $preflight,
        private readonly ScheduleRequirementBuilderResolver $requirementBuilders,
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
        private readonly SystemNotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'sometimes|integer|exists:sections,id',
            'status' => 'sometimes|in:pending,accepted,rejected',
        ]);

        if (isset($validated['section_id'])) {
            /** @var Sections $section */
            $section = Sections::query()->findOrFail($validated['section_id']);
            if (! $this->canManageDepartment($request, (int) $section->department_id)) {
                return $this->departmentForbiddenResponse();
            }
        }

        $recommendations = ScheduleRecommendation::with(['section', 'term', 'department', 'requester'])
            ->when(($scope = $this->departmentScope($request)) !== null, fn ($query) => $query->where('department_id', $scope))
            ->when(isset($validated['section_id']), fn ($query) => $query->where('section_id', $validated['section_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest()
            ->get();

        return response()->json($recommendations);
    }

    /**
     * Find conflict-free placements for a single split-session block.
     *
     * POST /api/schedule-recommendations/recommend-split
     *
     * The Rule Engine validates every candidate; only conflict-free options
     * are returned. If no valid placement exists anywhere within operating
     * hours, status='no_solution' is returned instead of an error.
     */
    public function recommendSplit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'term_id' => 'required|integer|exists:terms,id',
            'section_id' => 'required|integer|exists:sections,id',
            'course_id' => 'required|integer|exists:courses,id',
            'department_id' => 'required|integer|exists:departments,id',
            'duration_slots' => 'required|integer|min:1|max:24',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'faculty_id' => 'nullable|integer|exists:faculties,id',
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
            'max_solutions' => 'sometimes|integer|min:1|max:10',
            'timeout_seconds' => 'sometimes|numeric|min:0.5|max:15',
            'meeting_type' => 'nullable|in:lecture,laboratory',
            'preferred_day' => 'nullable|string',
            'preferred_start_time' => 'nullable|string',
        ]);

        if (! $this->canManageDepartment($request, (int) $validated['department_id'])) {
            return $this->departmentForbiddenResponse();
        }

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);
        if ((int) $section->department_id !== (int) $validated['department_id']) {
            return response()->json(['message' => 'Schedule department must match the selected section department.'], 422);
        }

        try {
            $result = $this->splitScheduleService->recommend(
                termId: (int) $validated['term_id'],
                sectionId: (int) $validated['section_id'],
                courseId: (int) $validated['course_id'],
                departmentId: (int) $validated['department_id'],
                durationSlots: (int) $validated['duration_slots'],
                roomId: isset($validated['room_id']) ? (int) $validated['room_id'] : null,
                mode: $validated['mode'] ?? 'on-site',
                facultyId: isset($validated['faculty_id']) ? (int) $validated['faculty_id'] : null,
                deleteIds: array_map('intval', $validated['delete_ids'] ?? []),
                maxResults: (int) ($validated['max_solutions'] ?? 5),
                timeoutSeconds: (float) ($validated['timeout_seconds'] ?? 5.0),
                meetingType: $validated['meeting_type'] ?? null,
                preferredDay: $validated['preferred_day'] ?? null,
                preferredStartTime: $validated['preferred_start_time'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['status'] === 'no_solution') {
            return response()->json([
                'status' => 'no_solution',
                'message' => 'No conflict-free time slot found for this split session. '
                    .'All available times on all days are occupied. '
                    .'Try a different room, delivery mode, or reduce other scheduled sessions.',
                'recommendations' => [],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Conflict-free placements found for this split session.',
            'recommendations' => $result['recommendations'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'anchored_schedules' => 'sometimes|array',
            'anchored_schedules.*.course_id' => 'required|integer|exists:courses,id',
            'anchored_schedules.*.day' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'anchored_schedules.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'anchored_schedules.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/', 'after:anchored_schedules.*.start_time'],
            'anchored_schedules.*.room_id' => 'nullable|integer|exists:rooms,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:25',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        if (! $this->canManageDepartment($request, (int) $section->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        try {
            $validated['course_ids'] = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $profile = $this->preflight->validate($section, $validated['course_ids'], $validated);
            $validated['requirements_by_course_id'] = $this->requirementBuilders->build($section, $validated['course_ids'], $validated);
            $solutions = $this->candidateOptimizer->rankForSection(
                $this->cspSolver->solveRankedFromSchema($validated),
                $section,
                $validated,
            );
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($solutions !== []) {
            Schedule::where('section_id', $section->id)
                ->where('term_id', $section->term_id)
                ->whereIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
                ->delete();

            ScheduleRecommendation::where('section_id', $section->id)
                ->where('term_id', $section->term_id)
                ->delete();
        }

        $user = $request->user();

        $recommendations = DB::transaction(function () use ($solutions, $section, $validated, $user) {
            $created = [];

            foreach ($solutions as $solution) {
                $recommendation = ScheduleRecommendation::create([
                    'term_id' => (int) $section->term_id,
                    'section_id' => (int) $section->id,
                    'department_id' => (int) $section->department_id,
                    'requested_by' => $user?->id,
                    'rank' => (int) $solution['rank'],
                    'score' => (int) $solution['score'],
                    'status' => 'pending',
                    'input_payload' => $validated,
                    'recommended_schedules' => $solution['schedules'],
                ]);

                $this->recordAudit(
                    action: 'recommendation_generated',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'rank' => $solution['rank'],
                        'score' => $solution['score'],
                        'schedule_count' => count($solution['schedules']),
                    ],
                );

                $created[] = $recommendation->load(['section', 'term', 'department', 'requester']);
            }

            return $created;
        });

        if ($recommendations !== []) {
            $section->loadMissing(['department', 'term']);
            $this->notifications->notifyRoles(
                ['secretary', 'program_head', 'dean'],
                'schedule_generation_completed',
                'Schedule generation completed',
                $this->notifications->departmentWorkflowMessage(
                    'generated schedule recommendations for',
                    $section->department,
                    $section->term,
                    $user,
                    count($recommendations),
                ),
                $user,
                (int) $section->department_id,
                (int) $section->term_id,
                null,
                [
                    'section_id' => $section->id,
                    'recommendations_generated' => count($recommendations),
                    'search_limit_reached' => $this->cspSolver->searchLimitReached(),
                    'iterations_used' => $this->cspSolver->iterationsUsed(),
                ],
            );
        }

        return response()->json([
            'message' => $recommendations === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'search_limit_reached' => $this->cspSolver->searchLimitReached(),
            'iterations_used' => $this->cspSolver->iterationsUsed(),
            'recommendations' => $recommendations,
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'anchored_schedules' => 'sometimes|array',
            'anchored_schedules.*.course_id' => 'required|integer|exists:courses,id',
            'anchored_schedules.*.day' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'anchored_schedules.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'anchored_schedules.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/', 'after:anchored_schedules.*.start_time'],
            'anchored_schedules.*.room_id' => 'nullable|integer|exists:rooms,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_session_enabled' => 'sometimes|boolean',
            'selected_split_session_course_ids' => 'sometimes|array',
            'selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'split_units_enabled' => 'sometimes|boolean',
            'selected_split_unit_course_ids' => 'sometimes|array',
            'selected_split_unit_course_ids.*' => 'integer|exists:courses,id',
            'split_gec_enabled' => 'sometimes|boolean',
            'selected_gec_course_ids' => 'sometimes|array',
            'selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        if (! $this->canManageDepartment($request, (int) $section->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        try {
            $validated['course_ids'] = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $validated['selected_split_session_course_ids'] = ($validated['split_session_enabled'] ?? false)
                ? array_values(array_intersect(
                    array_map('intval', $validated['selected_split_session_course_ids'] ?? []),
                    $validated['course_ids'],
                ))
                : [];
            $selectedGecSplitCourseIds = ($validated['split_gec_enabled'] ?? false)
                ? array_values(array_intersect(
                    array_map('intval', $validated['selected_gec_course_ids'] ?? []),
                    $validated['course_ids'],
                ))
                : [];
            $validated['preferred_patterns'] = $this->mergeSelectedSplitPatterns(
                $validated['preferred_patterns'] ?? [],
                $selectedGecSplitCourseIds,
                $validated['course_ids'],
            );
            $validated['balanced_split_course_ids'] = $selectedGecSplitCourseIds;
            $profile = $this->preflight->validate($section, $validated['course_ids'], $validated);
            $validated['requirements_by_course_id'] = $this->requirementBuilders->build($section, $validated['course_ids'], $validated);
            $solutions = $this->candidateOptimizer->rankForSection(
                $this->cspSolver->solveRankedFromSchema($validated),
                $section,
                $validated,
            );
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $solutions === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'search_limit_reached' => $this->cspSolver->searchLimitReached(),
            'iterations_used' => $this->cspSolver->iterationsUsed(),
            'recommendations' => $solutions,
        ]);
    }

    public function yearLevelPreview(Request $request): JsonResponse
    {
        $this->allowLongRunningGeneration(self::YEAR_LEVEL_PREVIEW_EXECUTION_SECONDS);

        $validated = $request->validate([
            'term_id' => 'required|integer|exists:terms,id',
            'department_id' => 'required|integer|exists:departments,id',
            'year_level' => 'required|integer|min:1|max:4',
            'section_configs' => 'required|array|min:1',
            'section_configs.*.section_id' => 'required|integer|distinct|exists:sections,id',
            'section_configs.*.course_ids' => 'sometimes|array|min:1',
            'section_configs.*.course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'section_configs.*.is_hybrid' => 'sometimes|boolean',
            'section_configs.*.selected_split_session_course_ids' => 'sometimes|array',
            'section_configs.*.selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.selected_gec_course_ids' => 'sometimes|array',
            'section_configs.*.selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.preferred_patterns' => 'sometimes|array',
            'section_configs.*.preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'section_configs.*.delivery_modes_by_course_id' => 'sometimes|array',
            'section_configs.*.delivery_modes_by_course_id.*' => SchedulingPolicy::allowedDeliveryModesRule('required'),
        ]);

        if (! $this->canManageDepartment($request, (int) $validated['department_id'])) {
            return $this->departmentForbiddenResponse();
        }

        $sections = Sections::query()
            ->where('term_id', (int) $validated['term_id'])
            ->where('department_id', (int) $validated['department_id'])
            ->where('year_level', (string) $validated['year_level'])
            ->where('status', 'active')
            ->orderBy('section_name')
            ->get();

        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No active sections were found for the selected year level.'], 422);
        }

        $configs = collect($validated['section_configs'])->keyBy(static fn (array $config): int => (int) $config['section_id']);
        $expectedSectionIds = $sections->pluck('id')->map('intval')->sort()->values()->all();
        $configuredSectionIds = $configs->keys()->map('intval')->sort()->values()->all();
        if ($expectedSectionIds !== $configuredSectionIds) {
            return response()->json(['message' => 'Provide one configuration for every active section in the selected year level.'], 422);
        }

        $configsBySectionId = [];
        try {
            foreach ($sections as $section) {
                $config = $configs->get((int) $section->id);
                $courseIds = $this->resolveCourseIds($section, $config['course_ids'] ?? null);
                $splitIds = array_values(array_intersect(array_map('intval', $config['selected_split_session_course_ids'] ?? []), $courseIds));
                $gecIds = array_values(array_intersect(array_map('intval', $config['selected_gec_course_ids'] ?? []), $courseIds));
                $preferredPatterns = $this->mergeSelectedSplitPatterns($config['preferred_patterns'] ?? [], $gecIds, $courseIds);
                $sectionConfig = [
                    'course_ids' => $courseIds,
                    'mode' => (string) ($config['mode'] ?? 'on-site'),
                    'is_hybrid' => (bool) ($config['is_hybrid'] ?? false),
                    'selected_split_session_course_ids' => $splitIds,
                    'balanced_split_course_ids' => $gecIds,
                    'preferred_patterns' => $preferredPatterns,
                    'delivery_modes_by_course_id' => $config['delivery_modes_by_course_id'] ?? [],
                    'seed' => $this->yearLevelConfigSeed(
                        termId: (int) $validated['term_id'],
                        departmentId: (int) $validated['department_id'],
                        yearLevel: (int) $validated['year_level'],
                        sectionId: (int) $section->id,
                        courseIds: $courseIds,
                        splitIds: $splitIds,
                        gecIds: $gecIds,
                        preferredPatterns: $preferredPatterns,
                    ),
                ];
                $profile = $this->preflight->validate($section, $courseIds, $sectionConfig);
                $sectionConfig['requirements_by_course_id'] = $this->requirementBuilders->build($section, $courseIds, $sectionConfig);
                $sectionConfig['department_profile'] = $profile->value;
                $configsBySectionId[(int) $section->id] = $sectionConfig;
            }

            $result = $this->yearLevelGenerator->preview($sections->all(), $configsBySectionId);
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Year-level schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'year_level' => (int) $validated['year_level'],
            'sections' => $sections->map(fn (Sections $section): array => [
                'id' => (int) $section->id,
                'name' => (string) $section->section_name,
            ])->values(),
            'score' => $result['score'],
            'quality_score' => $result['quality_score'],
            'penalty_score' => $result['penalty_score'],
            'resource_usage_score' => $result['resource_usage_score'],
            'weekday_utilization_score' => $result['weekday_utilization_score'],
            'fair_distribution_score' => $result['fair_distribution_score'],
            'resource_fairness_score' => $result['resource_fairness_score'],
            'schedule_compactness_score' => $result['schedule_compactness_score'],
            'configuration_compliance_score' => $result['configuration_compliance_score'],
            'quality_breakdown' => $result['quality_breakdown'],
            'score_breakdown' => $result['score_breakdown'],
            'section_summaries' => $result['section_summaries'],
            'schedules' => $result['schedules'],
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'selected_rank' => 'required|integer|min:1|max:5',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        if (! $this->canManageDepartment($request, (int) $section->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        $solverInput = $validated;
        $selectedRank = (int) $solverInput['selected_rank'];
        unset($solverInput['selected_rank']);

        try {
            $solverInput['course_ids'] = $this->resolveCourseIds($section, $solverInput['course_ids'] ?? null);
            $profile = $this->preflight->validate($section, $solverInput['course_ids'], $solverInput);
            $solverInput['requirements_by_course_id'] = $this->requirementBuilders->build($section, $solverInput['course_ids'], $solverInput);
            $solutions = $this->candidateOptimizer->rankForSection(
                $this->cspSolver->solveRankedFromSchema($solverInput),
                $section,
                $solverInput,
            );
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $selectedSolution = collect($solutions)->first(
            static fn (array $solution): bool => (int) $solution['rank'] === $selectedRank,
        );

        if ($selectedSolution === null) {
            return response()->json([
                'message' => 'The selected recommendation is no longer available. Please refresh the recommendations.',
            ], 422);
        }

        $user = $request->user();

        $recommendation = DB::transaction(function () use ($selectedSolution, $section, $solverInput, $user) {
            $recommendation = ScheduleRecommendation::create([
                'term_id' => (int) $section->term_id,
                'section_id' => (int) $section->id,
                'department_id' => (int) $section->department_id,
                'requested_by' => $user?->id,
                'rank' => (int) $selectedSolution['rank'],
                'score' => (int) $selectedSolution['score'],
                'status' => 'pending',
                'input_payload' => $solverInput,
                'recommended_schedules' => $selectedSolution['schedules'],
            ]);

            $this->recordAudit(
                action: 'recommendation_selected',
                userId: $user?->id,
                recommendation: $recommendation,
                metadata: [
                    'rank' => $selectedSolution['rank'],
                    'score' => $selectedSolution['score'],
                    'schedule_count' => count($selectedSolution['schedules']),
                ],
            );

            return $recommendation->load(['section', 'term', 'department', 'requester']);
        });

        return response()->json([
            'message' => 'Schedule recommendation selected successfully.',
            'department_profile' => $profile->value,
            'recommendation' => $recommendation,
        ], 201);
    }

    public function show(ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->canManageDepartment(request(), (int) $scheduleRecommendation->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        return response()->json($scheduleRecommendation->load([
            'section',
            'term',
            'department',
            'requester',
            'accepter',
            'rejecter',
        ]));
    }

    public function review(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->canManageDepartment($request, (int) $scheduleRecommendation->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        $user = $request->user();

        $recommendation = DB::transaction(function () use ($scheduleRecommendation, $user) {
            /** @var ScheduleRecommendation $recommendation */
            $recommendation = ScheduleRecommendation::query()
                ->whereKey($scheduleRecommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->recordAudit(
                action: 'recommendation_reviewed',
                userId: $user?->id,
                recommendation: $recommendation,
                metadata: [
                    'status' => $recommendation->status,
                ],
            );

            return $recommendation->fresh([
                'section',
                'term',
                'department',
                'requester',
                'accepter',
                'rejecter',
            ]);
        });

        return response()->json([
            'message' => 'Recommendation review recorded successfully.',
            'recommendation' => $recommendation,
        ]);
    }

    public function accept(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->canManageDepartment($request, (int) $scheduleRecommendation->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($scheduleRecommendation, $user) {
                /** @var ScheduleRecommendation $recommendation */
                $recommendation = ScheduleRecommendation::query()
                    ->whereKey($scheduleRecommendation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recommendation->status !== 'pending') {
                    throw new InvalidArgumentException('Only pending recommendations can be accepted.');
                }

                $rows = $this->normalizeRecommendedSchedules($recommendation);
                $this->validateBatchConflicts($rows);

                $courseIdsToCreate = array_values(array_unique(array_map(
                    static fn (array $r): int => (int) $r['course_id'],
                    $rows,
                )));

                Schedule::query()
                    ->where('term_id', $rows[0]['term_id'])
                    ->where('section_id', $rows[0]['section_id'])
                    ->whereIn('course_id', $courseIdsToCreate)
                    ->whereIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
                    ->delete();

                $createdIds = [];
                foreach ($rows as $row) {
                    $s = Schedule::create($row);
                    $createdIds[] = $s->id;
                }

                $createdSchedules = Schedule::query()
                    ->whereIn('id', $createdIds)
                    ->with([
                        'term',
                        'section',
                        'course',
                        'faculty',
                        'room',
                        'department',
                    ])
                    ->get();

                $recommendation->update([
                    'status' => 'accepted',
                    'accepted_by' => $user?->id,
                    'accepted_at' => now(),
                ]);

                $this->recordAudit(
                    action: 'recommendation_accepted',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'created_schedule_ids' => $createdSchedules->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    ],
                );

                return [
                    'recommendation' => $recommendation->fresh([
                        'section',
                        'term',
                        'department',
                        'requester',
                        'accepter',
                    ]),
                    'schedules' => $createdSchedules,
                ];
            });
        } catch (RecommendationConflictException $exception) {
            return response()->json([
                'message' => 'Recommendation conflicts with existing or pending schedule entries.',
                'violations' => $exception->violations,
            ], 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Recommendation accepted and schedules created successfully.',
            'recommendation' => $result['recommendation'],
            'schedules' => $result['schedules'],
        ]);
    }

    public function reject(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->canManageDepartment($request, (int) $scheduleRecommendation->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        try {
            $recommendation = DB::transaction(function () use ($scheduleRecommendation, $validated, $user) {
                /** @var ScheduleRecommendation $recommendation */
                $recommendation = ScheduleRecommendation::query()
                    ->whereKey($scheduleRecommendation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recommendation->status !== 'pending') {
                    throw new InvalidArgumentException('Only pending recommendations can be rejected.');
                }

                $recommendation->update([
                    'status' => 'rejected',
                    'rejected_by' => $user?->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $validated['reason'] ?? null,
                ]);

                $this->recordAudit(
                    action: 'recommendation_rejected',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'reason' => $validated['reason'] ?? null,
                    ],
                );

                return $recommendation->fresh([
                    'section',
                    'term',
                    'department',
                    'requester',
                    'rejecter',
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Recommendation rejected successfully.',
            'recommendation' => $recommendation,
        ]);
    }

    private function normalizeRecommendedSchedules(ScheduleRecommendation $recommendation): array
    {
        $rows = $recommendation->recommended_schedules;

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('Recommendation does not contain schedule rows.');
        }

        return array_map(function (array $row) use ($recommendation): array {
            $courseId = (int) ($row['course_id'] ?? $row['subject_id']);

            return [
                'term_id' => (int) ($row['term_id'] ?? $recommendation->term_id),
                'section_id' => (int) ($row['section_id'] ?? $recommendation->section_id),
                'course_id' => $courseId,
                'faculty_id' => isset($row['faculty_id']) ? (int) $row['faculty_id'] : null,
                'room_id' => isset($row['room_id']) && $row['room_id'] !== null ? (int) $row['room_id'] : null,
                'department_id' => (int) ($row['department_id'] ?? $recommendation->department_id),
                'day' => (string) $row['day'],
                'start_time' => SchedulingPolicy::normalizeTime((string) $row['start_time']),
                'end_time' => SchedulingPolicy::normalizeTime((string) $row['end_time']),
                'mode' => (string) ($row['mode'] ?? 'on-site'),
                'is_hybrid' => (bool) ($row['is_hybrid'] ?? false),
                'preferred_pattern' => $row['preferred_pattern'] ?? null,
                'status' => (string) ($row['status'] ?? 'draft'),
                'split_group_id' => $row['split_group_id'] ?? null,
                'meeting_type' => $row['meeting_type'] ?? null,
                'meeting_index' => isset($row['meeting_index']) ? (int) $row['meeting_index'] : null,
            ];
        }, $rows);

    }

    /**
     * Resolve which courses to schedule for a section. If the caller
     * explicitly supplied course_ids, use those (manual override still
     * allowed). Otherwise, derive the list from the section's department's
     * ACTIVE curriculum, filtered to the section's year_level and semester —
     * Curriculum is the source of truth for what should be scheduled.
     */
    private function resolveCourseIds(Sections $section, ?array $providedCourseIds): array
    {
        if (! empty($providedCourseIds)) {
            $curriculum = Curriculum::where('department_id', $section->department_id)
                ->where('status', 'active')
                ->first();

            if ($curriculum) {
                $validPivotIds = $curriculum->courses()
                    ->whereIn('courses.id', $providedCourseIds)
                    ->wherePivot('year_level', (int) $section->year_level)
                    ->wherePivot('semester', $this->mapSemesterToInt($section->semester))
                    ->pluck('courses.id')
                    ->toArray();

                if (! empty($validPivotIds)) {
                    return $validPivotIds;
                }
            }

            $validProvidedIds = Course::query()
                ->whereIn('id', $providedCourseIds)
                ->where('status', 'active')
                ->where('year_level', (string) $section->year_level)
                ->where('semester', (string) $section->semester)
                ->pluck('id')
                ->toArray();

            if (! empty($validProvidedIds)) {
                return $validProvidedIds;
            }
        }

        $curriculum = Curriculum::where('department_id', $section->department_id)
            ->where('status', 'active')
            ->first();

        if ($curriculum) {
            $courseIds = $curriculum->courses()
                ->wherePivot('year_level', (int) $section->year_level)
                ->wherePivot('semester', $this->mapSemesterToInt($section->semester))
                ->pluck('courses.id')
                ->toArray();

            if (! empty($courseIds)) {
                return $courseIds;
            }
        }

        // Fallback: search courses table directly for courses matching section department/year_level/semester
        $fallbackCourseIds = Course::query()
            ->where('status', 'active')
            ->where(function ($q) use ($section) {
                $q->whereNull('department_id')
                    ->orWhere('department_id', $section->department_id);
            })
            ->where('year_level', (string) $section->year_level)
            ->where('semester', (string) $section->semester)
            ->pluck('id')
            ->toArray();

        if (! empty($fallbackCourseIds)) {
            return $fallbackCourseIds;
        }

        if (! $curriculum) {
            throw new InvalidArgumentException(
                'No active curriculum found for this department. Activate a curriculum before generating a schedule.'
            );
        }

        throw new InvalidArgumentException(
            "Year {$section->year_level} has no courses for {$section->semester} semester. Add courses to this year level before generating a schedule."
        );
    }

    private function mapSemesterToInt(string $semester): int
    {
        return match ($semester) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => throw new InvalidArgumentException("Unrecognized semester '{$semester}'."),
        };
    }

    private function mergeSelectedSplitPatterns(array $preferredPatterns, array $selectedCourseIds, array $validCourseIds): array
    {
        $validCourseIds = array_flip(array_map('intval', $validCourseIds));
        $selectedCourseIds = array_flip(array_map('intval', $selectedCourseIds));
        $merged = [];

        foreach ($preferredPatterns as $courseId => $preferredPattern) {
            $courseId = (int) $courseId;
            if ($courseId <= 0 || ! isset($validCourseIds[$courseId]) || ! isset($selectedCourseIds[$courseId])) {
                continue;
            }

            $merged[$courseId] = $preferredPattern;
        }

        return $merged;
    }

    private function yearLevelConfigSeed(
        int $termId,
        int $departmentId,
        int $yearLevel,
        int $sectionId,
        array $courseIds,
        array $splitIds,
        array $gecIds,
        array $preferredPatterns = [],
    ): int {
        $payload = implode('|', [
            $termId,
            $departmentId,
            $yearLevel,
            $sectionId,
            implode(',', array_map('intval', $courseIds)),
            implode(',', array_map('intval', $splitIds)),
            implode(',', array_map('intval', $gecIds)),
            json_encode($preferredPatterns),
        ]);

        return (abs((int) crc32($payload)) % 1000000) + 1;
    }

    private function validateBatchConflicts(array $rows): void
    {
        $violations = [];
        $roomIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['room_id'] ?? 0),
            $rows,
        ))));
        $roomTypeMap = $roomIds === []
            ? collect()
            : DB::table('rooms')->whereIn('id', $roomIds)->pluck('room_type', 'id');
        $roomCapacityMap = $roomIds === []
            ? collect()
            : DB::table('rooms')
                ->whereIn('id', $roomIds)
                ->get(['id', 'room_type', 'max_concurrent_classes'])
                ->mapWithKeys(static fn ($room): array => [
                    (int) $room->id => max(1, (int) ($room->max_concurrent_classes ?? 1)),
                ]);

        foreach ($rows as $leftIndex => $left) {
            foreach ($rows as $rightIndex => $right) {
                if ($leftIndex >= $rightIndex) {
                    continue;
                }

                if ($left['term_id'] !== $right['term_id'] || $left['day'] !== $right['day']) {
                    continue;
                }

                if (! $this->timesOverlap($left['start_time'], $left['end_time'], $right['start_time'], $right['end_time'])) {
                    continue;
                }

                if ($left['section_id'] === $right['section_id']) {
                    $violations[] = $this->batchViolation('section_conflict', $leftIndex, $rightIndex);
                }

                if (
                    $left['room_id'] !== null &&
                    $right['room_id'] !== null &&
                    $left['room_id'] === $right['room_id'] &&
                    ($left['mode'] ?? 'on-site') !== 'online' &&
                    ($right['mode'] ?? 'on-site') !== 'online' &&
                    ($roomTypeMap->get((int) $left['room_id']) !== 'field'
                        || (int) $left['department_id'] === (int) $right['department_id']) &&
                    ($roomTypeMap->get((int) $left['room_id']) === 'field'
                        ? $this->resourceLimits->field((int) $left['department_id'])
                        : (int) ($roomCapacityMap->get((int) $left['room_id']) ?? 1)) <= 1
                ) {
                    $violations[] = $this->batchViolation('room_conflict', $leftIndex, $rightIndex);
                }

                if (
                    ! empty($left['faculty_id'])
                    && ! empty($right['faculty_id'])
                    && $left['faculty_id'] === $right['faculty_id']
                ) {
                    $violations[] = $this->batchViolation('faculty_conflict', $leftIndex, $rightIndex);
                }
            }
        }

        $violations = array_merge(
            $violations,
            $this->batchRoomCapacityViolations($rows, $roomCapacityMap),
            $this->batchOnlineCapacityViolations($rows),
        );

        foreach ($rows as $index => $row) {
            $duplicateExists = Schedule::query()
                ->where('term_id', $row['term_id'])
                ->where('section_id', $row['section_id'])
                ->where('course_id', $row['course_id'])
                ->whereNotIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
                ->exists();

            if ($duplicateExists) {
                $violations[] = [
                    'rule' => 'duplicate_section_course',
                    'message' => 'This section already has an approved/finalized schedule for one of the recommended courses.',
                    'recommendation_row' => $index,
                ];
            }
        }

        if ($violations !== []) {
            throw new RecommendationConflictException($violations);
        }
    }

    /**
     * @param  Collection<int, int>  $roomCapacityMap
     */
    private function batchRoomCapacityViolations(array $rows, $roomCapacityMap): array
    {
        $violations = [];
        $groups = [];
        $fieldRoomIds = DB::table('rooms')
            ->whereIn('id', $roomCapacityMap->keys()->all())
            ->where('room_type', 'field')
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();

        foreach ($rows as $index => $row) {
            $roomId = (int) ($row['room_id'] ?? 0);
            $termId = (int) ($row['term_id'] ?? 0);
            $departmentId = (int) ($row['department_id'] ?? 0);
            $capacity = isset($fieldRoomIds[$roomId])
                ? $this->resourceLimits->field($departmentId)
                : (int) ($roomCapacityMap->get($roomId) ?? 1);
            $day = (string) ($row['day'] ?? '');

            if ($roomId <= 0 || $capacity <= 1 || $termId <= 0 || $departmentId <= 0 || $day === '') {
                continue;
            }

            $groups["{$termId}:{$departmentId}:{$roomId}:{$day}"][] = [
                'index' => $index,
                'term_id' => $termId,
                'department_id' => $departmentId,
                'room_id' => $roomId,
                'capacity' => $capacity,
                'day' => $day,
                'start' => $this->timeToMinutes((string) $row['start_time']),
                'end' => $this->timeToMinutes((string) $row['end_time']),
            ];
        }

        if ($groups !== []) {
            $roomIds = [];
            $termIds = [];
            $departmentIds = [];
            $days = [];
            foreach ($groups as $items) {
                foreach ($items as $item) {
                    $roomIds[$item['room_id'] ?? 0] = $item['room_id'] ?? 0;
                    $termIds[$item['term_id'] ?? 0] = $item['term_id'] ?? 0;
                    $departmentIds[$item['department_id'] ?? 0] = $item['department_id'] ?? 0;
                    $days[$item['day']] = $item['day'];
                }
            }

            $existingSchedules = Schedule::query()
                ->whereIn('room_id', array_filter(array_values($roomIds)))
                ->whereIn('term_id', array_filter(array_values($termIds)))
                ->whereIn('department_id', array_filter(array_values($departmentIds)))
                ->whereIn('day', array_values($days))
                ->get(['id', 'room_id', 'term_id', 'department_id', 'day', 'start_time', 'end_time']);

            foreach ($existingSchedules as $schedule) {
                $roomId = (int) $schedule->room_id;
                $capacity = isset($fieldRoomIds[$roomId])
                    ? $this->resourceLimits->field((int) $schedule->department_id)
                    : (int) ($roomCapacityMap->get($roomId) ?? 1);
                if ($capacity <= 1) {
                    continue;
                }

                $groups["{$schedule->term_id}:{$schedule->department_id}:{$roomId}:{$schedule->day}"][] = [
                    'index' => null,
                    'schedule_id' => (int) $schedule->id,
                    'department_id' => (int) $schedule->department_id,
                    'capacity' => $capacity,
                    'day' => (string) $schedule->day,
                    'start' => $this->timeToMinutes((string) $schedule->start_time),
                    'end' => $this->timeToMinutes((string) $schedule->end_time),
                ];
            }
        }

        foreach ($groups as $items) {
            $events = [];
            foreach ($items as $item) {
                $events[] = ['minute' => $item['start'], 'delta' => 1, 'item' => $item];
                $events[] = ['minute' => $item['end'], 'delta' => -1, 'item' => $item];
            }

            usort(
                $events,
                static fn (array $left, array $right): int => ($left['minute'] <=> $right['minute']) ?: ($left['delta'] <=> $right['delta']),
            );

            $active = [];
            $reported = [];
            foreach ($events as $event) {
                $item = $event['item'];
                $activeKey = $item['index'] ?? "existing:{$item['schedule_id']}";
                if ($event['delta'] < 0) {
                    unset($active[$activeKey]);

                    continue;
                }

                $active[$activeKey] = $item;
                if ($item['index'] === null || count($active) <= $item['capacity'] || isset($reported[$item['index']])) {
                    continue;
                }

                $violations[] = [
                    'rule' => 'room_capacity_conflict',
                    'message' => "Recommended row {$item['index']} exceeds room capacity. FIELD allows only {$item['capacity']} concurrent classes per department on {$item['day']}.",
                    'recommendation_row' => $item['index'],
                ];
                $reported[$item['index']] = true;
            }
        }

        return $violations;
    }

    private function batchOnlineCapacityViolations(array $rows): array
    {
        $violations = [];
        $groups = [];

        foreach ($rows as $index => $row) {
            if (($row['mode'] ?? 'on-site') !== 'online') {
                continue;
            }

            $termId = (int) ($row['term_id'] ?? 0);
            $departmentId = (int) ($row['department_id'] ?? 0);
            $day = (string) ($row['day'] ?? '');
            if ($termId <= 0 || $departmentId <= 0 || $day === '') {
                continue;
            }

            $groups["{$termId}:{$departmentId}:{$day}"][] = [
                'index' => $index,
                'term_id' => $termId,
                'department_id' => $departmentId,
                'day' => $day,
                'start' => $this->timeToMinutes((string) $row['start_time']),
                'end' => $this->timeToMinutes((string) $row['end_time']),
            ];
        }

        if ($groups === []) {
            return [];
        }

        $termIds = [];
        $departmentIds = [];
        $days = [];
        foreach ($groups as $items) {
            foreach ($items as $item) {
                $termIds[$item['term_id']] = $item['term_id'];
                $departmentIds[$item['department_id']] = $item['department_id'];
                $days[$item['day']] = $item['day'];
            }
        }

        $existingSchedules = Schedule::query()
            ->where('mode', 'online')
            ->whereIn('term_id', array_values($termIds))
            ->whereIn('department_id', array_values($departmentIds))
            ->whereIn('day', array_values($days))
            ->get(['id', 'term_id', 'department_id', 'day', 'start_time', 'end_time']);

        foreach ($existingSchedules as $schedule) {
            $groups["{$schedule->term_id}:{$schedule->department_id}:{$schedule->day}"][] = [
                'index' => null,
                'schedule_id' => (int) $schedule->id,
                'department_id' => (int) $schedule->department_id,
                'day' => (string) $schedule->day,
                'start' => $this->timeToMinutes((string) $schedule->start_time),
                'end' => $this->timeToMinutes((string) $schedule->end_time),
            ];
        }

        foreach ($groups as $items) {
            $events = [];
            foreach ($items as $item) {
                $events[] = ['minute' => $item['start'], 'delta' => 1, 'item' => $item];
                $events[] = ['minute' => $item['end'], 'delta' => -1, 'item' => $item];
            }

            usort(
                $events,
                static fn (array $left, array $right): int => ($left['minute'] <=> $right['minute']) ?: ($left['delta'] <=> $right['delta']),
            );

            $active = [];
            $reported = [];
            foreach ($events as $event) {
                $item = $event['item'];
                $activeKey = $item['index'] ?? "existing:{$item['schedule_id']}";
                if ($event['delta'] < 0) {
                    unset($active[$activeKey]);

                    continue;
                }

                $active[$activeKey] = $item;
                $onlineLimit = $this->resourceLimits->online((int) ($item['department_id'] ?? 0));
                if ($item['index'] === null || count($active) <= $onlineLimit || isset($reported[$item['index']])) {
                    continue;
                }

                $violations[] = [
                    'rule' => 'online_capacity_conflict',
                    'message' => "Recommended row {$item['index']} exceeds the configured online slot limit of {$onlineLimit} for this department on {$item['day']}.",
                    'recommendation_row' => $item['index'],
                ];
                $reported[$item['index']] = true;
            }
        }

        return $violations;
    }

    private function timesOverlap(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        return $leftStart < $rightEnd && $rightStart < $leftEnd;
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    private function batchViolation(string $rule, int $leftIndex, int $rightIndex): array
    {
        return [
            'rule' => $rule,
            'message' => "Recommended rows {$leftIndex} and {$rightIndex} overlap before they can be accepted.",
            'recommendation_rows' => [$leftIndex, $rightIndex],
        ];
    }

    public function autoGenerateAndApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        if (! $this->canManageDepartment($request, (int) $section->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        try {
            $resolvedCourseIds = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $validated['course_ids'] = $resolvedCourseIds;
            $validated['max_solutions'] = 5;
            $profile = $this->preflight->validate($section, $resolvedCourseIds, $validated);
            $validated['requirements_by_course_id'] = $this->requirementBuilders->build($section, $resolvedCourseIds, $validated);

            $solutions = $this->candidateOptimizer->rankForSection(
                $this->cspSolver->solveRankedFromSchema($validated),
                $section,
                $validated,
            );
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (empty($solutions)) {
            return response()->json([
                'message' => 'No valid schedule could be generated that satisfies all constraints for this section.',
            ], 422);
        }

        Schedule::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->whereIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
            ->delete();

        ScheduleRecommendation::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->delete();

        $bestSolution = $solutions[0];
        $user = $request->user();

        try {
            $createdSchedules = DB::transaction(function () use ($bestSolution, $section, $validated, $user) {
                $recommendation = ScheduleRecommendation::create([
                    'term_id' => (int) $section->term_id,
                    'section_id' => (int) $section->id,
                    'department_id' => (int) $section->department_id,
                    'requested_by' => $user?->id,
                    'accepted_by' => $user?->id,
                    'accepted_at' => now(),
                    'rank' => (int) $bestSolution['rank'],
                    'score' => (int) $bestSolution['score'],
                    'status' => 'accepted',
                    'input_payload' => $validated,
                    'recommended_schedules' => $bestSolution['schedules'],
                ]);

                $rows = $this->normalizeRecommendedSchedules($recommendation);
                $this->validateBatchConflicts($rows);

                $courseIdsToCreate = array_values(array_unique(array_map(
                    static fn (array $r): int => (int) $r['course_id'],
                    $rows,
                )));

                Schedule::query()
                    ->where('term_id', $rows[0]['term_id'])
                    ->where('section_id', $rows[0]['section_id'])
                    ->whereIn('course_id', $courseIdsToCreate)
                    ->whereIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
                    ->delete();

                $createdIds = [];
                foreach ($rows as $row) {
                    $s = Schedule::create($row);
                    $createdIds[] = $s->id;
                }

                $schedules = Schedule::query()
                    ->whereIn('id', $createdIds)
                    ->with([
                        'term',
                        'section',
                        'course',
                        'faculty',
                        'room',
                        'department',
                    ])
                    ->get();

                $this->recordAudit(
                    action: 'recommendation_auto_applied',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'created_schedule_ids' => $schedules->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    ],
                );

                return $schedules;
            });
        } catch (RecommendationConflictException $exception) {
            return response()->json([
                'message' => 'Generated schedule conflicts with existing schedules.',
                'violations' => $exception->violations,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Schedule generated and placed into Timetable Grid successfully.',
            'department_profile' => $profile->value,
            'schedules' => $createdSchedules,
        ]);
    }

    private function recordAudit(
        string $action,
        ?int $userId,
        ScheduleRecommendation $recommendation,
        array $metadata = [],
    ): void {
        SchedulingAuditLog::create([
            'user_id' => $userId,
            'schedule_recommendation_id' => $recommendation->id,
            'term_id' => $recommendation->term_id,
            'section_id' => $recommendation->section_id,
            'department_id' => $recommendation->department_id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function departmentScope(Request $request): ?int
    {
        $user = $request->user();

        if (! $user || $user->isVpaa() || $user->department_id === null) {
            return null;
        }

        return (int) $user->department_id;
    }

    private function canManageDepartment(Request $request, int $departmentId): bool
    {
        $scope = $this->departmentScope($request);

        return $scope === null || $scope === $departmentId;
    }

    private function departmentForbiddenResponse(): JsonResponse
    {
        return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
    }

    private function allowLongRunningGeneration(int $seconds): void
    {
        @ini_set('max_execution_time', (string) $seconds);
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }
}

class RecommendationConflictException extends RuntimeException
{
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Recommendation conflict.');
    }
}
