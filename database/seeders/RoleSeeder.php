<?php

namespace Database\Seeders;

use App\Support\CapabilityRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(CapabilityRegistry::class);
        $roles = array_keys($registry->roleDefaults());
        $permissions = $registry->names();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        }

        foreach ($registry->roleDefaults() as $role => $rolePermissions) {
            Role::findByName($role, 'api')->syncPermissions($rolePermissions);
        }
    }
}
