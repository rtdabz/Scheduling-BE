<?php

use App\Models\ScheduleSubmission;
use App\Services\Scheduling\ScheduleSignatories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the names and positions printed in a schedule's signature block (Prepared by, Reviewed by,
 * Recommended by, Approved by) with each submission and each Approved / Rejected / Recalled history entry,
 * so replacing an official or editing their account no longer rewrites past schedules.
 *
 * Existing records are backfilled now: reviewers come from the recorded reviewer columns, everyone else
 * from the officials in office today, and from this point on those names stay as recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->json('signatories')->nullable()->after('approval_override_reason');
        });
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->json('signatories')->nullable()->after('change_summary');
        });

        $service = app(ScheduleSignatories::class);
        $copies = [];
        ScheduleSubmission::query()->whereNull('signatories')->orderBy('id')->chunkById(200, function ($submissions) use ($service, &$copies): void {
            foreach ($submissions as $submission) {
                $stored = $service->stored($submission);
                DB::table('schedule_submissions')->where('id', $submission->id)->update(['signatories' => json_encode($stored)]);
                $copies[(int) $submission->id] = json_encode([...$stored, 'frozen' => true]);
            }
        });

        $links = DB::table('scheduling_audit_logs')
            ->whereNotNull('history_version_id')
            ->whereNotNull('schedule_submission_id')
            ->get(['history_version_id', 'schedule_submission_id']);
        foreach ($links as $link) {
            if (isset($copies[(int) $link->schedule_submission_id])) {
                DB::table('schedule_history_versions')
                    ->where('id', $link->history_version_id)
                    ->whereNull('signatories')
                    ->update(['signatories' => $copies[(int) $link->schedule_submission_id]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->dropColumn('signatories');
        });
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->dropColumn('signatories');
        });
    }
};
