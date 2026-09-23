<?php

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditDatabaseIntegrityCommand extends Command
{
    protected $signature = 'database:audit-integrity {--json : Print JSON output}';

    protected $description = 'Audit scheduling relationships, statuses, history coverage, and generation persistence.';

    public function handle(): int
    {
        $checks = [
            'duplicate_active_curriculum_scopes' => DB::table('curriculum')
                ->where('status', 'active')
                ->select('department_id', 'program_id', DB::raw('COUNT(*) AS count'))
                ->groupBy('department_id', 'program_id')
                ->having('count', '>', 1)->get()->map(fn ($r) => (array) $r)->values()->all(),
            'active_on_site_without_room' => DB::table('schedules')->whereNull('deleted_at')->where('mode', 'on-site')->whereNull('room_id')->count(),
            'archived_on_site_without_room' => DB::table('schedules')->whereNotNull('deleted_at')->where('mode', 'on-site')->whereNull('room_id')->count(),
            'faculty_done_without_faculty' => DB::table('schedules')->where('faculty_assignment_done', 1)->whereNull('faculty_id')->count(),
            'orphan_schedule_splits' => DB::table('schedule_splits')->leftJoin('schedules', 'schedules.id', '=', 'schedule_splits.schedule_id')->whereNull('schedules.id')->count(),
            'orphan_curriculum_courses' => DB::table('curriculum_course')
                ->leftJoin('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
                ->leftJoin('courses', 'courses.id', '=', 'curriculum_course.course_id')
                ->where(fn ($q) => $q->whereNull('curriculum.id')->orWhereNull('courses.id'))->count(),
            'history_items_without_live_schedule' => DB::table('schedule_history_items')->leftJoin('schedules', 'schedules.id', '=', 'schedule_history_items.original_schedule_id')->whereNotNull('schedule_history_items.original_schedule_id')->whereNull('schedules.id')->count(),
            'completed_runs_without_result' => DB::table('schedule_generation_runs')->where('status', 'completed')->whereNull('result')->count(),
            'generation_run_statuses' => DB::table('schedule_generation_runs')->select('status', DB::raw('COUNT(*) AS count'))->groupBy('status')->pluck('count', 'status')->all(),
            'history_versions' => DB::table('schedule_history_versions')->count(),
            'history_items' => DB::table('schedule_history_items')->count(),
            // An account created before accounts could link to an existing
            // instructor got its own profile, leaving the same person on the
            // roster twice. Reported, never merged: which row holds the real
            // schedules and loads is a human call.
            'account_faculty_duplicating_roster_entry' => DB::table('faculties AS linked')
                ->join('faculties AS roster', function ($join) {
                    $join->on('roster.department_id', '=', 'linked.department_id')
                        ->on('roster.id', '!=', 'linked.id')
                        ->whereRaw('LOWER(roster.first_name) = LOWER(linked.first_name)')
                        ->whereRaw('LOWER(roster.last_name) = LOWER(linked.last_name)')
                        ->whereNull('roster.user_id')
                        ->whereNull('roster.deleted_at');
                })
                ->whereNotNull('linked.user_id')
                ->whereNull('linked.deleted_at')
                ->select('linked.id AS account_faculty_id', 'roster.id AS roster_faculty_id', 'linked.user_id', 'linked.first_name', 'linked.last_name', 'linked.department_id')
                ->get()->map(fn ($r) => (array) $r)->values()->all(),
            'unlinked_scheduling_audits' => DB::table('scheduling_audit_logs')->whereNull('history_version_id')->count(),

            // Uniqueness rules. The database enforces these on MySQL since
            // 2026_09_23_000004; the checks cover SQLite and older copies.
            'active_semesters' => DB::table('semesters')->where('is_active', true)->whereNull('deleted_at')->count(),
            'duplicate_section_names' => DB::table('sections')
                ->select('department_id', 'semester_id', DB::raw('UPPER(TRIM(section_name)) AS section_name'), DB::raw('COUNT(*) AS count'))
                ->groupBy('department_id', 'semester_id', DB::raw('UPPER(TRIM(section_name))'))
                ->havingRaw('COUNT(*) > 1')->get()->map(fn ($r) => (array) $r)->values()->all(),
            'duplicate_shared_course_codes' => DB::table('courses')->whereNull('department_id')
                ->select('course_code', DB::raw('COUNT(*) AS count'))
                ->groupBy('course_code')->havingRaw('COUNT(*) > 1')
                ->get()->map(fn ($r) => (array) $r)->values()->all(),

            // Links held as text or copies that can drift from their source.
            'field_course_settings_without_course' => DB::table('field_course_settings AS settings')
                ->whereNotExists(fn ($q) => $q->from('courses')->whereColumn('courses.course_code', 'settings.course_code')->whereNull('courses.deleted_at'))
                ->pluck('settings.course_code')->all(),
            'live_schedules_without_curriculum' => DB::table('schedules')->whereNull('deleted_at')->whereNull('curriculum_id')->count(),
            // courses.year_level/semester mirrors the newest active curriculum.
            'course_placement_differs_from_curriculum' => (function (): array {
                $placements = Course::curriculumPlacements();

                return DB::table('courses')->whereIn('id', array_keys($placements))
                    ->get(['id', 'year_level', 'semester'])
                    ->filter(fn ($c) => (string) $c->year_level !== $placements[$c->id]['year_level']
                        || (string) $c->semester !== $placements[$c->id]['semester'])
                    ->pluck('id')->values()->all();
            })(),
            // users.role, the Spatie role assignment, and faculties.administrative_role.
            'users_role_not_assigned' => DB::table('users')
                ->whereNotExists(fn ($q) => $q->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', \App\Models\User::class)
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->whereColumn('roles.name', 'users.role'))
                ->pluck('users.id')->all(),
            'faculty_admin_role_differs_from_user' => DB::table('faculties')
                ->join('users', 'users.id', '=', 'faculties.user_id')
                ->whereNull('faculties.deleted_at')
                ->whereNotNull('faculties.administrative_role')
                ->whereColumn('faculties.administrative_role', '!=', 'users.role')
                ->pluck('faculties.id')->all(),
            'non_canonical_days' => collect(['schedules', 'department_forced_course_days', 'room_request_windows'])
                ->mapWithKeys(fn (string $table) => [$table => DB::table($table)
                    ->whereNotIn('day', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
                    ->distinct()->pluck('day')->all()])
                ->filter()->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($checks as $name => $value) {
            $this->line($name . ': ' . (is_array($value) ? json_encode($value) : $value));
        }

        $this->comment('VPAA accounts are not modified or included in cleanup operations.');
        return self::SUCCESS;
    }
}
