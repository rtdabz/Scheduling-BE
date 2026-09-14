<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the academic "term" entity to "semester": the terms table, every
 * term_id foreign key, the indexes named after them, and the term identifiers
 * already persisted in audit, history and generation payloads.
 *
 * The 1st/2nd/summer value keeps its column name `semester`, so the Eloquent
 * relation to this table is `academicSemester` (sections has its own column).
 */
return new class extends Migration
{
    /** child table => ON DELETE behaviour of its foreign key */
    private const CHILD_TABLES = [
        'sections' => 'cascade',
        'schedules' => 'cascade',
        'schedule_recommendations' => 'cascade',
        'scheduling_audit_logs' => 'set null',
        'system_notifications' => 'set null',
        'schedule_generation_runs' => 'cascade',
        'schedule_history_versions' => 'set null',
        'schedule_submissions' => 'cascade',
        'room_requests' => 'cascade',
    ];

    /** table => [old index name => new index name] */
    private const INDEXES = [
        'sections' => [
            'sections_department_term_status_index' => 'sections_department_semester_status_index',
            'sections_department_program_term_index' => 'sections_department_program_semester_index',
        ],
        'schedules' => [
            'schedules_term_department_status_index' => 'schedules_semester_department_status_index',
            'schedules_department_program_term_index' => 'schedules_department_program_semester_index',
        ],
        'system_notifications' => [
            'system_notifications_type_department_id_term_id_index' => 'system_notifications_type_department_id_semester_id_index',
        ],
        'schedule_generation_runs' => [
            'sgr_dept_term_year_created_idx' => 'sgr_dept_semester_year_created_idx',
        ],
        'schedule_history_versions' => [
            // The generated name would exceed MySQL's 64-character limit.
            'schedule_history_versions_department_id_term_id_created_at_index' => 'shv_department_semester_created_index',
        ],
        'room_requests' => [
            'room_requests_room_id_term_id_status_index' => 'room_requests_room_id_semester_id_status_index',
            'room_requests_requesting_department_id_term_id_status_index' => 'room_requests_requesting_department_id_semester_id_status_index',
        ],
        'curriculum_course' => [
            'curriculum_course_term_lookup_index' => 'curriculum_course_semester_lookup_index',
        ],
    ];

    /** Persisted strings: [table, column, old, new] */
    private const PAYLOAD_REWRITES = [
        ['scheduling_audit_logs', 'metadata', '"activated_term_id"', '"activated_semester_id"'],
        ['schedule_history_items', 'before_snapshot', '"term_id"', '"semester_id"'],
        ['schedule_history_items', 'after_snapshot', '"term_id"', '"semester_id"'],
        ['schedule_history_items', 'snapshot_metadata', '"term_change"', '"semester_change"'],
        ['schedule_generation_runs', 'result', '"term_id"', '"semester_id"'],
    ];

    /** Persisted enum-like values: [table, column, old, new] */
    private const VALUE_REWRITES = [
        ['scheduling_audit_logs', 'action', 'term_activated', 'semester_activated'],
        ['scheduling_audit_logs', 'action', 'schedule_term_archived', 'schedule_semester_archived'],
        ['schedule_history_versions', 'action', 'schedule_term_archived', 'schedule_semester_archived'],
        ['schedule_history_versions', 'source', 'term_change', 'semester_change'],
    ];

    public function up(): void
    {
        $this->rename(
            fromTable: 'terms', toTable: 'semesters',
            fromColumn: 'term_id', toColumn: 'semester_id',
            indexes: self::INDEXES,
        );

        foreach (self::PAYLOAD_REWRITES as [$table, $column, $old, $new]) {
            $this->replaceInColumn($table, $column, $old, $new);
        }
        foreach (self::VALUE_REWRITES as [$table, $column, $old, $new]) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::VALUE_REWRITES as [$table, $column, $old, $new]) {
            DB::table($table)->where($column, $new)->update([$column => $old]);
        }
        foreach (self::PAYLOAD_REWRITES as [$table, $column, $old, $new]) {
            $this->replaceInColumn($table, $column, $new, $old);
        }

        $this->rename(
            fromTable: 'semesters', toTable: 'terms',
            fromColumn: 'semester_id', toColumn: 'term_id',
            indexes: array_map(fn (array $names) => array_flip($names), self::INDEXES),
        );
    }

    /** @param array<string, array<string, string>> $indexes */
    private function rename(string $fromTable, string $toTable, string $fromColumn, string $toColumn, array $indexes): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $fromKey = rtrim($fromTable, 's').'_key';
        $toKey = rtrim($toTable, 's').'_key';

        // The generated uniqueness key (MySQL only, see correct_relational_constraints).
        if ($mysql && Schema::hasColumn($fromTable, $fromKey)) {
            DB::statement("ALTER TABLE `{$fromTable}` DROP INDEX `{$fromTable}_live_{$fromKey}_unique`");
            DB::statement("ALTER TABLE `{$fromTable}` DROP COLUMN `{$fromKey}`");
        }

        foreach (array_keys(self::CHILD_TABLES) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign([$fromColumn]));
        }

        Schema::rename($fromTable, $toTable);
        $this->renameIndex($toTable, "{$fromTable}_is_active_index", "{$toTable}_is_active_index");

        foreach (self::CHILD_TABLES as $table => $onDelete) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($fromColumn, $toColumn));

            // MySQL keeps the index that backed the dropped foreign key.
            $this->renameIndex($table, "{$table}_{$fromColumn}_foreign", "{$table}_{$toColumn}_foreign");
            foreach ($indexes[$table] ?? [] as $old => $new) {
                $this->renameIndex($table, $old, $new);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($toColumn, $toTable, $onDelete): void {
                $blueprint->foreign($toColumn)->references('id')->on($toTable)->onDelete($onDelete);
            });
        }

        foreach ($indexes['curriculum_course'] ?? [] as $old => $new) {
            $this->renameIndex('curriculum_course', $old, $new);
        }

        if ($mysql) {
            DB::statement(
                "ALTER TABLE `{$toTable}` ADD COLUMN `{$toKey}` VARCHAR(300) "
                ."GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, CONCAT(`academic_year`, '|', `semester`), NULL)) VIRTUAL"
            );
            DB::statement("ALTER TABLE `{$toTable}` ADD UNIQUE KEY `{$toTable}_live_{$toKey}_unique` (`{$toKey}`)");
        }
    }

    private function renameIndex(string $table, string $from, string $to): void
    {
        if (Schema::hasIndex($table, $from)) {
            try {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameIndex($from, $to));
            } catch (\Throwable) {
                // MariaDB < 10.5.2 does not support ALTER TABLE ... RENAME INDEX.
                // The existing index continues to cover the column under its previous name.
            }
        }
    }

    private function replaceInColumn(string $table, string $column, string $old, string $new): void
    {
        DB::update(
            "UPDATE {$table} SET {$column} = REPLACE({$column}, ?, ?) WHERE {$column} LIKE ?",
            [$old, $new, '%'.$old.'%'],
        );
    }
};
