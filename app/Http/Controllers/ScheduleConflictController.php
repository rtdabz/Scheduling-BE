<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictResolutionException;
use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Schedule\ConflictRecommender;
use App\Services\Scheduling\Schedule\ConflictResolutionLog;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;
use App\Services\Scheduling\Schedule\ResolveScheduleConflict;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Schedule\ScheduleConflictCase;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\Scheduling\Schedule\StandingRuleScanner;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The conflict inbox and the transactional resolution workflow behind it.
 *
 * Conflicts are derived on every read rather than stored, so there is no
 * `conflict_cases` table and no `resolved` schedule status. What persists is
 * the evidence: a schedule history version and a `conflict_resolved` (or
 * `conflict_overridden`) entry in `scheduling_audit_logs`, both written in the
 * same transaction as the schedule change they describe.
 *
 * Recommended fixes (ConflictRecommender) are ordinary resolve requests: each
 * option carries the exact body for POST /api/conflicts/{id}/resolve.
 */
class ScheduleConflictController extends Controller
{
    use ConfirmsFacultyOverload;

    public function __construct(
        private readonly ScheduleConflictScanner $scanner,
        private readonly ResolveScheduleConflict $resolver,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ConflictRecommender $recommender,
        private readonly ConflictResolutionLog $resolutionLog,
        private readonly StandingRuleScanner $standingRules,
    ) {}

