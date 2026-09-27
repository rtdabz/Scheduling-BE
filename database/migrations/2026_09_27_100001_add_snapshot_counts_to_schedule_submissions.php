<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a submission contained when it was sent, kept on the submission itself.
 *
 * The approval history counted sections from the submission's section links
 * and subjects from the meetings that exist now. Deleting a section drops its
 * links and regenerating a recalled section deletes its meetings, so past
 * submissions read "0 sections" / "0 subjects" as if the review had removed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->unsignedInteger('section_count')->nullable()->after('status');
            $table->unsignedInteger('subject_count')->nullable()->after('section_count');
        });

        // The submit audit entry recorded the sections sent; subjects were
        // never recorded, so older submissions keep the live count for those.
        DB::table('scheduling_audit_logs')
            ->where('action', 'schedule_submitted')
            ->whereNotNull('schedule_submission_id')
            ->orderBy('id')
            ->get(['schedule_submission_id', 'metadata'])
            ->each(function (object $log): void {
                $sectionIds = json_decode((string) $log->metadata, true)['selected_section_ids'] ?? null;
                if (is_array($sectionIds)) {
                    DB::table('schedule_submissions')
                        ->where('id', $log->schedule_submission_id)
                        ->whereNull('section_count')
                        ->update(['section_count' => count($sectionIds)]);
                }
            });

        // Whatever still has its links and was not in the audit log.
        DB::table('schedule_submissions')
            ->whereNull('section_count')
            ->update(['section_count' => DB::raw(
                '(SELECT COUNT(*) FROM schedule_submission_sections WHERE schedule_submission_sections.schedule_submission_id = schedule_submissions.id)'
            )]);
        DB::table('schedule_submissions')->where('section_count', 0)->update(['section_count' => null]);
    }

    public function down(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->dropColumn(['section_count', 'subject_count']);
        });
    }
};
