<?php

namespace App\Services\Scheduling\Submission;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Services\ScheduleHistoryRecorder;
use Illuminate\Support\Collection;

/**
 * Records what happens to a recalled or rejected version's working copy.
 *
 * The submitted version itself is frozen in its submit snapshot. This keeps the
 * steps from it to the next version -- meetings added, removed or changed, a
 * section deleted, a course's details edited -- as history versions linked to
 * that submission, each with the state before and after and the names the ids
 * resolved to at the time, so none of it depends on rows that may since be gone.
 */
class RevisionChangeRecorder
{
    public const SOURCE = 'revision_working_copy';

    public const SCHEDULES_CHANGED = 'revision_schedules_changed';
    public const SECTION_DELETED = 'revision_section_deleted';
    public const COURSE_CHANGED = 'revision_course_changed';

    /** A meeting's content; status, instructor flags and timestamps are not a revision. */
    private const CONTENT_FIELDS = ['section_id', 'course_id', 'faculty_id', 'room_id', 'day', 'start_time', 'end_time', 'mode', 'is_hybrid'];

    /** The course details a section's timetable depends on. */
    private const COURSE_FIELDS = ['course_code', 'course_name', 'course_category', 'units', 'lecture_hours', 'lab_hours', 'room_type_required'];

    public function __construct(
        private readonly ScheduleHistoryRecorder $history,
        private readonly SubmissionStatusResolver $statuses,
        private readonly ScheduleDescriptors $descriptors,
    ) {}

    /**
     * Meetings added, removed or changed in one operation. Only rows of sections
     * under revision are kept; anything else is ordinary drafting.
     *
     * @param  iterable<Schedule|array>  $before  The rows as they were, before the write.
     * @param  iterable<Schedule|array>  $after  The rows as they are now; a removed row is absent.
     */
    public function recordScheduleChanges(iterable $before, iterable $after, ?int $actorUserId, string $operation): void
    {
        $before = $this->keyed($before);
        $after = $this->keyed($after);
        $versions = $this->statuses->versionsUnderRevision(
            $before->pluck('section_id')->merge($after->pluck('section_id'))->all(),
        );
        if ($versions === []) {
            return;
        }

        $inRevision = static fn (array $row): bool => isset($versions[(int) ($row['section_id'] ?? 0)]);
        $changes = ['added' => [], 'removed' => [], 'updated' => []];
        foreach ($before->keys()->merge($after->keys())->unique() as $id) {
            $old = $before->get($id);
            $new = $after->get($id);
            if (! ($old !== null && $inRevision($old)) && ! ($new !== null && $inRevision($new))) {
                continue;
            }
            if ($old === null) {
                $changes['added'][] = $id;
            } elseif ($new === null) {
                $changes['removed'][] = $id;
            } elseif ($this->content($old) !== $this->content($new)) {
                $changes['updated'][] = $id;
            }
        }
        $ids = array_merge(...array_values($changes));
        if ($ids === []) {
            return;
        }

        $beforeRows = $before->only($ids);
        $afterRows = $after->only($ids);
        $sectionIds = $beforeRows->pluck('section_id')->merge($afterRows->pluck('section_id'))
            ->map('intval')->filter(static fn (int $id): bool => isset($versions[$id]))->unique()->values()->all();

        $this->store(
            self::SCHEDULES_CHANGED,
            $beforeRows,
            $afterRows,
            $actorUserId,
            array_intersect_key($versions, array_flip($sectionIds)),
            [
                'operation' => $operation,
                'added_schedule_ids' => $changes['added'],
                'removed_schedule_ids' => $changes['removed'],
                'updated_schedule_ids' => $changes['updated'],
            ],
            // Names from before the write for removed and changed rows, from
            // after it for added ones.
            $this->descriptors->for($afterRows),
            $this->descriptors->for($beforeRows),
        );
    }

    /**
     * A section about to be deleted. Its meetings and its links to the
     * submissions that sent it go with it, so it is recorded first whenever it
     * was ever submitted -- not only while under revision.
     */
    public function recordSectionDeleted(Sections $section, ?int $actorUserId): void
    {
        $submissions = ScheduleSubmission::query()
            ->whereHas('sections', fn ($query) => $query->where('sections.id', $section->id))
            ->orderByDesc('revision_number')
            ->get();
        if ($submissions->isEmpty()) {
            return;
        }

        $rows = Schedule::withTrashed()->where('section_id', $section->id)->orderBy('id')->get();
        $versions = $this->statuses->versionsUnderRevision([(int) $section->id]);
        $linked = $versions !== [] ? $versions : [(int) $section->id => $submissions->first()];

        $this->store(
            self::SECTION_DELETED,
            $this->keyed($rows),
            collect(),
            $actorUserId,
            $linked,
            [
                'section' => [
                    'id' => (int) $section->id,
                    'section_name' => $section->section_name,
                    'year_level' => $section->year_level,
                    'semester' => $section->semester,
                    'program_id' => $section->program_id,
                    'semester_id' => $section->semester_id,
                ],
                'schedule_submission_ids' => $submissions->pluck('id')->map('intval')->values()->all(),
                'removed_schedule_ids' => $rows->modelKeys(),
            ],
            [],
            $this->descriptors->for($rows),
            (int) $section->department_id,
            $section->semester_id === null ? null : (int) $section->semester_id,
        );
    }

