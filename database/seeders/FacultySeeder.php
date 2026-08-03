<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\Faculty;
use Illuminate\Database\Seeder;

class FacultySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = Departments::query()
            ->get(['id', 'department_code'])
            ->keyBy('department_code');

        $cit = $departments->get('CIT');
        $cas = $departments->get('CAS');

        if (!$cit || !$cas) {
            throw new \RuntimeException(
                'FacultySeeder requires the CIT and CAS departments to exist. Run DepartmentSeeder first.'
            );
        }

        $faculties = [
            ['first_name' => 'Alan', 'last_name' => 'Turing', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cit->id, 'status' => 'active'],
            ['first_name' => 'Grace', 'last_name' => 'Hopper', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cit->id, 'status' => 'active'],
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cit->id, 'status' => 'active'],
            ['first_name' => 'Donald', 'last_name' => 'Knuth', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cit->id, 'status' => 'active'],
            ['first_name' => 'Margaret', 'last_name' => 'Hamilton', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cit->id, 'status' => 'active'],
            ['first_name' => 'Katherine', 'last_name' => 'Johnson', 'employment_type' => 'part-time', 'max_units' => 12, 'department_id' => $cit->id, 'status' => 'active'],

            ['first_name' => 'Marie', 'last_name' => 'Curie', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cas->id, 'status' => 'active'],
            ['first_name' => 'Albert', 'last_name' => 'Einstein', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cas->id, 'status' => 'active'],
            ['first_name' => 'Rosalind', 'last_name' => 'Franklin', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cas->id, 'status' => 'active'],
            ['first_name' => 'Jose', 'last_name' => 'Rizal', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cas->id, 'status' => 'active'],
            ['first_name' => 'Fe', 'last_name' => 'Del Mundo', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $cas->id, 'status' => 'active'],
            ['first_name' => 'Socrates', 'last_name' => 'Reyes', 'employment_type' => 'part-time', 'max_units' => 12, 'department_id' => $cas->id, 'status' => 'active'],
        ];

        foreach ($faculties as $facultyData) {
            $fac = Faculty::updateOrCreate(
                [
                    'first_name' => $facultyData['first_name'],
                    'last_name' => $facultyData['last_name'],
                    'department_id' => $facultyData['department_id'],
                ],
                $facultyData
            );

            if ($fac->employment_type === 'part-time') {
                $fac->availabilities()->delete();
                
                // Weekdays: 5:00 PM to 7:00 PM (17:00:00 to 19:00:00)
                for ($d = 0; $d < 5; $d++) {
                    $fac->availabilities()->create([
                        'day_index' => $d,
                        'start_time' => '17:00:00',
                        'end_time' => '19:00:00',
                    ]);
                }
                
                // Saturday & Sunday: 7:00 AM to 7:00 PM (07:00:00 to 19:00:00)
                for ($d = 5; $d <= 6; $d++) {
                    $fac->availabilities()->create([
                        'day_index' => $d,
                        'start_time' => '07:00:00',
                        'end_time' => '19:00:00',
                    ]);
                }
            }
        }
    }
}
