<?php

namespace Database\Seeders;

use App\Models\Departments;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public const DEPARTMENTS = [
        ['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS'],
        ['department_name' => 'College of Business Administration', 'department_code' => 'CBA'],
        ['department_name' => 'College of Criminal Justice and Public Safety', 'department_code' => 'CCJPS'],
        ['department_name' => 'College of Education', 'department_code' => 'CED'],
        ['department_name' => 'College of Hospitality Management', 'department_code' => 'CHM'],
        ['department_name' => 'College of Information Technology', 'department_code' => 'CIT'],
        ['department_name' => 'College of Library and Information Science', 'department_code' => 'CLIS'],
        ['department_name' => 'College of Midwifery', 'department_code' => 'CM'],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $department) {
            // Existing installations may use a different abbreviation. Include
            // archived rows because both names and codes remain globally unique.
            $existing = Departments::withTrashed()
                ->where('department_code', $department['department_code'])
                ->orWhere('department_name', $department['department_name'])
                ->first();

            if ($existing === null) {
                Departments::create($department);
            }
        }
    }
}
