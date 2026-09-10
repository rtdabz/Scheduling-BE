<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the index and constraint names left behind when `curricula` became
 * `curriculum`. Renaming a table does not rename what hangs off it, so the
 * schema still announced the old name in three places. Cosmetic, but the stale
 * names are the sort of thing that sends the next reader looking for a table
 * that no longer exists.
 *
 * Indexes are dropped and recreated rather than renamed: MariaDB, which this
 * project runs on, has no ALTER TABLE ... RENAME INDEX. Drop-then-add is
 * understood by both engines.
 *
 * Every step is guarded, so the migration can be re-run over a database left
 * half-converted by an earlier failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        // information_schema and in-place index changes are MySQL/MariaDB-only.
        // On SQLite the index names come from whatever the create migrations
        // declared, so there is nothing stale to rename.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeyIfExists('curricula_department_id_foreign');

        $this->renameIndex(
            'curricula_code_unique',
            'curriculum_code_unique',
            'ADD UNIQUE KEY `curriculum_code_unique` (`code`)'
        );

        $this->renameIndex(
            'curricula_department_id_foreign',
            'curriculum_department_id_foreign',
            'ADD KEY `curriculum_department_id_foreign` (`department_id`)'
        );

        if (! $this->foreignKeyExists('curriculum_department_id_foreign')) {
            DB::statement(
                'ALTER TABLE `curriculum` ADD CONSTRAINT `curriculum_department_id_foreign` '
                .'FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeyIfExists('curriculum_department_id_foreign');

        $this->renameIndex(
            'curriculum_code_unique',
            'curricula_code_unique',
            'ADD UNIQUE KEY `curricula_code_unique` (`code`)'
        );

        $this->renameIndex(
            'curriculum_department_id_foreign',
            'curricula_department_id_foreign',
            'ADD KEY `curricula_department_id_foreign` (`department_id`)'
        );

        if (! $this->foreignKeyExists('curricula_department_id_foreign')) {
            DB::statement(
                'ALTER TABLE `curriculum` ADD CONSTRAINT `curricula_department_id_foreign` '
                .'FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL'
            );
        }
    }

    private function renameIndex(string $from, string $to, string $addClause): void
    {
        if ($this->indexExists($from)) {
            DB::statement('ALTER TABLE `curriculum` DROP INDEX `'.$from.'`');
        }

        if (! $this->indexExists($to)) {
            DB::statement('ALTER TABLE `curriculum` '.$addClause);
        }
    }

    private function dropForeignKeyIfExists(string $name): void
    {
        if ($this->foreignKeyExists($name)) {
            DB::statement('ALTER TABLE `curriculum` DROP FOREIGN KEY `'.$name.'`');
        }
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.statistics '
            ."WHERE table_schema = DATABASE() AND table_name = 'curriculum' AND index_name = ? LIMIT 1",
            [$name]
        ) !== null;
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.table_constraints '
            ."WHERE constraint_schema = DATABASE() AND table_name = 'curriculum' "
            ."AND constraint_name = ? AND constraint_type = 'FOREIGN KEY' LIMIT 1",
            [$name]
        ) !== null;
    }
};
