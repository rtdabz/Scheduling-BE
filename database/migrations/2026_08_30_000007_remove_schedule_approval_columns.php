<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by_dean');
            $table->dropConstrainedForeignId('approved_by_vpaa');
            $table->dropColumn([
                'reviewed_at_dean',
                'approved_at_vpaa',
                'rejection_reason',
                'approval_override',
                'approval_override_reason',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by_dean')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at_dean')->nullable()->after('reviewed_by_dean');
            $table->foreignId('approved_by_vpaa')->nullable()->after('reviewed_at_dean')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at_vpaa')->nullable()->after('approved_by_vpaa');
            $table->boolean('approval_override')->default(false)->after('approved_at_vpaa');
            $table->string('approval_override_reason', 2000)->nullable()->after('approval_override');
        });
    }
};
