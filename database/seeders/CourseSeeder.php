<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        $cit = Departments::where('department_code', 'CIT')->value('id');
        $cas = Departments::where('department_code', 'CAS')->value('id');

        if (!$cit || !$cas) {
            throw new \RuntimeException(
                'CourseSeeder requires CIT and CAS departments to exist. Run DepartmentSeeder first.'
            );
        }

        $courses = [
            // ===================== FIRST YEAR - FIRST SEMESTER =====================
            ['course_code' => 'IT 101', 'course_name' => 'Introduction To Computing', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 102', 'course_name' => 'Computer Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 103', 'course_name' => 'Integrated Application Software', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'GEC 1', 'course_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['course_code' => 'ROTC/CWTS 101', 'course_name' => 'Basic Army Science/CWTS', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['course_code' => 'PATH FIT 1', 'course_name' => 'Movement Competency Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],

            // ===================== FIRST YEAR - SECOND SEMESTER =====================
            ['course_code' => 'IT 104', 'course_name' => 'Information Technology Fundamentals', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 105', 'course_name' => 'Computer Programming 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 106', 'course_name' => 'Digital Logic Design', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'GEC 2', 'course_name' => 'Readings in Philippine History', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['course_code' => 'ROTC/CWTS 102', 'course_name' => 'Reserved Officer Training Corps/CWTS', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['course_code' => 'PATH FIT 2', 'course_name' => 'Fitness Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],

            // ===================== SECOND YEAR - FIRST SEMESTER =====================
            ['course_code' => 'IT 107', 'course_name' => 'Application Development & Emerging Technologies', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 108', 'course_name' => 'Data Structures & Algorithms', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 109', 'course_name' => 'Fundamentals of Database Systems', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 110', 'course_name' => 'Object Oriented Programming (OOP)', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 111', 'course_name' => 'Social Issues & Professional Issues', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'GEC 3', 'course_name' => 'The Contemporary World', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['course_code' => 'PATH FIT 3', 'course_name' => 'Dance Sports/In-Dual/Group/Outdoor', 'lecture_hours' => 2, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],

            // ===================== SECOND YEAR - SECOND SEMESTER =====================
            ['course_code' => 'IT 112', 'course_name' => 'Information Management 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 113', 'course_name' => 'Event-Driven Programming', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 114', 'course_name' => 'Integrative Programming & Technologies 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 115', 'course_name' => 'Human Computer Interaction 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 116', 'course_name' => 'Advanced Database System', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'GEC 4', 'course_name' => 'Mathematics in the Modern World', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['course_code' => 'GEE 6', 'course_name' => 'Art Appreciation', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => null],
            ['course_code' => 'GEE 8', 'course_name' => 'Ethics', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => null],
            ['course_code' => 'PATH FIT 4', 'course_name' => 'Team Sports', 'lecture_hours' => 2, 'lab_hours' => 0, 'course_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],

            // ===================== THIRD YEAR =====================
            ['course_code' => 'IT 119', 'course_name' => 'System Integration & Architecture 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 120', 'course_name' => 'Research 1 (Methods of Research)', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 125', 'course_name' => 'Capstone Project 1', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['course_code' => 'IT 128', 'course_name' => 'Networking 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],

            // ===================== FOURTH YEAR =====================
            ['course_code' => 'IT 129', 'course_name' => 'Capstone Project 2', 'lecture_hours' => 3, 'lab_hours' => 0, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],
            ['course_code' => 'IT 130', 'course_name' => 'Practicum / Internship (480 Hours)', 'lecture_hours' => 0, 'lab_hours' => 6, 'course_category' => 'major', 'room_type_required' => 'field', 'year_level' => '4', 'semester' => '2nd', 'department_id' => $cit],
        ];

        foreach ($courses as $c) {
            $units = $c['lecture_hours'] + $c['lab_hours'];
            Course::updateOrCreate(
                ['course_code' => $c['course_code']],
                array_merge($c, ['units' => $units, 'status' => 'active'])
            );
        }
    }
}
