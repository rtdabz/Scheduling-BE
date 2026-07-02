<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Rooms;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rooms = [
            // AS Rooms
            ['room_code' => 'NEE 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 1],
            ['room_code' => 'NEE 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 1],
            ['room_code' => 'NEE 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 1],

            // BA Rooms
            ['room_code' => 'BA 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA 205', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA 206', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
            ['room_code' => 'BA Simulation', 'room_name' => '', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 2],

            // CRIM Rooms

            // HM Rooms
            ['room_code' => 'HM 201', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 5],
            ['room_code' => 'HM 202', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 5],
            ['room_code' => 'HM 203', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 5],
            ['room_code' => 'HM 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 5],
            ['room_code' => 'HM Simulation', 'room_name' => '', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 5],

            // IT Rooms
            ['room_code' => 'IT 105', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 6],
            ['room_code' => 'NEE 204', 'room_name' => '', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 6],
            ['room_code' => 'CompLab1', 'room_name' => 'Laboratory 1', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 6],
            ['room_code' => 'CompLab2', 'room_name' => 'Laboratory 2', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 6],
            ['room_code' => 'CompLab3', 'room_name' => 'Laboratory 3', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 6],
            ['room_code' => 'CompLab4', 'room_name' => 'Laboratory 4', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => 6],


        ];

        foreach ($rooms as $room) {
            Rooms::create($room);
        }
    }
}
