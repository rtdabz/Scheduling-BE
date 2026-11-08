<?php

namespace App\Services\Scheduling;

use App\Models\InstitutionSetting;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\User;
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

    /** Approvers sign their own slots, so an edit by them never makes them the schedule's preparer. */
    private const APPROVER_ROLES = ['dean', 'vpaa', 'admin'];

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
            $signatories['reviewed'] = $this->acted($official ?? $signatories['reviewed'], $at);
        }
        if (in_array('recommended', $slots, true)) {
            $official = $actor?->role === 'vpaa' ? $this->official($actor, self::RECOMMENDED_TITLE) : $this->currentVpaa();
            $signatories['recommended'] = $this->acted($official ?? $signatories['recommended'], $at);
        }
        if (in_array('approved', $slots, true)) {
            $signatories['approved'] = $this->acted($this->currentPresident(), $at);
        }

        $signatories['frozen'] = $freeze;
        $signatories['recorded_at'] = $at->toISOString();

        return $signatories;
    }

    /** The signature block to show for a submission today. */
    public function resolve(ScheduleSubmission $submission): array
    {
        $signatories = $this->stored($submission);

        return $signatories['frozen'] || in_array($submission->status, self::CLOSED_STATUSES, true)
            ? $signatories
            : $this->refreshed($signatories, (int) $submission->department_id);
    }

    /** A frozen copy for a history entry: the record of who held each slot when the action happened. */
    public function frozenCopy(ScheduleSubmission $submission): array
    {
        return [...$this->resolve($submission), 'frozen' => true];
    }

    /**
     * The stored snapshot, or for a submission recorded before signatories were kept, one rebuilt from the
     * reviewer columns. Missing keys are filled so every caller sees the full shape.
     */
    public function stored(ScheduleSubmission $submission): array
    {
        $stored = $submission->signatories;
        if (is_array($stored) && array_key_exists('prepared', $stored)) {
            return $stored + ['reviewed' => null, 'recommended' => null, 'approved' => null, 'frozen' => false];
        }

        $sectionIds = DB::table('schedule_submission_sections')
            ->where('schedule_submission_id', $submission->id)
            ->pluck('section_id')->map('intval')->all();
        $reviewer = fn (?int $userId, string $title, ?CarbonInterface $at) => ($user = $userId ? User::withTrashed()->find($userId) : null)
            ? $this->acted($this->official($user, $title), $at ?? now())
            : null;
        $legacy = $this->initial((int) $submission->department_id, $submission->semester_id, $sectionIds, $submission->submitted_at);
        $legacy['reviewed'] = $reviewer($submission->dean_reviewed_by, self::REVIEWED_TITLE, $submission->dean_reviewed_at) ?? $legacy['reviewed'];
        $legacy['recommended'] = $reviewer($submission->vpaa_reviewed_by, self::RECOMMENDED_TITLE, $submission->vpaa_reviewed_at) ?? $legacy['recommended'];
        if ($submission->status === 'approved' && $submission->vpaa_reviewed_at !== null) {
            $legacy['approved'] = $this->acted($legacy['approved'], $submission->vpaa_reviewed_at);
        }
        $legacy['frozen'] = in_array($submission->status, self::CLOSED_STATUSES, true);

        return $legacy;
    }

    /** Fill each slot no official has acted on yet with whoever holds that office now. */
    private function refreshed(array $signatories, int $departmentId): array
    {
        if ($signatories['frozen'] ?? false) {
            return $signatories;
        }
        $pending = static fn (?array $slot): bool => ($slot['acted_at'] ?? null) === null;
        if ($pending($signatories['reviewed'] ?? null)) {
            $signatories['reviewed'] = $this->currentDean($departmentId) ?? $signatories['reviewed'] ?? null;
        }
        if ($pending($signatories['recommended'] ?? null)) {
            $signatories['recommended'] = $this->currentVpaa() ?? $signatories['recommended'] ?? null;
        }
        if ($pending($signatories['approved'] ?? null)) {
            $signatories['approved'] = $this->currentPresident();
        }

        return $signatories;
    }

    /**
     * Whoever entered each program's schedule rows (program head or secretary); a program whose rows
     * have no recorded creator falls back to its Program Head.
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
            ->get(['section_id', 'created_by']);
        $users = User::withTrashed()
            ->whereIn('id', $creatorRows->pluck('created_by')->unique())
            ->whereNotIn('role', self::APPROVER_ROLES)
            ->get(['id', 'name', 'role'])
            ->keyBy('id');

        $preparers = [];
        foreach ($programBySection->values()->unique() as $programId) {
            $creatorIds = $creatorRows
                ->filter(fn ($row) => ($programBySection[$row->section_id] ?? null) === $programId)
                ->pluck('created_by')->unique()
                ->filter(fn ($id) => $users->has($id));
            $people = $creatorIds->map(fn ($id) => $users[$id])->values();
            if ($people->isEmpty() && $programId !== null) {
                $head = User::query()->where('role', 'program_head')->where('program_id', $programId)
                    ->where('is_active', true)->latest('id')->first(['id', 'name', 'role']);
                $people = collect($head ? [$head] : []);
            }
            foreach ($people as $person) {
                $preparers[] = ['program_id' => $programId, ...$this->official($person, self::PREPARED_TITLE)];
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
        return ['user_id' => (int) $user->id, 'name' => trim((string) $user->name), 'title' => $title, 'acted_at' => null];
    }

    private function acted(?array $official, CarbonInterface $at): ?array
    {
        return $official === null ? null : [...$official, 'acted_at' => $at->toISOString()];
    }
}
