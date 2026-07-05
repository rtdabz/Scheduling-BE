<?php

namespace Database\Seeders;

use App\Models\Sections;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            // BSIT — 1st Year
            ['section_name' => 'BSIT 1-A', 'year_level' => '1', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],
            ['section_name' => 'BSIT 1-B', 'year_level' => '1', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],

            // BSIT — 2nd Year
            ['section_name' => 'BSIT 2-A', 'year_level' => '2', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],
            ['section_name' => 'BSIT 2-B', 'year_level' => '2', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],

            // BSIT — 3rd Year
            ['section_name' => 'BSIT 3-A', 'year_level' => '3', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],

            // BSIT — 4th Year
            ['section_name' => 'BSIT 4-A', 'year_level' => '4', 'semester' => '1st', 'status' => 'active', 'department_id' => 6, 'term_id' => 1],
        ];

        foreach ($sections as $section) {
            Sections::firstOrCreate(
                [
                    'section_name' => $section['section_name'],
                    'term_id'      => $section['term_id'],
                ],
                $section
            );
        }
    }
}