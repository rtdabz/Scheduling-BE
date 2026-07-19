<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\Rooms;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = Departments::query()
            ->get(['id', 'department_code'])
            ->keyBy('department_code');

        $requiredDepartments = ['CAS', 'CBA', 'CED', 'CHM', 'CIT', 'CLIS', 'CM'];

        foreach ($requiredDepartments as $departmentCode) {
            if (!$departments->has($departmentCode)) {
                throw new \RuntimeException(
                    "RoomSeeder requires the {$departmentCode} department to exist. Run DepartmentSeeder first."
                );
            }
        }

        $rooms = [
            // AS Rooms
            ['room_code' => 'NEE 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],
            ['room_code' => 'NEE 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],
            ['room_code' => 'NEE 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],

            // BA Rooms
            ['room_code' => 'BA 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 205', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 206', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA Simulation', 'room_name' => '', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CBA'],

            // CRIM Rooms

            // EDUC Rooms
            ['room_code' => 'Educ 101', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 102', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 103', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 104', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 301', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 302', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 303', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],


            // HM Rooms
            ['room_code' => 'HM 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM Simulation', 'room_name' => '', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CHM'],

            // IT Rooms
            ['room_code' => 'IT 105', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'NEE 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab1', 'room_name' => 'Laboratory 1', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab2', 'room_name' => 'Laboratory 2', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab3', 'room_name' => 'Laboratory 3', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab4', 'room_name' => 'Laboratory 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],

            // LIS Rooms
            ['room_code' => 'Lib Bldg', 'room_name' => 'Library', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'Educ 105', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'NEE 304', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'GF', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],

            // MID Rooms
            ['room_code' => 'NEE 101', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 102', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 103', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 104', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            

        ];

        foreach ($rooms as $room) {
            $departmentCode = $room['department_code'];
            unset($room['department_code']);

            Rooms::updateOrCreate(
                ['room_code' => $room['room_code']],
                array_merge($room, [
                    'department_id' => $departments->get($departmentCode)->id,
                ])
            );
        }

        $shared = [
            ['room_code' => 'ONLINE', 'room_name' => 'Online Class', 'room_type' => 'online', 'department_id' => null],
            ['room_code' => 'FIELD', 'room_name' => 'Field/Outdoor Area', 'room_type' => 'field', 'department_id' => null],
        ];

        foreach ($shared as $room) {
            Rooms::updateOrCreate(['room_code' => $room['room_code']], $room);
        }
    }
}
