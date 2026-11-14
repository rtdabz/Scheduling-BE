<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Module-scoped archive listings stay within the module's record types. */
class ArchiveModuleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_module_scope_never_lists_or_counts_other_modules_records(): void
    {
        $f = $this->fixture();
        $room = Rooms::create(['room_code' => 'Z900', 'building' => 'Annex', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        Rooms::create(['room_code' => 'Z901', 'building' => 'Annex', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        $room->delete();
        $designation = Designation::create(['name' => 'Coordinator', 'code' => 'CRD', 'deload_units' => 3, 'status' => 'active']);
        $designation->delete();

        $response = $this->actingAs($f['vpaa'])->getJson('/api/archives?'.http_build_query(['types' => ['buildings', 'rooms']]))->assertOk();
        $this->assertSame(['rooms' => 1, 'buildings' => 0], $response->json('counts'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('total_archived'));
        $this->assertSame(['rooms'], collect($response->json('data'))->pluck('type')->unique()->values()->all());

        // A type from another module in the URL is ignored instead of widening the scope.
        $widened = $this->actingAs($f['vpaa'])->getJson('/api/archives?'.http_build_query(['types' => ['buildings', 'rooms'], 'type' => 'designations']))->assertOk();
        $this->assertSame(['rooms'], collect($widened->json('data'))->pluck('type')->unique()->values()->all());

        $this->actingAs($f['vpaa'])->getJson('/api/archives?'.http_build_query(['types' => ['schedules']]))->assertJsonValidationErrors('types.0');
        $this->actingAs($f['secretary'])->getJson('/api/archives?'.http_build_query(['types' => ['rooms']]))->assertForbidden();
        $this->actingAs($f['vpaa'])->getJson('/api/archives?'.http_build_query(['types' => ['unknown']]))->assertJsonValidationErrors('types.0');
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'PA', 'name' => 'Program A']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $course = static fn (string $code): Course => Course::create([
            'course_code' => $code, 'course_name' => "Course {$code}", 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);

        return [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'A101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'courseA' => $course('A101'),
            'courseB' => $course('A102'),
            'section' => Sections::create([
                'section_name' => 'A1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
                'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
            ]),
            'vpaa' => User::factory()->create(['role' => 'vpaa', 'department_id' => null]),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }
}
