<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accept and auto-apply both commit a recommendation's plan. They now share
 * one path, so auto-apply leaves the same recommendation_accepted record the
 * manual accept always did, and both return schedules with the trimmed
 * relation set rather than whole department rows (which carry a base64 logo).
 */
class RecommendationCommitAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_apply_records_the_acceptance_and_returns_lean_schedules(): void
    {
        [$section, $course, $user] = $this->scaffold();

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/auto-generate', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
            'seed' => 1234,
        ]);

        $response->assertOk();
        $this->assertNotSame([], $response->json('schedules'));
        $this->assertSame(Schedule::query()->count(), count($response->json('schedules')));

        $audit = SchedulingAuditLog::query()->where('action', 'recommendation_accepted')->first();
        $this->assertNotNull($audit, 'auto-apply left no acceptance record');
        $this->assertSame($user->id, (int) $audit->user_id);
        $this->assertTrue((bool) ($audit->metadata['auto_applied'] ?? false));
        $this->assertSame(
            Schedule::query()->orderBy('id')->pluck('id')->map('intval')->all(),
            array_map('intval', $audit->metadata['created_schedule_ids']),
        );

        $department = $response->json('schedules.0.department');
        $this->assertSame(['department_code', 'department_name', 'id'], collect($department)->keys()->sort()->values()->all());
    }

    /** @return array{0: Sections, 1: Course, 2: User} */
    private function scaffold(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Audit', 'department_code' => 'AUD', 'scheduling_profile' => 'standard',
            'logo' => 'data:image/png;base64,'.str_repeat('A', 2048),
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSAUD', 'name' => 'Bachelor of Audit']);
        $section = Sections::create([
            'section_name' => 'AUD 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'AUD 101', 'course_name' => 'Audit Principles', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
        $curriculum = Curriculum::create([
            'name' => 'Audit Curriculum', 'department_id' => $department->id, 'code' => 'AUD-2026',
            'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create(['room_code' => 'AUD 101', 'building' => 'B1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$section, $course, $user];
    }
}
