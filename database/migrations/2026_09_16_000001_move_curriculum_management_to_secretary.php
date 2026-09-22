<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Curriculum writes used to hang off a `role:vpaa` route gate, which put them
 * outside the capability system: nothing in User Management could show or move
 * them. They are now `curriculum.manage`, held by the secretary that owns the
 * programs, while the dean and the VPAA keep read-only access.
 *
 * Roles are re-synced from config/capabilities.php rather than patched by hand
 * so the stored permissions cannot drift from the declared defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        // See 2026_09_13_000002_sync_room_request_capability_permissions: a
        // stale cache would sync roles against permission ids that no longer exist.
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

        // Curriculum authoring goes back to being a VPAA-only route gate, so the
        // capability itself is removed rather than reassigned.
        Permission::where('name', 'curriculum.manage')->where('guard_name', 'api')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
