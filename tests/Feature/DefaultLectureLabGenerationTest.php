<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
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

    private function durationMinutes(array $row): int
    {
        return (int) ((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);
    }
}
