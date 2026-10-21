<?php

namespace Database\Seeders;

use App\Models\Departments;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS'],
            ['department_name' => 'College of Business Administration', 'department_code' => 'CBA'],
            ['department_name' => 'College of Criminal Justice and Public Safety', 'department_code' => 'CCJPS'],
            ['department_name' => 'College of Education', 'department_code' => 'CED'],
            ['department_name' => 'College of Hospitality Management', 'department_code' => 'CHM'],
            ['department_name' => 'College of Information Technology', 'department_code' => 'CIT'],
            ['department_name' => 'College of Library and Information Science', 'department_code' => 'CLIS'],
            ['department_name' => 'College of Midwifery', 'department_code' => 'CM'],
        ];

        foreach ($departments as $department) {
            Departments::updateOrCreate(
                ['department_code' => $department['department_code']],
                [
                    'department_name' => $department['department_name'],
                ],
            );
        }
    }
}
