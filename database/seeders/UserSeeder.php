<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
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

        return;
    }
}
