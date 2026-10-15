<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\ScheduleSplit;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Exceptions\ScheduleConflictException;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleSplitController extends Controller
{
    public function __construct(
        private readonly ScheduleAuthorizationService $authorization,
        private readonly SchedulingScopeLock $writeLock,
        private readonly RuleEngine $ruleEngine,
        private readonly ScheduleHistoryRecorder $history,
        private readonly RevisionChangeRecorder $revisionChanges,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ScheduleSplit::query()->with('schedule')
            ->whereHas('schedule', fn ($schedule) => $this->authorization->scopeReadableSchedules($schedule, $request));

        if ($request->has('schedule_id') && $request->schedule_id) {
            $query->where('schedule_id', $request->schedule_id);
        }

        if ($request->has('split_group_id') && $request->split_group_id) {
            $query->where('split_group_id', $request->split_group_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id'    => ['required', 'integer', Rule::exists('schedules', 'id')->whereNull('deleted_at'), 'unique:schedule_splits,schedule_id'],
            'split_group_id' => 'required|string|max:36',
            'meeting_type'   => 'required|in:lecture,laboratory',
            'meeting_index'  => 'required|integer|min:1',
        ]);
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $validated['schedule_id']])) {
            return $this->programForbidden();
        }

        return $this->mutate($request, null, $validated, false);
    }

    public function show(Request $request, ScheduleSplit $scheduleSplit): JsonResponse
    {
        if (! ScheduleSplit::query()->whereKey($scheduleSplit->id)
            ->whereHas('schedule', fn ($schedule) => $this->authorization->scopeReadableSchedules($schedule, $request))
            ->exists()) {
            return response()->json(['message' => 'You cannot view this schedule split.'], 403);
        }

        return response()->json($scheduleSplit->load('schedule'));
    }

    public function update(Request $request, ScheduleSplit $scheduleSplit): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id'    => ['sometimes', 'required', 'integer', Rule::exists('schedules', 'id')->whereNull('deleted_at'), Rule::unique('schedule_splits', 'schedule_id')->ignore($scheduleSplit->id)],
            'split_group_id' => 'sometimes|required|string|max:36',
            'meeting_type'   => 'sometimes|required|in:lecture,laboratory',
            'meeting_index'  => 'sometimes|required|integer|min:1',
        ]);
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $scheduleSplit->schedule_id, (int) ($validated['schedule_id'] ?? $scheduleSplit->schedule_id)])) {
            return $this->programForbidden();
        }

        return $this->mutate($request, $scheduleSplit, $validated, false);
    }

    public function destroy(Request $request, ScheduleSplit $scheduleSplit): JsonResponse
    {
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $scheduleSplit->schedule_id])) {
            return $this->programForbidden();
        }

        return $this->mutate($request, $scheduleSplit, [], true);
    }

    private function mutate(Request $request, ?ScheduleSplit $split, array $data, bool $delete): JsonResponse
    {
        $ids = array_values(array_unique(array_filter([$split?->schedule_id, $data['schedule_id'] ?? null])));
        $semesters = Schedule::whereIn('id', $ids)->pluck('semester_id')->map('intval')->all();
        try {
            $response = $this->writeLock->execute($semesters, function () use ($request, $split, $data, $delete, $ids) {
                return DB::transaction(function () use ($request, $split, $data, $delete, $ids) {
                    $before = Schedule::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                    abort_if($before->count() !== count($ids), 422, 'The parent schedule is unavailable.');
                    abort_unless($this->authorization->scheduleIdsWritable($request, $ids), 403, ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);
                    foreach ($before as $schedule) {
                        abort_unless(in_array($schedule->status, Schedule::PLOTTING_EDITABLE_STATUSES, true), 422, 'This schedule is locked at its current approval stage. Recall it or return it to revision before editing the timetable.');
                    }
                    $groups = $before->pluck('split_group_id')->push($data['split_group_id'] ?? null)->filter()->unique()->all();
                    $existingPartners = Schedule::whereHas('split', fn ($query) => $query->whereIn('split_group_id', $groups))->orderBy('id')->lockForUpdate()->get();
                    foreach ($existingPartners as $partner) {
                        abort_unless(in_array($partner->status, Schedule::PLOTTING_EDITABLE_STATUSES, true), 422, 'A linked meeting is locked at its current approval stage. Recall it before changing the split.');
                    }
                    if ($split !== null) {
                        $split = ScheduleSplit::whereKey($split->id)->lockForUpdate()->firstOrFail();
                        abort_unless(in_array((int) $split->schedule_id, array_map('intval', $ids), true), 422, 'The split changed. Refresh and try again.');
                    }
                    if ($delete) {
                        $split->delete();
                    } elseif ($split !== null) {
                        $split->update($data);
                    } else {
                        $split = ScheduleSplit::create($data);
                    }
                    $after = Schedule::whereIn('id', $ids)->get();
                    $engine = $this->ruleEngine;
                    $violations = [];
                    foreach ($after as $schedule) {
                        if ($schedule->split === null && ($schedule->is_hybrid || SchedulingPolicy::isFixedMeetingPattern($schedule->preferred_pattern) || SchedulingPolicy::consecutiveDayCount($schedule->preferred_pattern) !== null)) {
                            $violations[] = ['rule' => 'meeting_group_required', 'message' => 'This meeting pattern requires linked split metadata. Update the timetable through its scheduling workflow.'];
                        }
                        $violations = [...$violations, ...$engine->validate([...$schedule->toArray(), 'ignore_schedule_id' => $schedule->id])];
                    }
                    $partners = Schedule::whereHas('split', fn ($query) => $query->whereIn('split_group_id', $groups))->get();
                    foreach ($partners->groupBy('split_group_id') as $members) {
                        if ($members->map(fn ($row) => $row->semester_id.':'.$row->section_id.':'.$row->course_id)->unique()->count() > 1) {
                            $violations[] = ['rule' => 'split_group_identity', 'message' => 'Linked meetings must belong to the same semester, section, and course.'];
                        }
                        if ($members->pluck('meeting_index')->unique()->count() !== $members->count()) {
                            $violations[] = ['rule' => 'split_group_index', 'message' => 'Linked meetings must have distinct meeting indexes.'];
                        }
                    }
                    $violations = [...$violations, ...$engine->validateConfiguredMeetingGroups($partners->toArray())];
                    if ($violations !== []) {
                        throw new ScheduleConflictException($violations, 'The split change would leave invalid meetings.');
                    }
                    $actorId = $request->user()?->id;
                    $version = $this->history->record('schedule_updated', $before, $after, $actorId, source: 'schedule_split');
                    $this->revisionChanges->recordScheduleChanges($before, $after, $actorId, 'update');
                    $parent = $after->first();
                    SchedulingAuditLog::create([
                        'user_id' => $actorId, 'semester_id' => $parent->semester_id,
                        'section_id' => $parent->section_id, 'department_id' => $parent->department_id,
                        'action' => 'schedule_updated', 'history_version_id' => $version->id,
                        'metadata' => ['schedule_ids' => $ids, 'source' => 'schedule_split'],
                    ]);
                    return $delete
                        ? response()->json(['message' => 'Schedule split archived successfully'])
                        : response()->json($split->fresh()->load('schedule'), $request->isMethod('post') ? 201 : 200);
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }
        ApiCache::forgetGroups(['faculty.index', 'initial.data', 'instructor_assignments.index']);

        return $response;
    }

    private function programForbidden(): JsonResponse
    {
        return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
    }
}
