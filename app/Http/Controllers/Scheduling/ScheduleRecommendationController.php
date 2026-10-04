<?php

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\ScheduleGenerationRun;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Generation\GenerationCourseSelection;
use App\Services\Scheduling\Generation\GenerationDraftReviewer;
use App\Services\Scheduling\Generation\ScheduleGenerationPreflightService;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Services\Scheduling\YearLevel\YearLevelGenerationEligibilityService;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
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

    private const GENERATION_QUEUE_STALE_SECONDS = 180;

    private const YEAR_LEVEL_PREVIEW_EXECUTION_SECONDS = 150;

    public function __construct(
        private readonly YearLevelScheduleGenerationService $yearLevelGenerator,
        private readonly YearLevelGenerationEligibilityService $yearLevelEligibility,
        private readonly ScheduleGenerationPreflightService $preflight,
        private readonly ScheduleRequirementBuilderResolver $requirementBuilders,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly GenerationCourseSelection $courseSelection,
    ) {}

    public function availableSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_id' => 'required|integer|exists:courses,id',
            'duration_slots' => 'required|integer|min:1|max:48',
            'modes' => 'sometimes|array|min:1',
            'modes.*' => SchedulingPolicy::allowedDeliveryModesRule('required'),
            'meeting_type' => 'sometimes|nullable|in:lecture,laboratory',
            'excluded_days' => 'sometimes|array',
            'excluded_days.*' => SchedulingPolicy::allowedDaysRule('required'),
            'search_from_day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'consecutive_days' => 'sometimes|nullable|integer|min:'.SchedulingPolicy::MIN_CONSECUTIVE_DAYS.'|max:'.count(SchedulingPolicy::DAYS),
            'ignore_schedule_ids' => 'sometimes|array',
            'ignore_schedule_ids.*' => 'integer',
            'tentative_schedules' => 'sometimes|array',
            'tentative_schedules.*.id' => 'sometimes|nullable|integer',
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
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        if (($guard = $this->departmentGuard($request, (int) $section->department_id, [(int) $section->id])) !== null) {
            return $guard;
        }

        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: (int) $section->semester_id,
            departmentId: (int) $section->department_id,
            sectionIds: [(int) $section->id],
            courseIds: [(int) $validated['course_id']],
        );

        $result = app(AvailableSlotFinder::class)->find(
            snapshot: $snapshot,
            sectionId: (int) $section->id,
            courseId: (int) $validated['course_id'],
            durationSlots: (int) $validated['duration_slots'],
            modes: $validated['modes'] ?? AvailableSlotFinder::MODES,
            tentativeSchedules: $validated['tentative_schedules'] ?? [],
            ignoreScheduleIds: array_map('intval', $validated['ignore_schedule_ids'] ?? []),
            meetingType: $validated['meeting_type'] ?? null,
            excludedDays: $validated['excluded_days'] ?? [],
            searchFromDay: $validated['search_from_day'] ?? null,
            consecutiveDays: isset($validated['consecutive_days']) ? (int) $validated['consecutive_days'] : null,
        );

        return response()->json($result);
    }

    public function reviewDraft(Request $request): JsonResponse
    {
        $time = ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'];
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semesters,id',
            'department_id' => 'required|integer|exists:departments,id',
            'section_ids' => 'required|array|min:1',
            'section_ids.*' => 'integer',
            'preferred_days' => 'sometimes|nullable|array',
            'preferred_days.*' => SchedulingPolicy::allowedDaysRule('required'),
            'rows' => 'present|array|max:3000',
            'rows.*.section_id' => 'required|integer',
            'rows.*.course_id' => 'required|integer',
            'rows.*.semester_id' => 'required|integer',
            'rows.*.department_id' => 'required|integer',
            'rows.*.faculty_id' => 'nullable|integer',
            'rows.*.room_id' => 'nullable|integer',
            'rows.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'rows.*.start_time' => $time,
            'rows.*.end_time' => $time,
            'rows.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('required'),
            'rows.*.is_hybrid' => 'sometimes|boolean',
            'rows.*.preferred_pattern' => 'nullable|string|max:20',
            'rows.*.split_group_id' => 'nullable|string|max:36',
            'rows.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'rows.*.meeting_index' => 'nullable|integer|min:1',
            'unplaced' => 'sometimes|array|max:500',
            'unplaced.*.section_id' => 'required|integer',
            'unplaced.*.course_id' => 'required|integer',
            'unplaced.*.reason' => 'nullable|string|max:500',
            'unplaced.*.shape' => 'nullable|in:split,online_split',
            'unplaced.*.meetings' => 'required|array|min:1|max:6',
            'unplaced.*.meetings.*.duration_slots' => 'required|integer|min:1|max:48',
            'unplaced.*.meetings.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'unplaced.*.meetings.*.modes' => 'sometimes|array',
            'unplaced.*.meetings.*.modes.*' => SchedulingPolicy::allowedDeliveryModesRule('required'),
        ]);

        $semesterId = (int) $validated['semester_id'];
        $departmentId = (int) $validated['department_id'];
        if (($guard = $this->departmentGuard($request, $departmentId, $validated['section_ids'])) !== null) {
            return $guard;
        }

        $sectionIds = array_values(array_unique(array_map('intval', $validated['section_ids'])));
        $ownedSections = Sections::query()
            ->whereIn('id', $sectionIds)
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->count();
        $foreignRow = collect([...$validated['rows'], ...($validated['unplaced'] ?? [])])
            ->contains(fn (array $row): bool => ! in_array((int) $row['section_id'], $sectionIds, true));
        if ($ownedSections !== count($sectionIds) || $foreignRow) {
            return response()->json([
                'message' => 'The draft may only hold sections of this department and semester.',
            ], 422);
        }

        return response()->json(app(GenerationDraftReviewer::class)->review(
            semesterId: $semesterId,
            departmentId: $departmentId,
            sectionIds: $sectionIds,
            rows: $validated['rows'],
            unplaced: $validated['unplaced'] ?? [],
            preferredDays: $validated['preferred_days'] ?? null,
        ));
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
            ...$this->ruleOverrideRules(),
        ]);

        if (($guard = $this->departmentGuard($request, (int) $validated['department_id'], [
            ...array_column($validated['section_configs'], 'section_id'),
            ...($validated['section_ids'] ?? []),
        ])) !== null) {
            return $guard;
        }

        return DepartmentCourseRules::withOverride((int) $validated['department_id'], $this->ruleOverrides($validated), function () use ($request, $validated) {
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
            ->whereIn('program_id', $this->authorization->writableProgramIds($request))
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
                CourseSetupOverrides::assertSundayAllowed($section, $sectionConfig['allowed_days']);
                CourseSetupOverrides::assertRequiredDaysAllowed($section, $courseIds, $sectionConfig['allowed_days']);
                $sectionConfig[CourseSetupOverrides::DURATIONS_KEY] = CourseSetupOverrides::normalizeDurations(
                    $section,
                    $config['duration_minutes_by_course_id'] ?? [],
                    $courseIds,
                    $sectionConfig,
                );
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
            'status' => $result['status'] ?? 'complete',
            'unplaced_courses' => $result['unplaced_courses'] ?? [],
            'sections' =>$sections->map(fn (Sections $section): array => [
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
        });
    }

    public function queueYearLevelPreview(Request $request): JsonResponse
    {
        $request->merge(['async' => false]);
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semesters,id',
            'department_id' => 'required|integer|exists:departments,id',
            'year_level' => 'required|integer|min:1|max:4',
            'section_ids' => 'sometimes|array|min:1',
            'section_ids.*' => 'integer|distinct',
            'section_configs' => 'required|array|min:1',
            'section_configs.*.section_id' => 'required|integer|distinct|exists:sections,id',
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
            ...$this->ruleOverrideRules(),
        ]);
        if (($guard = $this->departmentGuard($request, (int) $validated['department_id'], [
            ...array_column($validated['section_configs'], 'section_id'),
            ...($validated['section_ids'] ?? []),
        ])) !== null) {
            return $guard;
        }
        return DepartmentCourseRules::withOverride((int) $validated['department_id'], $this->ruleOverrides($validated), function () use ($request, $validated) {
        $semester = Semester::query()->findOrFail((int) $validated['semester_id']);
        if (! $semester->is_active) {
            return response()->json(['message' => 'Schedule generation is only available for the active academic semester.'], 422);
        }
        $requestedSectionIds = array_map('intval', $validated['section_ids'] ?? []);
        $sections = Sections::query()->with('department')->where('semester_id', $validated['semester_id'])
            ->where('department_id', $validated['department_id'])
            ->where('year_level', (string) $validated['year_level'])
            ->where('semester', (string) $semester->semester)
            ->where('status', 'active')
            ->whereIn('program_id', $this->authorization->writableProgramIds($request))
            ->when($requestedSectionIds !== [], fn ($query) => $query->whereIn('id', $requestedSectionIds))
            ->orderBy('section_name')->get();
        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No active sections were found for the selected year level.'], 422);
        }
        if ($requestedSectionIds !== [] && $sections->count() !== count($requestedSectionIds)) {
            return response()->json(['message' => 'Every selected section must be an active section of the selected year level.'], 422);
        }
        if (! $this->yearLevelEligibility->canGenerate($sections, (int) $validated['semester_id'])) {
            return response()->json(['message' => $requestedSectionIds !== []
                ? YearLevelGenerationEligibilityService::SECTIONS_BLOCKED_MESSAGE
                : YearLevelGenerationEligibilityService::BLOCKED_MESSAGE], 422);
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
                'allowed_days' => SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null),
                'allow_friday_saturday_split' => (bool) ($config['allow_friday_saturday_split'] ?? false),
                'seed' => $this->yearLevelConfigSeed((int) $validated['semester_id'], (int) $validated['department_id'], (int) $validated['year_level'], (int) $section->id, $courseIds, $splitIds, $gecIds, $preferredPatterns),
            ];
            CourseSetupOverrides::assertSundayAllowed($section, $sectionConfig['allowed_days']);
            CourseSetupOverrides::assertRequiredDaysAllowed($section, $courseIds, $sectionConfig['allowed_days']);
            $sectionConfig[CourseSetupOverrides::DURATIONS_KEY] = CourseSetupOverrides::normalizeDurations(
                $section,
                $config['duration_minutes_by_course_id'] ?? [],
                $courseIds,
                $sectionConfig,
            );
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
            $this->ruleOverrides($validated),
        )->onQueue('scheduling');

        return response()->json(['run_id' => $runId, 'status' => 'queued'], 202);
        });
    }

    public function generationRun(Request $request, string $runId): JsonResponse
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        if ($request->user()->role !== 'vpaa' && (int) $run->requested_by !== (int) $request->user()->id) {
            return $this->departmentForbiddenResponse();
        }

        return response()->json($this->reconcileOrphanedRun($run));
    }

    public function cancelGenerationRun(Request $request, string $runId): JsonResponse
    {
        $run = ScheduleGenerationRun::query()->where('run_id', $runId)->firstOrFail();
        if ($request->user()->role !== 'vpaa' && (int) $run->requested_by !== (int) $request->user()->id) {
            return $this->departmentForbiddenResponse();
        }

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

        if ($cancelled > 0 && $wasQueued) {
            DB::table('jobs')
                ->where('queue', 'scheduling')
                ->whereNull('reserved_at')
                ->where('payload', 'like', '%'.$runId.'%')
                ->delete();
        }

        return response()->json($run->refresh());
    }

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

        $run = $this->reconcileOrphanedRun($run);

        return response()->json([
            'run' => in_array($run->status, ['queued', 'running'], true) ? $run : null,
        ]);
    }

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

    /** @return array<string, mixed> */
    private function ruleOverrideRules(): array
    {
        return [
            'rule_overrides' => 'sometimes|array',
            'rule_overrides.forced_day_rules' => 'sometimes|array',
            'rule_overrides.forced_day_rules.*.course_id' => 'required|integer|exists:courses,id',
            'rule_overrides.forced_day_rules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'rule_overrides.consecutive_day_rules' => 'sometimes|array',
            'rule_overrides.consecutive_day_rules.*.course_id' => 'required|integer|exists:courses,id',
            'rule_overrides.consecutive_day_rules.*.section_id' => 'nullable|integer|exists:sections,id',
            'rule_overrides.consecutive_day_rules.*.day_count' => 'required|integer|min:'.SchedulingPolicy::MIN_CONSECUTIVE_DAYS,
            'rule_overrides.consecutive_day_rules.*.preferred_start_day' => SchedulingPolicy::allowedDaysRule('nullable'),
            'rule_overrides.consecutive_day_rules.*.meeting_days' => 'nullable|array',
            'rule_overrides.consecutive_day_rules.*.meeting_days.*' => SchedulingPolicy::allowedDaysRule('required'),
            'rule_overrides.field_course_codes' => 'sometimes|array',
            'rule_overrides.field_course_codes.*' => 'required|string|max:255',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function ruleOverrides(array $validated): ?array
    {
        $rules = $validated['rule_overrides'] ?? null;
        if (! is_array($rules)) {
            return null;
        }

        $scope = [];
        foreach ($validated['section_configs'] ?? [] as $config) {
            foreach ($config['course_ids'] ?? [] as $courseId) {
                $scope[(int) $courseId] = (int) $courseId;
            }
        }

        return [
            'forced_day_rules' => array_values($rules['forced_day_rules'] ?? []),
            'consecutive_day_rules' => array_values($rules['consecutive_day_rules'] ?? []),
            'field_course_codes' => array_values($rules['field_course_codes'] ?? []),
            'scope_course_ids' => array_values($scope),
        ];
    }

    /**
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

    private function departmentGuard(Request $request, int $departmentId, array $sectionIds = []): ?JsonResponse
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, $departmentId)) {
            return $this->departmentForbiddenResponse();
        }

        if (! $this->authorization->departmentHasProgram($departmentId)) {
            return $this->departmentMissingProgramResponse();
        }

        if (! $this->authorization->sectionIdsWritable($request, $sectionIds)) {
            return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
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
