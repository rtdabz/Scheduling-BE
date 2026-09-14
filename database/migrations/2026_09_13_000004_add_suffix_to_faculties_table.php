<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A name suffix (Jr., Sr., II, III...) for instructors, kept apart from the
 * last name so sorting and searching by surname stay correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->string('suffix', 10)->nullable()->after('middle_name');
        });
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });
    }
};
