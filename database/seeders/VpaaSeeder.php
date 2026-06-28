<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class VpaaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed VPAA
        if (!\App\Models\User::where('username', 'vpaa')->exists()) {
            \App\Models\User::create([
                'name' => 'VPAA User',
                'username' => 'vpaa',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'vpaa',
            ]);
        }

        // Seed Secretary
        if (!\App\Models\User::where('username', 'secretary')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'secretary',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 2, // College of Information Technology
            ]);
        }

        // Seed Dean
        if (!\App\Models\User::where('username', 'dean')->exists()) {
            \App\Models\User::create([
                'name' => 'Dean User',
                'username' => 'dean',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'dean',
                'department_id' => 1, // College of Arts and Sciences
            ]);
        }

        // Seed Program Head
        if (!\App\Models\User::where('username', 'program_head')->exists()) {
            \App\Models\User::create([
                'name' => 'Program Head User',
                'username' => 'program_head',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'program_head',
                'department_id' => 2, // College of Information Technology
            ]);
        }
    }
}
