<?php

namespace Database\Seeders;

use App\Models\Departments;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS', 'scheduling_profile' => 'standard'],
            ['department_name' => 'College of Business Administration', 'department_code' => 'CBA', 'scheduling_profile' => 'standard'],
            ['department_name' => 'College of Criminal Justice and Public Safety', 'department_code' => 'CCJPS', 'scheduling_profile' => 'standard'],
            ['department_name' => 'College of Education', 'department_code' => 'CED', 'scheduling_profile' => 'standard'],
            ['department_name' => 'College of Hospitality Management', 'department_code' => 'CHM', 'scheduling_profile' => 'laboratory_enabled'],
            ['department_name' => 'College of Information Technology', 'department_code' => 'CIT', 'scheduling_profile' => 'laboratory_enabled'],
            ['department_name' => 'College of Library and Information Science', 'department_code' => 'CLIS', 'scheduling_profile' => 'standard'],
            ['department_name' => 'College of Midwifery', 'department_code' => 'CM', 'scheduling_profile' => 'laboratory_enabled'],
        ];

        foreach ($departments as $department) {
            Departments::updateOrCreate(
                ['department_code' => $department['department_code']],
                [
                    'department_name' => $department['department_name'],
                    'scheduling_profile' => $department['scheduling_profile'],
                ],
            );
        }
    }
}
