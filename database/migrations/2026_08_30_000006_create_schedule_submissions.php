<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        Schema::create('schedule_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('parent_submission_id')->nullable()->constrained('schedule_submissions')->nullOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 40)->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('dean_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dean_reviewed_at')->nullable();
            $table->foreignId('vpaa_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vpaa_reviewed_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('approval_override')->default(false);
            $table->string('approval_override_reason', 2000)->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'term_id', 'revision_number'], 'schedule_submission_revision_unique');
            $table->index(['department_id', 'term_id', 'status'], 'schedule_submission_scope_status_idx');
        });

        Schema::create('schedule_submission_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_submission_id')->constrained('schedule_submissions')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->string('state', 30)->default('included');
            $table->timestamps();
            $table->unique(['schedule_submission_id', 'section_id'], 'schedule_submission_section_unique');
            $table->index(['section_id', 'state']);
        });

        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->foreignId('schedule_submission_id')
                ->nullable()
                ->after('history_version_id')
                ->constrained('schedule_submissions')
                ->nullOnDelete();
        });

        $this->backfillSubmissions();
    }

    public function down(): void
    {
        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('schedule_submission_id');
        });
        Schema::dropIfExists('schedule_submission_sections');
        Schema::dropIfExists('schedule_submissions');
    }

    private function backfillSubmissions(): void
    {
        $scopes = DB::table('scheduling_audit_logs')
            ->whereIn('action', self::WORKFLOW_ACTIONS)
            ->whereNotNull('department_id')
            ->whereNotNull('term_id')
            ->select(['department_id', 'term_id'])
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            $audits = DB::table('scheduling_audit_logs')
                ->where('department_id', $scope->department_id)
                ->where('term_id', $scope->term_id)
                ->whereIn('action', self::WORKFLOW_ACTIONS)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            $submissions = $audits->where('action', 'schedule_submitted')->values();
            $parentSubmissionId = null;

            foreach ($submissions as $index => $submittedAudit) {
                $nextSubmission = $submissions->get($index + 1);
                $events = $audits->filter(function ($audit) use ($submittedAudit, $nextSubmission): bool {
                    if ($audit->id < $submittedAudit->id) {
                        return false;
                    }
                    if ($nextSubmission === null) {
                        return true;
                    }

                    return $audit->id < $nextSubmission->id;
                })->values();
                $sectionIds = $this->metadataSectionIds($submittedAudit->metadata);
                if ($sectionIds->isEmpty()) {
                    $sectionIds = DB::table('schedules')
                        ->where('department_id', $scope->department_id)
                        ->where('term_id', $scope->term_id)
                        ->pluck('section_id')
                        ->map('intval')
                        ->unique()
                        ->values();
                }

                $state = [
                    'status' => 'pending_dean',
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
                $withdrawnSectionIds = collect();

                foreach ($events as $event) {
                    $metadata = $this->decodeMetadata($event->metadata);
                    if ($event->action === 'schedule_approved_by_dean') {
                        $state['status'] = 'pending_vpaa';
                        $state['dean_reviewed_by'] = $event->user_id;
                        $state['dean_reviewed_at'] = $event->created_at;
                        $state['approval_override'] = (bool) ($metadata['approval_override'] ?? false);
                        $state['approval_override_reason'] = $metadata['approval_override_reason'] ?? null;
                    } elseif ($event->action === 'schedule_returned_by_dean') {
                        $state['status'] = 'rejected_by_dean';
                        $state['dean_reviewed_by'] = $event->user_id;
                        $state['dean_reviewed_at'] = $event->created_at;
                        $state['rejection_reason'] = $metadata['rejection_reason'] ?? null;
                    } elseif ($event->action === 'schedule_approved_by_vpaa') {
                        $state['status'] = 'approved';
                        $state['vpaa_reviewed_by'] = $event->user_id;
                        $state['vpaa_reviewed_at'] = $event->created_at;
                    } elseif ($event->action === 'schedule_returned_by_vpaa') {
                        $state['status'] = 'rejected_by_vpaa';
                        $state['vpaa_reviewed_by'] = $event->user_id;
                        $state['vpaa_reviewed_at'] = $event->created_at;
                        $state['rejection_reason'] = $metadata['rejection_reason'] ?? null;
                    } elseif ($event->action === 'schedule_withdrawn') {
                        $withdrawnSectionIds = $this->metadataSectionIds($event->metadata);
                        $state['status'] = $withdrawnSectionIds->isNotEmpty() && $withdrawnSectionIds->count() < $sectionIds->count()
                            ? 'partially_withdrawn'
                            : 'withdrawn';
                        $state['withdrawn_by'] = $event->user_id;
                        $state['withdrawn_at'] = $event->created_at;
                    }
                }

                $submissionId = DB::table('schedule_submissions')->insertGetId(array_merge($state, [
                    'department_id' => $scope->department_id,
                    'term_id' => $scope->term_id,
                    'parent_submission_id' => $parentSubmissionId,
                    'revision_number' => $index + 1,
                    'submitted_by' => $submittedAudit->user_id,
                    'submitted_at' => $submittedAudit->created_at,
                    'created_at' => $submittedAudit->created_at,
                    'updated_at' => $events->last()?->created_at ?? $submittedAudit->created_at,
                ]));

                foreach ($sectionIds as $sectionId) {
                    DB::table('schedule_submission_sections')->insert([
                        'schedule_submission_id' => $submissionId,
                        'section_id' => $sectionId,
                        'state' => $withdrawnSectionIds->contains($sectionId) ? 'withdrawn' : 'included',
                        'created_at' => $submittedAudit->created_at,
                        'updated_at' => $events->last()?->created_at ?? $submittedAudit->created_at,
                    ]);
                }

                DB::table('scheduling_audit_logs')
                    ->whereIn('id', $events->pluck('id'))
                    ->update(['schedule_submission_id' => $submissionId]);
                $parentSubmissionId = $submissionId;
            }
        }

        $this->backfillScopesWithoutSubmissionAudits();
    }

    private function backfillScopesWithoutSubmissionAudits(): void
    {
        $knownScopes = DB::table('schedule_submissions')
            ->select(['department_id', 'term_id'])
            ->distinct()
            ->get()
            ->mapWithKeys(
                static fn ($scope): array => ["{$scope->department_id}:{$scope->term_id}" => true]
            );
        $scheduleScopes = DB::table('schedules')
            ->whereIn('status', [
                'submitted', 'approved_by_dean', 'conditionally_approved', 'rejected_by_dean',
                'approved', 'faculty_assignment', 'finalized', 'rejected', 'revision',
            ])
            ->select(['department_id', 'term_id'])
            ->distinct()
            ->get();

        foreach ($scheduleScopes as $scope) {
            if ($knownScopes->has("{$scope->department_id}:{$scope->term_id}")) {
                continue;
            }
            $rows = DB::table('schedules')
                ->where('department_id', $scope->department_id)
                ->where('term_id', $scope->term_id)
                ->whereIn('status', [
                    'submitted', 'approved_by_dean', 'conditionally_approved', 'rejected_by_dean',
                    'approved', 'faculty_assignment', 'finalized', 'rejected', 'revision',
                ])
                ->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $statuses = $rows->pluck('status');
            $status = match (true) {
                $statuses->contains('submitted') => 'pending_dean',
                $statuses->contains('approved_by_dean'), $statuses->contains('conditionally_approved') => 'pending_vpaa',
                $statuses->contains('rejected_by_dean') => 'rejected_by_dean',
                $statuses->contains('rejected') => 'rejected_by_vpaa',
                $statuses->contains('revision') => 'withdrawn',
                default => 'approved',
            };
            $submissionId = DB::table('schedule_submissions')->insertGetId([
                'department_id' => $scope->department_id,
                'term_id' => $scope->term_id,
                'revision_number' => 1,
                'status' => $status,
                'submitted_by' => null,
                'submitted_at' => $rows->min('updated_at'),
                'dean_reviewed_by' => $rows->pluck('reviewed_by_dean')->filter()->first(),
                'dean_reviewed_at' => $rows->pluck('reviewed_at_dean')->filter()->max(),
                'vpaa_reviewed_by' => $rows->pluck('approved_by_vpaa')->filter()->first(),
                'vpaa_reviewed_at' => $rows->pluck('approved_at_vpaa')->filter()->max(),
                'rejection_reason' => $rows->pluck('rejection_reason')->filter()->first(),
                'approval_override' => (bool) $rows->pluck('approval_override')->filter()->first(),
                'approval_override_reason' => $rows->pluck('approval_override_reason')->filter()->first(),
                'created_at' => $rows->min('created_at'),
                'updated_at' => $rows->max('updated_at'),
            ]);
            foreach ($rows->pluck('section_id')->map('intval')->unique() as $sectionId) {
                DB::table('schedule_submission_sections')->insert([
                    'schedule_submission_id' => $submissionId,
                    'section_id' => $sectionId,
                    'state' => $status === 'withdrawn' ? 'withdrawn' : 'included',
                    'created_at' => $rows->min('created_at'),
                    'updated_at' => $rows->max('updated_at'),
                ]);
            }
        }
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
