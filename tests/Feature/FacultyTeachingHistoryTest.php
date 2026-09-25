<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An instructor's teaching history lists every semester they taught, newest
 * first, counted the way the live load is: approved assignments only, and a
 * class met across several meetings counts its units once.
 */
class FacultyTeachingHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_groups_approved_classes_by_semester_newest_first(): void
    {
        $fixture = $this->fixture();
        $past = Semester::create(['academic_year' => '2025-2026', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);

        // Two meetings of one class count its units once.
        $this->schedule($fixture, ['status' => 'finalized']);
        $this->schedule($fixture, ['status' => 'finalized', 'day' => 'Wednesday']);
        $this->schedule($fixture, ['semester_id' => $past->id, 'status' => 'finalized']);
        // Not an assignment yet, so not history.
        $this->schedule($fixture, ['status' => 'revision', 'section_id' => $this->section($fixture, 'HIS-1B')->id]);

        $this->actingAs($fixture['user'])
            ->getJson("/api/faculties/{$fixture['faculty']->id}/teaching-history")
            ->assertOk()
            ->assertJsonCount(2, 'semesters')
            ->assertJsonPath('semesters.0.semester_id', $fixture['semester']->id)
            ->assertJsonPath('semesters.0.is_active', true)
            ->assertJsonPath('semesters.0.total_units', 3)
            ->assertJsonPath('semesters.0.section_count', 1)
            ->assertJsonPath('semesters.0.courses.0.course_code', 'HIS101')
            ->assertJsonPath('semesters.0.courses.0.sections', ['HIS-1A'])
            ->assertJsonPath('semesters.1.semester_id', $past->id)
            ->assertJsonPath('semesters.1.is_active', false);
    }

    public function test_history_is_hidden_from_other_departments(): void
    {
        $fixture = $this->fixture();
        $other = Departments::create(['department_name' => 'Other Dept', 'department_code' => 'OTH']);
        $outsider = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $other->id]));

        $this->actingAs($outsider)
            ->getJson("/api/faculties/{$fixture['faculty']->id}/teaching-history")
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'History Dept', 'department_code' => 'HIS']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'HISP', 'name' => 'History Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $fixture = [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => Rooms::create([
                'room_code' => 'HIS101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'course' => Course::create([
                'course_code' => 'HIS101',
                'course_name' => 'History Course',
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
            'faculty' => Faculty::create([
                'first_name' => 'History',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'max_units' => 18,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
        $fixture['section'] = $this->section($fixture, 'HIS-1A');

        return $fixture;
    }

    /** @param array<string, mixed> $fixture */
    private function section(array $fixture, string $name): Sections
    {
        return Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function schedule(array $fixture, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $fixture['semester']->id,
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
