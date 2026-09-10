<?php

use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the permissions table in line with the capability catalog.
 *
 * A capability cannot be granted to anyone until a matching permission row
 * exists, so adding one to config/capabilities.php is only half the change.
 * Re-running RoleSeeder would do this too, but a deployment should not have to
 * remember to; this makes the catalog and the database converge on their own.
 *
 * Existing grants are left alone. Roles are re-synced to their declared
 * defaults, which is how the VPAA picks up newly added capabilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

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
        // The catalog is the source of truth for which permissions exist, and
        // rows removed here would take every grant that references them. A
        // capability that is no longer offered is harmless once nothing asks
        // for it, so nothing is dropped.
    }
};
