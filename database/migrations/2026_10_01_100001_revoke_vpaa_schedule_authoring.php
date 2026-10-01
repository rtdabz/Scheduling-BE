<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The VPAA approves and returns schedules but no longer builds them; each
 * program's timetable is written by its owner (config/capabilities.php
 * `role_defaults`). Grants are held by the stored role, so the existing role
 * row has to drop them.
 */
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
