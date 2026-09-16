<?php

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manage Access is gone: what an account may do now follows from its role
 * alone. Secretaries and program heads used to start with nothing and work
 * only through per-account grants, so this re-syncs the roles against the new
 * defaults in config/capabilities.php first and only then drops the grants.
 * Doing it the other way round would leave every secretary locked out between
 * the two steps.
 *
 * The grants are removed rather than kept because nothing can show or change
 * them any more; a leftover grant would be invisible, unrevocable power.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('model_has_permissions')) {
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

        DB::table('model_has_permissions')
            ->where('model_type', (new User)->getMorphClass())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The removed grants cannot be reconstructed. Restore
        // `model_has_permissions` from a backup if they are needed back.
    }
};
