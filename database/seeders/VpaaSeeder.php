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
        if (!\App\Models\User::where('username', 'arts_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'arts_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 1, // College of Arts and Sciences
            ]);
        }
        if (!\App\Models\User::where('username', 'ba_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'ba_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 2, // College of Business Administration
            ]);
        }
        if (!\App\Models\User::where('username', 'crim_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'crim_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 3, // College of Criminal Justice and Public Safety
            ]);
        }
        if (!\App\Models\User::where('username', 'educ_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'educ_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 4, // College of Education
            ]);
        }
        if (!\App\Models\User::where('username', 'hm_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'hm_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 5, // College of Hospitality Management
            ]);
        }
        if (!\App\Models\User::where('username', 'it_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'it_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 6, // College of Information Technology
            ]);
        }
        if (!\App\Models\User::where('username', 'lib_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'lib_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 7, // College of Library and Information Science
            ]);
        }
        if (!\App\Models\User::where('username', 'mid_sec')->exists()) {
            \App\Models\User::create([
                'name' => 'Secretary User',
                'username' => 'mid_sec',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'secretary',
                'department_id' => 8, // College of Midwifery
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

        if (!\App\Models\User::where('username', 'it_dean')->exists()) {
            \App\Models\User::create([
                'name' => 'IT Dean',
                'username' => 'it_dean',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'dean',
                'department_id' => 6, // College of Information Technology
            ]);
        }

        // Seed Program Head
        if (!\App\Models\User::where('username', 'program_head')->exists()) {
            \App\Models\User::create([
                'name' => 'Program Head User',
                'username' => 'program_head',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'program_head',
                'department_id' => 6, // College of Information Technology
            ]);
        }
    }
}
