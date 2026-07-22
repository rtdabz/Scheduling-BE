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
            ['room_code' => 'NEE 201', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],
            ['room_code' => 'NEE 202', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],
            ['room_code' => 'NEE 203', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CAS'],

            // BA Rooms
            ['room_code' => 'BA 201', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 202', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 203', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 204', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 205', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA 206', 'building' => 'Building 1', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CBA'],
            ['room_code' => 'BA Simulation', 'building' => 'Building 1', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CBA'],

            // CRIM Rooms

            // EDUC Rooms
            ['room_code' => 'Educ 101', 'building' => 'Building 2', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 102', 'building' => 'Building 2', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 103', 'building' => 'Building 2', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'Educ 104', 'building' => 'Building 2', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 301', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 302', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],
            ['room_code' => 'NEE 303', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CED'],


            // HM Rooms
            ['room_code' => 'HM 201', 'building' => 'Building 3', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 202', 'building' => 'Building 3', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 203', 'building' => 'Building 3', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM 204', 'building' => 'Building 3', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CHM'],
            ['room_code' => 'HM Simulation', 'building' => 'Building 3', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CHM'],

            // IT Rooms
            ['room_code' => 'IT 105', 'building' => 'Building 4', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'NEE 204', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab1', 'building' => 'Building 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab2', 'building' => 'Building 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab3', 'building' => 'Building 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],
            ['room_code' => 'CompLab4', 'building' => 'Building 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_code' => 'CIT'],

            // LIS Rooms
            ['room_code' => 'Lib Bldg', 'building' => 'Building 5', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'Educ 105', 'building' => 'Building 2', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'NEE 304', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],
            ['room_code' => 'GF', 'building' => 'Building 5', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CLIS'],

            // MID Rooms
            ['room_code' => 'NEE 101', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 102', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 103', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            ['room_code' => 'NEE 104', 'building' => 'NEE Building', 'room_type' => 'lecture', 'status' => 'available', 'department_code' => 'CM'],
            

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
            ['room_code' => 'ONLINE', 'building' => null, 'room_type' => 'online', 'department_id' => null],
            ['room_code' => 'FIELD', 'building' => null, 'room_type' => 'field', 'department_id' => null],
        ];

        foreach ($shared as $room) {
            Rooms::updateOrCreate(['room_code' => $room['room_code']], $room);
        }
    }
}
