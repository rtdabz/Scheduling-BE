<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\CourseScheduleRequirementBuilder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A course's class time follows the minutes its section's curriculum recorded on import;
 * a curriculum entered by hand falls back to the course's lecture and laboratory hours.
 */
class CurriculumClassMinutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_imported_curriculum_sizes_the_course_from_its_recorded_minutes(): void
    {
        $f = $this->fixture();

        // IT 105: 2 lecture + 1 laboratory unit imported at 60 / 180 minutes per unit.
        $imported = $this->requirements($f['imported'], $f['course'], ['selected_split_session_course_ids' => [(int) $f['course']->id]]);
        $this->assertSame([4, 6], array_column($imported[(int) $f['course']->id], 'duration_slots'));
        $this->assertSame(10, $this->requirements($f['imported'], $f['course'])[(int) $f['course']->id][0]['duration_slots']);

        // The same course in a curriculum entered by hand keeps its recorded hours.
        $this->assertSame(6, $this->requirements($f['manual'], $f['course'])[(int) $f['course']->id][0]['duration_slots']);
    }

    public function test_manual_placement_is_held_to_the_imported_total_hours(): void
    {
        $f = $this->fixture();
        $attempt = fn (string $end): array => [
            'semester_id' => $f['semester']->id, 'section_id' => $f['imported']->id, 'course_id' => $f['course']->id,
            'department_id' => $f['department']->id, 'room_id' => $f['room']->id, 'day' => 'Monday',
            'start_time' => '08:00', 'end_time' => $end, 'mode' => 'on-site',
        ];
        $duration = fn (array $violations): array => array_values(array_filter(array_column($violations, 'rule'), static fn (string $rule): bool => $rule === 'class_duration'));

        $this->assertSame([], $duration(app(RuleEngine::class)->validate($attempt('13:00'))), 'five imported hours fit');
        $this->assertSame(['class_duration'], $duration(app(RuleEngine::class)->validate($attempt('13:30'))), 'more than five do not');
    }

    public function test_a_placed_course_carries_only_its_own_curriculums_minutes(): void
    {
        $f = $this->fixture();
        $course = ['id' => (int) $f['course']->id, 'lecture_hours' => 2, 'lab_hours' => 1];

        $placed = SchedulingPolicy::placedCourse($course, (int) $f['importedCurriculum']->id);
        $this->assertSame([120, 180], [$placed['lecture_minutes'], $placed['laboratory_minutes']]);
        $this->assertSame(10, SchedulingPolicy::courseDurationSlots($placed));

        $this->assertSame($course, SchedulingPolicy::placedCourse($course, (int) $f['manualCurriculum']->id));
        $this->assertSame($course, SchedulingPolicy::placedCourse($course, null));
        $this->assertSame(6, SchedulingPolicy::courseDurationSlots($course));
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function requirements(Sections $section, Course $course, array $options = []): array
    {
        return app(CourseScheduleRequirementBuilder::class)->build($section->fresh(), collect([$course->fresh()]), $options);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $course = Course::create([
            'course_code' => 'IT 105', 'course_name' => 'Computer Programming 2',
            'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
        $importedCurriculum = Curriculum::create(['name' => 'BSIT 2020', 'department_id' => $department->id, 'code' => 'BSIT-2020', 'effective_school_year' => '2020-2021', 'status' => 'active']);
        $importedCurriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1, 'lecture_minutes' => 120, 'laboratory_minutes' => 180]);
        $manualCurriculum = Curriculum::create(['name' => 'BSIT 2018', 'department_id' => $department->id, 'code' => 'BSIT-2018', 'effective_school_year' => '2018-2019', 'status' => 'active']);
        $manualCurriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $section = fn (string $name, Curriculum $curriculum): Sections => Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st', 'curriculum_id' => $curriculum->id,
            'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'course' => $course,
            'importedCurriculum' => $importedCurriculum,
            'manualCurriculum' => $manualCurriculum,
            'imported' => $section('IT 1A', $importedCurriculum),
            'manual' => $section('IT 1B', $manualCurriculum),
            'room' => Rooms::create(['room_code' => 'LAB 1', 'building' => 'IT', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]),
        ];
    }
}
