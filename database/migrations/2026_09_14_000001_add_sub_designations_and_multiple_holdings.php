<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two changes to how designations are held.
     *
     * Sub-designations: a designation may sit under one other, one level deep --
     * "Director" over "Networking Dev't", "Research & Extension" and so on. The
     * parent then serves as a heading only; it is the sub-designations that are
     * assigned. Names only have to be unique among siblings now, so the global
     * unique index goes and the rule moves into validation.
     *
     * Several designations per instructor: up to three, held through
     * `designation_faculty`. `faculties.designation_id` stays as the instructor's
     * primary (first) designation so existing readers keep working, and
     * `faculties.deload_units` stays the copied total the scheduler reads.
     */
    public function up(): void
    {
        Schema::table('designations', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('designations')
                ->nullOnDelete();
        });

        Schema::table('designations', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::create('designation_faculty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained('designations')->cascadeOnDelete();
            // The order the instructor's designations are listed and printed in.
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['faculty_id', 'designation_id']);
        });

        // Every instructor already holding a designation keeps it, as their first.
        $now = now();
        DB::table('faculties')
            ->whereNotNull('designation_id')
            ->orderBy('id')
            ->select(['id', 'designation_id'])
            ->chunk(500, function ($rows) use ($now): void {
                DB::table('designation_faculty')->insert($rows->map(fn ($row): array => [
                    'faculty_id' => $row->id,
                    'designation_id' => $row->designation_id,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('designation_faculty');

        Schema::table('designations', function (Blueprint $table) {
            $table->unique('name');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
