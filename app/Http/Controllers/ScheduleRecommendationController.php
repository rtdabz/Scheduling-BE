<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationConfigurationConfirmationException;
use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\SchedulePlanCommitException;
use App\Exceptions\YearLevelGenerationException;
use App\Jobs\GenerateSectionSchedulePreview;
use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRecommendation;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\PreparedGenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\ScheduleRecommendationPayload;
use App\Services\Scheduling\Generation\GenerationCourseSelection;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Generation\GenerateSectionSchedulePlans;
use App\Services\Scheduling\Generation\ScheduleGenerationPreflightService;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Schedule\CommitSchedulePlan;
use App\Services\Scheduling\Schedule\PreviewedPlanStore;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use App\Services\Scheduling\Schedule\SplitScheduleService;
use App\Services\Scheduling\Support\SchedulingMetricsReporter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\YearLevel\YearLevelGenerationEligibilityService;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use App\Services\SystemNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ScheduleRecommendationController extends Controller
{
    private const GENERATION_RUN_TIMEOUT_SECONDS = 180;

    // Keep this below the client's 190-second polling deadline so an
    // unconsumed scheduling job reaches a durable terminal state.
    private const GENERATION_QUEUE_STALE_SECONDS = 180;

    private const REPLACEABLE_SCHEDULE_STATUSES = ['draft', 'completed', 'revision'];

    private const YEAR_LEVEL_PREVIEW_EXECUTION_SECONDS = 150;

    public function __construct(
        private readonly SplitScheduleService $splitScheduleService,
        private readonly YearLevelScheduleGenerationService $yearLevelGenerator,
        private readonly YearLevelGenerationEligibilityService $yearLevelEligibility,
        private readonly ScheduleGenerationPreflightService $preflight,
        private readonly ScheduleRequirementBuilderResolver $requirementBuilders,
        private readonly SystemNotificationService $notifications,
        private readonly SchedulingMetricsReporter $metricsReporter,
        private readonly GenerateSectionSchedulePlans $sectionGeneration,
        private readonly CommitSchedulePlan $planCommitter,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly SectionCurriculumResolver $curriculumResolver,
        private readonly PreviewedPlanStore $previewedPlans,
        private readonly GenerationCourseSelection $courseSelection,
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
            if (! $this->authorization->payloadBelongsToDepartment($request, (int) $section->department_id)) {
                return $this->departmentForbiddenResponse();
            }
        }

        $recommendations = ScheduleRecommendation::with(['section', 'academicSemester', 'department', 'requester'])
            ->when(($scope = $this->authorization->departmentScope($request)) !== null, fn ($query) => $query->where('department_id', $scope))
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
            'semester_id' => 'required|integer|exists:semesters,id',
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

        if (($guard = $this->departmentGuard($request, (int) $validated['department_id'])) !== null) {
            return $guard;
        }

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);
        if ((int) $section->department_id !== (int) $validated['department_id']) {
            return response()->json(['message' => 'Schedule department must match the selected section department.'], 422);
        }

        try {
            $result = $this->splitScheduleService->recommend(
                semesterId: (int) $validated['semester_id'],
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
            'anchored_schedules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
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
            ...$this->configurationConfirmationRules(),
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        try {
            $this->assertActiveSectionSemester($section);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (($guard = $this->departmentGuard($request, (int) $section->department_id)) !== null) {
            return $guard;
        }

        try {
            $validated['course_ids'] = $this->courseSelection->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $generated = $this->sectionGeneration->generate($section, $validated);
            $profile = $generated->profile;
            $preparedConfiguration = $generated->preparedConfiguration;
            $solutions = $generated->solutions;
            $plans = $generated->plans;
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (GenerationConfigurationConfirmationException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $user = $request->user();

        $recommendations = DB::transaction(function () use ($solutions, $plans, $section, $validated, $user, $preparedConfiguration) {
            if ($solutions !== []) {
                // Keep replacement atomic with recommendation creation. If a
                // recommendation insert fails, the previous editable schedule
                // and its pending options remain available.
                $replacedIds = Schedule::where('section_id', $section->id)
                    ->where('semester_id', $section->semester_id)
                    ->whereIn('status', self::REPLACEABLE_SCHEDULE_STATUSES)
                    ->pluck('id');

                Schedule::whereIn('id', $replacedIds)->delete();
                // A bulk delete fires no model events, so the split rows have
                // to be retired explicitly or they outlive their schedules.
                Schedule::retireSplitsFor($replacedIds);

                ScheduleRecommendation::where('section_id', $section->id)
                    ->where('semester_id', $section->semester_id)
                    ->delete();
            }

            $created = [];

            foreach ($solutions as $solution) {
                $plan = collect($plans)->first(
                    static fn (SchedulePlan $candidate): bool => ($solution['plan_id'] ?? null) === $candidate->planId
                        || (int) ($candidate->metadata['rank'] ?? 0) === (int) $solution['rank'],
                );
                $recommendation = ScheduleRecommendation::create([
                    'semester_id' => (int) $section->semester_id,
                    'section_id' => (int) $section->id,
                    'department_id' => (int) $section->department_id,
                    'requested_by' => $user?->id,
                    'rank' => (int) $solution['rank'],
                    'score' => (int) $solution['score'],
                    'status' => 'pending',
                    'input_payload' => ScheduleRecommendationPayload::fromPrepared(
                        $validated,
                        $preparedConfiguration,
                        $plan,
                    )->toArray(),
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

                $created[] = $recommendation->load(['section', 'academicSemester', 'department', 'requester']);
            }

            return $created;
        });

        if ($recommendations !== []) {
            $section->loadMissing(['department', 'academicSemester']);
            $this->notifications->notifyRoles(
                ['secretary', 'program_head', 'dean'],
                'schedule_generation_completed',
                'Schedule generation completed',
                $this->notifications->departmentWorkflowMessage(
                    'generated schedule recommendations for',
                    $section->department,
                    $section->academicSemester,
                    $user,
                    count($recommendations),
                ),
                $user,
                (int) $section->department_id,
                (int) $section->semester_id,
                null,
                [
                    'section_id' => $section->id,
                    'recommendations_generated' => count($recommendations),
                    'search_limit_reached' => (bool) ($generated->generationMetrics['search_limit_reached'] ?? false),
                    'iterations_used' => (int) ($generated->generationMetrics['iterations'] ?? 0),
                ],
            );
        }

        return response()->json([
            'message' => $recommendations === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'search_limit_reached' => (bool) ($generated->generationMetrics['search_limit_reached'] ?? false),
            'iterations_used' => (int) ($generated->generationMetrics['iterations'] ?? 0),
            'generation_metrics' => $generated->generationMetrics,
            'configuration_contract' => $this->configurationContract($preparedConfiguration),
            'recommendations' => $recommendations,
            'schedule_plans' => array_map(static fn (SchedulePlan $plan): array => $plan->toArray(), $plans),
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
            'anchored_schedules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'anchored_schedules.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'anchored_schedules.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/', 'after:anchored_schedules.*.start_time'],
            'anchored_schedules.*.room_id' => 'nullable|integer|exists:rooms,id',
            'tentative_schedules' => 'sometimes|array',
            'tentative_schedules.*.id' => 'sometimes|integer|exists:schedules,id',
            'tentative_schedules.*.semester_id' => 'required|integer|exists:semesters,id',
            'tentative_schedules.*.section_id' => 'required|integer|exists:sections,id',
            'tentative_schedules.*.course_id' => 'required|integer|exists:courses,id',
            'tentative_schedules.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'tentative_schedules.*.room_id' => 'nullable|integer|exists:rooms,id',
            'tentative_schedules.*.department_id' => 'required|integer|exists:departments,id',
            'tentative_schedules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'tentative_schedules.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'tentative_schedules.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/', 'after:tentative_schedules.*.start_time'],
            'tentative_schedules.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('required'),
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
            'hybrid_split_course_ids' => 'sometimes|array',
            'hybrid_split_course_ids.*' => 'integer|exists:courses,id',
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'seed' => 'sometimes|integer',
            ...$this->configurationConfirmationRules(),
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        try {
            $this->assertActiveSectionSemester($section);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (($guard = $this->departmentGuard($request, (int) $section->department_id)) !== null) {
            return $guard;
        }

        try {
            $validated = [...$validated, ...$this->courseSelection->resolve($section, $validated)];
            $generated = $this->sectionGeneration->generate($section, $validated);
            $profile = $generated->profile;
            $preparedConfiguration = $generated->preparedConfiguration;
            $solutions = $generated->solutions;
            $plans = $generated->plans;
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (GenerationConfigurationConfirmationException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($request->user() !== null) {
            $this->previewedPlans->remember(
                (int) $request->user()->id,
                (int) $section->id,
                $validated,
                $generated,
                $this->configurationContract($preparedConfiguration),
            );
        }

        return response()->json([
            'message' => $solutions === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'search_limit_reached' => (bool) ($generated->generationMetrics['search_limit_reached'] ?? false),
            'iterations_used' => (int) ($generated->generationMetrics['iterations'] ?? 0),
            'generation_metrics' => $generated->generationMetrics,
            'configuration_contract' => $this->configurationContract($preparedConfiguration),
            'recommendations' => $solutions,
            'schedule_plans' => array_map(static fn (SchedulePlan $plan): array => $plan->toArray(), $plans),
        ]);
    }

    public function queuePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'split_session_enabled' => 'sometimes|boolean',
            'selected_split_session_course_ids' => 'sometimes|array',
            'selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'split_gec_enabled' => 'sometimes|boolean',
            'selected_gec_course_ids' => 'sometimes|array',
            'selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'hybrid_split_course_ids' => 'sometimes|array',
            'hybrid_split_course_ids.*' => 'integer|exists:courses,id',
            'delivery_modes_by_course_id' => 'sometimes|array',
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'seed' => 'sometimes|integer',
            ...$this->configurationConfirmationRules(),
        ]);

        $section = Sections::query()->findOrFail((int) $validated['section_id']);
        try {
            $this->assertActiveSectionSemester($section);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (($guard = $this->departmentGuard($request, (int) $section->department_id)) !== null) {
            return $guard;
        }

        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create([
            'run_id' => $runId,
            'requested_by' => $request->user()->id,
            'semester_id' => $section->semester_id,
            'department_id' => $section->department_id,
            'year_level' => (int) $section->year_level,
            'status' => 'queued',
        ]);
        GenerateSectionSchedulePreview::dispatch($runId, (int) $section->id, $validated)->onQueue('scheduling');

        return response()->json(['run_id' => $runId, 'status' => 'queued'], 202);
    }

    public function runAsyncSectionPreview(int $sectionId, array $input): array
    {
        $section = Sections::query()->findOrFail($sectionId);
        $this->assertActiveSectionSemester($section);
        $input = [...$input, ...$this->courseSelection->resolve($section, $input)];
        $generated = $this->sectionGeneration->generate($section, $input);
        $profile = $generated->profile;
        $preparedConfiguration = $generated->preparedConfiguration;
        $solutions = $generated->solutions;
        $plans = $generated->plans;

        return [
            'message' => $solutions === [] ? 'No recommendations found that satisfy the scheduling constraints.' : 'Schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'search_limit_reached' => (bool) ($generated->generationMetrics['search_limit_reached'] ?? false),
            'iterations_used' => (int) ($generated->generationMetrics['iterations'] ?? 0),
            'generation_metrics' => $generated->generationMetrics,
            'configuration_contract' => $this->configurationContract($preparedConfiguration),
            'recommendations' => $solutions,
            'schedule_plans' => array_map(static fn (SchedulePlan $plan): array => $plan->toArray(), $plans),
        ];
    }

    public function yearLevelPreview(Request $request): JsonResponse
    {
        $this->allowLongRunningGeneration(self::YEAR_LEVEL_PREVIEW_EXECUTION_SECONDS);

        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semesters,id',
            'department_id' => 'required|integer|exists:departments,id',
            'year_level' => 'required|integer|min:1|max:4',
            'section_configs' => 'required|array|min:1',
            'section_configs.*.section_id' => 'required|integer|distinct|exists:sections,id',
            // What the client believed the section follows. Asserted, not
            // applied: a mismatch means the user is looking at a course list
            // from a different curriculum than the one now stored.
            'section_configs.*.curriculum_id' => 'sometimes|nullable|integer|exists:curriculum,id',
            'section_configs.*.course_ids' => 'sometimes|array|min:1',
            'section_configs.*.course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'section_configs.*.is_hybrid' => 'sometimes|boolean',
            'section_configs.*.selected_split_session_course_ids' => 'sometimes|array',
            'section_configs.*.selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.selected_gec_course_ids' => 'sometimes|array',
            'section_configs.*.selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.hybrid_split_course_ids' => 'sometimes|array',
            'section_configs.*.hybrid_split_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.preferred_patterns' => 'sometimes|array',
            'section_configs.*.preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'section_configs.*.delivery_modes_by_course_id' => 'sometimes|array',
            'section_configs.*.time_preferences_by_course_id' => 'sometimes|array',
            'section_configs.*.time_preferences_by_course_id.*' => 'nullable|in:morning,afternoon,evening',
            'section_configs.*.preferred_period' => 'sometimes|nullable|in:morning,afternoon,evening',
            'section_configs.*.preferred_periods_by_course_id' => 'sometimes|array',
            'section_configs.*.preferred_periods_by_course_id.*' => 'array',
            'section_configs.*.preferred_periods_by_course_id.*.*' => 'in:morning,afternoon,evening',
            'section_configs.*.allowed_days' => 'sometimes|nullable|array',
            'section_configs.*.allow_friday_saturday_split' => 'sometimes|boolean',
            'section_configs.*.allowed_days.*' => SchedulingPolicy::allowedDaysRule(),
            'section_configs.*.duration_minutes_by_course_id' => 'sometimes|array',
            'section_configs.*.duration_minutes_by_course_id.*' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.component_minutes_by_course_id' => 'sometimes|array',
            'section_configs.*.component_minutes_by_course_id.*' => 'array',
            'section_configs.*.component_minutes_by_course_id.*.lecture' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.component_minutes_by_course_id.*.laboratory' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.preferred_rooms_by_course_id' => 'sometimes|array',
            'section_configs.*.preferred_rooms_by_course_id.*' => 'integer|exists:rooms,id',
            'section_configs.*.delivery_modes_by_course_id.*' => SchedulingPolicy::allowedDeliveryModesRule('required'),
        ]);

        if (($guard = $this->departmentGuard($request, (int) $validated['department_id'])) !== null) {
            return $guard;
        }

        $semester = Semester::query()->findOrFail((int) $validated['semester_id']);
        if (! $semester->is_active) {
            return response()->json(['message' => 'Schedule generation is only available for the active academic semester.'], 422);
        }

        $sections = Sections::query()
            ->with('department')
            ->where('semester_id', (int) $validated['semester_id'])
            ->where('department_id', (int) $validated['department_id'])
            ->where('year_level', (string) $validated['year_level'])
            ->where('semester', (string) $semester->semester)
            ->where('status', 'active')
            ->orderBy('section_name')
            ->get();

        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No active sections were found for the selected year level.'], 422);
        }

        if (! $this->yearLevelEligibility->canGenerate($sections, (int) $validated['semester_id'])) {
            return response()->json(['message' => YearLevelGenerationEligibilityService::BLOCKED_MESSAGE], 422);
        }

        $configs = collect($validated['section_configs'])->keyBy(static fn (array $config): int => (int) $config['section_id']);
        $expectedSectionIds = $sections->pluck('id')->map('intval')->sort()->values()->all();
        $configuredSectionIds = $configs->keys()->map('intval')->sort()->values()->all();
        if ($expectedSectionIds !== $configuredSectionIds) {
            return response()->json(['message' => 'Provide one configuration for every active section in the selected year level.'], 422);
        }

        if (($stale = $this->rejectStaleCurriculumSelection($sections, $configs)) !== null) {
            return $stale;
        }

        $configsBySectionId = [];
        try {
            foreach ($sections as $section) {
                $config = $configs->get((int) $section->id);
                $selection = $this->courseSelection->resolve($section, $config);
                $courseIds = $selection['course_ids'];
                $splitIds = $selection['selected_split_session_course_ids'];
                $gecIds = $selection['balanced_split_course_ids'];
                $hybridSplitIds = $selection['hybrid_split_course_ids'];
                $preferredPatterns = $selection['preferred_patterns'];
                $sectionConfig = [
                    'course_ids' => $courseIds,
                    'mode' => (string) ($config['mode'] ?? 'on-site'),
                    'is_hybrid' => $selection['is_hybrid'],
                    'selected_split_session_course_ids' => $splitIds,
                    'balanced_split_course_ids' => $gecIds,
                    'hybrid_split_course_ids' => $hybridSplitIds,
                    'preferred_patterns' => $preferredPatterns,
                    'delivery_modes_by_course_id' => $config['delivery_modes_by_course_id'] ?? [],
                    'time_preferences_by_course_id' => $config['time_preferences_by_course_id'] ?? [],
                    'preferred_period' => $config['preferred_period'] ?? null,
                    'preferred_periods_by_course_id' => $config['preferred_periods_by_course_id'] ?? [],
                    'allowed_days' => SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null),
                    'allow_friday_saturday_split' => (bool) ($config['allow_friday_saturday_split'] ?? false),
                    'seed' => $this->yearLevelConfigSeed(
                        semesterId: (int) $validated['semester_id'],
                        departmentId: (int) $validated['department_id'],
                        yearLevel: (int) $validated['year_level'],
                        sectionId: (int) $section->id,
                        courseIds: $courseIds,
                        splitIds: $splitIds,
                        gecIds: $gecIds,
                        preferredPatterns: $preferredPatterns,
                    ),
                ];
                // Step 1's Preferred Days must leave room for every Required Day.
                CourseSetupOverrides::assertRequiredDaysAllowed($section, $courseIds, $sectionConfig['allowed_days']);
                // Setup Courses "Configure" choices, normalised to the shape
                // each course is generated in; refused here when the validator
                // would refuse the result at save time.
                $sectionConfig[CourseSetupOverrides::DURATIONS_KEY] = CourseSetupOverrides::normalizeDurations(
                    $section,
                    $config['duration_minutes_by_course_id'] ?? [],
                    $courseIds,
                    $sectionConfig,
                );
                // Integrated Hybrid: lecture and laboratory lengths, set separately.
                $sectionConfig[CourseSetupOverrides::COMPONENTS_KEY] = CourseSetupOverrides::normalizeComponents(
                    $section,
                    $config['component_minutes_by_course_id'] ?? [],
                    $courseIds,
                    $sectionConfig,
                );
                $sectionConfig[CourseSetupOverrides::PREFERRED_ROOMS_KEY] = CourseSetupOverrides::normalizePreferredRooms(
                    $section,
                    $config['preferred_rooms_by_course_id'] ?? [],
                    $courseIds,
                    $sectionConfig,
                );
                $profile = $this->preflight->validate($section, $courseIds, $sectionConfig);
                $sectionConfig['requirements_by_course_id'] = $this->requirementBuilders->build($section, $courseIds, $sectionConfig);
                $sectionConfig['department_profile'] = $profile->value;
                $configsBySectionId[(int) $section->id] = $sectionConfig;
            }

            $result = $this->yearLevelGenerator->preview($sections->all(), $configsBySectionId);
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (YearLevelGenerationException $exception) {
            return response()->json(array_merge($exception->payload(), [
                'department_profile' => $profile->value ?? null,
                'year_level' => (int) $validated['year_level'],
                'sections' => $sections->map(fn (Sections $section): array => [
                    'id' => (int) $section->id,
                    'name' => (string) $section->section_name,
                ])->values(),
            ]), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Year-level schedule recommendations generated successfully.',
            'department_profile' => $profile->value,
            'year_level' => (int) $validated['year_level'],
            'applied_strategy' => $result['applied_strategy'] ?? null,
            'applied_adjustments' => $result['applied_adjustments'] ?? [],
            'generation_attempts' => $result['generation_attempts'] ?? [],
            'generation_changes' => $result['generation_changes'] ?? [],
            'recommendations' => $result['recommendations'] ?? [],
            'generation_metrics' => $result['generation_metrics'] ?? null,
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

    /** Queue the expensive year-level solve and return immediately. */
    public function queueYearLevelPreview(Request $request): JsonResponse
    {
        $request->merge(['async' => false]);
        // Reuse the same validation and preparation contract as the preview
        // endpoint without running the solver in this request.
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semesters,id',
            'department_id' => 'required|integer|exists:departments,id',
            'year_level' => 'required|integer|min:1|max:4',
            'section_configs' => 'required|array|min:1',
            'section_configs.*.section_id' => 'required|integer|distinct|exists:sections,id',
            // What the client believed the section follows. Asserted, not
            // applied: a mismatch means the user is looking at a course list
            // from a different curriculum than the one now stored.
            'section_configs.*.curriculum_id' => 'sometimes|nullable|integer|exists:curriculum,id',
            'section_configs.*.course_ids' => 'sometimes|array|min:1',
            'section_configs.*.course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'section_configs.*.is_hybrid' => 'sometimes|boolean',
            'section_configs.*.selected_split_session_course_ids' => 'sometimes|array',
            'section_configs.*.selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.selected_gec_course_ids' => 'sometimes|array',
            'section_configs.*.selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.hybrid_split_course_ids' => 'sometimes|array',
            'section_configs.*.hybrid_split_course_ids.*' => 'integer|exists:courses,id',
            'section_configs.*.preferred_patterns' => 'sometimes|array',
            'section_configs.*.delivery_modes_by_course_id' => 'sometimes|array',
            'section_configs.*.time_preferences_by_course_id' => 'sometimes|array',
            'section_configs.*.time_preferences_by_course_id.*' => 'nullable|in:morning,afternoon,evening',
            'section_configs.*.preferred_period' => 'sometimes|nullable|in:morning,afternoon,evening',
            'section_configs.*.preferred_periods_by_course_id' => 'sometimes|array',
            'section_configs.*.preferred_periods_by_course_id.*' => 'array',
            'section_configs.*.preferred_periods_by_course_id.*.*' => 'in:morning,afternoon,evening',
            'section_configs.*.allowed_days' => 'sometimes|nullable|array',
            'section_configs.*.allow_friday_saturday_split' => 'sometimes|boolean',
            'section_configs.*.allowed_days.*' => SchedulingPolicy::allowedDaysRule(),
            'section_configs.*.duration_minutes_by_course_id' => 'sometimes|array',
            'section_configs.*.duration_minutes_by_course_id.*' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.component_minutes_by_course_id' => 'sometimes|array',
            'section_configs.*.component_minutes_by_course_id.*' => 'array',
            'section_configs.*.component_minutes_by_course_id.*.lecture' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.component_minutes_by_course_id.*.laboratory' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES.'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'section_configs.*.preferred_rooms_by_course_id' => 'sometimes|array',
            'section_configs.*.preferred_rooms_by_course_id.*' => 'integer|exists:rooms,id',
        ]);
        if (($guard = $this->departmentGuard($request, (int) $validated['department_id'])) !== null) {
            return $guard;
        }
        $semester = Semester::query()->findOrFail((int) $validated['semester_id']);
        if (! $semester->is_active) {
            return response()->json(['message' => 'Schedule generation is only available for the active academic semester.'], 422);
        }
        $sections = Sections::query()->with('department')->where('semester_id', $validated['semester_id'])
            ->where('department_id', $validated['department_id'])
            ->where('year_level', (string) $validated['year_level'])
            ->where('semester', (string) $semester->semester)
            ->where('status', 'active')->orderBy('section_name')->get();
        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No active sections were found for the selected year level.'], 422);
        }
        if (! $this->yearLevelEligibility->canGenerate($sections, (int) $validated['semester_id'])) {
            return response()->json(['message' => YearLevelGenerationEligibilityService::BLOCKED_MESSAGE], 422);
        }
        $configs = collect($validated['section_configs'])->keyBy(fn (array $config): int => (int) $config['section_id']);
        if (($stale = $this->rejectStaleCurriculumSelection($sections, $configs)) !== null) {
            return $stale;
        }
        $configsBySectionId = [];
        foreach ($sections as $section) {
            $config = $configs->get((int) $section->id);
            if ($config === null) {
                return response()->json(['message' => 'Provide one configuration for every active section.'], 422);
            }
            $selection = $this->courseSelection->resolve($section, $config);
            $courseIds = $selection['course_ids'];
            $splitIds = $selection['selected_split_session_course_ids'];
            $gecIds = $selection['balanced_split_course_ids'];
            $hybridSplitIds = $selection['hybrid_split_course_ids'];
            $preferredPatterns = $selection['preferred_patterns'];
            $sectionConfig = [
                'course_ids' => $courseIds,
                'mode' => (string) ($config['mode'] ?? 'on-site'),
                'is_hybrid' => $selection['is_hybrid'],
                'selected_split_session_course_ids' => $splitIds, 'balanced_split_course_ids' => $gecIds,
                'hybrid_split_course_ids' => $hybridSplitIds,
                'preferred_patterns' => $preferredPatterns, 'delivery_modes_by_course_id' => $config['delivery_modes_by_course_id'] ?? [],
                'time_preferences_by_course_id' => $config['time_preferences_by_course_id'] ?? [],
                'preferred_period' => $config['preferred_period'] ?? null,
                'preferred_periods_by_course_id' => $config['preferred_periods_by_course_id'] ?? [],
                'allowed_days' => SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null),
                'allow_friday_saturday_split' => (bool) ($config['allow_friday_saturday_split'] ?? false),
                'seed' => $this->yearLevelConfigSeed((int) $validated['semester_id'], (int) $validated['department_id'], (int) $validated['year_level'], (int) $section->id, $courseIds, $splitIds, $gecIds, $preferredPatterns),
            ];
            // Step 1's Preferred Days must leave room for every Required Day.
            CourseSetupOverrides::assertRequiredDaysAllowed($section, $courseIds, $sectionConfig['allowed_days']);
            // Setup Courses "Configure" choices, normalised to the shape
            // each course is generated in; refused here when the validator
            // would refuse the result at save time.
            $sectionConfig[CourseSetupOverrides::DURATIONS_KEY] = CourseSetupOverrides::normalizeDurations(
                $section,
                $config['duration_minutes_by_course_id'] ?? [],
                $courseIds,
                $sectionConfig,
            );
            // Integrated Hybrid: lecture and laboratory lengths, set separately.
            $sectionConfig[CourseSetupOverrides::COMPONENTS_KEY] = CourseSetupOverrides::normalizeComponents(
                $section,
                $config['component_minutes_by_course_id'] ?? [],
                $courseIds,
                $sectionConfig,
            );
            $sectionConfig[CourseSetupOverrides::PREFERRED_ROOMS_KEY] = CourseSetupOverrides::normalizePreferredRooms(
                $section,
                $config['preferred_rooms_by_course_id'] ?? [],
                $courseIds,
                $sectionConfig,
            );
            $profile = $this->preflight->validate($section, $courseIds, $sectionConfig);
            $sectionConfig['requirements_by_course_id'] = $this->requirementBuilders->build($section, $courseIds, $sectionConfig);
            $sectionConfig['department_profile'] = $profile->value;
            $configsBySectionId[(int) $section->id] = $sectionConfig;
        }
        $runId = (string) Str::uuid();
        ScheduleGenerationRun::create(['run_id' => $runId, 'requested_by' => $request->user()->id, 'semester_id' => $validated['semester_id'], 'department_id' => $validated['department_id'], 'year_level' => $validated['year_level'], 'status' => 'queued']);
        GenerateYearLevelSchedulePreview::dispatch(
            $runId,
            $sections->pluck('id')->map('intval')->values()->all(),
            $configsBySectionId,
        )->onQueue('scheduling');

        return response()->json(['run_id' => $runId, 'status' => 'queued'], 202);
    }

    public function generationRun(Request $request, string $runId): JsonResponse
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        if ($request->user()->role !== 'vpaa' && (int) $run->requested_by !== (int) $request->user()->id) {
            return $this->departmentForbiddenResponse();
        }

        return response()->json($this->reconcileOrphanedRun($run));
    }

    /**
     * Stop a run the caller owns.
     *
     * Cancellation is cooperative: this marks the durable run terminal, and
     * the worker notices at its next placement boundary and unwinds. A run
     * still waiting on the queue is also removed from the queue table so no
     * worker picks up work whose result is already discarded.
     */
    public function cancelGenerationRun(Request $request, string $runId): JsonResponse
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        if ($request->user()->role !== 'vpaa' && (int) $run->requested_by !== (int) $request->user()->id) {
            return $this->departmentForbiddenResponse();
        }

        // Cancelling a run that already finished is a no-op, not an error: the
        // user clicked while the last poll was still in flight.
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return response()->json($run);
        }

        $wasQueued = $run->status === 'queued';
        $cancelled = ScheduleGenerationRun::query()
            ->where('run_id', $runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'cancelled',
                'error_message' => 'Generation was cancelled by the requester.',
                'finished_at' => now(),
            ]);

        // Only an unreserved job is safe to delete; a reserved one belongs to a
        // worker that will stop on its own at the next cancellation check.
        if ($cancelled > 0 && $wasQueued) {
            DB::table('jobs')
                ->where('queue', 'scheduling')
                ->whereNull('reserved_at')
                ->where('payload', 'like', '%'.$runId.'%')
                ->delete();
        }

        return response()->json($run->refresh());
    }

    /**
     * The newest still-active run the caller owns for a department and semester.
     *
     * Progress tracking lives outside the generator modal, so a reload or a
     * closed panel must be able to find the run again. Ownership matches the
     * single-run endpoint: a run belongs to whoever requested it.
     */
    public function activeGenerationRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'semester_id' => 'required|integer|exists:semesters,id',
        ]);

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return $this->departmentForbiddenResponse();
        }

        $run = ScheduleGenerationRun::query()
            ->where('department_id', (int) $validated['department_id'])
            ->where('semester_id', (int) $validated['semester_id'])
            ->where('requested_by', (int) $request->user()->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($run === null) {
            return response()->json(['run' => null]);
        }

        // Reconcile before answering: an orphaned run must not be reported as
        // active work the caller is still waiting on.
        $run = $this->reconcileOrphanedRun($run);

        return response()->json([
            'run' => in_array($run->status, ['queued', 'running'], true) ? $run : null,
        ]);
    }

    /**
     * A worker can be terminated by its timeout before the queued job's
     * exception handler runs. Reconcile an orphaned active run on read so the
     * durable status reflects the actual lifecycle outcome.
     */
    private function reconcileOrphanedRun(ScheduleGenerationRun $run): ScheduleGenerationRun
    {
        if (! in_array($run->status, ['queued', 'running'], true) || $run->finished_at !== null) {
            return $run;
        }

        $reference = $run->started_at ?? $run->created_at;
        $staleAfter = $run->status === 'running'
            ? self::GENERATION_RUN_TIMEOUT_SECONDS
            : self::GENERATION_QUEUE_STALE_SECONDS;
        if ($reference === null || ! $reference->lt(now()->subSeconds($staleAfter))) {
            return $run;
        }

        $wasQueued = $run->status === 'queued';
        $errorMessage = $wasQueued
            ? 'Year-level generation did not start within the queue wait limit. Verify that a worker is consuming the scheduling queue.'
            : 'Year-level generation exceeded its execution time limit.';
        $run->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);

        // A queued run can outlive its durable status when polling expires it
        // before a worker claims the job. Remove only the still-unreserved
        // queue record for this run so the queue and generation-run tables do
        // not report different lifecycles.
        if ($wasQueued) {
            DB::table('jobs')
                ->where('queue', 'scheduling')
                ->whereNull('reserved_at')
                ->where('payload', 'like', '%'.$run->run_id.'%')
                ->delete();
        }
        $run->refresh();

        return $run;
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
            'split_session_enabled' => 'sometimes|boolean',
            'selected_split_session_course_ids' => 'sometimes|array',
            'selected_split_session_course_ids.*' => 'integer|exists:courses,id',
            'split_gec_enabled' => 'sometimes|boolean',
            'selected_gec_course_ids' => 'sometimes|array',
            'selected_gec_course_ids.*' => 'integer|exists:courses,id',
            'tentative_schedules' => 'sometimes|array',
            'tentative_schedules.*.id' => 'sometimes|integer|exists:schedules,id',
            'tentative_schedules.*.semester_id' => 'required|integer|exists:semesters,id',
            'tentative_schedules.*.section_id' => 'required|integer|exists:sections,id',
            'tentative_schedules.*.course_id' => 'required|integer|exists:courses,id',
            'tentative_schedules.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'tentative_schedules.*.room_id' => 'nullable|integer|exists:rooms,id',
            'tentative_schedules.*.department_id' => 'required|integer|exists:departments,id',
            'tentative_schedules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'tentative_schedules.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'tentative_schedules.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/', 'after:tentative_schedules.*.start_time'],
            'tentative_schedules.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('required'),
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'selected_rank' => 'required|integer|min:1|max:5',
            'plan_id' => 'sometimes|nullable|string|max:64',
            'seed' => 'sometimes|integer',
            ...$this->configurationConfirmationRules(),
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        try {
            $this->assertActiveSectionSemester($section);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (($guard = $this->departmentGuard($request, (int) $section->department_id)) !== null) {
            return $guard;
        }

        $selectedRank = (int) $validated['selected_rank'];

        // Save exactly the plan the user previewed and compared. Re-running the
        // solver could return different rows for the same rank, because the
        // search stops on a wall-clock limit.
        $previewed = isset($validated['plan_id']) && $request->user() !== null
            ? $this->previewedPlans->find((string) $validated['plan_id'], (int) $request->user()->id, (int) $section->id)
            : null;
        if ($previewed !== null && (int) ($previewed['solution']['rank'] ?? 0) === $selectedRank) {
            return $this->storeSelectedRecommendation(
                $request,
                $section,
                $previewed['solution'],
                $previewed['input_payload'],
                $previewed['schedule_plan'],
                $previewed['department_profile'],
                $previewed['generation_metrics'],
                $previewed['configuration_contract'],
            );
        }

        // No remembered preview (expired, another server, or an older client):
        // generate again with the same input and seed.
        $solverInput = $validated;
        unset($solverInput['selected_rank'], $solverInput['plan_id']);

        try {
            $solverInput = [...$solverInput, ...$this->courseSelection->resolve($section, $solverInput)];
            $generated = $this->sectionGeneration->generate($section, $solverInput);
            $profile = $generated->profile;
            $preparedConfiguration = $generated->preparedConfiguration;
            $solutions = $generated->solutions;
            $plans = $generated->plans;
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (GenerationConfigurationConfirmationException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $selectedSolution = collect($solutions)->first(
            static fn (array $solution): bool => (int) $solution['rank'] === $selectedRank,
        );
        $selectedPlan = collect($plans)->first(
            static fn (SchedulePlan $plan): bool => ($selectedSolution['plan_id'] ?? null) === $plan->planId
                || (int) ($plan->metadata['rank'] ?? 0) === $selectedRank,
        );

        if ($selectedSolution === null) {
            return response()->json([
                'message' => 'The selected recommendation is no longer available. Please refresh the recommendations.',
            ], 422);
        }

        return $this->storeSelectedRecommendation(
            $request,
            $section,
            $selectedSolution,
            ScheduleRecommendationPayload::fromPrepared($solverInput, $preparedConfiguration, $selectedPlan)->toArray(),
            $selectedPlan?->toArray(),
            $profile->value,
            $generated->generationMetrics,
            $this->configurationContract($preparedConfiguration),
        );
    }

    /**
     * Records the chosen solution as a pending recommendation.
     *
     * @param  array<string, mixed>  $selectedSolution
     * @param  array<string, mixed>  $inputPayload
     * @param  array<string, mixed>|null  $schedulePlan
     * @param  array<string, mixed>  $generationMetrics
     * @param  array<string, mixed>  $configurationContract
     */
    private function storeSelectedRecommendation(
        Request $request,
        Sections $section,
        array $selectedSolution,
        array $inputPayload,
        ?array $schedulePlan,
        string $departmentProfile,
        array $generationMetrics,
        array $configurationContract,
    ): JsonResponse {
        $user = $request->user();

        $recommendation = DB::transaction(function () use ($selectedSolution, $inputPayload, $section, $user) {
            $recommendation = ScheduleRecommendation::create([
                'semester_id' => (int) $section->semester_id,
                'section_id' => (int) $section->id,
                'department_id' => (int) $section->department_id,
                'requested_by' => $user?->id,
                'rank' => (int) $selectedSolution['rank'],
                'score' => (int) $selectedSolution['score'],
                'status' => 'pending',
                'input_payload' => $inputPayload,
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

            return $recommendation->load(['section', 'academicSemester', 'department', 'requester']);
        });

        return response()->json([
            'message' => 'Schedule recommendation selected successfully.',
            'department_profile' => $departmentProfile,
            'generation_metrics' => $generationMetrics,
            'configuration_contract' => $configurationContract,
            'schedule_plan' => $schedulePlan,
            'recommendation' => $recommendation,
        ], 201);
    }

    public function show(ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->authorization->payloadBelongsToDepartment(request(), (int) $scheduleRecommendation->department_id)) {
            return $this->departmentForbiddenResponse();
        }

        return response()->json($scheduleRecommendation->load([
            'section',
            'academicSemester',
            'department',
            'requester',
            'accepter',
            'rejecter',
        ]));
    }

    private function recommendationPlan(ScheduleRecommendation $recommendation): ?SchedulePlan
    {
        $payload = is_array($recommendation->input_payload) ? $recommendation->input_payload : [];
        // Order 4 only accepts recommendations that carry the immutable,
        // versioned plan envelope. Historical row-only payloads are rejected
        // until the dedicated Order 5 migration reconstructs them explicitly.
        if (! ScheduleRecommendationPayload::isVersioned($payload)) {
            return null;
        }

        return ScheduleRecommendationPayload::fromArray($payload)->schedulePlan;
    }

    public function review(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $scheduleRecommendation->department_id)) {
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
                'academicSemester',
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
        if (($guard = $this->departmentGuard($request, (int) $scheduleRecommendation->department_id)) !== null) {
            return $guard;
        }

        $user = $request->user();

        // New recommendations carry the exact immutable plan generated during
        // preview. Commit it through the shared persistence boundary so stale
        // snapshots and final constraint violations are rechecked uniformly.
        try {
            $plan = $this->recommendationPlan($scheduleRecommendation);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($plan !== null) {
            try {
                // Keep schedule persistence and recommendation state changes in
                // one database transaction. A committed schedule must never
                // be visible while its recommendation remains pending.
                [$committedPlan, $recommendation, $createdIds] = DB::transaction(function () use ($plan, $scheduleRecommendation, $user) {
                    $recommendation = ScheduleRecommendation::query()
                        ->whereKey($scheduleRecommendation->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ($recommendation->status !== 'pending') {
                        throw new InvalidArgumentException('Only pending recommendations can be accepted.');
                    }

                    $committedPlan = $this->planCommitter->commit($plan, $user?->id);
                    $createdIds = array_values(array_map(
                        'intval',
                        $committedPlan->metadata['created_schedule_ids'] ?? [],
                    ));
                    $recommendation->update([
                        'status' => 'accepted',
                        'accepted_by' => $user?->id,
                        'accepted_at' => now(),
                    ]);
                    $this->recordAudit(
                        action: 'recommendation_accepted',
                        userId: $user?->id,
                        recommendation: $recommendation,
                        metadata: ['created_schedule_ids' => $createdIds, 'plan_id' => $recommendation->input_payload['_scheduling']['schedule_plan']['plan_id'] ?? null],
                    );

                    return [
                        $committedPlan,
                        $recommendation->fresh(['section', 'academicSemester', 'department', 'requester', 'accepter']),
                        $createdIds,
                    ];
                });

                return response()->json([
                    'message' => 'Recommendation accepted and schedules created successfully.',
                    'recommendation' => $recommendation,
                    'schedules' => Schedule::query()->whereIn('id', $createdIds)->with(['academicSemester', 'section', 'course', 'faculty', 'room', 'department'])->get(),
                    'schedule_plan' => $committedPlan,
                ]);
            } catch (SchedulePlanCommitException|InvalidArgumentException|RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        return response()->json([
            'message' => 'This recommendation does not contain a migrated schedule plan. Regenerate the recommendation before accepting it.',
        ], 422);
    }

    public function reject(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $scheduleRecommendation->department_id)) {
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
                    'academicSemester',
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

    /**
     * Resolve which courses to schedule for a section. If the caller
     * explicitly supplied course_ids, use those (manual override still
     * allowed). Otherwise, derive the list from the section's department's
     * ACTIVE curriculum, filtered to the section's year_level and semester —
     * Curriculum is the source of truth for what should be scheduled.
     */
    /**
     * Refuses a run whose client was looking at a different curriculum than the
     * one the section now follows.
     *
     * Generation is configured against a course list, and that list only means
     * anything relative to a curriculum. If someone reassigns the year level
     * while another user has the wizard open, silently generating against the
     * new curriculum would produce a timetable for courses that user never saw.
     *
     * @param  Collection<int, Sections>  $sections
     * @param  Collection<int, array<string, mixed>>  $configs
     */
    private function rejectStaleCurriculumSelection($sections, $configs): ?JsonResponse
    {
        foreach ($sections as $section) {
            $config = $configs->get((int) $section->id);
            $claimed = $config['curriculum_id'] ?? null;

            if ($claimed === null) {
                continue;
            }

            if ((int) $claimed !== (int) $section->curriculum_id) {
                return response()->json([
                    'message' => sprintf(
                        'Section %s now follows a different curriculum than the one this setup was built from. Reload the generator and choose the curriculum again.',
                        (string) $section->section_name,
                    ),
                    'code' => 'curriculum_selection_stale',
                    'section_id' => (int) $section->id,
                    'expected_curriculum_id' => $section->curriculum_id === null ? null : (int) $section->curriculum_id,
                ], 409);
            }
        }

        return null;
    }

    private function yearLevelConfigSeed(
        int $semesterId,
        int $departmentId,
        int $yearLevel,
        int $sectionId,
        array $courseIds,
        array $splitIds,
        array $gecIds,
        array $preferredPatterns = [],
    ): int {
        $payload = implode('|', [
            $semesterId,
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
            ...$this->configurationConfirmationRules(),
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        try {
            $this->assertActiveSectionSemester($section);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (($guard = $this->departmentGuard($request, (int) $section->department_id)) !== null) {
            return $guard;
        }

        try {
            $resolvedCourseIds = $this->courseSelection->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $validated['course_ids'] = $resolvedCourseIds;
            $validated['max_solutions'] = 5;
            $generated = $this->sectionGeneration->generate($section, $validated);
            $profile = $generated->profile;
            $preparedConfiguration = $generated->preparedConfiguration;
            $solutions = $generated->solutions;
            $plans = $generated->plans;
        } catch (ScheduleGenerationPreflightException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (GenerationConfigurationConfirmationException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (empty($solutions)) {
            return response()->json([
                'message' => 'No valid schedule could be generated that satisfies all constraints for this section.',
                'generation_metrics' => $generated->generationMetrics,
            ], 422);
        }

        $bestSolution = $solutions[0];
        $user = $request->user();
        $bestPlan = collect($plans)->first(
            static fn (SchedulePlan $plan): bool => ($bestSolution['plan_id'] ?? null) === $plan->planId
                || (int) ($plan->metadata['rank'] ?? 0) === (int) $bestSolution['rank'],
        );

        if ($bestPlan !== null) {
            try {
                [$committedPlan, $recommendation, $createdIds] = DB::transaction(function () use ($bestPlan, $bestSolution, $preparedConfiguration, $section, $validated, $user) {
                    $recommendation = ScheduleRecommendation::create([
                        'semester_id' => (int) $section->semester_id,
                        'section_id' => (int) $section->id,
                        'department_id' => (int) $section->department_id,
                        'requested_by' => $user?->id,
                        'rank' => (int) $bestSolution['rank'],
                        'score' => (int) $bestSolution['score'],
                        'status' => 'pending',
                        'input_payload' => ScheduleRecommendationPayload::fromPrepared($validated, $preparedConfiguration, $bestPlan)->toArray(),
                        'recommended_schedules' => $bestSolution['schedules'],
                    ]);
                    $committedPlan = $this->planCommitter->commit($bestPlan, $user?->id);
                    $createdIds = array_values(array_map('intval', $committedPlan->metadata['created_schedule_ids'] ?? []));
                    $recommendation->update(['status' => 'accepted', 'accepted_by' => $user?->id, 'accepted_at' => now()]);

                    return [$committedPlan, $recommendation, $createdIds];
                });

                return response()->json([
                    'message' => 'Schedule generated and placed into Timetable Grid successfully.',
                    'department_profile' => $profile->value,
                    'generation_metrics' => $generated->generationMetrics,
                    'schedules' => Schedule::query()->whereIn('id', $createdIds)->with(['academicSemester', 'section', 'course', 'faculty', 'room', 'department'])->get(),
                    'recommendation' => $recommendation,
                    'schedule_plan' => $committedPlan,
                ]);
            } catch (SchedulePlanCommitException|InvalidArgumentException|RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        return response()->json([
            'message' => 'The generated result did not include a typed schedule plan. Regenerate the schedule before applying it.',
            'generation_metrics' => $generated->generationMetrics,
        ], 422);
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
            'semester_id' => $recommendation->semester_id,
            'section_id' => $recommendation->section_id,
            'department_id' => $recommendation->department_id,
            'action' => $action,
            'history_version_id' => $metadata['history_version_id'] ?? null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function configurationConfirmationRules(): array
    {
        return [
            'configuration_confirmation' => 'sometimes|array',
            'configuration_confirmation.schema_version' => 'required_with:configuration_confirmation|integer|in:1',
            'configuration_confirmation.configuration_fingerprint' => ['required_with:configuration_confirmation', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'configuration_confirmation.confirmed_warning_rule_ids' => 'required_with:configuration_confirmation|array',
            'configuration_confirmation.confirmed_warning_rule_ids.*' => 'string|max:100|distinct',
        ];
    }

    /** @return array<string, mixed> */
    private function configurationContract(PreparedGenerationConfiguration $prepared): array
    {
        return [
            'schema_version' => 1,
            'configuration_schema_version' => $prepared->configuration->schemaVersion,
            'configuration_fingerprint' => $prepared->configurationFingerprint,
            'snapshot_fingerprint' => $prepared->validation->snapshotFingerprint,
            'confirmed_warning_rule_ids' => $prepared->confirmedWarningRuleIds,
        ];
    }

    private function assertActiveSectionSemester(Sections $section): void
    {
        $semester = Semester::query()->find((int) $section->semester_id);
        if (! $semester?->is_active) {
            throw new InvalidArgumentException('Schedule generation is only available for the active academic semester.');
        }

        if ((string) $section->semester !== (string) $semester->semester) {
            throw new InvalidArgumentException('The selected section belongs to a different semester than the active academic semester.');
        }
    }

    /**
     * Ownership plus the program precondition, for the paths that build or
     * commit schedules.
     *
     * These are two different failures and must not collapse into one answer:
     * a caller from the wrong department is forbidden, while a caller from the
     * right department whose department has no program yet is merely missing a
     * setup step. Folding the second into the ownership check answered it with
     * "you can only manage schedules for your department", which is misleading
     * -- the department is correct. Read-only and recommendation-lifecycle
     * endpoints keep the ownership check alone; requiring a program to list or
     * reject a recommendation would gate reads on a scheduling precondition.
     *
     * Returns the response to send, or null when the caller may proceed.
     */
    private function departmentGuard(Request $request, int $departmentId): ?JsonResponse
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, $departmentId)) {
            return $this->departmentForbiddenResponse();
        }

        if (! $this->authorization->departmentHasProgram($departmentId)) {
            return $this->departmentMissingProgramResponse();
        }

        return null;
    }

    private function departmentForbiddenResponse(): JsonResponse
    {
        return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
    }

    private function departmentMissingProgramResponse(): JsonResponse
    {
        return response()->json(['message' => 'Create at least one Program under this Department before scheduling.'], 422);
    }

    private function allowLongRunningGeneration(int $seconds): void
    {
        @ini_set('max_execution_time', (string) $seconds);
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }
}
