<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * `faculty.manage_designations` is now a VPAA-only capability: a designation
 * rewrites an instructor's Basic Load, so the list stays with the office that
 * owns faculty loading rather than being delegated per account.
 *
 * Narrowing `allowed_roles` in config only stops *new* grants -- Manage Access
 * refuses to hand it out, but a grant made while dean, program head or a
 * directly-granted secretary was still eligible keeps working, because the
 * middleware asks the stored permission and never the catalog. This pass
 * revokes those standing grants so the config and the database agree.
 */
return new class extends Migration
{
    private const CAPABILITY = 'faculty.manage_designations';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('model_has_permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionId = DB::table('permissions')
            ->where('name', self::CAPABILITY)
            ->where('guard_name', 'api')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        // Direct grants on accounts that are not a VPAA. The role's own default
        // grant lives in role_has_permissions and is left untouched.
        $revokable = DB::table('model_has_permissions')
            ->join('users', 'users.id', '=', 'model_has_permissions.model_id')
            ->where('model_has_permissions.permission_id', $permissionId)
            ->where('model_has_permissions.model_type', \App\Models\User::class)
            ->whereRaw('LOWER(users.role) <> ?', ['vpaa'])
            ->pluck('users.id');

        if ($revokable->isNotEmpty()) {
            DB::table('model_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('model_type', \App\Models\User::class)
                ->whereIn('model_id', $revokable)
                ->delete();
        }

        // Any role other than the VPAA that picked the capability up before it
        // was narrowed.
        if (Schema::hasTable('role_has_permissions') && Schema::hasTable('roles')) {
            $roleIds = DB::table('roles')
                ->whereRaw('LOWER(name) <> ?', ['vpaa'])
                ->pluck('id');

            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The revoked grants are not recorded anywhere, so they cannot be put
        // back. Re-granting is a Manage Access decision, not a migration.
    }
};
