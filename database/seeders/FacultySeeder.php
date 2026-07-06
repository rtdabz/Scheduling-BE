<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FacultySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faculties = [
            ['first_name' => 'Alan', 'last_name' => 'Turing', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => 6, 'status' => 'active'],
            ['first_name' => 'Grace', 'last_name' => 'Hopper', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => 6, 'status' => 'active'],
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => 6, 'status' => 'active'],
            ['first_name' => 'Marie', 'last_name' => 'Curie', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => 1, 'status' => 'active'],
            ['first_name' => 'Albert', 'last_name' => 'Einstein', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => 1, 'status' => 'active'],
        ];

        foreach ($faculties as $faculty) {
            \App\Models\Faculty::create($faculty);
        }
    }
}
