<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('programs')->whereNull('name')->update(['name' => DB::raw('code')]);

        Schema::table('programs', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();
        });
    }
};
