<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Departments;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'College of Arts and Sciences', 'code' => 'CAS'],
            ['name' => 'College of Information Technology', 'code' => 'CIT'],
            ['name' => 'College of Education', 'code' => 'CED'],
            ['name' => 'College of Business Administration', 'code' => 'CBA'],
            ['name' => 'College of Hospitality Management', 'code' => 'CHM'],
            ['name' => 'College of Library and Information Science', 'code' => 'CLIS'],
            ['name' => 'College of Criminal Justice and Public Safety', 'code' => 'CCJPS'],
        ];

        foreach ($departments as $department) {
            Departments::firstOrCreate(
                ['department_code' => $department['code']],
                ['department_name' => $department['name']]
            );
        }
    }
}
