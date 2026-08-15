<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MajorLectureLaboratoryFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_major_full_lecture_uses_lecture_capable_laboratory_when_no_lecture_room_is_available(): void
    {
        [$section, $course] = $this->createSectionWithMajorLectureCourse();
        $lab = Rooms::create([
            'room_code' => 'CompLab 1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => true,
            'status' => 'available',
            'department_id' => $section->department_id,
        ]);
        Rooms::create([
            'room_code' => 'Kitchen Lab',
            'building' => 'Building 3',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => false,
            'status' => 'available',
            'department_id' => $section->department_id,
        ]);

        $solutions = app(CSPSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            maxIterations: 50000,
            timeoutSeconds: 4,
        );

        $this->assertNotEmpty($solutions);
        $row = $solutions[0]['schedules'][0];
        $this->assertSame('on-site', $row['mode']);
        $this->assertSame($lab->id, $row['room_id']);
    }

    public function test_major_full_lecture_does_not_use_specialized_laboratory_without_lecture_capability(): void
    {
        [$section, $course] = $this->createSectionWithMajorLectureCourse();
        Rooms::create([
            'room_code' => 'Kitchen Lab',
            'building' => 'Building 3',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => false,
            'status' => 'available',
            'department_id' => $section->department_id,
        ]);

        $solutions = app(CSPSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            maxIterations: 50000,
            timeoutSeconds: 4,
        );

        $this->assertNotEmpty($solutions);
        $row = $solutions[0]['schedules'][0];
        $this->assertSame('online', $row['mode']);
        $this->assertNull($row['room_id']);
    }

    public function test_manual_validation_rejects_major_lecture_in_non_capable_laboratory(): void
    {
        [$section, $course] = $this->createSectionWithMajorLectureCourse();
        $lab = Rooms::create([
            'room_code' => 'Kitchen Lab',
            'building' => 'Building 3',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => false,
            'status' => 'available',
            'department_id' => $section->department_id,
        ]);

        $violation = app(RuleEngine::class)->checkRoomTypeMatch(
            courseId: (int) $course->id,
            roomId: (int) $lab->id,
            deliveryMode: 'on-site',
            meetingType: 'lecture',
        );

        $this->assertSame('room_type_match', $violation['rule'] ?? null);
    }

    private function createSectionWithMajorLectureCourse(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
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
            'course_name' => 'Introduction to Computing',
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
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return [$section, $course];
    }
}
