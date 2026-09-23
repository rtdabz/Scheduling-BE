<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Room requests are now settled between departments: the secretary of the
 * department that owns the room approves, rejects or revokes, and the VPAA only
 * watches through `room.view_all_requests` and is notified when a room is lent.
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

        // Review goes back to the VPAA, and the watch-only capability is removed.
        Permission::findOrCreate('room.review_requests', 'api');
        Role::findOrCreate('secretary', 'api')->revokePermissionTo('room.review_requests');
        Role::findOrCreate('vpaa', 'api')->givePermissionTo('room.review_requests');
        Permission::where('name', 'room.view_all_requests')->where('guard_name', 'api')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
