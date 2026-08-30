<?php

namespace App\Console\Commands;

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
            'unlinked_scheduling_audits' => DB::table('scheduling_audit_logs')->whereNull('history_version_id')->count(),
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
