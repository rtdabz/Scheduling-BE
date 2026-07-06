<?php

namespace Database\Seeders;

use App\Models\Terms;
use Illuminate\Database\Seeder;

class TermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['academic_year' => '2026-2027', 'semester' => '1st',    'is_active' => true,  'is_enabled' => true],
            ['academic_year' => '2026-2027', 'semester' => '2nd',    'is_active' => false, 'is_enabled' => true],
            ['academic_year' => '2026-2027', 'semester' => 'summer', 'is_active' => false, 'is_enabled' => false],
        ];

        foreach ($terms as $term) {
            Terms::firstOrCreate(
                [
                    'academic_year' => $term['academic_year'],
                    'semester'      => $term['semester'],
                ],
                $term
            );
        }
    }
}
