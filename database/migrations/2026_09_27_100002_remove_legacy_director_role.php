<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Director portal was retired, but its Spatie role row stayed behind with
 * no capabilities and no holders. Supported roles come from
 * config/capabilities.php `role_defaults`; `director` is not one of them.
 * Legacy accounts are still refused at login by users.role alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->where('name', 'director')->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('model_has_roles')->whereIn('role_id', $roleIds)->delete();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The role held nothing; there is nothing to restore.
    }
};