    /**
     * GET /api/conflicts — every open conflict in the caller's scope.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'section_id' => 'nullable|integer|exists:sections,id',
        ]);

        if ($this->authorization->rejectsRequestedDepartment($request, $validated['department_id'] ?? null)) {
            return $this->forbidden();
        }

        $semesterId = (int) ($validated['semester_id'] ?? Semester::query()->where('is_active', true)->value('id'));
        if ($semesterId <= 0) {
            return response()->json(['semester_id' => null, 'conflicts' => []]);
        }

        $departmentId = $this->authorization->requestedDepartment($request, $validated['department_id'] ?? null);

        $conflicts = $this->scanner->scan(
            $semesterId,
            $departmentId,
            isset($validated['section_id']) ? (int) $validated['section_id'] : null,
        );

        return response()->json([
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'conflicts' => array_map(
                static fn (ScheduleConflictCase $case): array => $case->toArray(),
                $conflicts,
            ),
        ]);
    }

    /**
     * GET /api/conflicts/{conflict}/recommendations — ranked fixes that clear it.
     *
     * Each option's `payload` is the body for the resolve endpoint. Options the
     * caller may not apply are left out, so every one listed is one click.
     */
    public function recommendations(Request $request, string $conflict): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:10',
        ]);

        $parsed = ScheduleConflictCase::parseId($conflict);
        if ($parsed === null) {
            return response()->json(['message' => 'That is not a conflict identifier.'], 404);
        }

        $ids = [$parsed['schedule_id'], $parsed['other_schedule_id']];
        if (! $this->authorization->scheduleIdsBelongToDepartment($request, [$ids[0]])
            && ! $this->authorization->scheduleIdsBelongToDepartment($request, [$ids[1]])) {
            return $this->forbidden();
        }

        $semesterId = (int) Schedule::query()->whereIn('id', $ids)->value('semester_id');
        $case = null;
        foreach ($this->scanner->scan($semesterId, onlyScheduleIds: $ids) as $open) {
            if ($open->id() === $conflict) {
                $case = $open;
                break;
            }
        }

        if ($case === null) {
            return response()->json(['message' => 'This conflict is already resolved.'], 404);
        }

        $options = array_values(array_filter(
            $this->recommender->recommend($case, (int) ($validated['limit'] ?? ConflictRecommender::DEFAULT_LIMIT) + 5),
            fn (array $option): bool => $this->authorizeAction($request, (int) $option['schedule_id'], (string) $option['action']) === null,
        ));
        $options = array_slice($options, 0, (int) ($validated['limit'] ?? ConflictRecommender::DEFAULT_LIMIT));

        return response()->json([
            'conflict' => $case->toArray(),
            'options' => array_map(
                static fn (array $option, int $index): array => [...$option, 'rank' => $index + 1],
                $options,
                array_keys($options),
            ),
        ]);
    }

    /**
     * GET /api/conflicts/rule-issues — saved classes in the caller's scope that
     * no longer satisfy a rule on their own (see StandingRuleScanner).
     *
     * Separate from the conflict list because it re-runs every single-class
     * rule on every row: read when asked for, not on each timetable change.
     */
    public function ruleIssues(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'section_id' => 'nullable|integer|exists:sections,id',
        ]);

        if ($this->authorization->rejectsRequestedDepartment($request, $validated['department_id'] ?? null)) {
            return $this->forbidden();
        }

        $semesterId = (int) ($validated['semester_id'] ?? Semester::query()->where('is_active', true)->value('id'));
        if ($semesterId <= 0) {
            return response()->json(['semester_id' => null, 'issues' => []]);
        }

        $departmentId = $this->authorization->requestedDepartment($request, $validated['department_id'] ?? null);

        return response()->json([
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'issues' => $this->standingRules->scan(
                $semesterId,
                $departmentId,
                isset($validated['section_id']) ? (int) $validated['section_id'] : null,
            ),
        ]);
    }

    /**
     * GET /api/conflicts/resolved — how conflicts in the caller's scope ended,
     * newest first, read back from the audit trail (see ConflictResolutionLog).
     */
    public function resolved(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'section_id' => 'nullable|integer|exists:sections,id',
        ]);

        if ($this->authorization->rejectsRequestedDepartment($request, $validated['department_id'] ?? null)) {
            return $this->forbidden();
        }

        $semesterId = (int) ($validated['semester_id'] ?? Semester::query()->where('is_active', true)->value('id'));
        if ($semesterId <= 0) {
            return response()->json(['semester_id' => null, 'resolutions' => []]);
        }

        $departmentId = $this->authorization->requestedDepartment($request, $validated['department_id'] ?? null);
        $sectionId = isset($validated['section_id']) ? (int) $validated['section_id'] : null;

        // Scanned so an entry whose conflict has come back says so.
        $openIds = array_map(
            static fn (ScheduleConflictCase $case): string => $case->id(),
            $this->scanner->scan($semesterId, $departmentId, $sectionId),
        );

        return response()->json([
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'resolutions' => $this->resolutionLog->entries($semesterId, $departmentId, $sectionId, $openIds),
        ]);
    }

    /**
     * POST /api/conflicts/{conflict}/resolve — apply a manual fix, then prove it worked.
     */
    public function resolve(Request $request, string $conflict): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:'.implode(',', ResolveScheduleConflict::ACTIONS),
            'schedule_id' => 'required|integer|exists:schedules,id',
            'day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i|after:start_time',
            'room_id' => 'sometimes|nullable|integer|exists:rooms,id',
            'faculty_id' => 'sometimes|nullable|integer|exists:faculties,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'reason' => 'nullable|string|max:2000',
            'source' => 'sometimes|in:manual,recommendation',
        ]);

        $scheduleId = (int) $validated['schedule_id'];
        $action = (string) $validated['action'];

        if (($guard = $this->authorizeAction($request, $scheduleId, $action)) !== null) {
            return $guard;
        }

        // Moving a class needs a whole placement, not a stray field: a new day
        // with the old times is not a move anyone asked for.
        if ($action === 'move_schedule'
            && (! isset($validated['day']) || ! isset($validated['start_time']) || ! isset($validated['end_time']))) {
            return response()->json([
                'message' => 'A move needs a day, a start time and an end time.',
            ], 422);
        }

        if ($action === 'change_room' && ! array_key_exists('room_id', $validated)) {
            return response()->json(['message' => 'Choose a room to move this class to.'], 422);
        }

        if ($action === 'change_delivery_mode' && ! isset($validated['mode'])) {
            return response()->json(['message' => 'Choose a delivery mode for this class.'], 422);
        }

        if ($action === 'reassign_instructor') {
            if (! array_key_exists('faculty_id', $validated)) {
                return response()->json(['message' => 'Choose the instructor to reassign this class to.'], 422);
            }

            $confirmation = $this->overloadGate($request, $scheduleId, $validated['faculty_id']);
            if ($confirmation !== null) {
                return $confirmation;
            }
        }

        return $this->run(fn (): array => $this->resolver->resolve(
            $conflict,
            $validated,
            $request->user()?->id,
        ));
    }

    /**
     * POST /api/conflicts/{conflict}/override — let a permitted clash stand, on the record.
     */
    public function override(Request $request, string $conflict): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
            'confirm' => 'accepted',
        ]);

        $parsed = ScheduleConflictCase::parseId($conflict);
        if ($parsed === null) {
            return response()->json(['message' => 'That is not a conflict identifier.'], 404);
        }

        // An override changes nothing about the placement, so both sides of the
        // clash must be the caller's to allow. Flagging another college's class
        // would silence a conflict its own department never agreed to.
        if (! $this->authorization->scheduleIdsBelongToDepartment(
            $request,
            [$parsed['schedule_id'], $parsed['other_schedule_id']],
        )) {
            return $this->forbidden();
        }

        if ($request->user()?->hasCapability('schedule.assign_instructor') !== true) {
            return response()->json([
                'message' => 'Allowing an instructor conflict to stand needs the Assign Instructors permission.',
            ], 403);
        }

        return $this->run(fn (): array => $this->resolver->override(
            $conflict,
            (string) $validated['reason'],
            $request->user()?->id,
        ));
    }

    /**
     * @param  callable(): array<string, mixed>  $work
     */
    private function run(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (ConflictResolutionException $exception) {
            return response()->json($exception->payload(), $exception->status());
        } catch (ScheduleConflictException $exception) {
            // Same refusal shape the manual edit endpoints use, so the client
            // renders violations -- and offers "allow anyway" where the rules
            // permit it -- without a second decoder.
            return response()->json(
                FacultyConflictOverride::refusal($exception->getMessage(), $exception->violations()),
                422,
            );
        }
    }

    /**
     * The row being changed must be the caller's, and the action must be one
     * their role may take: reassignment answers to instructor assignment, every
     * other fix to schedule editing.
     */
    private function authorizeAction(Request $request, int $scheduleId, string $action): ?JsonResponse
    {
        if ($action === 'reassign_instructor') {
            if (! $this->authorization->scheduleIdsAssignableByDepartment($request, [$scheduleId])) {
                return $this->forbidden();
            }

            return $request->user()?->hasCapability('schedule.assign_instructor') === true
                ? null
                : response()->json([
                    'message' => 'Reassigning an instructor needs the Assign Instructors permission.',
                ], 403);
        }

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, [$scheduleId])) {
            return $this->forbidden();
        }
        if (! $this->authorization->scheduleIdsWritable($request, [$scheduleId])) {
            return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
        }

        return $request->user()?->hasCapability('schedule.update') === true
            ? null
            : response()->json([
                'message' => 'Changing a schedule placement needs the Update Schedules permission.',
            ], 403);
    }

    /**
     * The same 409 the assignment endpoints answer with when a reassignment
     * would push the instructor past their Basic Load.
     */
    private function overloadGate(Request $request, int $scheduleId, mixed $facultyId): ?JsonResponse
    {
        if ($facultyId === null || $request->boolean('confirm_overload')) {
            return null;
        }

        $schedule = Schedule::query()->find($scheduleId);
        $faculty = Faculty::query()->find((int) $facultyId);
        $pair = $schedule !== null && $faculty !== null ? $this->loadPairForSchedule($schedule) : null;

        if ($pair === null) {
            return null;
        }

        return $this->overloadConfirmationResponse([
            $this->withAssignmentLabel(
                $this->facultyLoad->projectLoad($faculty, $this->activeSemesterId(), [$pair]),
                $this->assignmentLabelForSchedule($schedule),
            ),
        ]);
    }

    public function review(Request $request, string $conflict): JsonResponse
    {
        $parsed = ScheduleConflictCase::parseId($conflict);
        if ($parsed === null) {
            return response()->json(['message' => 'Invalid conflict id.'], 404);
        }

        $schedule = Schedule::query()->find($parsed['schedule_id']);
        $user = $request->user();

        SchedulingAuditLog::create([
            'user_id' => $user?->id,
            'semester_id' => $schedule?->semester_id,
            'department_id' => $schedule?->department_id,
            'section_id' => $schedule?->section_id,
            'action' => 'conflict_reviewed',
            'metadata' => [
                'conflict_id' => $conflict,
                'rule' => $parsed['rule'] ?? null,
                'schedule_id' => $parsed['schedule_id'],
                'other_schedule_id' => $parsed['other_schedule_id'],
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Conflict review logged successfully.']);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
    }
}
