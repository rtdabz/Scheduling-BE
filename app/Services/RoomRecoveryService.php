<?php

namespace App\Services;

use App\Models\Departments;
use App\Models\Course;
use App\Models\RoomRecoveryCase;
use App\Models\RoomRecoveryPlan;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Engine\Rules\AttemptRecords;
use App\Services\Scheduling\Engine\Rules\DepartmentAssignmentRule;
use App\Services\Scheduling\Engine\Rules\RoomTypeRule;
use App\Services\Scheduling\Engine\Rules\RuleLookupCache;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Recommendations\PlacementGroupValidator;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use App\Services\Scheduling\Schedule\SameTimePartnerMover;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Support\ApiCache;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RoomRecoveryService
{
    // Reviewed recovery can relocate classes during faculty assignment while retaining their instructors.
    private const TRANSFER_EDITABLE_STATUSES = [...Schedule::PLOTTING_EDITABLE_STATUSES, 'faculty_assignment'];

    public function __construct(
        private readonly SchedulingScopeLock $lock,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly PlacementGroupValidator $validator,
        private readonly AvailableSlotFinder $slots,
        private readonly SameTimePartnerMover $partners,
        private readonly ScheduleHistoryRecorder $history,
    ) {}

    public function close(Rooms $room, string $reason, ?int $actor): void
    {
        // Lock every existing semester, including ones with no current source-room bookings.
        $this->lock->execute(Semester::pluck('id')->map('intval')->all(), function () use ($room, $reason, $actor) {
            DB::transaction(function () use ($room, $reason, $actor) {
                $current = Rooms::whereKey($room->id)->lockForUpdate()->firstOrFail();
                abort_if(Rooms::isSharedType($current->room_type), 422, 'Only physical rooms can be closed.');
                if ($current->status !== 'available') {
                    return;
                }
                $rows = Schedule::where('room_id', $room->id)->lockForUpdate()->get();
                $current->update(['status' => 'not available']);
                $this->recordImpact($room->id, $rows, 'room_closed', $reason, $actor);
                SchedulingAuditLog::create(['user_id' => $actor, 'action' => 'room_closed', 'metadata' => ['room_id' => $room->id, 'reason' => $reason], 'created_at' => now()]);
            });
        });
        $this->refreshCaches();
    }

    /** Called within the availability/grant transaction, before assignments are cleared. */
    public function recordImpact(int $roomId, Collection $rows, string $trigger, string $reason, ?int $actor): void
    {
        foreach ($rows->groupBy(fn ($row) => $row->semester_id.':'.$row->department_id) as $group) {
            $first = $group->first();
            $case = RoomRecoveryCase::create([
                'room_id' => $roomId, 'semester_id' => $first->semester_id, 'department_id' => $first->department_id,
                'trigger' => $trigger, 'reason' => $reason, 'created_by' => $actor,
                'original_rows' => $group->map->toArray()->values()->all(),
            ]);
            DB::afterCommit(function () use ($case) {
                try {
                    app(SystemNotificationService::class)->notifyRoles(
                        ['secretary'], 'room_recovery', 'Room or laboratory needs attention',
                        'Room availability changed. Review affected schedules in Room Transfer.',
                        departmentId: $case->department_id, semesterId: $case->semester_id,
                        remarks: $case->reason, metadata: ['recovery_case_id' => $case->id, 'room_id' => $case->room_id, 'semester_id' => $case->semester_id],
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /** Recovery follows room-management ownership; teaching delegation only grants general schedule reads. */
    public function readable(Request $request)
    {
        $departmentId = $this->authorization->departmentScope($request);

        return $this->authorization->scopeReadableSchedules(Schedule::query(), $request)
            ->when($departmentId !== null, fn ($query) => $query->where('schedules.department_id', $departmentId));
    }

    public function roomProblem(Schedule $row): bool
    {
        return $this->roomProblems(new EloquentCollection([$row]))[$row->id];
    }

    /** Check only room rules, using eager records and a lookup cache limited to this batch. */
    public function roomProblems(EloquentCollection $rows): array
    {
        $rows->loadMissing(['academicSemester', 'section', 'course', 'room']);
        $departments = new DepartmentAssignmentRule(new RuleLookupCache);

        return $rows->mapWithKeys(function (Schedule $row) use ($departments) {
            if ($row->mode !== 'on-site') {
                return [$row->id => false];
            }
            if ($row->room === null || $row->room->status !== 'available') {
                return [$row->id => true];
            }
            if ($row->course === null || $row->section === null || $row->academicSemester === null) {
                return [$row->id => false];
            }
            $records = new AttemptRecords($row->academicSemester, $row->section, $row->course, $row->room, null, $row->mode);
            $accessProblem = collect($departments->check($row->getAttributes(), $records))->contains('rule', 'room_department_alignment');
            $typeProblem = RoomTypeRule::mismatch($row->course, $row->room, $row->mode, $row->meeting_type, (int) $row->section->department_id) !== null;

            return [$row->id => $accessProblem || $typeProblem];
        })->all();
    }

    public function cases(Request $request): array
    {
        $departmentId = $this->authorization->departmentScope($request);
        $cases = RoomRecoveryCase::with(['room', 'semester'])
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->orderByDesc('id')->get();
        $ids = $cases->flatMap(fn ($case) => array_column($case->original_rows, 'id'))->unique()->all();
        $visible = $this->readable($request)->whereIn('schedules.id', $ids)->with(['room', 'course', 'section'])->get()->keyBy('id');
        $problems = $this->roomProblems($visible);
        $seen = [];

        return $cases->map(function ($case) use ($visible, $problems, &$seen) {
            $rows = collect($case->original_rows)->map(fn ($row) => $visible->get($row['id']))->filter();
            if ($rows->isEmpty()) {
                return null;
            }
            $unresolved = $rows->filter(fn ($row) => ! isset($seen[$row->id]) && ($problems[$row->id] ?? false));
            foreach ($unresolved as $row) {
                $seen[$row->id] = true;
            }
            if ($unresolved->isEmpty()) {
                return null;
            }

            return ['id' => $case->id, 'room_code' => $case->room?->room_code ?? 'Archived room',
                'reason' => $case->reason, 'trigger' => $case->trigger, 'semester_id' => $case->semester_id,
                'semester_label' => $case->semester?->academic_year.' '.$case->semester?->semester,
                'meeting_count' => $unresolved->count(), 'schedule_ids' => $unresolved->pluck('id')->all()];
        })->filter()->values()->all();
    }

    public function requiresRevision(Schedule $row): bool
    {
        return ! in_array($row->status, self::TRANSFER_EDITABLE_STATUSES, true);
    }

    public function authorizeRows(Request $request, Collection $rows, array $proposed = [], bool $allowTimeChanges = false, bool $reviewing = false): void
    {
        $ids = $rows->pluck('id')->map('intval')->all();
        $canChange = $reviewing
            ? $request->user()->isVpaa() && $request->user()->hasCapability('schedule.approve_vpaa')
            : $request->user()->hasCapability('schedule.update')
                && $this->authorization->scheduleIdsBelongToDepartment($request, $ids)
                && $this->authorization->scheduleIdsWritable($request, $ids);
        abort_unless($canChange, 403, 'You cannot change these schedules.');
        abort_if($rows->contains(fn ($row) => $this->requiresRevision($row)), 422,
            'An affected meeting requires the existing revision workflow before reassignment.');
        if ($proposed !== [] && ! $allowTimeChanges) {
            $byId = collect($proposed)->keyBy('id');
            foreach ($rows as $row) {
                $after = $byId->get($row->id);
                abort_unless($after && $row->day === $after['day']
                    && SchedulingPolicy::normalizeTime($row->start_time) === SchedulingPolicy::normalizeTime($after['start_time'])
                    && SchedulingPolicy::normalizeTime($row->end_time) === SchedulingPolicy::normalizeTime($after['end_time']),
                    422, 'Day and time changes were not enabled for this transfer. Refresh recommendations with that option enabled.');
            }
        }
    }

    private function snapshot(Collection $rows): SchedulingSnapshot
    {
        $first = $rows->first();

        return $this->snapshots->capture((int) $first->semester_id, (int) $first->department_id,
            $rows->pluck('section_id')->unique()->sort()->values()->all(),
            $rows->pluck('course_id')->unique()->sort()->values()->all(), replacedClasses: []);
    }

    private function withSundayClasses(SchedulingSnapshot $snapshot): SchedulingSnapshot
    {
        return SchedulingSnapshot::fromArray([
            ...$snapshot->toArray(),
            'fingerprint' => hash('sha256', $snapshot->fingerprint.'|room-transfer-sunday'),
            'department_settings' => [...$snapshot->departmentSettings, 'sunday_classes_enabled' => true],
        ]);
    }

    public function preview(Request $request, array $input): array
    {
        $targets = $this->readable($request)->whereIn('schedules.id', $input['schedule_ids'])->get();
        abort_unless($targets->count() === count($input['schedule_ids']), 404, 'Some schedules are unavailable.');
        abort_unless($targets->pluck('semester_id')->unique()->count() === 1 && $targets->pluck('department_id')->unique()->count() === 1, 422,
            'Select classes from one department and semester.');
        // Include complete linked meetings even when only one of them lost its room.
        $groups = [];
        foreach ($targets as $target) {
            $key = $target->split_group_id ?? 'single-'.$target->id;
            $groups[$key] = collect([$target, ...$this->partners->groupPartnersFor($target)])->sortBy('id')->values();
        }
        $all = collect($groups)->flatten(1)->unique('id')->values();
        $snapshot = $this->snapshot($all);
        $sourceFingerprint = $snapshot->fingerprint;
        $includeSunday = (bool) ($input['allow_time_changes'] ?? false);
        if ($includeSunday) {
            $snapshot = $this->withSundayClasses($snapshot);
        }
        $targetIds = $targets->pluck('id')->all();
        $options = [];
        $unresolved = [];
        $budget = 1600;
        $deadline = microtime(true) + 12;
        $candidateDeadline = $deadline - 3;
        $groupsLeft = count($groups);
        $candidateLimitReached = false;
        foreach ($groups as $key => $rows) {
            $share = max(0, intdiv($budget, max(1, $groupsLeft--)));
            try {
                $this->authorizeRows($request, $rows);
            } catch (HttpException $e) {
                $unresolved[] = ['schedule_ids' => $rows->pluck('id')->all(), 'reason' => $e->getMessage(),
                    'reason_code' => $e->getStatusCode() === 403 ? 'permission_denied' : 'revision_required'];

                continue;
            }
            $allowTime = (bool) ($input['allow_time_changes'] ?? false);
            $groupBudget = $share;
            $candidates = $this->candidates($rows, $snapshot, $targetIds, $input['destination_room_ids'] ?? [], $allowTime, $groupBudget, $candidateDeadline);
            $budget -= $share - $groupBudget;
            $candidateLimitReached = $candidateLimitReached || $groupBudget <= 0;
            if ($candidates === []) {
                $unresolved[] = ['schedule_ids' => $rows->pluck('id')->all(), 'reason_code' => 'no_suitable_room',
                    'reason' => 'No suitable replacement was verified within the search limit. Check room type, access, instructor availability and existing bookings.'
                        .($allowTime ? '' : ' You can also allow day and time changes.')];
            } else {
                $options[$key] = ['before' => $rows->map->toArray()->all(), 'candidates' => $candidates];
            }
        }
        uasort($options, fn ($a, $b) => count($a['candidates']) <=> count($b['candidates']));
        $keys = array_keys($options);
        $best = [];
        $nodes = 0;
        $search = function (int $index, array $selected) use (&$search, &$best, &$nodes, $keys, $options, $snapshot, $deadline): void {
            if (++$nodes > 2000 || microtime(true) > $deadline) {
                return;
            }
            if (count($selected) > count($best) && $this->validBatch($snapshot, $selected)) {
                $best = $selected;
            }
            if ($index >= count($keys) || count($selected) + count($keys) - $index <= count($best)) {
                return;
            }
            $key = $keys[$index];
            foreach ($options[$key]['candidates'] as $candidate) {
                $next = [...$selected, $key => $candidate];
                if ($this->validBatch($snapshot, $next)) {
                    $search($index + 1, $next);
                    if (count($best) === count($keys)) {
                        return;
                    }
                }
            }
            $search($index + 1, $selected);
        };
        $search(0, []);
        $planGroups = [];
        foreach ($options as $key => $option) {
            if (! isset($best[$key])) {
                $unresolved[] = ['schedule_ids' => array_column($option['before'], 'id'), 'reason_code' => 'batch_conflict',
                    'reason' => 'These meetings could not be transferred together without a scheduling conflict within the search limit.'];
            } else {
                $planGroups[] = ['key' => (string) $key, 'before' => $option['before'], 'after' => $best[$key]];
            }
        }
        $meetings = $this->readable($request)->whereIn('schedules.id', $all->pluck('id'))->with(['course', 'section', 'room'])->orderBy('schedules.id')->get()
            ->map(fn ($row) => ['id' => $row->id, 'course' => $row->course?->course_code ?? 'Unknown course',
                'section' => $row->section?->section_name ?? 'Unknown section', 'mode' => $row->mode,
                'room' => $row->mode === 'online' ? 'Online' : ($row->room?->room_code ?? 'Room TBA'),
                'day' => $row->day, 'start_time' => $row->start_time, 'end_time' => $row->end_time])->all();
        $plan = RoomRecoveryPlan::create(['id' => (string) Str::uuid(), 'created_by' => $request->user()->id,
            'semester_id' => $all->first()->semester_id, 'department_id' => $all->first()->department_id,
            'expires_at' => now()->addMinutes(20), 'payload' => ['version' => 1, 'source_ids' => $all->pluck('id')->all(),
                'source_hash' => $this->sourceHash($all), 'fingerprint' => $sourceFingerprint,
                'allow_day_time_changes' => (bool) ($input['allow_time_changes'] ?? false),
                'include_sunday' => $includeSunday, 'groups' => $planGroups, 'meetings' => $meetings]]);

        return ['plan_id' => $plan->id, 'groups' => $planGroups, 'meetings' => $meetings, 'unresolved' => $unresolved, 'expires_at' => $plan->expires_at,
            'bounded_search' => true, 'search_limit_reached' => $candidateLimitReached || $budget <= 0 || $nodes > 2000 || microtime(true) > $candidateDeadline];
    }

    /** Custom day pairs describe the actual placement; named/fixed rules remain unchanged. */
    private function transferPattern(array $rows): array
    {
        $pattern = $rows[0]['preferred_pattern'] ?? null;
        if (count($rows) !== 2 || ! is_string($pattern) || preg_match(SchedulingPolicy::CUSTOM_PATTERN_REGEX, $pattern) !== 1
            || ($rows[1]['preferred_pattern'] ?? null) !== $pattern) {
            return $rows;
        }
        $days = array_column($rows, 'day');
        $previous = SchedulingPolicy::allowedDaysForPattern($pattern);
        if (count(array_unique($days)) !== 2 || array_diff($days, $previous) === []) {
            return $rows;
        }
        $updated = 'days:'.SchedulingPolicy::dayIndex($days[0]).'-'.SchedulingPolicy::dayIndex($days[1]);

        return array_map(fn ($row) => [...$row, 'preferred_pattern' => $updated], $rows);
    }

    /** Reserve candidate coverage for every available day before filling further alternatives. */
    private function slotsAcrossDays(array $slots): iterable
    {
        $days = [];
        foreach ($slots as $slot) {
            $days[$slot['day']][] = $slot;
        }
        for ($index = 0; $days !== []; $index++) {
            foreach ($days as $day => $choices) {
                if (isset($choices[$index])) {
                    yield $choices[$index];
                } else {
                    unset($days[$day]);
                }
            }
        }
    }

    private function candidates(Collection $models, SchedulingSnapshot $snapshot, array $targets, array $destinations, bool $allowTime, int &$budget, float $deadline, ?Closure $passes = null): array
    {
        $original = $models->map->toArray()->all();
        $ignore = $models->pluck('id')->map('intval')->all();
        $passes ??= $this->validator->forContext($snapshot, ignoreIds: $ignore);
        $choices = [];
        foreach ($original as $row) {
            if (! in_array($row['id'], $targets, true) || $row['mode'] !== 'on-site') {
                $choices[] = [$row];

                continue;
            }
            $course = $snapshot->coursesById[$row['course_id']] ?? null;
            $rooms = array_values(array_filter($snapshot->roomsById, fn ($room) => is_array($course) && $room['status'] === 'available'
                && ! Rooms::isSharedType($room['room_type']) && (empty($destinations) || in_array((int) $room['id'], $destinations, true))
                && RoomTypeRule::mismatch($course, $room, $row['mode'], $row['meeting_type'],
                    $row['department_id'], $snapshot->fieldCourseCodes) === null));
            $choices[] = array_map(fn ($room) => [...$row, 'room_id' => (int) $room['id']], $rooms);
        }
        $results = [];
        $walk = function (int $index, array $rows) use (&$walk, &$results, &$budget, $deadline, $choices, $original, $passes): void {
            if ($budget <= 0 || count($results) >= 10 || microtime(true) > $deadline) {
                return;
            }
            if ($index === count($choices)) {
                $budget--;
                $rows = $this->transferPattern($rows);
                if ($rows !== $original && $passes($rows)) {
                    $results[] = $rows;
                }

                return;
            }
            foreach ($choices[$index] as $row) {
                $walk($index + 1, [...$rows, $row]);
            }
        };
        $walk(0, []);
        // Try changing the affected meeting while keeping already-valid linked partners in place.
        if ($allowTime && count($original) > 1 && count(array_filter($original,
            fn ($row) => $row['mode'] === 'on-site' && in_array($row['id'], $targets, true))) === 1) {
            foreach ($original as $index => $row) {
                if ($row['mode'] !== 'on-site' || ! in_array($row['id'], $targets, true)
                    || $budget <= 0 || count($results) >= 20 || microtime(true) > $deadline) {
                    continue;
                }
                $template = $row;
                if (preg_match(SchedulingPolicy::CUSTOM_PATTERN_REGEX, $row['preferred_pattern'] ?? '') === 1) {
                    $template['preferred_pattern'] = null;
                }
                $kept = array_map(fn ($partner) => array_diff_key($partner, ['id' => true]),
                    array_values(array_filter($original, fn ($partner) => $partner['id'] !== $row['id'])));
                $duration = SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']);
                $found = $this->slots->find($snapshot, $row['section_id'], $row['course_id'], intdiv($duration, SchedulingPolicy::SLOT_MINUTES),
                    modes: ['on-site'], tentativeSchedules: $kept, ignoreScheduleIds: $ignore,
                    meetingType: $row['meeting_type'], searchFromDay: $row['day'], rowTemplate: $template);
                foreach ($this->slotsAcrossDays($found['slots']) as $slot) {
                    if ($budget <= 0 || count($results) >= 20 || microtime(true) > $deadline) {
                        break;
                    }
                    if ($destinations !== [] && ! in_array($slot['room_id'], $destinations, true)) {
                        continue;
                    }
                    $candidate = $original;
                    $candidate[$index] = [...$row, ...array_intersect_key($slot, array_flip(['day', 'start_time', 'end_time', 'room_id']))];
                    $candidate[$index]['start_time'] = substr($slot['start_time'], 0, 5);
                    $candidate[$index]['end_time'] = substr($slot['end_time'], 0, 5);
                    $candidate = $this->transferPattern($candidate);
                    $budget--;
                    if ($candidate !== $original && $passes($candidate)) {
                        $results[] = $candidate;
                    }
                }
            }
        }
        // Keep same-time choices first, then same-day alternatives, then other permitted days.
        if ($allowTime && count($original) > 1) {
            $days = SchedulingPolicy::teachingDays((bool) ($snapshot->departmentSettings['sunday_classes_enabled'] ?? false));
            // Same-time pairs and consecutive runs may need their whole group moved together.
            $dayShifts = collect(range(-6, 6))->filter(fn ($shift) => collect($original)->every(
                fn ($row) => in_array(SchedulingPolicy::DAYS[SchedulingPolicy::dayIndex($row['day']) + $shift] ?? null, $days, true),
            ))->sortBy(fn ($shift) => max(array_map(
                fn ($row) => SchedulingPolicy::searchDayRank(SchedulingPolicy::DAYS[SchedulingPolicy::dayIndex($row['day']) + $shift], $row['day']),
                $original,
            )))->all();
            $timeShifts = [0];
            for ($offset = SchedulingPolicy::SLOT_MINUTES; $offset <= 720; $offset += SchedulingPolicy::SLOT_MINUTES) {
                array_push($timeShifts, $offset, -$offset);
            }
            foreach ($dayShifts as $dayShift) {
                foreach ($timeShifts as $shift) {
                    if ($budget <= 0 || count($results) >= 20 || microtime(true) > $deadline) {
                        break 2;
                    }
                    if ($dayShift === 0 && $shift === 0) {
                        continue;
                    }
                    $shifted = $models->map(function ($model) use ($dayShift, $shift) {
                        $copy = clone $model;
                        $copy->day = SchedulingPolicy::DAYS[SchedulingPolicy::dayIndex($copy->day) + $dayShift];
                        $start = SchedulingPolicy::timeToMinutes($copy->start_time) + $shift;
                        $end = SchedulingPolicy::timeToMinutes($copy->end_time) + $shift;
                        if ($start < 0 || $end >= 1440) {
                            return null;
                        }
                        $copy->start_time = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
                        $copy->end_time = sprintf('%02d:%02d', intdiv($end, 60), $end % 60);

                        return $copy;
                    });
                    if ($shifted->contains(null)) {
                        continue;
                    }
                    $results = array_slice([...$results, ...$this->candidates($shifted, $snapshot, $targets, $destinations, false, $budget, $deadline, $passes)], 0, 20);
                }
            }
        }
        if ($allowTime && count($original) === 1 && $original[0]['mode'] === 'on-site' && $budget > 0 && microtime(true) < $deadline) {
            $row = $original[0];
            $duration = SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']);
            $found = $this->slots->find($snapshot, $row['section_id'], $row['course_id'], intdiv($duration, SchedulingPolicy::SLOT_MINUTES),
                modes: ['on-site'], ignoreScheduleIds: $ignore, meetingType: $row['meeting_type'], searchFromDay: $row['day'], rowTemplate: $row);
            foreach ($this->slotsAcrossDays($found['slots']) as $slot) {
                if ($budget <= 0 || count($results) >= 20 || microtime(true) > $deadline) {
                    break;
                }
                if ($destinations !== [] && ! in_array($slot['room_id'], $destinations, true)) {
                    continue;
                }
                $budget--;
                $candidate = [...$row, ...array_intersect_key($slot, array_flip(['day', 'start_time', 'end_time', 'room_id']))];
                $candidate['start_time'] = substr($candidate['start_time'], 0, 5);
                $candidate['end_time'] = substr($candidate['end_time'], 0, 5);
                if ($candidate !== $row && $passes([$candidate])) {
                    $results[] = [$candidate];
                }
            }
        }

        return $results;
    }

    private function validBatch(SchedulingSnapshot $snapshot, array $groups): bool
    {
        $flat = array_merge(...array_values($groups ?: [[]]));
        if (app(BatchConflictValidator::class)->validate($flat) !== []) {
            return false;
        }
        $ignore = array_map('intval', array_column($flat, 'id'));
        foreach ($groups as $rows) {
            $ids = array_column($rows, 'id');
            $others = array_values(array_filter($flat, fn ($row) => ! in_array($row['id'], $ids, true)));
            if (! $this->validator->passes($snapshot, $rows, $others, $ignore)) {
                return false;
            }
        }

        return true;
    }

    private function sourceHash(Collection $rows): string
    {
        return hash('sha256', json_encode($rows->sortBy('id')->map(fn ($row) => [...$row->getAttributes(), 'split' => $row->split?->getAttributes()])->values()->all()));
    }

    private function unchangedSource(RoomRecoveryPlan $plan, string $nextStep): Collection
    {
        $source = Schedule::whereIn('id', $plan->payload['source_ids'])->get();
        abort_unless($source->count() === count($plan->payload['source_ids']) && $this->sourceHash($source) === $plan->payload['source_hash'],
            409, 'Source schedules changed. '.$nextStep);

        return $source;
    }

    /** Recheck the proposal against current bookings and rules; unrelated activity elsewhere in the semester does not invalidate it. */
    private function assertProposalCurrent(RoomRecoveryPlan $plan, Collection $groups, Collection $source, string $nextStep): void
    {
        $snapshot = $this->snapshot($source);
        if ($plan->payload['include_sunday'] ?? false) {
            $snapshot = $this->withSundayClasses($snapshot);
        }
        abort_unless($this->validBatch($snapshot, $groups->pluck('after')->all()), 409,
            'Room availability, bookings or scheduling rules changed, so the selected transfer is no longer valid. '.$nextStep);
    }

    private function notAwaitingMessage(RoomRecoveryPlan $plan, string $stage): string
    {
        return match (true) {
            str_starts_with($plan->status, 'rejected_') => 'This transfer was rejected and cannot be approved. Create a new proposal.',
            in_array($plan->status, ['approved', 'applied'], true) => 'This transfer was already approved.',
            $stage === 'vpaa' && $plan->status === 'pending_dean' => 'Dean approval is required before VPAA approval.',
            $stage === 'dean' && $plan->status === 'pending_vpaa' => 'Dean approval was already recorded.',
            default => 'This transfer has not been submitted for approval.',
        };
    }

    public function submit(Request $request, RoomRecoveryPlan $plan, array $selected): array
    {
        abort_unless($request->user()->isSecretary(), 403, 'Only the Secretary can submit room transfers.');
        abort_unless((int) $plan->created_by === (int) $request->user()->id, 404);

        return $this->processTransfer($request, $plan, $selected, false);
    }

    public function approve(Request $request, RoomRecoveryPlan $plan): array
    {
        $stage = $this->reviewStage($request, $plan);
        if ($stage === 'dean') {
            return $this->lock->execute([(int) $plan->semester_id], function () use ($request, $plan) {
                return DB::transaction(function () use ($request, $plan) {
                    $plan = RoomRecoveryPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                    if ($plan->dean_reviewed_at && in_array($plan->status, ['pending_vpaa', 'approved', 'rejected_by_vpaa'], true)) {
                        return ['message' => 'Dean approval was already recorded.', 'status' => $plan->status, 'applied' => $plan->applied_at !== null];
                    }
                    abort_unless($plan->status === 'pending_dean', 409, $this->notAwaitingMessage($plan, 'dean'));
                    // Forward only proposals that the VPAA could still apply.
                    $groups = collect($plan->payload['groups'])->whereIn('key', $plan->selected_groups)->values();
                    $nextStep = 'Reject this request and ask for a new proposal.';
                    $this->assertProposalCurrent($plan, $groups, $this->unchangedSource($plan, $nextStep), $nextStep);
                    $plan->update(['status' => 'pending_vpaa', 'dean_reviewed_by' => $request->user()->id, 'dean_reviewed_at' => now()]);
                    SchedulingAuditLog::create(['user_id' => $request->user()->id, 'semester_id' => $plan->semester_id,
                        'department_id' => $plan->department_id, 'action' => 'room_transfer_dean_approved',
                        'metadata' => ['plan_id' => $plan->id], 'created_at' => now()]);
                    $this->notifyTransfer($request, $plan, 'dean_approved');

                    return ['message' => 'Pending VPAA Approval. Existing schedules are unchanged.', 'status' => 'pending_vpaa', 'applied' => false];
                });
            });
        }

        return $this->processTransfer($request, $plan, [], true);
    }

    private function reviewStage(Request $request, RoomRecoveryPlan $plan): string
    {
        $user = $request->user();
        $stage = $user->role;
        abort_unless(in_array($stage, ['dean', 'vpaa'], true) && $user->hasCapability('schedule.approve_'.$stage), 403);
        abort_unless($stage === 'vpaa' || ($user->department_id !== null && (int) $user->department_id === (int) $plan->department_id), 403,
            'You can review room transfers only for your department.');

        return $stage;
    }

    private function processTransfer(Request $request, RoomRecoveryPlan $plan, array $selected, bool $approving): array
    {
        $result = $this->lock->execute([(int) $plan->semester_id], function () use ($request, $plan, $selected, $approving) {
            return DB::transaction(function () use ($request, $plan, $selected, $approving) {
                $plan = RoomRecoveryPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if ($approving) {
                    if ($plan->status === 'approved') {
                        return ['message' => 'This transfer was already approved.', 'status' => 'approved', 'applied' => true];
                    }
                    abort_unless($plan->status === 'pending_vpaa' && $plan->dean_reviewed_at !== null, 409, $this->notAwaitingMessage($plan, 'vpaa'));
                    $selected = $plan->selected_groups;
                } else {
                    sort($selected);
                    if ($plan->status !== 'preview') {
                        abort_unless(in_array($plan->status, ['pending_dean', 'pending_vpaa'], true) && $plan->selected_groups === $selected,
                            409, 'This plan has already been submitted or reviewed. Create a new preview to change the selection.');

                        return ['message' => 'This transfer is already awaiting approval. Existing schedules are unchanged.', 'status' => $plan->status, 'applied' => false];
                    }
                    abort_if($plan->expires_at->isPast(), 409, 'This preview expired. Refresh recommendations.');
                }
                $groups = collect($plan->payload['groups'])->whereIn('key', $selected)->values();
                abort_unless($groups->isNotEmpty() && $groups->count() === count($selected), 422, 'Choose complete groups from this plan.');
                $nextStep = $approving ? 'Reject this request and ask for a new proposal.' : 'Refresh recommendations.';
                // Lock resource facts before eager schedule reads establish a repeatable-read snapshot.
                $department = Departments::whereKey($plan->department_id)->lockForUpdate()->firstOrFail();
                $roomIds = $groups->flatMap(fn ($group) => array_merge(array_column($group['before'], 'room_id'), array_column($group['after'], 'room_id')))->filter()->unique()->sort()->values()->all();
                Rooms::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();
                $ids = $groups->flatMap(fn ($group) => array_column($group['before'], 'id'))->unique()->all();
                $current = Schedule::whereIn('id', $ids)->lockForUpdate()->get();
                abort_unless($current->count() === count($ids), 409, 'Schedules changed. '.$nextStep);
                sort($selected);
                $source = $this->unchangedSource($plan, $nextStep);
                $this->authorizeRows($request, $current, $groups->flatMap(fn ($group) => $group['after'])->all(),
                    (bool) ($plan->payload['allow_day_time_changes'] ?? false), $approving);
                $this->assertProposalCurrent($plan, $groups, $source, $nextStep);
                if (! $approving) {
                    $pending = RoomRecoveryPlan::where('semester_id', $plan->semester_id)->whereIn('status', ['pending_dean', 'pending_vpaa'])->get();
                    $proposed = $groups->flatMap(fn ($group) => $group['after'])->values()->all();
                    foreach ($pending as $other) {
                        $otherGroups = collect($other->payload['groups'])->whereIn('key', $other->selected_groups);
                        $otherIds = $otherGroups->flatMap(fn ($group) => array_column($group['before'], 'id'))->all();
                        abort_if(array_intersect($ids, $otherIds) !== [], 409, 'A room transfer for these schedules is already awaiting approval.');
                        // Pending requests do not book rooms, so two of them must not claim the same room, section or instructor time.
                        $claimed = $otherGroups->flatMap(fn ($group) => $group['after'])->values()->all();
                        abort_if(app(BatchConflictValidator::class)->validate([...$proposed, ...$claimed]) !== [], 409,
                            'Another room transfer awaiting approval already proposes a conflicting room or time. Refresh recommendations.');
                    }
                    $plan->update(['status' => 'pending_dean', 'selected_groups' => $selected, 'submitted_at' => now()]);
                    SchedulingAuditLog::create(['user_id' => $request->user()->id, 'semester_id' => $plan->semester_id,
                        'department_id' => $plan->department_id, 'action' => 'room_transfer_submitted',
                        'metadata' => ['plan_id' => $plan->id, 'schedule_ids' => $ids], 'created_at' => now()]);
                    $this->notifyTransfer($request, $plan, 'submitted');

                    return ['message' => 'Pending Dean Approval. The Dean has been notified. Existing schedules are unchanged.',
                        'status' => 'pending_dean', 'applied' => false];
                }
                $enableSunday = ($plan->payload['include_sunday'] ?? false) && ! $department->sunday_classes_enabled
                    && $groups->flatMap(fn ($group) => $group['after'])->contains('day', 'Sunday');
                if ($enableSunday) {
                    // Keep the confirmed timetable valid in every existing scheduling validator.
                    $department->forceFill(['sunday_classes_enabled' => true])->save();
                }
                $before = $current->map->toArray()->all();
                $byId = $current->keyBy('id');
                foreach ($groups as $group) {
                    foreach ($group['after'] as $row) {
                        $model = $byId[$row['id']];
                        $model->fill(array_intersect_key($row, array_flip(['room_id', 'day', 'start_time', 'end_time', 'preferred_pattern'])));
                        if ($model->isDirty()) {
                            $model->save();
                        }
                    }
                }
                // Validate the final persisted state as well; any violation rolls back the entire batch.
                foreach (Schedule::whereIn('id', $ids)->get() as $row) {
                    $violations = (new RuleEngine)->validate([...$row->toArray(), 'ignore_schedule_id' => (int) $row->id]);
                    if ($violations !== []) {
                        throw ValidationException::withMessages(['plan' => array_column($violations, 'message')]);
                    }
                }
                $after = Schedule::whereIn('id', $ids)->get();
                $this->history->record('room_recovery_applied', $before, $after, $request->user()->id,
                    $plan->semester_id, $plan->department_id, 'room_recovery', 'Reviewed room reassignment', ['plan_id' => $plan->id]);
                SchedulingAuditLog::create(['user_id' => $request->user()->id, 'semester_id' => $plan->semester_id, 'department_id' => $plan->department_id,
                    'action' => 'room_recovery_applied', 'metadata' => ['plan_id' => $plan->id, 'schedule_ids' => $ids,
                        'sunday_classes_enabled_automatically' => $enableSunday], 'created_at' => now()]);
                $plan->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                    'applied_at' => now(), 'applied_groups' => $selected]);
                $this->notifyTransfer($request, $plan, 'approved');

                return ['message' => 'Room transfer approved and schedule changes applied.'
                    .($enableSunday ? ' Sunday classes were enabled automatically for your department.' : ''), 'status' => 'approved', 'applied' => true];
            });
        });
        $this->refreshCaches();

        return $result;
    }

    public function reject(Request $request, RoomRecoveryPlan $plan, string $reason): array
    {
        $stage = $this->reviewStage($request, $plan);

        return $this->lock->execute([(int) $plan->semester_id], function () use ($request, $plan, $reason, $stage) {
            return DB::transaction(function () use ($request, $plan, $reason, $stage) {
                $plan = RoomRecoveryPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $rejected = 'rejected_by_'.$stage;
                if ($plan->status === $rejected) {
                    return ['message' => 'This transfer was already rejected.', 'status' => $rejected, 'applied' => false];
                }
                abort_unless($plan->status === 'pending_'.$stage && ($stage === 'dean' || $plan->dean_reviewed_at !== null),
                    409, 'This transfer is not awaiting your approval.');
                $plan->update(['status' => $rejected, 'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(), 'rejection_reason' => $reason,
                    ...($stage === 'dean' ? ['dean_reviewed_by' => $request->user()->id, 'dean_reviewed_at' => now()] : [])]);
                SchedulingAuditLog::create(['user_id' => $request->user()->id, 'semester_id' => $plan->semester_id,
                    'department_id' => $plan->department_id, 'action' => 'room_transfer_rejected',
                    'metadata' => ['plan_id' => $plan->id, 'reason' => $reason, 'stage' => $stage], 'created_at' => now()]);
                $this->notifyTransfer($request, $plan, 'rejected');

                return ['message' => 'Room transfer rejected. Existing schedules are unchanged.', 'status' => $rejected, 'applied' => false];
            });
        });
    }

    private function notifyTransfer(Request $request, RoomRecoveryPlan $plan, string $action): void
    {
        $notifications = app(SystemNotificationService::class);
        $reference = substr($plan->id, 0, 8);
        $metadata = ['plan_id' => $plan->id, 'semester_id' => $plan->semester_id];
        // Persist the in-app notification with the decision; realtime delivery is flushed after the request.
        if (in_array($action, ['submitted', 'dean_approved'], true)) {
            $approver = $action === 'submitted' ? 'Dean' : 'VPAA';
            $notifications->notifyRoles([$action === 'submitted' ? 'dean' : 'vpaa'], 'room_transfer_'.$action, 'Room transfer pending '.$approver.' approval',
                "Room transfer {$reference} requires {$approver} approval. Review the proposed changes in Schedule Approval.",
                actor: $request->user(), departmentId: $plan->department_id, semesterId: $plan->semester_id, metadata: $metadata);
        } else {
            $reviewer = $request->user()->isVpaa() ? 'VPAA' : 'Dean';
            // The Dean who forwarded the request also learns the VPAA's final decision.
            $recipients = array_filter([$plan->created_by, $reviewer === 'VPAA' ? $plan->dean_reviewed_by : null]);
            $notifications->createForUsers(User::whereKey($recipients)->get(), 'room_transfer_'.$action,
                'Room transfer '.$action, "Room transfer {$reference} was {$action} by the {$reviewer}. "
                    .($action === 'approved' ? 'Schedule changes have been applied.' : 'Existing schedules are unchanged.'),
                actor: $request->user(), departmentId: $plan->department_id, semesterId: $plan->semester_id,
                remarks: $plan->rejection_reason, metadata: $metadata);
        }
    }

    public function transfers(Request $request, ?int $semesterId = null): array
    {
        $user = $request->user();
        if (! $user->isVpaa() && $user->department_id === null) {
            return [];
        }
        $plans = RoomRecoveryPlan::with(['creator', 'department', 'semester'])
            ->whereIn('status', ['pending_dean', 'pending_vpaa', 'approved', 'applied', 'rejected_by_dean', 'rejected_by_vpaa'])
            ->when($user->isVpaa(), fn ($query) => $query->whereNotIn('status', ['pending_dean', 'rejected_by_dean']))
            ->when(! $user->isVpaa(), fn ($query) => $query->where('department_id', $user->department_id))
            ->when($user->role === 'program_head', fn ($query) => $query->where('created_by', $user->id))
            ->when($semesterId !== null, fn ($query) => $query->where('semester_id', $semesterId))
            ->orderByDesc('submitted_at')->get();
        $proposed = $plans->flatMap(fn ($plan) => collect($plan->payload['groups'])->flatMap(fn ($group) => $group['after']));
        $rooms = Rooms::whereIn('id', $proposed->pluck('room_id')->filter()->unique())->pluck('room_code', 'id');
        $courses = Course::whereIn('id', $proposed->pluck('course_id')->unique())
            ->get(['id', 'course_code', 'course_name', 'lecture_hours', 'lab_hours', 'units', 'course_category'])->keyBy('id');
        $sections = Sections::with('program:id,code,name,major')->whereIn('id', $proposed->pluck('section_id')->unique())
            ->get(['id', 'section_name', 'year_level', 'semester', 'department_id', 'program_id', 'semester_id', 'status'])->keyBy('id');
        $signatories = User::query()->where('is_active', true)
            ->where(fn ($query) => $query
                ->where(fn ($scope) => $scope->whereIn('department_id', $plans->pluck('department_id')->unique())
                    ->whereIn('role', ['secretary', 'program_head', 'dean']))
                ->orWhere('role', 'vpaa'))
            ->latest()->get(['id', 'name', 'role', 'department_id', 'program_id']);
        // Requests print as complete section timetables, like Schedule Approval, including linked online sessions.
        $timetables = Schedule::with(['course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category', 'room:id,room_code'])
            ->whereIn('section_id', $sections->keys())->whereIn('semester_id', $plans->pluck('semester_id')->unique())
            ->orderBy('id')->get()->groupBy('section_id');

        return $plans->map(function ($plan) use ($rooms, $courses, $sections, $signatories, $timetables) {
            // Transfers applied before the approval workflow recorded only their applied groups.
            $groups = collect($plan->payload['groups'])->whereIn('key', $plan->selected_groups ?? $plan->applied_groups ?? []);
            $meetings = collect($plan->payload['meetings'] ?? [])->keyBy('id');
            $changes = $groups->flatMap(function ($group) use ($meetings, $rooms, $courses, $sections) {
                $before = collect($group['before'])->keyBy('id');

                // Linked online sessions stay listed beside the meeting they belong to.
                return collect($group['after'])->map(function ($row) use ($before, $meetings, $rooms, $courses, $sections) {
                    $original = $before[$row['id']];
                    $meeting = $meetings->get($row['id']);
                    $placement = fn ($value, $room) => ['room' => $room, 'day' => $value['day'],
                        'start_time' => $value['start_time'], 'end_time' => $value['end_time']];
                    $proposedRoom = $row['mode'] === 'online' ? 'Online' : ($rooms[$row['room_id']] ?? 'Room TBA');

                    return ['schedule_id' => $row['id'], 'mode' => $row['mode'], 'course' => $meeting['course'] ?? 'Unknown course',
                        'section' => $meeting['section'] ?? 'Unknown section',
                        'before' => $placement($original, $meeting['room'] ?? 'Room TBA'),
                        'after' => $placement($row, $proposedRoom),
                        'preview_schedule' => [...array_intersect_key($row, array_flip(['id', 'semester_id', 'section_id', 'course_id',
                            'department_id', 'room_id', 'day', 'start_time', 'end_time', 'mode', 'status', 'is_hybrid', 'meeting_type', 'meeting_index'])),
                            'course' => $courses->get($row['course_id']), 'section' => $sections->get($row['section_id']),
                            'room' => $row['mode'] === 'online' ? null : ['room_code' => $proposedRoom]]];
                });
            })->values()->all();
            $proposals = $groups->flatMap(fn ($group) => $group['after'])->keyBy('id');
            $previewSchedules = $proposals->pluck('section_id')->unique()
                ->flatMap(fn ($sectionId) => $timetables->get($sectionId, collect()))->where('semester_id', $plan->semester_id)
                ->map(function (Schedule $row) use ($proposals, $rooms, $sections) {
                    $proposal = $proposals->get($row->id);
                    $roomId = $proposal['room_id'] ?? $row->room_id;
                    $roomCode = $proposal ? ($rooms[$roomId] ?? null) : $row->room?->room_code;

                    return [...$row->only(['id', 'semester_id', 'section_id', 'course_id', 'department_id', 'room_id', 'day', 'start_time', 'end_time',
                        'mode', 'status', 'is_hybrid', 'split_group_id', 'meeting_type', 'meeting_index']),
                        ...($proposal ? array_intersect_key($proposal, array_flip(['room_id', 'day', 'start_time', 'end_time'])) : []),
                        'course' => $row->course, 'section' => $sections->get($row->section_id),
                        'room' => $roomId === null || $row->mode === 'online' ? null : ['room_code' => $roomCode ?? 'Room TBA']];
                })->values()->all();

            return ['id' => $plan->id, 'status' => $plan->status, 'department_id' => $plan->department_id, 'department' => $plan->department?->department_name,
                'semester_id' => $plan->semester_id, 'semester_label' => $plan->semester?->academic_year.' '.$plan->semester?->semester,
                'preview_semester' => $plan->semester?->only(['id', 'academic_year', 'semester', 'is_active']),
                'preview_department' => $plan->department?->only(['id', 'department_name', 'department_code', 'logo']),
                'preview_users' => $signatories->filter(fn ($official) => $official->role === 'vpaa'
                    || (int) $official->department_id === (int) $plan->department_id)->values()->all(),
                'submitted_by' => $plan->creator?->name ?? 'Former user', 'submitted_at' => ($plan->submitted_at ?? $plan->applied_at)?->toISOString(),
                'dean_reviewed_at' => $plan->dean_reviewed_at?->toISOString(),
                'reviewed_at' => $plan->reviewed_at?->toISOString(), 'rejection_reason' => $plan->rejection_reason,
                'schedule_ids' => $groups->flatMap(fn ($group) => array_column($group['before'], 'id'))->unique()->values()->all(),
                'enables_sunday' => in_array($plan->status, ['pending_dean', 'pending_vpaa'], true) && ($plan->payload['include_sunday'] ?? false)
                    && ! $plan->department?->sunday_classes_enabled && $groups->flatMap(fn ($group) => $group['after'])->contains('day', 'Sunday'),
                'changes' => $changes, 'preview_schedules' => $previewSchedules];
        })->all();
    }

    public function refreshCaches(): void
    {
        try {
            ApiCache::forgetGroups(['rooms.index', 'departments.index', 'initial.data', 'schedules.index', 'instructor_assignments.index', 'faculty.index']);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
