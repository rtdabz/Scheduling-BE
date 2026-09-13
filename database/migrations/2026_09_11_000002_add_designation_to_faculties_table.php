<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable because most instructors hold no administrative post, and
     * nullOnDelete so archiving a designation releases its holders back to a
     * plain teaching load rather than cascading the instructors away.
     *
     * Note this is NOT `administrative_role`, which mirrors the linked user
     * account's role and is written by UserFacultyProfileService. A designation
     * is institution data the roster maintains; the two are independent.
     */
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->foreignId('designation_id')
                ->nullable()
                ->after('administrative_role')
                ->constrained('designations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designation_id');
        });
    }
};
