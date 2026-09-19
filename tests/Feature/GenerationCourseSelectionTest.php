<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Generation\GenerationCourseSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every generation endpoint resolves a section's course selection through
 * GenerationCourseSelection. The section-level paths (preview, queued preview,
 * select) used to drop a Hybrid Split course's preferred pattern while the
 * year-level paths kept it, so the same course generated differently
 * depending on the screen.
 */
class GenerationCourseSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hybrid_split_keeps_its_preferred_pattern_and_other_patterns_are_dropped(): void
    {
        [$section, $hybrid, $plain] = $this->scaffold();

        $selection = app(GenerationCourseSelection::class)->resolve($section, [
            'course_ids' => [$hybrid->id, $plain->id],
            'hybrid_split_course_ids' => [$hybrid->id],
            'preferred_patterns' => [$hybrid->id => 'MW', $plain->id => 'TTh'],
        ]);

        $this->assertSame([$hybrid->id], $selection['hybrid_split_course_ids']);
        $this->assertSame([$hybrid->id], $selection['balanced_split_course_ids']);
        $this->assertSame([$hybrid->id => 'MW'], $selection['preferred_patterns']);
    }

    public function test_an_ineligible_hybrid_split_request_is_ignored(): void
    {
        [$section, , , $lab] = $this->scaffold();

        $selection = app(GenerationCourseSelection::class)->resolve($section, [
            'course_ids' => [$lab->id],
            'hybrid_split_course_ids' => [$lab->id],
            'preferred_patterns' => [$lab->id => 'MW'],
        ]);

        $this->assertSame([], $selection['hybrid_split_course_ids']);
        $this->assertSame([], $selection['preferred_patterns']);
    }

    public function test_courses_outside_the_request_never_reach_the_selection(): void
    {
        [$section, $hybrid, $plain] = $this->scaffold();

        $selection = app(GenerationCourseSelection::class)->resolve($section, [
            'course_ids' => [$plain->id],
            'hybrid_split_course_ids' => [$hybrid->id],
            'preferred_patterns' => [$hybrid->id => 'MW'],
        ]);

        $this->assertSame([$plain->id], $selection['course_ids']);
        $this->assertSame([], $selection['hybrid_split_course_ids']);
        $this->assertSame([], $selection['preferred_patterns']);
    }

    /** @return array{0: Sections, 1: Course, 2: Course, 3: Course} */
    private function scaffold(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Selection', 'department_code' => 'SEL']);
        $curriculum = Curriculum::create([
            'name' => 'Selection Curriculum', 'department_id' => $department->id, 'code' => 'SEL-2026',
            'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);
        $section = Sections::create([
            'section_name' => 'SEL 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);

        $course = function (string $code, array $attributes) use ($department, $curriculum): Course {
            $course = Course::create(array_merge([
                'course_code' => $code, 'course_name' => $code, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ], $attributes));
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

            return $course;
        };

        return [
            $section,
            $course('SEL 101', []),
            $course('SEL 102', []),
            $course('SEL 103', ['lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']),
        ];
    }
}
