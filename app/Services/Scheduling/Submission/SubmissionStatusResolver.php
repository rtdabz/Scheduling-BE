<?php

namespace App\Services\Scheduling\Submission;

use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The two statuses a section's schedule is shown with.
 *
 * Submission status: where the section's latest submitted version stands --
 * draft (never submitted), submitted, dean_approved, vpaa_approved, recalled
 * or rejected. A recalled or rejected version stays so until it is resubmitted,
 * whatever happens to the working copy in between.
 *
 * Revision status: initial, or modified when the working copy (or the version
 * resubmitted from it) differs from the last recalled or rejected version, or
 * reset when the working copy was emptied after one. That
 * version is read from its submit snapshot, so an edit or Reset of the working
 * copy never changes what it is compared against.
 *
 * Both are derived, never stored, so they cannot drift from the workflow.
 */
class SubmissionStatusResolver
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const DEAN_APPROVED = 'dean_approved';
    public const VPAA_APPROVED = 'vpaa_approved';
    public const RECALLED = 'recalled';
    public const REJECTED = 'rejected';

    public const INITIAL = 'initial';
    public const MODIFIED = 'modified';
    public const RESET = 'reset';

    /** What makes two versions of a section the same timetable; status and instructors are not content. */
    private const CONTENT_FIELDS = ['course_id', 'day', 'start_time', 'end_time', 'room_id', 'mode', 'is_hybrid'];

    /**
     * @param  list<int>  $sectionIds
     * @return array<int, array{submission_status: string, revision_status: string, revision_number: int|null}>
     */
    public function forSections(array $sectionIds, ?int $semesterId): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $submissions = ScheduleSubmission::query()
            ->with('sections:id')
            ->when($semesterId !== null, fn ($query) => $query->where('semester_id', $semesterId))
            ->whereHas('sections', fn ($query) => $query->whereIn('sections.id', $sectionIds))
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get();
        $liveBySection = Schedule::query()
            ->whereIn('section_id', $sectionIds)
            ->when($semesterId !== null, fn ($query) => $query->where('semester_id', $semesterId))
            ->get(array_merge(['id', 'section_id', 'status'], self::CONTENT_FIELDS))
            ->groupBy('section_id');

        $closedBySection = [];
        foreach ($sectionIds as $sectionId) {
            $closed = $submissions->first(fn (ScheduleSubmission $submission): bool => $this->isClosedFor($submission, $sectionId));
            if ($closed !== null) {
                $closedBySection[$sectionId] = $closed;
            }
        }
        $fingerprints = $this->sectionFingerprints(collect($closedBySection)->pluck('snapshot_version_id')->filter()->all());

        $result = [];
        foreach ($sectionIds as $sectionId) {
            $live = $liveBySection->get($sectionId, collect());
            $latest = $submissions->first(fn (ScheduleSubmission $submission): bool => $this->pivotStateFor($submission, $sectionId) !== null);
            $closed = $closedBySection[$sectionId] ?? null;

            $revision = self::INITIAL;
            if ($closed !== null && $closed->snapshot_version_id !== null) {
                // '' is the fingerprint of no meetings at all.
                $before = $fingerprints[(int) $closed->snapshot_version_id][$sectionId] ?? '';
                if ($before !== $this->fingerprint($live)) {
                    // A working copy emptied by Reset is a fresh start, not an edit.
                    $revision = $live->isEmpty() && $before !== '' ? self::RESET : self::MODIFIED;
                }
            }

            $result[$sectionId] = [
                'submission_status' => $this->submissionStatus($latest, $sectionId, $live->pluck('status')->all()),
                'revision_status' => $revision,
                'revision_number' => $latest?->revision_number,
            ];
        }

        return $result;
    }

    /**
     * Whether each submission changed its sections from the recalled or
     * rejected version before it. Submissions must carry their sections.
     *
     * @param  Collection<int, ScheduleSubmission>  $submissions
     * @return array<int, string>  Keyed by submission id.
     */
    public function forSubmissions(Collection $submissions): array
    {
        $result = $submissions->mapWithKeys(fn (ScheduleSubmission $submission): array => [$submission->id => self::INITIAL])->all();
        // A version with no sections left has nothing to compare, and cannot be
        // the version another one was revised from, so its snapshot is never read.
        $ordered = $submissions
            ->filter(fn (ScheduleSubmission $submission): bool => $submission->sections->isNotEmpty())
            ->sortByDesc('revision_number')
            ->values();
        $fingerprints = $this->sectionFingerprints($ordered->pluck('snapshot_version_id')->filter()->all());

        foreach ($ordered as $submission) {
            if ($submission->snapshot_version_id === null) {
                continue;
            }
            $own = $fingerprints[(int) $submission->snapshot_version_id];
            foreach ($submission->sections as $section) {
                $sectionId = (int) $section->id;
                $previous = $ordered->first(fn (ScheduleSubmission $candidate): bool => (int) $candidate->department_id === (int) $submission->department_id
                    && (int) $candidate->semester_id === (int) $submission->semester_id
                    && $candidate->revision_number < $submission->revision_number
                    && $this->isClosedFor($candidate, $sectionId));
                if ($previous === null || $previous->snapshot_version_id === null) {
                    continue;
                }
                $before = $fingerprints[(int) $previous->snapshot_version_id];
                if (($before[$sectionId] ?? '') !== ($own[$sectionId] ?? '')) {
                    $result[$submission->id] = self::MODIFIED;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * For each section of a submission, the recalled or rejected version it was
     * revised from -- the one forSubmissions() compares it against.
     *
     * @return array<int, ScheduleSubmission>  Keyed by section id.
     */
    public function previousVersionsFor(ScheduleSubmission $submission): array
    {
        $candidates = ScheduleSubmission::query()
            ->with('sections:id')
            ->where('department_id', $submission->department_id)
            ->where('semester_id', $submission->semester_id)
            ->where('revision_number', '<', $submission->revision_number)
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($submission->sections()->pluck('sections.id') as $sectionId) {
            $previous = $candidates->first(fn (ScheduleSubmission $candidate): bool => $this->isClosedFor($candidate, (int) $sectionId));
            if ($previous !== null) {
                $result[(int) $sectionId] = $previous;
            }
        }

        return $result;
    }

    /**
     * Sections whose latest submitted version is recalled or rejected -- the
     * ones now being revised -- with that version.
     *
     * @param  list<int>  $sectionIds
     * @return array<int, ScheduleSubmission>  Keyed by section id.
     */
    public function versionsUnderRevision(array $sectionIds): array
    {
        $sectionIds = array_values(array_unique(array_filter(array_map('intval', $sectionIds))));
        if ($sectionIds === []) {
            return [];
        }

        $submissions = ScheduleSubmission::query()
            ->with('sections:id')
            ->whereHas('sections', fn ($query) => $query->whereIn('sections.id', $sectionIds))
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($sectionIds as $sectionId) {
            $latest = $submissions->first(fn (ScheduleSubmission $submission): bool => $this->pivotStateFor($submission, $sectionId) !== null);
            if ($latest !== null && $this->isClosedFor($latest, $sectionId)) {
                $result[$sectionId] = $latest;
            }
        }

        return $result;
    }

    /** @param  list<string>  $liveStatuses */
    private function submissionStatus(?ScheduleSubmission $latest, int $sectionId, array $liveStatuses): string
    {
        if ($latest === null) {
            // Rows from before submissions were recorded still say where they stand.
            return $this->statusFromRows($liveStatuses) ?? self::DRAFT;
        }
        if ($this->isClosedFor($latest, $sectionId)) {
            return in_array($latest->status, ['rejected_by_dean', 'rejected_by_vpaa'], true)
                && $this->pivotStateFor($latest, $sectionId) !== 'withdrawn'
                ? self::REJECTED
                : self::RECALLED;
        }

        return match ($latest->status) {
            'pending_dean' => self::SUBMITTED,
            'pending_vpaa' => self::DEAN_APPROVED,
            'approved' => self::VPAA_APPROVED,
            // A section still included in a partially recalled submission: its
            // meetings say which stage it reached.
            default => $this->statusFromRows($liveStatuses) ?? self::SUBMITTED,
        };
    }

    /** @param  list<string>  $statuses */
    private function statusFromRows(array $statuses): ?string
    {
        $present = array_flip($statuses);

        return match (true) {
            isset($present['submitted']) => self::SUBMITTED,
            isset($present['approved_by_dean']), isset($present['conditionally_approved']) => self::DEAN_APPROVED,
            isset($present['approved']), isset($present['faculty_assignment']),
            isset($present['reassignment']), isset($present['finalized']) => self::VPAA_APPROVED,
            isset($present['rejected']), isset($present['rejected_by_dean']), isset($present['rejected_by_vpaa']) => self::REJECTED,
            isset($present['revision']) => self::RECALLED,
            default => null,
        };
    }

    private function isClosedFor(ScheduleSubmission $submission, int $sectionId): bool
    {
        $state = $this->pivotStateFor($submission, $sectionId);

        return $state !== null && (
            $state === 'withdrawn'
            || in_array($submission->status, ['withdrawn', 'rejected_by_dean', 'rejected_by_vpaa'], true)
        );
    }

    private function pivotStateFor(ScheduleSubmission $submission, int $sectionId): ?string
    {
        $section = $submission->sections->first(fn ($candidate): bool => (int) $candidate->id === $sectionId);

        return $section === null ? null : ($section->pivot->state ?? 'included');
    }

    /**
     * Each submitted version's fingerprint per section, keyed by version id and
     * then section id; a section with no meetings in the version is absent.
     *
     * A submit snapshot is written once and never changed -- later edits go to
     * the working copy and new versions get new ids -- so a version's
     * fingerprints are computed once and kept, instead of decoding its snapshot
     * JSON on every read. Bump the key's `v1` whenever fingerprint(),
     * meetingKey() or CONTENT_FIELDS changes, so old entries are not reused.
     *
     * @param  list<int>  $versionIds
     * @return array<int, array<int, string>>
     */
    private function sectionFingerprints(array $versionIds): array
    {
        $result = [];
        foreach (array_unique(array_map('intval', $versionIds)) as $versionId) {
            $result[$versionId] = Cache::rememberForever(
                "submission.fingerprints.v1.{$versionId}",
                fn (): array => ScheduleHistoryItem::query()
                    ->where('history_version_id', $versionId)
                    ->get(['before_snapshot', 'after_snapshot'])
                    ->map(fn (ScheduleHistoryItem $item): ?array => $item->after_snapshot ?: $item->before_snapshot)
                    ->filter()
                    ->groupBy(fn (array $row): int => (int) ($row['section_id'] ?? 0))
                    ->map(fn (Collection $rows): string => $this->fingerprint($rows))
                    ->all(),
            );
        }

        return $result;
    }

    /** @param  iterable<array|Schedule>  $rows */
    private function fingerprint(iterable $rows): string
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = self::meetingKey($row instanceof Schedule ? $row->getAttributes() : $row);
        }
        sort($keys);

        return implode(';', $keys);
    }

    /** What makes two meetings the same: its CONTENT_FIELDS, so never the instructor. */
    public static function meetingKey(array $row): string
    {
        return implode('|', [
            (int) ($row['course_id'] ?? 0),
            (string) ($row['day'] ?? ''),
            substr((string) ($row['start_time'] ?? ''), 0, 5),
            substr((string) ($row['end_time'] ?? ''), 0, 5),
            (int) ($row['room_id'] ?? 0),
            (string) ($row['mode'] ?? ''),
            (int) (bool) ($row['is_hybrid'] ?? false),
        ]);
    }
}
