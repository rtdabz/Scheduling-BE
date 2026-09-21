<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sections', 'deleted_at')) {
            // Permanently remove any previously archived (soft-deleted) sections
            DB::table('sections')->whereNotNull('deleted_at')->delete();

            Schema::table('sections', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sections', 'deleted_at')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }
};

