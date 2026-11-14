<?php

namespace App\Services\Scheduling;

use App\Models\InstitutionSetting;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\User;
use App\Support\ActorIdentity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The four officials printed in a schedule's signature block, recorded on each submission as the
 * workflow runs so a later change of officials (or of their names) never rewrites a past schedule.
 *
 * Stored shape (schedule_submissions.signatories, schedule_history_versions.signatories):
 *   prepared:    list of {program_id, user_id, name, title}  -- fixed when the schedule is submitted
 *   reviewed:    {user_id, name, title, acted_at}             -- the Dean
 *   recommended: {user_id, name, title, acted_at}             -- the VPAA
 *   approved:    {user_id: null, name, title, acted_at}       -- the OIC-College President (institution setting)
 *   frozen:      bool
 *
 * A slot whose acted_at is set belongs to the official who performed that action and never changes.
 * Until the submission closes (approved, returned, or fully recalled), a slot not yet acted on follows the
 * official currently assigned, since that official is the one who will act. Closing freezes every slot.
 */
class ScheduleSignatories
{
    public const PREPARED_TITLE = 'Program Head';

    public const REVIEWED_TITLE = 'Dean';

    public const RECOMMENDED_TITLE = 'Vice-President for Academic Affairs';

    /** Returned schedules are editable but retain the officials recorded on their rejected version. */
    public const UNSIGNED_SCHEDULE_STATUSES = ['draft', 'completed', 'revision'];

    private const CLOSED_STATUSES = ['approved', 'rejected_by_dean', 'rejected_by_vpaa', 'withdrawn'];

    /** The snapshot taken when a schedule is submitted: its preparers, and the officials expected to act on it. */
    public function initial(int $departmentId, ?int $semesterId, array $sectionIds, ?CarbonInterface $at = null): array
    {
        return [
            'prepared' => $this->preparers($sectionIds, $semesterId),
            'reviewed' => $this->currentDean($departmentId),
            'recommended' => $this->currentVpaa(),
            'approved' => $this->currentPresident(),
            'frozen' => false,
            'recorded_at' => ($at ?? now())->toISOString(),
        ];
    }

    /**
     * Record that each of $slots was acted on by $actor now. Pass $freeze when the action closes the
     * submission; every slot still open is then filled with the official in office at that moment and kept.
     *
     * @param  list<'reviewed'|'recommended'|'approved'>  $slots
     */
    public function stamp(ScheduleSubmission $submission, array $slots, ?User $actor, bool $freeze, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $signatories = $this->refreshed($this->stored($submission), (int) $submission->department_id);
        if ($signatories['frozen']) {
            return $signatories;
        }

        if (in_array('reviewed', $slots, true)) {
            // A VPAA may complete the Dean step; the Dean of the department is still the reviewing official.
            $official = $actor?->role === 'dean' ? $this->official($actor, self::REVIEWED_TITLE) : $this->currentDean((int) $submission->department_id);
            $signatories['reviewed'] = $this->acted($official ?? ['user_id' => null, 'name' => 'Not recorded',
                'title' => self::REVIEWED_TITLE, 'role' => null, 'official_status' => 'unassigned'], $at);
        }
        if (in_array('recommended', $slots, true)) {
            $official = $actor?->role === 'vpaa' ? $this->official($actor, self::RECOMMENDED_TITLE) : $this->currentVpaa();
            $signatories['recommended'] = $this->acted($official, $at);
        }
        if (in_array('approved', $slots, true)) {
            $signatories['approved'] = $this->acted($this->currentPresident(), $at);
        }

        foreach ($slots as $slot) {
            if (isset($signatories[$slot])) {
                $signatories[$slot]['action_actor'] = ActorIdentity::snapshot($actor);
                $signatories[$slot]['action_kind'] = $slot === 'approved' ? 'document_signatory' : ($actor?->id === ($signatories[$slot]['user_id'] ?? null) ? 'direct' : 'delegated');
            }
        }
        $signatories['frozen'] = $freeze;
        $signatories['recorded_at'] = $at->toISOString();

        return $signatories;
    }

    /** The signature block to show for a submission today. */
    public function resolve(ScheduleSubmission $submission): array
    {
        $signatories = $this->stored($submission);

        return $signatories['frozen'] || $this->isClosed($submission)
            ? $signatories
            : $this->refreshed($signatories, (int) $submission->department_id);
    }

