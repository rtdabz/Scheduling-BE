<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FieldCourseWeekendLastResortTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sunday is a teaching day, but it is the last resort, not the default.
     * Field candidates sit in the preferred allocation tier, so nothing gated
     * their weekend placements, and the day-balance penalty left Sunday at
     * load zero for the whole solve -- every PATHFIT landed on a Sunday.
     */
    public function test_field_courses_take_the_weekdays_before_the_weekend(): void
    {
        [$user, $payload, , $fieldCourse] = $this->scenario(sectionNames: ['IT 1A', 'IT 1B', 'IT 1C']);

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $fieldRows = collect($response->json('schedules'))->where('course_id', (int) $fieldCourse->id);
        $this->assertSame(3, $fieldRows->pluck('section_id')->unique()->count());
        foreach ($fieldRows as $row) {
            $this->assertNotContains((string) $row['day'], ['Saturday', 'Sunday'], 'The week is wide open, so no field meeting belongs on the weekend.');
        }
    }

    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/api/schedule-recommendations/year-level-preview',
            $payload,
        );
    }

    /** @return array{0: User, 1: array<string, mixed>, 2: Course, 3: Course} */
    /** @param  list<string>  $sectionNames */
    private function scenario(int $majorUnits = 3, int $fieldUnits = 2, array $sectionNames = ['IT 1A']): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = array_map(static fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]), $sectionNames);
        $shared = [
            'lab_hours' => 0,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];
        $major = Course::create($shared + [
            'course_code' => 'IT 101',
            'course_name' => 'Introduction to Computing',
            'lecture_hours' => $majorUnits,
            'units' => $majorUnits,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
        ]);
        $fieldCourse = Course::create($shared + [
            'course_code' => 'PATHFIT 1',
            'course_name' => 'Movement Competency Training',
            'lecture_hours' => $fieldUnits,
            'units' => $fieldUnits,
            'course_category' => 'minor',
            'room_type_required' => 'field',
        ]);
        foreach ([$major, $fieldCourse] as $course) {
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        }
        foreach ($sections as $i => $section) {
            Rooms::create(['room_code' => 'IT 10'.($i + 1), 'building' => 'IT', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }
        Rooms::create(['room_code' => 'FIELD 1', 'building' => 'Grounds', 'room_type' => 'field', 'status' => 'available', 'department_id' => $department->id]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => (int) $section->id,
                'course_ids' => [(int) $major->id, (int) $fieldCourse->id],
            ], $sections),
        ], $major, $fieldCourse];
    }
}
