<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the curriculum a per-section fact instead of a per-department singleton.
 *
 * Until now the code answered "which curriculum applies?" with
 * Curriculum::where('status','active')->first(), which is only unambiguous while
 * a department runs exactly one curriculum. A department mid-transition runs two:
 * the incoming cohort on the new curriculum, the upper years on the old one. The
 * section already carries term, department, program, year level and semester —
 * the exact key any curriculum map would need — so the pointer belongs there.
 *
 * schedules.curriculum_id records what a timetable was actually generated from.
 * It is stored rather than derived because curricula are editable and a section
 * can be re-pointed to a different curriculum later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sections') && ! Schema::hasColumn('sections', 'curriculum_id')) {
            Schema::table('sections', function (Blueprint $table): void {
                // restrictOnDelete: a curriculum that produced timetables must not
                // disappear out from under them. CurriculumController turns the
                // resulting integrity error into a readable 422.
                $table->foreignId('curriculum_id')
                    ->nullable()
                    ->after('program_id')
                    ->constrained('curriculum')
                    ->restrictOnDelete();

                $table->index(
                    ['term_id', 'department_id', 'year_level', 'curriculum_id'],
                    'sections_curriculum_lookup_index',
                );
            });
        }

        if (Schema::hasTable('schedules') && ! Schema::hasColumn('schedules', 'curriculum_id')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->foreignId('curriculum_id')
                    ->nullable()
                    ->after('section_id')
                    ->constrained('curriculum')
                    ->nullOnDelete();
            });
        }

        $this->backfillSections();
        $this->backfillSchedules();
    }

    public function down(): void
    {
        if (Schema::hasTable('schedules') && Schema::hasColumn('schedules', 'curriculum_id')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('curriculum_id');
            });
        }

        if (Schema::hasTable('sections') && Schema::hasColumn('sections', 'curriculum_id')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->dropIndex('sections_curriculum_lookup_index');
                $table->dropConstrainedForeignId('curriculum_id');
            });
        }
    }

    /**
     * Point every section at the curriculum its department was running when this
     * migration ran. A program-scoped curriculum wins over a department-wide one;
     * among equals the newest effective school year wins. Sections whose
     * department has published nothing stay NULL — the generator refuses those
     * loudly rather than falling back to an arbitrary row.
     */
    private function backfillSections(): void
    {
        if (! Schema::hasTable('sections') || ! Schema::hasTable('curriculum')) {
            return;
        }

        $curricula = DB::table('curriculum')
            ->where('status', 'active')
            ->get(['id', 'department_id', 'program_id', 'effective_school_year']);

        if ($curricula->isEmpty()) {
            return;
        }

        DB::table('sections')
            ->whereNull('curriculum_id')
            ->orderBy('id')
            ->select(['id', 'department_id', 'program_id'])
            ->chunk(200, function ($sections) use ($curricula): void {
                foreach ($sections as $section) {
                    $match = $curricula
                        ->filter(fn ($curriculum): bool => (int) $curriculum->department_id === (int) $section->department_id
                            && ($curriculum->program_id === null || (int) $curriculum->program_id === (int) $section->program_id))
                        ->sortByDesc(fn ($curriculum): string => sprintf(
                            '%d|%s',
                            $curriculum->program_id === null ? 0 : 1,
                            (string) $curriculum->effective_school_year,
                        ))
                        ->first();

                    if ($match === null) {
                        continue;
                    }

                    DB::table('sections')
                        ->where('id', $section->id)
                        ->update(['curriculum_id' => (int) $match->id]);
                }
            });
    }

    /** Existing timetables were generated from whatever their section now points at. */
    private function backfillSchedules(): void
    {
        if (! Schema::hasTable('schedules') || ! Schema::hasTable('sections')) {
            return;
        }

        DB::table('schedules')
            ->whereNull('schedules.curriculum_id')
            ->update([
                'schedules.curriculum_id' => DB::raw(
                    '(select sections.curriculum_id from sections where sections.id = schedules.section_id)'
                ),
            ]);
    }
};
