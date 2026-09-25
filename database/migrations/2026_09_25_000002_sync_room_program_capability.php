<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `room.assign_program`: the department secretary decides which program
 * each of the department's rooms belongs to and how programs share them.
 *
 * Roles are re-synced from config/capabilities.php, as in
 * 2026_09_23_000002_move_room_request_review_to_owner_department, so the stored
 * permissions cannot drift from the declared defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registry = app(CapabilityRegistry::class);

        foreach ($registry->names() as $capability) {
            Permission::findOrCreate($capability, 'api');
        }

        foreach ($registry->roleDefaults() as $role => $permissions) {
            Role::findOrCreate($role, 'api')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'room.assign_program')->where('guard_name', 'api')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
