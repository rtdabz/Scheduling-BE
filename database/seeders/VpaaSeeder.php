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
        if (!\App\Models\User::where('username', 'vpaa')->exists()) {
            \App\Models\User::create([
                'name' => 'VPAA User',
                'username' => 'vpaa',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'vpaa',
            ]);
        }
    }
}
