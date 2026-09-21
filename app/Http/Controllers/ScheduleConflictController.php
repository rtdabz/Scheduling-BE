<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictResolutionException;
use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\Semester;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;
use App\Services\Scheduling\Schedule\ResolveScheduleConflict;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Schedule\ScheduleConflictCase;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
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
 * Recommendation-based resolution is not duplicated here. It keeps its existing
 * contract -- preview, select a plan_id, accept, CommitSchedulePlan -- which
 * already revalidates against a fresh snapshot and refuses a stale plan. A
 * conflict whose options include `apply_recommendation` is telling the client
 * to go through those endpoints, not this one.
 */
class ScheduleConflictController extends Controller
{
    use ConfirmsFacultyOverload;

    public function __construct(
        private readonly ScheduleConflictScanner $scanner,
        private readonly ResolveScheduleConflict $resolver,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly FacultyLoadService $facultyLoad,
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

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
    }
}
