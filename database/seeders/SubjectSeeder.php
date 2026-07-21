<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\Subjects;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Source: TCC BSIT Active Curriculum, CMO 25 s.2015 (effective SY 2020-2021)
     *
     * Notes on category mapping decisions:
     * - IT xxx / IT Elec x  -> category 'major', department = CIT
     * - GEC / GEE / PATHFIT / NSTP -> category 'minor', department = CAS / owning department
     * - Research1, Capstone Project 1/2, Practicum -> category 'major'
     *
     * units = lecture_hours + lab_hours (matches "TU" column in the curriculum sheet,
     * consistent with the auto-computed units design already finalized for this table)
     */
    public function run(): void
    {
        $cit = Departments::where('department_code', 'CIT')->value('id');
        $cas = Departments::where('department_code', 'CAS')->value('id');

        if (!$cit || !$cas) {
            throw new \RuntimeException(
                'SubjectSeeder requires CIT and CAS departments to exist. Run DepartmentSeeder first.'
            );
        }

        $subjects = [

            // ===================== FIRST YEAR - FIRST SEMESTER =====================
            ['subject_code' => 'IT 101', 'subject_name' => 'Introduction To Computing', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 102', 'subject_name' => 'Computer Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 103', 'subject_name' => 'Integrated Application Software', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'GEC 1', 'subject_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'ROTC/CWTS 101', 'subject_name' => 'Basic Army Science/CWTS', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'PATH FIT 1', 'subject_name' => 'Movement Competency Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],

            // ===================== FIRST YEAR - SECOND SEMESTER =====================
            ['subject_code' => 'IT 104', 'subject_name' => 'Information Technology Fundamentals', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 105', 'subject_name' => 'Computer Programming 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 106', 'subject_name' => 'Digital Logic Design', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'GEC 2', 'subject_name' => 'Readings in Philippine History', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'ROTC/CWTS 102', 'subject_name' => 'Reserved Officer Training Corps/CWTS', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'PATH FIT 2', 'subject_name' => 'Fitness Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],

            // ===================== SECOND YEAR - FIRST SEMESTER =====================
            ['subject_code' => 'IT 107', 'subject_name' => 'Application Development & Emerging Technologies', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 108', 'subject_name' => 'Data Structures & Algorithms', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 109', 'subject_name' => 'Fundamentals of Database Systems', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 110', 'subject_name' => 'Object Oriented Programming (OOP)', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 111', 'subject_name' => 'Social Issues & Professional Issues', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'GEC 3', 'subject_name' => 'The Contemporary World', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'PATH FIT 3', 'subject_name' => 'Dance Sports/In-Dual/Group/Outdoor', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],

            // ===================== SECOND YEAR - SECOND SEMESTER =====================
            ['subject_code' => 'IT 112', 'subject_name' => 'Information Management 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 113', 'subject_name' => 'Event-Driven Programming', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 114', 'subject_name' => 'Integrative Programming & Technologies 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 115', 'subject_name' => 'Human Computer Interaction 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 116', 'subject_name' => 'Advanced Database System', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'GEC 4', 'subject_name' => 'Mathematics in the Modern World', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'GEE 6', 'subject_name' => 'Art Appreciation', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => null],
            ['subject_code' => 'GEE 8', 'subject_name' => 'Ethics', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => null],
            ['subject_code' => 'PATH FIT 4', 'subject_name' => 'Team Sports', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],

            // ===================== SUMMER (after 2nd year) =====================
            ['subject_code' => 'IT 117', 'subject_name' => 'Qualitative Methods (Modeling & Simulation)', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => 'summer', 'department_id' => $cit],
            ['subject_code' => 'IT 118', 'subject_name' => 'Human Computer Interaction 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => 'summer', 'department_id' => $cit],
            ['subject_code' => 'IT Elec 1', 'subject_name' => 'System Analysis and Design (SAD)', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => 'summer', 'department_id' => $cit],

            // ===================== THIRD YEAR - FIRST SEMESTER =====================
            ['subject_code' => 'IT 119', 'subject_name' => 'System Integration & Architecture 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 120', 'subject_name' => 'Research 1 (Methods of Research in Computing)', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 121', 'subject_name' => 'Information Management 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 122', 'subject_name' => 'Integrative Programming & Technologies 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT Elec 2', 'subject_name' => 'Computer Graphics', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'GEC 5', 'subject_name' => 'Purposive Communication', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEC 7', 'subject_name' => 'Science, Technology and Society', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEC 9', 'subject_name' => 'Life, Works & Writing of Dr. Jose Rizal', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEE 9', 'subject_name' => 'Technopreneurship (The Entrepreneurial Mind)', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '1st', 'department_id' => null],

            // ===================== THIRD YEAR - SECOND SEMESTER =====================
            ['subject_code' => 'IT 123', 'subject_name' => 'Information Assurance & Security 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 124', 'subject_name' => 'Networking 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 125', 'subject_name' => 'Capstone Project 1', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 126', 'subject_name' => 'System Integration & Architecture 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT 127', 'subject_name' => 'Platform Technologies', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'IT Elec 3', 'subject_name' => 'Computer-Aided Design (CAD)', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cit],
            ['subject_code' => 'GEE 4', 'subject_name' => 'Living in the IT Era', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => null],
            ['subject_code' => 'GEE 7', 'subject_name' => 'Gender & Society', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => null],
            ['subject_code' => 'GEE 10', 'subject_name' => 'Entrepreneurial Management', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => null],
            ['subject_code' => 'GEE 11', 'subject_name' => 'Phil. Indigenous Communities & Peace Studies Education', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => null],

            // ===================== SUMMER (after 3rd year) =====================
            ['subject_code' => 'IT Elec 4', 'subject_name' => 'Fundamentals of Data Warehousing & Data Mining', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => 'summer', 'department_id' => $cit],
            ['subject_code' => 'IT Elec 5', 'subject_name' => 'Multimedia System', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => 'summer', 'department_id' => $cit],
            ['subject_code' => 'IT Elec 6', 'subject_name' => 'Management Information System (MIS)', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => 'summer', 'department_id' => $cit],

            // ===================== FOURTH YEAR - FIRST SEMESTER =====================
            ['subject_code' => 'IT 128', 'subject_name' => 'System Administration and Maintenance', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 129', 'subject_name' => 'Information Assurance & Security 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 130', 'subject_name' => 'Networking 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 131', 'subject_name' => 'Web System and Technologies', 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],
            ['subject_code' => 'IT 132', 'subject_name' => 'Capstone Project 2', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cit],

            // ===================== FOURTH YEAR - SECOND SEMESTER =====================
            ['subject_code' => 'Prac 101', 'subject_name' => 'Practicum (486 hours)', 'lecture_hours' => 6, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'field', 'year_level' => '4', 'semester' => '2nd', 'department_id' => $cit],


            // ===================== CAS ========================
            ['subject_code' => 'GEC 1', 'subject_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEE 1', 'subject_name' => 'Environmental Science', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEE 4', 'subject_name' => 'Living in the IT Era', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCC 1', 'subject_name' => 'General Sociology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCC 2', 'subject_name' => 'Philippine Contemporary Social Issue', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'LE 1', 'subject_name' => 'Developmental Reading', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'PATH-FIT 1', 'subject_name' => 'Movement Competency Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'NSTP 1', 'subject_name' => 'CWTS/ROTC 1', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas],
            // FIRST YEAR — 2nd Semester
            ['subject_code' => 'GEC 2', 'subject_name' => 'Readings in Philippine History with Indigenous People\'s Studies', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'GEE 8', 'subject_name' => 'The Entrepreneurial Mind', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCC 3', 'subject_name' => 'Sociological Theories I', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCC 7', 'subject_name' => 'Social Statistics', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 1', 'subject_name' => 'Deviance and Community Ethnicity', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'LE 2', 'subject_name' => 'Interactive English, Listening, Speaking & Writing', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'PATH-FIT 2', 'subject_name' => 'Fitness Training', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'NSTP 2', 'subject_name' => 'CWTS/ROTC 2', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $cas],

            // SECOND YEAR — 1st Semester
            ['subject_code' => 'GEC 3', 'subject_name' => 'The Contemporary World', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEC 4', 'subject_name' => 'Mathematics in the Modern World', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEC 5', 'subject_name' => 'Purposive Communication', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCC 5', 'subject_name' => 'Social Research Methods I', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCE 2', 'subject_name' => 'Rural Sociology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCC 4', 'subject_name' => 'Sociological Theories II', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'FE 1', 'subject_name' => 'General Anthropology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'ENTREP 1', 'subject_name' => 'Entrepreneurial Management', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'PATH-FIT 3', 'subject_name' => 'Dance Sports & Individual/Group Exercise/Outdoor', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '1st', 'department_id' => $cas],

            // SECOND YEAR — 2nd Semester
            ['subject_code' => 'GEC 6', 'subject_name' => 'Art Appreciation', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'GEC 8', 'subject_name' => 'Ethics', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCC 6', 'subject_name' => 'Social Research Methods II', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 3', 'subject_name' => 'Urban Sociology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 4', 'subject_name' => 'Political Sociology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 5', 'subject_name' => 'Industrial Sociology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 6', 'subject_name' => 'Sociology of Religion', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'PATH-FIT 4', 'subject_name' => 'Team Sports', 'lecture_hours' => 2, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'field', 'year_level' => '2', 'semester' => '2nd', 'department_id' => $cas],

            // THIRD YEAR — 1st Semester
            ['subject_code' => 'GEC 7', 'subject_name' => 'Science, Technology & Society', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'minor', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'GEC 9', 'subject_name' => 'Life and Works of Rizal', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'minor', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCE 7', 'subject_name' => 'Sociology of Mass Communication', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCE 8', 'subject_name' => 'Race and Ethnic Relations', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SCE 9', 'subject_name' => 'Medical Sociology', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'SOC 1', 'subject_name' => 'Directed Research I (Proposal Stage)', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '3', 'semester' => '1st', 'department_id' => $cas],

            // THIRD YEAR — 2nd Semester
            ['subject_code' => 'GEE 7', 'subject_name' => 'Gender & Society with Peace Studies', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 10', 'subject_name' => 'Sociology of Education', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SCE 11', 'subject_name' => 'Sociology of Development', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'FE 2', 'subject_name' => 'Personality Psychology', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'FE 3', 'subject_name' => 'Aesthetics', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],
            ['subject_code' => 'SOC 2', 'subject_name' => 'Directed Research II (Data Gathering Stage)', 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '3', 'semester' => '2nd', 'department_id' => $cas],

            // FOURTH YEAR — 1st Semester
            ['subject_code' => 'SOC 3', 'subject_name' => 'Directed Research III (Presentation of Results)', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cas],
            ['subject_code' => 'EC 1', 'subject_name' => 'Enhancement Class', 'lecture_hours' => 0, 'lab_hours' => 3, 'subject_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '4', 'semester' => '1st', 'department_id' => $cas],

            // FOURTH YEAR — 2nd Semester
            ['subject_code' => 'SOC 4', 'subject_name' => 'Practicum in Sociology (360 HRS)', 'lecture_hours' => 0, 'lab_hours' => 6, 'subject_category' => 'major', 'room_type_required' => 'field', 'year_level' => '4', 'semester' => '2nd', 'department_id' => $cas],
        ];

        foreach ($subjects as $subject) {
            Subjects::firstOrCreate(
                ['subject_code' => $subject['subject_code']],
                array_merge($subject, [
                    'units' => $subject['lecture_hours'] + $subject['lab_hours'],
                ])
            );
        }
    }
}