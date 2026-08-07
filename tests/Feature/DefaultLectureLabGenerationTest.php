<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultLectureLabGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_major_course_with_lecture_and_lab_generates_separate_default_components(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Programming 1',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $lectureRoom = Rooms::create([
            'room_code' => 'IT 105',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $rows = $solutions[0]['schedules'];
        $this->assertCount(2, $rows);

        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');

        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame($lecture['split_group_id'], $laboratory['split_group_id']);
        $this->assertSame($lectureRoom->id, $lecture['room_id']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
        $this->assertSame(120, $this->durationMinutes($lecture));
        $this->assertSame(180, $this->durationMinutes($laboratory));
    }

    public function test_default_lecture_lab_generation_can_place_lecture_online_when_no_classroom_exists(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 102',
            'course_name' => 'Computer Programming 2',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $rows = $solutions[0]['schedules'];
        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');

        $this->assertSame('online', $lecture['mode']);
        $this->assertNull($lecture['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
        $this->assertSame(120, $this->durationMinutes($lecture));
        $this->assertSame(180, $this->durationMinutes($laboratory));
    }

    public function test_generation_falls_back_online_when_existing_classrooms_are_fully_booked(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $targetSection = Sections::create([
            'section_name' => 'BSBA 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $blockingSection = Sections::create([
            'section_name' => 'BSBA 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'MGT 101',
            'course_name' => 'Principles of Management',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $blockingSection->id,
                'department_id' => $department->id,
                'course_id' => $course->id,
                'room_id' => $room->id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $targetSection->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertSame('online', $solutions[0]['schedules'][0]['mode']);
        $this->assertNull($solutions[0]['schedules'][0]['room_id']);
    }

    public function test_generation_allows_more_than_five_online_fallbacks_when_rooms_are_fully_booked(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $targetSection = Sections::create([
            'section_name' => 'BSBA 3D',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $blockingSection = Sections::create([
            'section_name' => 'BSBA 3C',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'BA 301',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $courseIds = [];
        for ($index = 1; $index <= 6; $index++) {
            $course = Course::create([
                'course_code' => "MGT 30{$index}",
                'course_name' => "Business Course {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '3',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $courseIds[] = $course->id;
        }

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $blockingSection->id,
                'department_id' => $department->id,
                'course_id' => $courseIds[0],
                'room_id' => $room->id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $targetSection->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(6, $solutions[0]['schedules']);
        $this->assertSame(6, collect($solutions[0]['schedules'])->where('mode', 'online')->count());
        $this->assertTrue(collect($solutions[0]['schedules'])->every(fn (array $row): bool => $row['room_id'] === null));
    }

    public function test_generation_skips_zero_duration_internship_courses(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $section = Sections::create([
            'section_name' => 'BSBA 4A',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $regularCourse = Course::create([
            'course_code' => 'PMC 401',
            'course_name' => 'Financial Analysis and Reporting',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $internship = Course::create([
            'course_code' => 'INTERN 101',
            'course_name' => 'Prac Work w/ Integ Learning (300 hrs)',
            'lecture_hours' => 0,
            'lab_hours' => 0,
            'units' => 0,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'BA 401',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$regularCourse->id, $internship->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(1, $solutions[0]['schedules']);
        $this->assertSame($regularCourse->id, $solutions[0]['schedules'][0]['course_id']);
    }

    private function durationMinutes(array $row): int
    {
        return (int) ((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);
    }
}
