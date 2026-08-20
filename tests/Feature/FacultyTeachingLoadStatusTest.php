<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teaching load used to count every row carrying a faculty_id, so a schedule
 * withdrawn back to `revision` — or one that never reached approval — kept
 * inflating the instructor's units on the Faculty pages, the dashboards and the
 * printable load form. Only assignments on approved rows are real load.
 */
class FacultyTeachingLoadStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_counts_approved_assignments(): void
    {
        $fixture = $this->fixture();
        $this->schedule($fixture, ['status' => 'faculty_assignment']);

        $this->actingAs($fixture['user'])
            ->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonPath('0.assigned_units', 3)
            ->assertJsonCount(1, '0.assigned_courses');

        $this->actingAs($fixture['user'])
            ->getJson("/api/faculties/{$fixture['faculty']->id}")
            ->assertOk()
            ->assertJsonPath('assigned_units', 3);
    }

    public function test_load_ignores_assignments_on_withdrawn_rows(): void
    {
        $fixture = $this->fixture();
        $this->schedule($fixture, ['status' => 'revision']);

        $this->actingAs($fixture['user'])
            ->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonPath('0.assigned_units', 0)
            ->assertJsonCount(0, '0.assigned_courses');

        $this->actingAs($fixture['user'])
            ->getJson("/api/faculties/{$fixture['faculty']->id}")
            ->assertOk()
            ->assertJsonPath('assigned_units', 0);
    }

    public function test_load_ignores_assignments_on_rows_pushed_back_to_done(): void
    {
        $fixture = $this->fixture();
        $this->schedule($fixture, ['status' => 'completed']);

        $this->actingAs($fixture['user'])
            ->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonPath('0.assigned_units', 0);
    }

    public function test_finalized_assignments_still_count(): void
    {
        $fixture = $this->fixture();
        $this->schedule($fixture, ['status' => 'finalized']);

        $this->actingAs($fixture['user'])
            ->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonPath('0.assigned_units', 3);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Load Dept', 'department_code' => 'LOD']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'term' => $term,
            'room' => Rooms::create([
                'room_code' => 'LOD101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'course' => Course::create([
                'course_code' => 'LOD101',
                'course_name' => 'Load Course',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'section' => Sections::create([
                'section_name' => 'LOD-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Load',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'max_units' => 18,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function schedule(array $fixture, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['course']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => $fixture['faculty']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
        ], $overrides));
    }
}
