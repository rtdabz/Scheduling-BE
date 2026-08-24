<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->boolean('approval_override')->default(false)->after('approved_at_vpaa');
            $table->string('approval_override_reason', 2000)->nullable()->after('approval_override');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn(['approval_override', 'approval_override_reason']);
        });
    }
};
