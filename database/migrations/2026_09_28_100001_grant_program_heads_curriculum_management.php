<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Program heads now author their own program's curriculum alongside the
 * department secretary (config/capabilities.php `role_defaults`). Grants are
 * held by the stored role, so the existing role row has to pick it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first();
        if ($role === null) {
            return;
        }

        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'curriculum.manage', 'guard_name' => 'api']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first()
            ?->revokePermissionTo('curriculum.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
