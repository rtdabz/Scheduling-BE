<?php

use App\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the second copies of data the schema held twice.
 *
 * - faculties.designation_id repeated the first row of designation_faculty.
 *   The pivot is the record; any id missing from it is carried over first.
 * - Days are stored by name everywhere. faculty_availabilities held a 0-6
 *   index (0 = Monday) and room_request_windows a free string; both now use
 *   the same day enum as schedules.day.
 * - courses.year_level/semester is brought in line with the newest active
 *   curriculum, which Course::syncPlacementFromCurricula() maintains from now on.
 */
return new class extends Migration
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function up(): void
    {
        if (Schema::hasColumn('faculties', 'designation_id')) {
            $missing = DB::table('faculties')
                ->whereNotNull('designation_id')
                ->whereNotExists(fn ($q) => $q->from('designation_faculty')
                    ->whereColumn('designation_faculty.faculty_id', 'faculties.id')
                    ->whereColumn('designation_faculty.designation_id', 'faculties.designation_id'))
                ->get(['id', 'designation_id']);
            foreach ($missing as $row) {
                DB::table('designation_faculty')->insert([
                    'faculty_id' => $row->id,
                    'designation_id' => $row->designation_id,
                    'position' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('faculties', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('designation_id');
            });
        }

        if (Schema::hasColumn('faculty_availabilities', 'day_index')) {
            Schema::table('faculty_availabilities', function (Blueprint $table): void {
                $table->enum('day', self::DAYS)->nullable()->after('faculty_id');
            });
            foreach (self::DAYS as $index => $day) {
                DB::table('faculty_availabilities')->where('day_index', $index)->update(['day' => $day]);
            }
            if (DB::table('faculty_availabilities')->whereNull('day')->exists()) {
                throw new RuntimeException('faculty_availabilities holds a day_index outside 0-6. Correct it before applying this migration.');
            }
            Schema::table('faculty_availabilities', function (Blueprint $table): void {
                $table->enum('day', self::DAYS)->nullable(false)->change();
                $table->dropColumn('day_index');
            });
        }

        if (DB::table('room_request_windows')->whereNotIn('day', self::DAYS)->exists()) {
            throw new RuntimeException('room_request_windows holds a day that is not a full day name. Correct it before applying this migration.');
        }
        Schema::table('room_request_windows', function (Blueprint $table): void {
            $table->enum('day', self::DAYS)->change();
        });

        Course::syncPlacementFromCurricula(DB::table('curriculum_course')->distinct()->pluck('course_id'));
    }

    public function down(): void
    {
        Schema::table('room_request_windows', function (Blueprint $table): void {
            $table->string('day', 16)->change();
        });

        if (! Schema::hasColumn('faculty_availabilities', 'day_index')) {
            Schema::table('faculty_availabilities', function (Blueprint $table): void {
                $table->tinyInteger('day_index')->default(0)->after('faculty_id');
            });
            foreach (self::DAYS as $index => $day) {
                DB::table('faculty_availabilities')->where('day', $day)->update(['day_index' => $index]);
            }
            Schema::table('faculty_availabilities', function (Blueprint $table): void {
                $table->dropColumn('day');
            });
        }

        if (! Schema::hasColumn('faculties', 'designation_id')) {
            Schema::table('faculties', function (Blueprint $table): void {
                $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            });
            DB::table('designation_faculty')->where('position', 0)->orderBy('id')->get(['faculty_id', 'designation_id'])
                ->each(fn ($row) => DB::table('faculties')->where('id', $row->faculty_id)->update(['designation_id' => $row->designation_id]));
        }
    }
};
