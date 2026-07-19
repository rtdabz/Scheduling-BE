<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = Departments::query()
            ->get(['id', 'department_code'])
            ->keyBy('department_code');

        $requiredDepartments = ['CAS', 'CBA', 'CCJPS', 'CED', 'CHM', 'CIT', 'CLIS', 'CM'];

        foreach ($requiredDepartments as $departmentCode) {
            if (!$departments->has($departmentCode)) {
                throw new \RuntimeException(
                    "UserSeeder requires the {$departmentCode} department to exist. Run DepartmentSeeder first."
                );
            }
        }

        // Seed VPAA
        User::updateOrCreate(
            ['username' => 'vpaa'],
            [
                'name' => 'VPAA User',
                'password' => Hash::make('password'),
                'role' => 'vpaa',
            ]
        );

        // Seed Secretary
        User::updateOrCreate(
            ['username' => 'arts_sec'],
            [
                'name' => 'Dessa Mae Krism Cardinez',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CAS')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'ba_sec'],
            [
                'name' => 'Rexyl Ann Bacarro',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CBA')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'crim_sec'],
            [
                'name' => 'Lochinvar Kyle Vestal',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CCJPS')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'educ_sec'],
            [
                'name' => 'John Carlo Villarosa',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CED')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'hm_sec'],
            [
                'name' => 'Carrex Salcedo',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CHM')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'it_sec'],
            [
                'name' => 'Richie Dadubo',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CIT')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'lib_sec'],
            [
                'name' => 'Secretary User',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CLIS')->id,
            ]
        );
        User::updateOrCreate(
            ['username' => 'mid_sec'],
            [
                'name' => 'Secretary User',
                'password' => Hash::make('password'),
                'role' => 'secretary',
                'department_id' => $departments->get('CM')->id,
            ]
        );

        // Seed Dean
        User::updateOrCreate(
            ['username' => 'dean'],
            [
                'name' => 'Dean User',
                'password' => Hash::make('password'),
                'role' => 'dean',
                'department_id' => $departments->get('CAS')->id,
            ]
        );

        User::updateOrCreate(
            ['username' => 'it_dean'],
            [
                'name' => 'IT Dean',
                'password' => Hash::make('password'),
                'role' => 'dean',
                'department_id' => $departments->get('CIT')->id,
            ]
        );

        // Seed Program Head
        User::updateOrCreate(
            ['username' => 'program_head'],
            [
                'name' => 'Program Head User',
                'password' => Hash::make('password'),
                'role' => 'program_head',
                'department_id' => $departments->get('CIT')->id,
            ]
        );
    }
}
