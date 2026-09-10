<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The institution-wide VPAA account must survive database resets. On a
        // fresh database this creates the standard account; on an existing
        // database firstOrCreate leaves the current password and profile intact.
        $vpaa = User::firstOrCreate(
            ['username' => 'vpaa'],
            [
                'name' => 'Dr. Kharen Jane S. Ungab',
                'password' => Hash::make('password'),
                'role' => 'vpaa',
                'department_id' => null,
            ],
        );
        $vpaa->syncRoles(['vpaa']);

        // This reset starts with the VPAA as the sole administrative account.
        // Departments may create the remaining scoped users through User Management.
        return;
    }
}
