<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manual scheduling picks a curriculum per section: two sections of the same
 * year level may follow different curricula, and each one's course options,
 * durations, and saved classes come from its own curriculum.
 */
class SectionCurriculumSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_section_changes_curriculum_without_moving_its_year_level(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['secretary'])
            ->putJson('/api/sections/'.$fixture['sectionB']->id, ['curriculum_id' => $fixture['new']->id])
            ->assertOk()
            ->assertJsonPath('curriculum.id', $fixture['new']->id);

        $this->assertSame((int) $fixture['old']->id, (int) $fixture['sectionA']->fresh()->curriculum_id);
        $this->assertSame((int) $fixture['new']->id, (int) $fixture['sectionB']->fresh()->curriculum_id);
    }

    public function test_a_section_with_classes_from_its_curriculum_keeps_them_and_its_curriculum(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->plot($fixture, $fixture['sectionA'], $fixture['old'], $fixture['oldOnly']);

        $this->actingAs($fixture['secretary'])
            ->putJson('/api/sections/'.$fixture['sectionA']->id, ['curriculum_id' => $fixture['new']->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This change would leave existing classes in a different curriculum. Clear those classes first.');

        $this->assertSame((int) $fixture['old']->id, (int) $fixture['sectionA']->fresh()->curriculum_id);
        $this->assertNotNull($schedule->fresh());
        $this->assertSame((int) $fixture['old']->id, (int) $schedule->fresh()->curriculum_id);
    }

    public function test_a_manual_class_must_use_a_course_from_the_section_curriculum(): void
    {
        $fixture = $this->fixture();
        $fixture['sectionB']->update(['curriculum_id' => $fixture['new']->id]);

        // OLD 101 sits at year 1, 1st semester in the old curriculum, so only
        // curriculum membership can tell it apart from a valid choice.
        $response = $this->actingAs($fixture['secretary'])
            ->postJson('/api/schedules', $this->payload($fixture, $fixture['sectionB'], $fixture['oldOnly']))
            ->assertUnprocessable();

        $this->assertContains('curriculum_course_membership', array_column($response->json('violations'), 'rule'));
        $this->assertSame(0, Schedule::query()->where('section_id', $fixture['sectionB']->id)->count());

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/schedules', $this->payload($fixture, $fixture['sectionB'], $fixture['newOnly']))
            ->assertCreated()
            ->assertJsonPath('curriculum_id', $fixture['new']->id);
    }

    public function test_the_scheduler_payload_carries_every_curriculum_placement_and_duration(): void
    {
        $fixture = $this->fixture();

        $courses = collect(
            $this->actingAs($fixture['secretary'])->getJson('/api/initial-data')->assertOk()->json('courses'),
        )->keyBy('id');

        $shared = $courses[$fixture['shared']->id];
        $this->assertEqualsCanonicalizing([
            ['curriculum_id' => $fixture['old']->id, 'year_level' => '1', 'semester' => '1st'],
            ['curriculum_id' => $fixture['new']->id, 'year_level' => '2', 'semester' => '1st'],
        ], $shared['curriculum_placements']);
        $this->assertSame(180, $shared['class_minutes_by_curriculum'][$fixture['old']->id]['lecture']);
        $this->assertSame(120, $shared['class_minutes_by_curriculum'][$fixture['new']->id]['lecture']);

        $this->assertSame(
            [['curriculum_id' => $fixture['new']->id, 'year_level' => '1', 'semester' => '1st']],
            $courses[$fixture['newOnly']->id]['curriculum_placements'],
        );
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);

        $old = Curriculum::create([
            'name' => 'BSIT 2020', 'code' => 'BSIT-2020', 'department_id' => $department->id,
            'effective_school_year' => '2020-2021', 'status' => 'active',
        ]);
        $new = Curriculum::create([
            'name' => 'BSIT 2026', 'code' => 'BSIT-2026', 'department_id' => $department->id,
            'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);

        $oldOnly = $this->course('OLD 101', $department->id);
        $newOnly = $this->course('NEW 101', $department->id);
        $shared = $this->course('SHARED 1', $department->id);
        $this->place($old, $oldOnly, 1);
        $this->place($new, $newOnly, 1);
        $this->place($old, $shared, 1, 180);
        $this->place($new, $shared, 2, 120);

        $section = fn (string $name) => Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id,
            'curriculum_id' => $old->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'IT 101', 'building' => 'IT Building',
            'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $department->id,
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'program' => $program,
            'old' => $old,
            'new' => $new,
            'oldOnly' => $oldOnly,
            'newOnly' => $newOnly,
            'shared' => $shared,
            'sectionA' => $section('IT 1A'),
            'sectionB' => $section('IT 1B'),
            'secretary' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            ),
        ];
    }

    private function course(string $code, int $departmentId): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code,
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $departmentId, 'status' => 'active',
        ]);
    }

    private function place(Curriculum $curriculum, Course $course, int $yearLevel, ?int $lectureMinutes = null): void
    {
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => $yearLevel,
            'semester' => 1,
            'lecture_minutes' => $lectureMinutes,
        ]);
    }

    private function plot(array $fixture, Sections $section, Curriculum $curriculum, Course $course): Schedule
    {
        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'room_id' => Rooms::query()->firstOrFail()->id,
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'day' => 'Monday',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $fixture, Sections $section, Course $course): array
    {
        return [
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $fixture['department']->id,
            'room_id' => Rooms::query()->firstOrFail()->id,
            'day' => 'Tuesday',
            'start_time' => '08:00',
            'end_time' => '11:00',
            'mode' => 'on-site',
        ];
    }
}
