<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scopes = DB::table('scheduling_audit_logs as audit')
            ->join('schedule_submissions as submission', 'submission.id', '=', 'audit.schedule_submission_id')
            ->whereColumn('audit.created_at', '<', 'submission.submitted_at')
            ->select(['audit.department_id', 'audit.term_id'])
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            DB::transaction(function () use ($scope): void {
                $submissions = DB::table('schedule_submissions')
                    ->where('department_id', $scope->department_id)
                    ->where('term_id', $scope->term_id)
                    ->orderBy('revision_number')
                    ->lockForUpdate()
                    ->get();
                $firstSubmission = $submissions->first();
                if ($firstSubmission === null) {
                    return;
                }

                $audits = DB::table('scheduling_audit_logs')
                    ->where('department_id', $scope->department_id)
                    ->where('term_id', $scope->term_id)
                    ->where('created_at', '<', $firstSubmission->submitted_at)
                    ->whereNotNull('schedule_submission_id')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($audits->isEmpty()) {
                    return;
                }

                foreach ($submissions->sortByDesc('revision_number') as $submission) {
                    DB::table('schedule_submissions')
                        ->where('id', $submission->id)
                        ->update(['revision_number' => $submission->revision_number + 1]);
                }

                $state = $this->deriveState($audits);
                $predecessorId = DB::table('schedule_submissions')->insertGetId(array_merge($state, [
                    'department_id' => $scope->department_id,
                    'term_id' => $scope->term_id,
                    'revision_number' => 1,
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
                        'schedule_submission_id' => $predecessorId,
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
                    ->update(['schedule_submission_id' => $predecessorId]);
                DB::table('schedule_submissions')
                    ->where('id', $firstSubmission->id)
                    ->whereNull('parent_submission_id')
                    ->update(['parent_submission_id' => $predecessorId]);
            });
        }
    }

    public function down(): void
    {
        // Historical normalization is retained on rollback.
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
            if ($audit->action === 'schedule_approved_by_dean') {
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