    /** A frozen copy for a history entry: the record of who held each slot when the action happened. */
    public function frozenCopy(ScheduleSubmission $submission): array
    {
        return [...$this->resolve($submission), 'frozen' => true];
    }

    /**
     * The stored snapshot. Missing historical names cannot be reconstructed from today's officials.
     * Open legacy submissions can still follow the current officials until their workflow closes.
     */
    public function stored(ScheduleSubmission $submission): array
    {
        $stored = $submission->signatories;
        if (is_array($stored) && array_key_exists('prepared', $stored)) {
            return $stored + ['reviewed' => null, 'recommended' => null, 'approved' => null, 'frozen' => false];
        }

        if ($this->isClosed($submission)) {
            return ['prepared' => [], 'reviewed' => null, 'recommended' => null, 'approved' => null, 'frozen' => true];
        }

        $sectionIds = DB::table('schedule_submission_sections')
            ->where('schedule_submission_id', $submission->id)
            ->pluck('section_id')->map('intval')->all();
        $reviewer = function (string $column, string $title, ?CarbonInterface $at) use ($submission): ?array {
            if ($at === null) {
                return null;
            }
            $identity = $submission->actor_snapshots[$column] ?? null;

            return ['user_id' => $submission->{$column}, 'name' => $identity['name'] ?? 'Not recorded',
                'title' => $title, 'role' => $identity['role'] ?? null, 'acted_at' => $at->toISOString()];
        };
        $legacy = $this->initial((int) $submission->department_id, $submission->semester_id, $sectionIds, $submission->submitted_at);
        if ($submission->dean_reviewed_at !== null) {
            $legacy['reviewed'] = $reviewer('dean_reviewed_by', self::REVIEWED_TITLE, $submission->dean_reviewed_at);
        }
        if ($submission->vpaa_reviewed_at !== null) {
            $legacy['recommended'] = $reviewer('vpaa_reviewed_by', self::RECOMMENDED_TITLE, $submission->vpaa_reviewed_at);
        }

        return $legacy;
    }

    /**
     * Point each submitted row at the submission whose recorded officials it prints, and return those
     * signature blocks keyed by submission id. A draft or a recalled row back in revision has no signed
     * version yet, so it is left unmarked and prints the current officials.
     *
     * @param  iterable<Schedule>  $schedules
     * @return array<int, array> submission id => signatories
     */
    public function attachToSchedules(iterable $schedules): array
    {
        $signed = collect($schedules)->reject(
            static fn (Schedule $schedule): bool => in_array($schedule->status, self::UNSIGNED_SCHEDULE_STATUSES, true),
        );
        $latest = [];
        foreach ($signed->groupBy(static fn (Schedule $schedule): int => (int) $schedule->semester_id) as $semesterId => $rows) {
            foreach (array_chunk($rows->pluck('section_id')->filter()->map('intval')->unique()->values()->all(), 500) as $chunk) {
                DB::table('schedule_submission_sections as pivot')
                    ->join('schedule_submissions as submission', 'submission.id', '=', 'pivot.schedule_submission_id')
                    ->whereIn('pivot.section_id', $chunk)
                    ->where('submission.semester_id', $semesterId)
                    ->orderByDesc('submission.revision_number')
                    ->orderByDesc('submission.id')
                    ->get(['pivot.section_id', 'submission.id'])
                    ->each(function (object $row) use (&$latest, $semesterId): void {
                        $latest[$semesterId.':'.$row->section_id] ??= (int) $row->id;
                    });
            }
        }

        $submissions = ScheduleSubmission::query()->whereKey(array_values(array_unique($latest)))->get()->keyBy('id');
        $resolved = [];
        foreach ($signed as $schedule) {
            $submission = $submissions->get($latest[(int) $schedule->semester_id.':'.(int) $schedule->section_id] ?? 0);
            if ($submission === null) {
                continue;
            }
            $schedule->setAttribute('signatories_submission_id', (int) $submission->id);
            $resolved[(int) $submission->id] ??= $this->resolve($submission);
        }

        return $resolved;
    }

