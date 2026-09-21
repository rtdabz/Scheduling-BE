<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A program's major is part of its identity: BSED "Major in English" and
     * BSED "Major in Mathematics" are two programs that share one code, so the
     * major has to sit inside the uniqueness key instead of beside it. It is
     * stored as '' rather than NULL so that MySQL actually enforces that key
     * (NULLs never collide in a unique index).
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            if (Schema::hasIndex('programs', 'programs_department_id_cluster_index')) {
                $table->dropIndex(['department_id', 'cluster']);
            }

            $table->renameColumn('cluster', 'major');
        });

        DB::table('programs')->whereNull('major')->update(['major' => '']);

        // The replacement key is added before the old one is dropped: the
        // department_id foreign key leans on whichever index starts with it.
        Schema::table('programs', function (Blueprint $table): void {
            $table->string('major')->default('')->nullable(false)->change();
            $table->unique(['department_id', 'code', 'major'], 'programs_department_code_major_unique');
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropUnique('programs_department_code_unique');
            $table->index(['department_id', 'major']);
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropIndex(['department_id', 'major']);
            $table->renameColumn('major', 'cluster');
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->string('cluster')->nullable()->default(null)->change();
        });

        DB::table('programs')->where('cluster', '')->update(['cluster' => null]);

        Schema::table('programs', function (Blueprint $table): void {
            $table->unique(['department_id', 'code'], 'programs_department_code_unique');
        });

        Schema::table('programs', function (Blueprint $table): void {
            $table->dropUnique('programs_department_code_major_unique');
            $table->index(['department_id', 'cluster']);
        });
    }
};
