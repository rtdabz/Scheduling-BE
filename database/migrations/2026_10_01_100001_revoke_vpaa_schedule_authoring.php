<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const AUTHORING = [
        'schedule.create', 'schedule.update', 'schedule.delete',
        'schedule.generate', 'schedule.submit', 'schedule.withdraw',
    ];

    public function up(): void
    {
        $role = Role::query()->where(['name' => 'vpaa', 'guard_name' => 'api'])->first();
        if ($role === null) {
            return;
        }

        $held = array_values(array_intersect(self::AUTHORING, $role->permissions->pluck('name')->all()));
        if ($held !== []) {
            $role->revokePermissionTo($held);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where(['name' => 'vpaa', 'guard_name' => 'api'])->first()
            ?->givePermissionTo(self::AUTHORING);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
