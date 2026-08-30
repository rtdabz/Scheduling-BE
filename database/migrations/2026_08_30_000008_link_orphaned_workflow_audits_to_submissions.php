<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WORKFLOW_ACTIONS = [
        'schedule_submitted',
        'schedule_approved_by_dean',
        'schedule_returned_by_dean',
        'schedule_approved_by_vpaa',
        'schedule_returned_by_vpaa',
        'schedule_withdrawn',
    ];

    public function up(): void
    {
        $scopes = DB::table('scheduling_audit_logs')
            ->whereIn('action', self::WORKFLOW_ACTIONS)
            ->whereNull('schedule_submission_id')
            ->whereNotNull('department_id')
            ->whereNotNull('term_id')
            ->select(['department_id', 'term_id'])
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            DB::transaction(function () use ($scope): void {
                $audits = DB::table('scheduling_audit_logs')
                    ->where('department_id', $scope->department_id)
                    ->where('term_id', $scope->term_id)
                    ->whereIn('action', self::WORKFLOW_ACTIONS)
                    ->whereNull('schedule_submission_id')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($audits->isEmpty()) {
                    return;
                }

                $existingSubmissions = DB::table('schedule_submissions')
                    ->where('department_id', $scope->department_id)
                    ->where('term_id', $scope->term_id)
                    ->orderBy('revision_number')
                    ->get();
                if ($existingSubmissions->isNotEmpty()) {
                    foreach ($audits as $audit) {
                        $submission = $existingSubmissions
                            ->filter(fn ($candidate): bool => $candidate->submitted_at <= $audit->created_at)
                            ->last()
                            ?? $existingSubmissions->first();
                        DB::table('scheduling_audit_logs')
                            ->where('id', $audit->id)
                            ->update(['schedule_submission_id' => $submission->id]);
                    }

                    return;
                }

                $state = $this->deriveState($audits);
                $revisionNumber = ((int) DB::table('schedule_submissions')
                    ->where('department_id', $scope->department_id)
                    ->where('term_id', $scope->term_id)
                    ->max('revision_number')) + 1;
                $submissionId = DB::table('schedule_submissions')->insertGetId(array_merge($state, [
                    'department_id' => $scope->department_id,
                    'term_id' => $scope->term_id,
                    'revision_number' => $revisionNumber,
                    'created_at' => $audits->first()->created_at,
                    'updated_at' => $audits->last()->created_at,
                ]));

                $sectionIds = $audits
                    ->flatMap(fn ($audit): Collection => $this->metadataSectionIds($audit->metadata))
                    ->unique()
                    ->values();
                if ($sectionIds->isEmpty()) {
                    $sectionIds = DB::table('schedules')
                        ->where('department_id', $scope->department_id)
                        ->where('term_id', $scope->term_id)
                        ->distinct()
                        ->pluck('section_id')
                        ->map('intval')
                        ->filter()
                        ->values();
                }

                foreach ($sectionIds as $sectionId) {
                    DB::table('schedule_submission_sections')->insert([
                        'schedule_submission_id' => $submissionId,
                        'section_id' => $sectionId,
                        'state' => in_array($state['status'], ['withdrawn', 'partially_withdrawn'], true)
                            ? 'withdrawn'
                            : 'included',
                        'created_at' => $audits->first()->created_at,
                        'updated_at' => $audits->last()->created_at,
                    ]);
                }

                DB::table('scheduling_audit_logs')
                    ->whereIn('id', $audits->pluck('id'))
                    ->update(['schedule_submission_id' => $submissionId]);
            });
        }
    }

    public function down(): void
    {
        // Historical repair is intentionally retained. The owning tables are
        // removed by the preceding schema migration when fully rolled back.
    }

    private function deriveState(Collection $audits): array
    {
        $state = [
            'status' => 'pending_dean',
            'submitted_by' => null,
            'submitted_at' => $audits->first()->created_at,
            'dean_reviewed_by' => null,
            'dean_reviewed_at' => null,
            'vpaa_reviewed_by' => null,
            'vpaa_reviewed_at' => null,
            'withdrawn_by' => null,
            'withdrawn_at' => null,
            'rejection_reason' => null,
            'approval_override' => false,
            'approval_override_reason' => null,
        ];

        foreach ($audits as $audit) {
            $metadata = $this->decodeMetadata($audit->metadata);
            if ($audit->action === 'schedule_submitted') {
                $state['status'] = 'pending_dean';
                $state['submitted_by'] = $audit->user_id;
                $state['submitted_at'] = $audit->created_at;
            } elseif ($audit->action === 'schedule_approved_by_dean') {
                $state['status'] = 'pending_vpaa';
                $state['dean_reviewed_by'] = $audit->user_id;
                $state['dean_reviewed_at'] = $audit->created_at;
                $state['approval_override'] = (bool) ($metadata['approval_override'] ?? false);
                $state['approval_override_reason'] = $metadata['approval_override_reason'] ?? null;
            } elseif ($audit->action === 'schedule_returned_by_dean') {
                $state['status'] = 'rejected_by_dean';
                $state['dean_reviewed_by'] = $audit->user_id;
                $state['dean_reviewed_at'] = $audit->created_at;
                $state['rejection_reason'] = $metadata['rejection_reason'] ?? null;
            } elseif ($audit->action === 'schedule_approved_by_vpaa') {
                $state['status'] = 'approved';
                $state['vpaa_reviewed_by'] = $audit->user_id;
                $state['vpaa_reviewed_at'] = $audit->created_at;
            } elseif ($audit->action === 'schedule_returned_by_vpaa') {
                $state['status'] = 'rejected_by_vpaa';
                $state['vpaa_reviewed_by'] = $audit->user_id;
                $state['vpaa_reviewed_at'] = $audit->created_at;
                $state['rejection_reason'] = $metadata['rejection_reason'] ?? null;
            } elseif ($audit->action === 'schedule_withdrawn') {
                $state['status'] = 'withdrawn';
                $state['withdrawn_by'] = $audit->user_id;
                $state['withdrawn_at'] = $audit->created_at;
            }
        }

        return $state;
    }

    private function metadataSectionIds(?string $metadata): Collection
    {
        $decoded = $this->decodeMetadata($metadata);

        return collect($decoded['selected_section_ids'] ?? $decoded['affected_section_ids'] ?? [])
            ->map('intval')
            ->filter()
            ->unique()
            ->values();
    }

    private function decodeMetadata(?string $metadata): array
    {
        if ($metadata === null || $metadata === '') {
            return [];
        }
        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
};