    private function isClosed(ScheduleSubmission $submission): bool
    {
        return in_array($submission->status, self::CLOSED_STATUSES, true)
            || ($submission->status === 'partially_withdrawn' && $submission->vpaa_reviewed_at !== null);
    }

    /** Fill each slot no official has acted on yet with whoever holds that office now. */
    private function refreshed(array $signatories, int $departmentId): array
    {
        if ($signatories['frozen'] ?? false) {
            return $signatories;
        }
        $pending = static fn (?array $slot): bool => ($slot['acted_at'] ?? null) === null;
        if ($pending($signatories['reviewed'] ?? null)) {
            $signatories['reviewed'] = $this->currentDean($departmentId);
        }
        if ($pending($signatories['recommended'] ?? null)) {
            $signatories['recommended'] = $this->currentVpaa();
        }
        if ($pending($signatories['approved'] ?? null)) {
            $signatories['approved'] = $this->currentPresident();
        }

        return $signatories;
    }

    /**
     * Whoever entered each program's rows. The creation snapshot establishes their role;
     * missing creator evidence is never replaced with a current officeholder.
     *
     * @return list<array{program_id: int|null, user_id: int|null, name: string, title: string}>
     */
    public function preparers(array $sectionIds, ?int $semesterId): array
    {
        if ($sectionIds === []) {
            return [];
        }
        $programBySection = DB::table('sections')->whereIn('id', $sectionIds)->pluck('program_id', 'id')
            ->map(static fn ($programId): ?int => $programId === null ? null : (int) $programId);
        $creatorRows = Schedule::query()
            ->whereIn('section_id', $sectionIds)
            ->when($semesterId !== null, fn ($query) => $query->where('semester_id', $semesterId))
            ->whereNotNull('created_by')
            ->orderBy('id')
            ->get(['section_id', 'created_by', 'creator_identity']);
        $users = User::withTrashed()
            ->whereIn('id', $creatorRows->pluck('created_by')->unique())
            ->get(['id', 'name', 'role'])
            ->keyBy('id');

        $preparers = [];
        foreach ($programBySection->values()->unique() as $programId) {
            $creatorIds = $creatorRows
                ->filter(fn ($row) => ($programBySection[$row->section_id] ?? null) === $programId)
                ->pluck('created_by')->unique()
                ->filter(fn ($id) => $users->has($id));
            foreach ($creatorIds as $creatorId) {
                $person = $users[$creatorId];
                $recorded = $creatorRows->first(fn ($row) => (int) $row->created_by === (int) $creatorId)?->creator_identity;
                $role = $recorded['role'] ?? null;
                $title = match ($role) {
                    'program_head' => self::PREPARED_TITLE,
                    'secretary' => 'Program Head/Secretary',
                    'director' => 'Department Director',
                    default => 'Schedule preparer',
                };
                $preparers[] = ['program_id' => $programId, 'user_id' => (int) $creatorId,
                    'name' => $person->name, 'title' => $title, 'role' => $role, 'creator_identity' => $recorded,
                    'provenance' => $recorded ? 'recorded_creator' : 'creator_reference_at_submission'];
            }
        }

        return $preparers;
    }

    private function currentDean(int $departmentId): ?array
    {
        $dean = User::query()->where('role', 'dean')->where('department_id', $departmentId)
            ->where('is_active', true)->latest('id')->first(['id', 'name', 'role']);

        return $dean ? $this->official($dean, self::REVIEWED_TITLE) : null;
    }

    private function currentVpaa(): ?array
    {
        $vpaa = User::query()->where('role', 'vpaa')->where('is_active', true)->latest('id')->first(['id', 'name', 'role']);

        return $vpaa ? $this->official($vpaa, self::RECOMMENDED_TITLE) : null;
    }

    private function currentPresident(): array
    {
        $settings = InstitutionSetting::current();

        return ['user_id' => null, 'name' => trim((string) $settings->president_name), 'title' => trim((string) $settings->president_title), 'acted_at' => null];
    }

    private function official(User $user, string $title): array
    {
        return ['user_id' => (int) $user->id, 'name' => trim((string) $user->name), 'title' => $title, 'role' => $user->role, 'acted_at' => null];
    }

    private function acted(?array $official, CarbonInterface $at): ?array
    {
        return $official === null ? null : [...$official, 'acted_at' => $at->toISOString()];
    }
}
