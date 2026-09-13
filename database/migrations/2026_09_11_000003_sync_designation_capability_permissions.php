<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Re-runs the capability sync so `faculty.manage_designations` gets a
 * permission row and the VPAA picks it up. The earlier sync migration has
 * already run on deployed databases, so adding a capability to the catalog
 * needs its own pass -- same body, same reasoning as
 * 2026_09_10_000001_sync_capability_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        // The permission cache lives outside the database transaction that
        // wraps this migration. A failed run therefore rolls back the rows it
        // inserted while leaving their ids in the cache, and every retry then
        // syncs roles against permission ids that no longer exist -- a foreign
        // key violation that repeats forever. Start from the stored rows.
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
        // Dropping the permission row would take every grant referencing it.
        // An unused capability is harmless, so nothing is removed.
    }
};
