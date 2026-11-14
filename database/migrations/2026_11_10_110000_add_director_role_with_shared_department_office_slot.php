<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reintroduces Director as a department office role that shares one slot with Secretary.
 *
 * 1. Quarantine legacy Director accounts. 2026_09_27_100002 removed the Director Spatie role but
 *    kept users.role = 'director'. Supporting the role again must not silently restore their
 *    access, so every such account (live or archived) is deactivated, its API tokens and pending
 *    setup/reset links are revoked, and an audit entry is written. History, faculty links and
 *    archived state are untouched. The VPAA reviews and reactivates them from Users; that goes
 *    through the shared-slot check and reassigns the Spatie role (User::booted, UserController).
 * 2. Create the Director Spatie role with exactly the Secretary capability set (config).
 * 3. Preflight: refuse to continue while a department has more than one active, live
 *    Secretary/Director.
 * 4. Replace the database backstop so Secretary and Director produce the same department slot:
 *    - SQLite: a partial unique index on department_id for active, live Secretary OR Director.
 *    - MySQL/MariaDB: the generated active_role_slot column maps both roles to one key.
 *    Dean (per department) and Program Head (per program) uniqueness are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->quarantineLegacyDirectors();
        $this->createDirectorRole();
        $this->assertNoSharedSlotDuplicates();

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS `users_active_department_role_unique`');
            DB::statement('DROP INDEX IF EXISTS `users_active_department_office_unique`');
            DB::statement("CREATE UNIQUE INDEX `users_active_department_role_unique` ON `users` (`role`, `department_id`) WHERE `deleted_at` IS NULL AND `is_active` = 1 AND `role` = 'dean'");
            DB::statement("CREATE UNIQUE INDEX `users_active_department_office_unique` ON `users` (`department_id`) WHERE `deleted_at` IS NULL AND `is_active` = 1 AND `role` IN ('secretary', 'director')");

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->replaceGeneratedSlot(
            "WHEN `role` = 'dean' THEN CONCAT('dean|department|', `department_id`) "
            ."WHEN `role` IN ('secretary', 'director') THEN CONCAT('department_office|department|', `department_id`) "
        );
    }

    public function down(): void
    {
        $active = DB::table('users')->whereNull('deleted_at')->where('is_active', true)->where('role', 'director')->count();
        if ($active > 0) {
            throw new RuntimeException("Deactivate the {$active} active Director account(s) before rolling back the shared Secretary/Director slot.");
        }

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS `users_active_department_office_unique`');
            DB::statement('DROP INDEX IF EXISTS `users_active_department_role_unique`');
            DB::statement("CREATE UNIQUE INDEX `users_active_department_role_unique` ON `users` (`role`, `department_id`) WHERE `deleted_at` IS NULL AND `is_active` = 1 AND `role` IN ('dean', 'secretary')");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->replaceGeneratedSlot("WHEN `role` IN ('dean', 'secretary') THEN CONCAT(`role`, '|department|', `department_id`) ");
        }

        $roleIds = DB::table('roles')->where('name', 'director')->pluck('id');
        DB::table('model_has_roles')->whereIn('role_id', $roleIds)->delete();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function quarantineLegacyDirectors(): void
    {
        // The Director Spatie role was deleted by 2026_09_27_100002 and is only recreated below,
        // so every users.role = 'director' row seen here predates the new Director role.
        if (DB::table('roles')->where('name', 'director')->where('guard_name', 'api')->exists()) {
            return;
        }

        $legacy = DB::table('users')->where('role', 'director')->get(['id', 'name', 'username', 'email', 'department_id', 'is_active', 'deleted_at']);
        if ($legacy->isEmpty()) {
            return;
        }

        $ids = $legacy->pluck('id')->all();
        $emails = $legacy->pluck('email')->filter()->map(fn (string $email): string => strtolower($email))->all();
        $now = now();

        DB::table('users')->whereIn('id', $ids)->update(['is_active' => false, 'remember_token' => null]);
        DB::table('personal_access_tokens')
            ->whereIn('tokenable_type', ['App\\Models\\User', 'user', 'users'])
            ->whereIn('tokenable_id', $ids)
            ->delete();
        DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->whereIn('model_id', $ids)->delete();
        foreach (['password_reset_tokens', 'account_invitation_tokens'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->whereIn(DB::raw('LOWER(email)'), $emails)->delete();
            }
        }

        DB::table('authentication_audit_logs')->insert($legacy->map(fn (object $user): array => [
            'actor_user_id' => null,
            'subject_user_id' => $user->id,
            'event' => 'legacy_director_quarantined',
            'ip_address' => null,
            'user_agent' => 'migration:2026_11_10_110000',
            'metadata' => json_encode([
                'reason' => 'Director role reintroduced; legacy account disabled pending VPAA review and reactivation.',
                'was_active' => (bool) $user->is_active,
                'archived' => $user->deleted_at !== null,
                '_subject' => [
                    'id' => $user->id, 'name' => $user->name, 'username' => $user->username,
                    'role' => 'director', 'department_id' => $user->department_id,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    private function createDirectorRole(): void
    {
        $capabilities = app(CapabilityRegistry::class)->roleDefaults()['director'] ?? [];
        foreach ($capabilities as $capability) {
            Permission::firstOrCreate(['name' => $capability, 'guard_name' => 'api']);
        }
        Role::firstOrCreate(['name' => 'director', 'guard_name' => 'api'])->syncPermissions($capabilities);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertNoSharedSlotDuplicates(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('department_id, COUNT(*) AS holders')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('role', ['secretary', 'director'])
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isEmpty()) {
            return;
        }

        $list = $duplicates->map(function (object $row): string {
            $holders = DB::table('users')->whereNull('deleted_at')->where('is_active', true)
                ->whereIn('role', ['secretary', 'director'])->where('department_id', $row->department_id)
                ->orderBy('id')->get(['id', 'username', 'role'])
                ->map(fn (object $user): string => "#{$user->id} @{$user->username} ({$user->role})")->implode(', ');

            return "department {$row->department_id}: {$holders}";
        })->implode('; ');

        throw new RuntimeException(
            "A department may have one active Secretary or one active Director, not both. Deactivate or archive the extra accounts, then migrate again: {$list}."
        );
    }

    private function replaceGeneratedSlot(string $departmentCases): void
    {
        DB::statement('ALTER TABLE `users` DROP INDEX `users_active_role_slot_unique`');
        DB::statement('ALTER TABLE `users` DROP COLUMN `active_role_slot`');
        DB::statement(
            'ALTER TABLE `users` ADD COLUMN `active_role_slot` VARCHAR(64) '
            .'GENERATED ALWAYS AS (IF(`deleted_at` IS NULL AND `is_active` = 1, CASE '
            .$departmentCases
            ."WHEN `role` = 'program_head' THEN CONCAT('program_head|program|', `program_id`) "
            .'ELSE NULL END, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `users_active_role_slot_unique` (`active_role_slot`)');
    }
};
