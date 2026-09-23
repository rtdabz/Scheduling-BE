<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Columns that copy a value held elsewhere are written only from their source.
 */
class DerivedCopiesStayInSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_placement_follows_the_newest_active_curriculum(): void
    {
        $dept = Departments::create(['department_name' => 'Sync Dept', 'department_code' => 'SYN']);
        $course = Course::create([
            'course_code' => 'SYN101', 'course_name' => 'Sync Course', 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st', 'department_id' => $dept->id, 'status' => 'active',
        ]);
        $old = $this->curriculum($dept, 'OLD', '2024-2025', 'active');
        $new = $this->curriculum($dept, 'NEW', '2026-2027', 'deactivated');
        $old->courses()->attach($course->id, ['year_level' => 2, 'semester' => 1]);
        $new->courses()->attach($course->id, ['year_level' => 3, 'semester' => 2]);

        Course::syncPlacementFromCurricula([$course->id]);
        $this->assertSame(['2', '1st'], [$course->fresh()->year_level, $course->fresh()->semester]);

        // Activating the newer curriculum makes its placement the course's.
        $new->update(['status' => 'active']);
        $this->assertSame(['3', '2nd'], [$course->fresh()->year_level, $course->fresh()->semester]);
    }

    public function test_changing_a_user_role_updates_the_linked_faculty_profile(): void
    {
        $dept = Departments::create(['department_name' => 'Role Dept', 'department_code' => 'ROL']);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]);
        $faculty = Faculty::create([
            'user_id' => $user->id, 'administrative_role' => 'secretary', 'first_name' => 'Ana', 'last_name' => 'Cruz',
            'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $dept->id, 'status' => 'active',
        ]);

        $user->update(['role' => 'dean']);

        $this->assertSame('dean', $faculty->fresh()->administrative_role);
    }

    public function test_availability_stores_the_day_by_name(): void
    {
        $dept = Departments::create(['department_name' => 'Day Dept', 'department_code' => 'DAY']);
        $faculty = Faculty::create([
            'first_name' => 'Ben', 'last_name' => 'Reyes', 'employment_type' => 'part-time',
            'max_units' => 12, 'department_id' => $dept->id, 'status' => 'active',
        ]);

        $window = FacultyAvailability::create(['faculty_id' => $faculty->id, 'day_index' => 2, 'start_time' => '08:00', 'end_time' => '10:00']);

        $this->assertDatabaseHas('faculty_availabilities', ['id' => $window->id, 'day' => 'Wednesday']);
        $this->assertSame(2, $window->fresh()->day_index);
    }

    private function curriculum(Departments $dept, string $code, string $year, string $status): Curriculum
    {
        return Curriculum::create([
            'name' => "Curriculum {$code}", 'code' => $code, 'department_id' => $dept->id,
            'effective_school_year' => $year, 'status' => $status,
        ]);
    }
}
