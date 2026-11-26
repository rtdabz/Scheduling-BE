<?php

namespace App\Services\Scheduling\History;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Schedule\BatchConflictMessages;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use App\Services\Scheduling\Schedule\DelegatedCourseSchedules;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new draft schedule in the active semester from a VPAA-approved history version.
 *
 * Reuse is a new schedule creation, not a copy of old rows: the historical snapshot (read from
 * schedule_history_items) is mapped onto the active semester's sections, then validated by the
 * same services the Schedule Builder's batch save uses (RuleEngine, BatchConflictValidator,
 * meeting-group rules) before any row is written. Only the timetable is reused: sections, courses,
 * rooms, days, times and delivery modes. Instructors are never carried over (`faculty_id` is null),
 * so they are assigned again after approval. The new rows get new keys and new split groups, start
 * as `draft`, and follow the normal approval workflow. The history version and the original
 * schedules are never modified.
 */
final class ScheduleReuseService
{
    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly BatchConflictValidator $batchConflicts,
        private readonly BatchConflictMessages $batchConflictMessages,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly SchedulingScopeLock $scheduleWriteLock,
        private readonly DelegatedCourseSchedules $delegatedCourses,
        private readonly RevisionChangeRecorder $revisionChanges,
    ) {}

    /**
     * @return array{schedules: Collection<int, Schedule>, semester: Semester, section_count: int}
     *
     * @throws ScheduleReuseRejected
     */
    public function reuse(ScheduleHistoryVersion $version, Request $request): array
    {
        $this->assertDepartment($version, $request);
        $sourceRows = ApprovedScheduleHistory::rows($version)->pluck('snapshot')->all();
        if ($sourceRows !== [] && ! $this->authorization->programOperationRowsWritable($request, $sourceRows, (int) $version->department_id)) {
            throw new ScheduleReuseRejected([['check' => 'program', 'message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE]], 403);
        }
        $semester = $this->activeSemester($version);

        return $this->scheduleWriteLock->execute([(int) $semester->id], fn () => DB::transaction(function () use ($version, $request, $semester): array {
            $plan = $this->plan($version, $request, $semester);

            $created = new \Illuminate\Database\Eloquent\Collection;
            foreach ($plan['operations'] as $operation) {
                $created->push(Schedule::create($operation));
            }
            $schedules = Schedule::query()
                ->whereKey($created->modelKeys())
                ->with(Schedule::RESPONSE_RELATIONS)
                ->get();

            $this->revisionChanges->recordScheduleChanges([], $schedules, $request->user()?->id, 'history_reuse');
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $semester->id,
                'department_id' => $version->department_id,
                'action' => 'schedule_created',
                'metadata' => [
                    'schedule_ids' => $created->modelKeys(),
                    'count' => $created->count(),
                    'source' => 'schedule_history_reuse',
                    'reused_history_version_id' => (int) $version->id,
                    'reused_from_semester_id' => $version->semester_id,
                    'reused_from_academic_year' => ApprovedScheduleHistory::academicYear($version),
                ],
                'created_at' => now(),
            ]);

            return ['schedules' => $schedules, 'semester' => $semester, 'section_count' => $plan['section_count']];
        }));
    }

    private function assertDepartment(ScheduleHistoryVersion $version, Request $request): void
    {
        $departmentId = (int) ($version->department_id ?? 0);
        if ($departmentId === 0 || Departments::query()->whereKey($departmentId)->doesntExist()) {
            throw new ScheduleReuseRejected([['check' => 'department', 'message' => 'The department that owned this schedule no longer exists.']]);
        }
        if (! $this->authorization->payloadBelongsToDepartment($request, $departmentId)) {
            throw new ScheduleReuseRejected([['check' => 'department', 'message' => 'You can only reuse schedules that belong to your department.']], 403);
        }
        if (! $this->authorization->departmentHasProgram($departmentId)) {
            throw new ScheduleReuseRejected([['check' => 'department', 'message' => 'Create at least one Program under this Department before scheduling.']]);
        }
    }

    private function activeSemester(ScheduleHistoryVersion $version): Semester
    {
        $active = Semester::query()->where('is_active', true)->first();
        if ($active === null) {
            throw new ScheduleReuseRejected([['check' => 'semester', 'message' => 'No semester is active. Activate the semester you are scheduling before reusing a schedule.']]);
        }
        if (! (bool) ($active->is_enabled ?? true)) {
            throw new ScheduleReuseRejected([['check' => 'semester', 'message' => 'The active semester is disabled for scheduling.']]);
        }

        // The academic year may differ; the term (1st, 2nd, Summer) must match.
        $term = ApprovedScheduleHistory::term($version);
        if ($term === null || $this->normalizeTerm($term) !== $this->normalizeTerm((string) $active->semester)) {
            throw new ScheduleReuseRejected([['check' => 'semester', 'message' => sprintf(
                'This schedule was approved for the %s, but the active scheduling period is the %s (A.Y. %s). Only a schedule from the same semester term can be reused.',
                $this->termLabel($term), $this->termLabel((string) $active->semester), $active->academic_year,
            )]]);
        }

        return $active;
    }

    /**
     * Maps every historical row onto the active semester and validates the result.
     *
     * @return array{operations: list<array<string, mixed>>, section_count: int}
     */
    private function plan(ScheduleHistoryVersion $version, Request $request, Semester $semester): array
    {
        $departmentId = (int) $version->department_id;
        $rows = ApprovedScheduleHistory::rows($version)
            ->filter(fn (array $row) => (int) ($row['snapshot']['department_id'] ?? $departmentId) === $departmentId)
            ->values();
        if ($rows->isEmpty()) {
            throw new ScheduleReuseRejected([['check' => 'schedule', 'message' => 'This history entry has no classes to reuse.']]);
        }
        if (! $this->authorization->programOperationRowsWritable($request, $rows->pluck('snapshot')->all(), $departmentId)) {
            throw new ScheduleReuseRejected([['check' => 'program', 'message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE]], 403);
        }

        $issues = [];
        $rows = $this->restoreLegacyHybridGroups($rows, $issues);
        $sectionMap = $this->mapSections($rows, $departmentId, $semester, $issues);
        $targetSectionIds = array_values(array_unique(array_filter($sectionMap)));
        if ($targetSectionIds !== [] && ! $this->authorization->sectionIdsWritable($request, $targetSectionIds)) {
            throw new ScheduleReuseRejected([['check' => 'department', 'message' => 'You can only reuse schedules for sections in your own program.']], 403);
        }
        $targetSections = Sections::query()->whereKey($targetSectionIds)->get()->keyBy('id');
        if ($targetSections->isNotEmpty() && ! $this->authorization->programOperationRowsWritable($request, $targetSections->map(fn ($section) => [
            'section_id' => $section->id, 'program_id' => $section->program_id, 'curriculum_id' => $section->curriculum_id,
        ])->values()->all(), $departmentId)) {
            throw new ScheduleReuseRejected([['check' => 'program', 'message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE]], 403);
        }

        $this->checkCurricula($rows, $sectionMap, $targetSections, $issues);
        $this->checkOccupancy($rows, $sectionMap, $targetSections, $semester, $issues);

        $courses = Course::query()->whereKey($rows->pluck('snapshot.course_id')->filter()->unique())->get()->keyBy('id');
        $rooms = Rooms::query()->whereKey($rows->map(fn ($row) => $this->roomId($row['snapshot']))->filter()->unique())->get()->keyBy('id');

        $splitGroups = [];
        $operations = [];
        $labels = [];
        foreach ($rows as $row) {
            $snapshot = $row['snapshot'];
            $label = $this->label($row);
            $sectionId = $sectionMap[(int) ($snapshot['section_id'] ?? 0)] ?? null;
            $courseId = (int) ($snapshot['course_id'] ?? 0);
            if (! $courses->has($courseId)) {
                $issues[] = ['check' => 'curriculum', 'message' => "{$label}: the course no longer exists."];
            }
            $roomId = $this->roomId($snapshot);
            $mode = $this->mode($snapshot);
            if ($roomId !== null && $mode !== 'online' && ! $rooms->has($roomId)) {
                $issues[] = ['check' => 'facilities', 'message' => sprintf('%s: room %s no longer exists.', $label, $row['metadata']['room_name'] ?? "#{$roomId}")];
            }
            if ($sectionId === null || ! $courses->has($courseId)) {
                continue;
            }

            $group = $snapshot['split_group_id'] ?? null;
            $operations[] = [
                'semester_id' => (int) $semester->id,
                'section_id' => (int) $sectionId,
                'course_id' => $courseId,
                // Only the timetable is reused; instructors are assigned again through the normal workflow.
                'faculty_id' => null,
                'room_id' => $mode === 'online' ? null : $roomId,
                'department_id' => $departmentId,
                'program_id' => $targetSections->get($sectionId)?->program_id,
                'curriculum_id' => $targetSections->get($sectionId)?->curriculum_id,
                'day' => (string) ($snapshot['day'] ?? ''),
                'start_time' => substr((string) ($snapshot['start_time'] ?? ''), 0, 5),
                'end_time' => substr((string) ($snapshot['end_time'] ?? ''), 0, 5),
                'mode' => $mode,
                'is_hybrid' => (bool) ($snapshot['is_hybrid'] ?? false),
                'preferred_pattern' => $snapshot['preferred_pattern'] ?? null,
                // Fresh split groups so the new rows never join the original rows' pairings.
                'split_group_id' => $group === null ? null : ($splitGroups[$group] ??= (string) Str::uuid()),
                'meeting_type' => $snapshot['meeting_type'] ?? null,
                'meeting_index' => isset($snapshot['meeting_index']) ? (int) $snapshot['meeting_index'] : null,
                'status' => 'draft',
            ];
            $labels[] = $label;
        }

        if ($issues === []) {
            $this->checkRules($operations, $labels, $semester, $issues);
        }

        if ($issues !== []) {
            throw new ScheduleReuseRejected($this->unique($issues));
        }

        return ['operations' => $operations, 'section_count' => count($targetSectionIds)];
    }

    /** Recover omitted links only when the recorded meetings identify one complete hybrid pair. */
    private function restoreLegacyHybridGroups(Collection $rows, array &$issues): Collection
    {
        $groups = $rows->groupBy(fn (array $row): string => implode(':', [
            $row['snapshot']['semester_id'] ?? '',
            $row['snapshot']['section_id'] ?? '',
            $row['snapshot']['course_id'] ?? '',
        ]), preserveKeys: true);

        foreach ($groups as $key => $group) {
            if (! $group->contains(fn (array $row): bool => ! empty($row['snapshot']['is_hybrid'])
                && ! array_key_exists('split_group_id', $row['snapshot']))) {
                continue;
            }

            $complete = $group->count() === 2
                && $group->every(fn (array $row): bool => ! empty($row['snapshot']['is_hybrid'])
                    && ! array_key_exists('split_group_id', $row['snapshot'])
                    && ! array_key_exists('meeting_type', $row['snapshot'])
                    && ! array_key_exists('meeting_index', $row['snapshot'])
                    && isset($row['metadata']['lecture_hours'], $row['metadata']['lab_hours']))
                && $group->map(fn (array $row): string => $this->mode($row['snapshot']))->sort()->values()->all() === ['on-site', 'online']
                && $group->pluck('metadata.lecture_hours')->unique()->count() === 1
                && $group->pluck('metadata.lab_hours')->unique()->count() === 1
                && $group->pluck('snapshot.preferred_pattern')->unique()->count() === 1;
            if (! $complete) {
                $issues[] = ['check' => 'snapshot', 'message' => $this->label($group->first()).': this older snapshot is missing linked meeting details that cannot be recovered safely. Rebuild this course in the Schedule Builder, or reuse a newer snapshot.'];

                continue;
            }

            $hasLab = (float) $group->first()['metadata']['lab_hours'] > 0;
            foreach ($group->keys() as $index => $rowIndex) {
                $row = $rows->get($rowIndex);
                $row['snapshot'] += [
                    'split_group_id' => 'legacy:'.$key,
                    'meeting_type' => $hasLab && $this->mode($row['snapshot']) === 'on-site' ? 'laboratory' : 'lecture',
                    'meeting_index' => $index + 1,
                ];
                $rows->put($rowIndex, $row);
            }
        }

        return $rows;
    }

    /**
     * Sections are created per semester, so each historical section is matched by department,
     * program, year level and name to its counterpart in the active semester.
     *
     * @return array<int, int|null> source section id => active-semester section id
     */
    private function mapSections(Collection $rows, int $departmentId, Semester $semester, array &$issues): array
    {
        $sourceIds = $rows->pluck('snapshot.section_id')->filter()->map('intval')->unique()->values();
        $sources = Sections::query()->whereKey($sourceIds)->get()->keyBy('id');
        $candidates = Sections::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semester->id)
            ->get();

        $map = [];
        foreach ($sourceIds as $sourceId) {
            $row = $rows->first(fn ($row) => (int) ($row['snapshot']['section_id'] ?? 0) === $sourceId);
            $source = $sources->get($sourceId);
            $name = $row['metadata']['section_name'] ?? $source?->section_name;
            $yearLevel = $row['metadata']['section_year_level'] ?? $source?->year_level;
            $programId = $row['snapshot']['program_id'] ?? $source?->program_id;

            if ($source !== null && (int) $source->semester_id === (int) $semester->id) {
                $map[$sourceId] = (int) $source->id;

                continue;
            }
            if ($name === null) {
                $issues[] = ['check' => 'sections', 'message' => "A section in this schedule (#{$sourceId}) has no recorded name, so it cannot be matched to the active semester."];
                $map[$sourceId] = null;

                continue;
            }

            $match = $candidates->first(fn (Sections $candidate) => Sections::normalizeName((string) $candidate->section_name) === Sections::normalizeName((string) $name)
                && ($programId === null || (int) $candidate->program_id === (int) $programId));
            if ($match === null) {
                $issues[] = ['check' => 'sections', 'message' => sprintf(
                    'Section %s does not exist in the active semester (A.Y. %s). Create it under the same program first.',
                    $name, $semester->academic_year,
                )];
            } elseif ($yearLevel !== null && (string) $match->year_level !== (string) $yearLevel) {
                $issues[] = ['check' => 'sections', 'message' => sprintf(
                    'Section %s is year %s in the active semester but was year %s in this schedule.',
                    $name, $match->year_level, $yearLevel,
                )];
                $match = null;
            } elseif (($match->status ?? 'active') !== 'active') {
                $issues[] = ['check' => 'sections', 'message' => "Section {$name} is inactive in the active semester."];
                $match = null;
            }
            $map[$sourceId] = $match === null ? null : (int) $match->id;
        }

        return $map;
    }

    /** The active section must follow the same, still-active curriculum the schedule was built on. */
    private function checkCurricula(Collection $rows, array $sectionMap, Collection $targetSections, array &$issues): void
    {
        $sources = Sections::query()->whereKey(array_keys($sectionMap))->get()->keyBy('id');
        $curricula = Curriculum::query()->whereKey(
            $rows->pluck('snapshot.curriculum_id')->merge($targetSections->pluck('curriculum_id'))->merge($sources->pluck('curriculum_id'))->filter()->unique()
        )->get()->keyBy('id');
        $name = fn (?int $id): string => $id === null ? 'none' : (($curriculum = $curricula->get($id)) ? ($curriculum->code ?: $curriculum->name) : "#{$id}");

        foreach ($sectionMap as $sourceId => $targetId) {
            $target = $targetId === null ? null : $targetSections->get($targetId);
            if ($target === null) {
                continue;
            }
            $row = $rows->first(fn ($row) => (int) ($row['snapshot']['section_id'] ?? 0) === (int) $sourceId);
            $recorded = $row['snapshot']['curriculum_id'] ?? $sources->get($sourceId)?->curriculum_id;
            $recorded = $recorded === null ? null : (int) $recorded;
            $current = $target->curriculum_id === null ? null : (int) $target->curriculum_id;

            if ($recorded !== null && $current !== $recorded) {
                $issues[] = ['check' => 'curriculum', 'message' => sprintf(
                    'Section %s now follows curriculum %s, but this schedule was built on curriculum %s. Assign the same curriculum to the section, or plot a new schedule.',
                    $target->section_name, $name($current), $name($recorded),
                )];

                continue;
            }
            $curriculum = $current === null ? null : $curricula->get($current);
            if ($current !== null && ($curriculum === null || $curriculum->status !== 'active')) {
                $issues[] = ['check' => 'curriculum', 'message' => sprintf('Curriculum %s used by section %s is no longer active.', $name($current), $target->section_name)];
            }
        }
    }

    /** Reuse never replaces or duplicates a class the section already has this semester. */
    private function checkOccupancy(Collection $rows, array $sectionMap, Collection $targetSections, Semester $semester, array &$issues): void
    {
        $targetIds = array_values(array_filter($sectionMap));
        if ($targetIds === []) {
            return;
        }
        $existing = Schedule::query()
            ->where('semester_id', $semester->id)
            ->whereIn('section_id', $targetIds)
            ->whereIn('course_id', $rows->pluck('snapshot.course_id')->filter()->unique())
            ->with('course:id,course_code')
            ->get(['id', 'section_id', 'course_id']);

        foreach ($existing->unique(fn (Schedule $schedule) => $schedule->section_id.':'.$schedule->course_id) as $schedule) {
            $sectionName = $targetSections->get($schedule->section_id)?->section_name ?? "#{$schedule->section_id}";
            $courseCode = $schedule->course?->course_code ?? 'This course';
            $issues[] = [
                'check' => 'conflicts',
                'code' => 'already_scheduled',
                'section_name' => $sectionName,
                'course_code' => $courseCode,
                'message' => "{$courseCode} is already scheduled for {$sectionName} in the active semester.",
            ];
        }
    }

    /**
     * The same validation pipeline as the Schedule Builder's batch save.
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  list<string>  $labels
     */
    private function checkRules(array $operations, array $labels, Semester $semester, array &$issues): void
    {
        $describe = fn (array $violation): string => isset($violation['operation_index'], $labels[(int) $violation['operation_index']])
            ? $labels[(int) $violation['operation_index']].': '.$violation['message']
            : (string) $violation['message'];

        foreach (array_merge(
            $this->batchConflictMessages->describe($this->batchConflicts->validate($operations), $operations),
            $this->ruleEngine->validateConfiguredMeetingGroups($operations),
        ) as $violation) {
            $issues[] = ['check' => $this->checkFor((string) ($violation['rule'] ?? 'conflict')), 'message' => $describe($violation)];
        }

        foreach ($operations as $index => $operation) {
            if (($refusal = $this->delegatedCourses->refusal(
                (int) $operation['course_id'], (int) $operation['department_id'], (int) $semester->id,
                isset($operation['program_id']) ? (int) $operation['program_id'] : null,
            )) !== null) {
                $issues[] = ['check' => 'conflicts', 'message' => "{$labels[$index]}: {$refusal}"];
            }
            foreach ($this->ruleEngine->validate($operation + ['ignore_schedule_id' => []]) as $violation) {
                $issues[] = ['check' => $this->checkFor((string) ($violation['rule'] ?? '')), 'message' => "{$labels[$index]}: {$violation['message']}"];
            }
        }
    }

    private function checkFor(string $rule): string
    {
        return match (true) {
            str_contains($rule, 'conflict') || str_contains($rule, 'overlap') || $rule === 'concurrent_write' => 'conflicts',
            str_contains($rule, 'faculty') || str_contains($rule, 'instructor') => 'instructors',
            str_contains($rule, 'room') => 'facilities',
            str_contains($rule, 'curriculum') || str_contains($rule, 'subject') || str_contains($rule, 'course') || str_contains($rule, 'section') => 'curriculum',
            str_contains($rule, 'semester') => 'semester',
            default => 'scheduling_rules',
        };
    }

    /** A class moved online by a room closure is reused in the room it was planned for. */
    private function roomId(array $snapshot): ?int
    {
        $id = $snapshot['displaced_room_id'] ?? $snapshot['room_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function mode(array $snapshot): string
    {
        if (($snapshot['displaced_room_id'] ?? null) !== null) {
            return 'on-site';
        }

        return (string) ($snapshot['mode'] ?? 'on-site');
    }

    private function label(array $row): string
    {
        $snapshot = $row['snapshot'];

        return sprintf(
            '%s · %s (%s %s–%s)',
            $row['metadata']['section_name'] ?? 'Section',
            $row['metadata']['course_code'] ?? 'Course',
            $snapshot['day'] ?? '',
            substr((string) ($snapshot['start_time'] ?? ''), 0, 5),
            substr((string) ($snapshot['end_time'] ?? ''), 0, 5),
        );
    }

    private function normalizeTerm(string $term): string
    {
        return match (strtolower(trim($term))) {
            '1', '1st', 'first', '1st semester' => '1st',
            '2', '2nd', 'second', '2nd semester' => '2nd',
            default => strtolower(trim($term)),
        };
    }

    private function termLabel(?string $term): string
    {
        return match ($this->normalizeTerm((string) $term)) {
            '1st' => '1st Semester',
            '2nd' => '2nd Semester',
            'summer' => 'Summer term',
            default => 'an unrecorded term',
        };
    }

    /**
     * @param  list<array{check: string, message: string, code?: string, section_name?: string, course_code?: string}>  $issues
     * @return list<array{check: string, message: string, code?: string, section_name?: string, course_code?: string}>
     */
    private function unique(array $issues): array
    {
        return array_values(collect($issues)->unique('message')->all());
    }
}
