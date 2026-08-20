<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Institution-wide document signatories.
 *
 * The president's name was hardcoded in the print/PDF builders, so a change of
 * officer meant a code change. Single row: these are college-wide values, not
 * per-department ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_settings', function (Blueprint $table) {
            $table->id();
            $table->string('president_name', 150)->default('ATTY. NADYA B. EMANO-ELIPE');
            $table->string('president_title', 150)->default('OIC-College President');
            $table->timestamps();
        });

        // Seed the values the print builders already used, so documents printed
        // before anyone visits Settings come out unchanged.
        DB::table('institution_settings')->insert([
            'president_name' => 'ATTY. NADYA B. EMANO-ELIPE',
            'president_title' => 'OIC-College President',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_settings');
    }
};
