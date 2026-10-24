<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_recovery_plans', function (Blueprint $table) {
            $table->string('status')->default('preview')->index();
            $table->json('selected_groups')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        // Keep historical transfers identifiable without implying VPAA approval.
        DB::table('room_recovery_plans')->whereNotNull('applied_at')->update(['status' => 'applied']);
    }

    public function down(): void
    {
        Schema::table('room_recovery_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'selected_groups', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
