<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Room requests are secretary to secretary; program heads no longer send them
 * (config/capabilities.php). Grants are held by the stored role and by
 * individual accounts, so both drop `room.request`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first()
            ?->revokePermissionTo('room.request');

        User::query()->where('role', 'program_head')->each(function (User $user): void {
            if ($user->hasDirectPermission('room.request')) {
                $user->revokePermissionTo('room.request');
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first()
            ?->givePermissionTo('room.request');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
