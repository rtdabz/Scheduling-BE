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

        // firstOrCreate, not updateOrCreate: which term is active is an
        // operational decision made in the app, and re-running the seeder on a
        // live database must not drag it back to whichever term was current
        // when this list was written. Only terms that do not exist yet are
        // created, with the defaults below as their starting state.
        foreach ($terms as $term) {
            Terms::firstOrCreate(
                [
                    'academic_year' => $term['academic_year'],
                    'semester'      => $term['semester'],
                ],
                [
                    'is_active' => $term['is_active'],
                    'is_enabled' => $term['is_enabled'],
                ]
            );
        }
    }
}
