<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signatory columns defaulted to a named individual. The defaults become a
 * neutral placeholder; the saved settings row is left as the VPAA set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_settings', function (Blueprint $table): void {
            $table->string('president_name', 150)->default('College President')->change();
            $table->string('president_title', 150)->default('President')->change();
        });
    }

    public function down(): void
    {
        // The previous defaults named a real person; they are not restored.
    }
};
