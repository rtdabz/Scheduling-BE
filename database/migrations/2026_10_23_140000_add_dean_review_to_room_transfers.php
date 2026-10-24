<?php

use App\Services\SystemNotificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_recovery_plans', function (Blueprint $table) {
            $table->foreignId('dean_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dean_reviewed_at')->nullable();
        });

        DB::transaction(function () {
            $pending = DB::table('room_recovery_plans')->where('status', 'pending_approval')->get();
            DB::table('room_recovery_plans')->where('status', 'pending_approval')->update(['status' => 'pending_dean']);
            DB::table('room_recovery_plans')->where('status', 'rejected')->update(['status' => 'rejected_by_vpaa']);
            foreach ($pending as $plan) {
                $reference = substr($plan->id, 0, 8);
                app(SystemNotificationService::class)->notifyRoles(['dean'], 'room_transfer_submitted', 'Room transfer pending Dean approval',
                    "Room transfer {$reference} requires Dean approval. Review the proposed changes in Schedule Approval.",
                    departmentId: $plan->department_id, semesterId: $plan->semester_id,
                    metadata: ['plan_id' => $plan->id, 'semester_id' => $plan->semester_id]);
            }
        });
    }

    public function down(): void
    {
        DB::table('room_recovery_plans')->whereIn('status', ['pending_dean', 'pending_vpaa'])->update(['status' => 'pending_approval']);
        DB::table('room_recovery_plans')->whereIn('status', ['rejected_by_dean', 'rejected_by_vpaa'])->update(['status' => 'rejected']);
        Schema::table('room_recovery_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dean_reviewed_by');
            $table->dropColumn('dean_reviewed_at');
        });
    }
};
