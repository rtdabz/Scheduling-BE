<?php

use App\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes field-course configuration to a department.
 *
 * `field_course_settings` had no `department_id` and a unique index on
 * `course_code`, so the whole institution shared one row per code: one
 * department's save deleted another's selection for the same code, and the
 * `course_code IS NULL` marker row enabled the feature everywhere at once.
 * Every sibling scheduling setting is per-department (audit findings #34, #35).
 *
 * `department_id` is nullable on purpose: a course with no owning department is a
 * shared minor, and its field-ness is genuinely institution-wide.
 *
 * The separate `enabled` marker row is dropped — "enabled" is now derived from
 * whether the department has any codes configured, which removes the one-way
 * switch that nothing could turn off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_course_settings', function (Blueprint $table): void {
            $table->foreignId('department_id')
                ->nullable()
                ->after('id')
                ->constrained('departments')
                ->cascadeOnDelete();
        });

        // MySQL needs the old unique index gone before the composite one lands.
        Schema::table('field_course_settings', function (Blueprint $table): void {
            $table->dropUnique('field_course_settings_course_code_unique');
        });

        // Attribute each configured code to the department that owns the course.
        // A code owned by several departments becomes one row per department.
        $codeRows = DB::table('field_course_settings')
            ->whereNotNull('course_code')
            ->get(['id', 'course_code', 'enabled']);

        foreach ($codeRows as $row) {
            $departmentIds = Course::query()
                ->whereRaw('UPPER(TRIM(course_code)) = ?', [strtoupper(trim((string) $row->course_code))])
                ->whereNotNull('department_id')
                ->distinct()
                ->pluck('department_id')
                ->all();

            if ($departmentIds === []) {
                // Shared minor, or the course no longer exists: leave it global.
                continue;
            }

            DB::table('field_course_settings')
                ->where('id', $row->id)
                ->update(['department_id' => $departmentIds[0]]);

            foreach (array_slice($departmentIds, 1) as $departmentId) {
                DB::table('field_course_settings')->insert([
                    'department_id' => $departmentId,
                    'course_code' => $row->course_code,
                    'enabled' => $row->enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // "Enabled" is derived from the presence of codes now.
        DB::table('field_course_settings')->whereNull('course_code')->delete();

        Schema::table('field_course_settings', function (Blueprint $table): void {
            $table->unique(['department_id', 'course_code'], 'field_course_settings_department_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('field_course_settings', function (Blueprint $table): void {
            $table->dropUnique('field_course_settings_department_code_unique');
        });

        // Collapse back to one row per code.
        $codes = DB::table('field_course_settings')->whereNotNull('course_code')->pluck('course_code')->unique();
        DB::table('field_course_settings')->delete();

        foreach ($codes as $code) {
            DB::table('field_course_settings')->insert([
                'course_code' => $code,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('field_course_settings')->insert([
            'course_code' => null,
            'enabled' => $codes->isNotEmpty(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('field_course_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->unique('course_code');
        });
    }
};