    /**
     * A course's details edited while sections under revision schedule it. The
     * submitted versions keep the details they were sent with; this records the
     * change against each version being revised.
     *
     * @param  array<string, mixed>  $originalAttributes  The course as it was before the edit.
     */
    public function recordCourseChanged(array $originalAttributes, Course $course, ?int $actorUserId): void
    {
        $changed = [];
        foreach (self::COURSE_FIELDS as $field) {
            $old = $originalAttributes[$field] ?? null;
            $new = $course->getAttribute($field);
            if ((string) $old !== (string) $new) {
                $changed[$field] = ['before' => $old, 'after' => $new];
            }
        }
        if ($changed === []) {
            return;
        }

        $rows = Schedule::query()->where('course_id', $course->id)->orderBy('id')->get();
        $versions = $this->statuses->versionsUnderRevision($rows->pluck('section_id')->all());
        $rows = $rows->filter(static fn (Schedule $row): bool => isset($versions[(int) $row->section_id]))->values();
        if ($rows->isEmpty()) {
            return;
        }

        $current = $this->descriptors->for($rows);
        $previousCourse = array_intersect_key($originalAttributes, array_flip(self::COURSE_FIELDS));
        $previous = array_map(static fn (array $descriptor): array => array_replace($descriptor, array_intersect_key($previousCourse, $descriptor)), $current);
        $keyed = $this->keyed($rows);

        $this->store(
            self::COURSE_CHANGED,
            $keyed,
            $keyed,
            $actorUserId,
            $versions,
            [
                'course_id' => (int) $course->id,
                'course_changes' => $changed,
                'updated_schedule_ids' => $rows->modelKeys(),
            ],
            $current,
            $previous,
        );
    }

    /**
     * @param  Collection<int, array>  $before
     * @param  Collection<int, array>  $after
     * @param  array<int, ScheduleSubmission>  $versions  Keyed by section id.
     * @param  array<int, array>  $afterNames
     * @param  array<int, array>  $beforeNames
     */
    private function store(
        string $action,
        Collection $before,
        Collection $after,
        ?int $actorUserId,
        array $versions,
        array $summary,
        array $afterNames,
        array $beforeNames,
        ?int $departmentId = null,
        ?int $semesterId = null,
    ): void {
        $submissions = collect($versions)->unique('id')->values();
        $first = $submissions->first();
        $sectionsBySubmission = collect($versions)
            ->map(static fn (ScheduleSubmission $submission, int $sectionId): array => ['submission' => (int) $submission->id, 'section' => $sectionId])
            ->groupBy('submission')
            ->map(static fn (Collection $pairs): array => $pairs->pluck('section')->values()->all());

        $itemMetadata = [];
        foreach ($before->keys()->merge($after->keys())->unique() as $id) {
            $itemMetadata[$id] = array_filter([
                'before_names' => $beforeNames[$id] ?? null,
                'after_names' => $afterNames[$id] ?? null,
            ], static fn ($value): bool => $value !== null) + ($afterNames[$id] ?? $beforeNames[$id] ?? []);
        }

        $version = $this->history->record(
            $action,
            $before->values(),
            $after->values(),
            $actorUserId,
            $semesterId ?? (int) $first->semester_id,
            $departmentId ?? (int) $first->department_id,
            self::SOURCE,
            null,
            $summary + [
                'schedule_submission_ids' => $submissions->pluck('id')->map('intval')->all(),
                'submission_section_ids' => $sectionsBySubmission->all(),
            ],
            $itemMetadata,
        );

        foreach ($submissions as $submission) {
            SchedulingAuditLog::create([
                'user_id' => $actorUserId,
                'semester_id' => $submission->semester_id,
                'department_id' => $submission->department_id,
                'section_id' => count($sectionsBySubmission[(int) $submission->id] ?? []) === 1
                    ? $sectionsBySubmission[(int) $submission->id][0]
                    : null,
                'schedule_submission_id' => $submission->id,
                'history_version_id' => $version->id,
                'action' => $action,
                'metadata' => $summary + ['section_ids' => $sectionsBySubmission[(int) $submission->id] ?? []],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  iterable<Schedule|array>  $rows
     * @return Collection<int, array>
     */
    private function keyed(iterable $rows): Collection
    {
        return collect($rows)
            ->map(static fn ($row): array => $row instanceof Schedule ? $row->getAttributes() : (array) $row)
            ->filter(static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0)
            ->keyBy(static fn (array $row): int => (int) $row['id']);
    }

    private function content(array $row): string
    {
        return implode('|', array_map(static function (string $field) use ($row): string {
            $value = (string) ($row[$field] ?? '');

            return in_array($field, ['start_time', 'end_time'], true) ? substr($value, 0, 5) : $value;
        }, self::CONTENT_FIELDS));
    }
}
