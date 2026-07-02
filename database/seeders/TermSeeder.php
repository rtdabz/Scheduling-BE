<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('terms')->insert([
            ['academic_year' => '2026-2027', 'semester' => '1st',    'is_active' => true,  'is_enabled' => true,  'created_at' => now(), 'updated_at' => now()],
            ['academic_year' => '2026-2027', 'semester' => '2nd',    'is_active' => false, 'is_enabled' => true,  'created_at' => now(), 'updated_at' => now()],
            ['academic_year' => '2026-2027', 'semester' => 'summer', 'is_active' => false, 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()],
]);
    }
}
