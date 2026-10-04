<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
