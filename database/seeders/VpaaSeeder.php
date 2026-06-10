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
        User::create([
            'name' => 'VPAA User',
            'username' => 'vpaa',
            'password' => 'password',
            'role' => 'vpaa',
        ]);
    }
}
