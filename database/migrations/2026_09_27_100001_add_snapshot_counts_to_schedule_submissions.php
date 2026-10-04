<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->unsignedInteger('section_count')->nullable()->after('status');
            $table->unsignedInteger('subject_count')->nullable()->after('section_count');
        });

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
