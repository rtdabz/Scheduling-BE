<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()->where(['name' => 'vpaa', 'guard_name' => 'api'])->first()
            ?->revokePermissionTo('room.request');

        User::query()->where('role', 'vpaa')->each(function (User $user): void {
            if ($user->hasDirectPermission('room.request')) {
                $user->revokePermissionTo('room.request');
            }
        });

        Permission::query()->where(['name' => 'room.view_all_requests', 'guard_name' => 'api'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'room.view_all_requests', 'guard_name' => 'api']);
        Role::query()->where(['name' => 'vpaa', 'guard_name' => 'api'])->first()
            ?->givePermissionTo(['room.request', $permission]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
