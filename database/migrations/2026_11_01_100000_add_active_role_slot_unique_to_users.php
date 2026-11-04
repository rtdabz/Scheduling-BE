<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database backstop for the one-active-holder rule enforced by AdministrativeRoleSlots:
 * at most one live, active dean or secretary per department and one program head per program.
 * Accounts without a scope are not covered here, matching the nullable scope columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw("role, CASE WHEN role = 'program_head' THEN program_id ELSE department_id END AS scope_id, COUNT(*) AS holders")
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('role', ['dean', 'secretary', 'program_head'])
            ->groupByRaw("role, CASE WHEN role = 'program_head' THEN program_id ELSE department_id END")
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->filter(fn (object $row): bool => $row->scope_id !== null);

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->map(fn (object $row): string => "{$row->role} in scope {$row->scope_id} ({$row->holders} active)")->implode('; ');

            throw new RuntimeException("Deactivate duplicate administrative role holders before migrating: {$list}.");
        }

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX `users_active_department_role_unique` ON `users` (`role`, `department_id`) WHERE `deleted_at` IS NULL AND `is_active` = 1 AND `role` IN ('dean', 'secretary')");
            DB::statement("CREATE UNIQUE INDEX `users_active_program_head_unique` ON `users` (`program_id`) WHERE `deleted_at` IS NULL AND `is_active` = 1 AND `role` = 'program_head'");

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE `users` ADD COLUMN `active_role_slot` VARCHAR(64) '
            ."GENERATED ALWAYS AS (IF(`deleted_at` IS NULL AND `is_active` = 1, CASE "
            ."WHEN `role` IN ('dean', 'secretary') THEN CONCAT(`role`, '|department|', `department_id`) "
            ."WHEN `role` = 'program_head' THEN CONCAT('program_head|program|', `program_id`) "
            .'ELSE NULL END, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `users_active_role_slot_unique` (`active_role_slot`)');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS `users_active_department_role_unique`');
            DB::statement('DROP INDEX IF EXISTS `users_active_program_head_unique`');

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `users` DROP INDEX `users_active_role_slot_unique`');
        DB::statement('ALTER TABLE `users` DROP COLUMN `active_role_slot`');
    }
};
