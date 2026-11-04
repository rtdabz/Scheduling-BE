<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source notes printed on a curriculum document (text, colour runs, provenance, scope). Nullable so existing
 * curricula, which never recorded notes, keep reading as "no notes" instead of an invented value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum', function (Blueprint $table): void {
            $table->json('notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
