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
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryFieldPlacementTest extends TestCase
{
    use RefreshDatabase;

    public static function laboratoryCourses(): array
    {
        return [
            'major mixed' => ['major', 2, 1, false, false],
            'minor mixed' => ['minor', 2, 1, false, false],
            'laboratory only' => ['major', 0, 3, false, false],
            'legacy field classification' => ['minor', 2, 1, true, false],
            'integrated' => ['major', 2, 1, false, true],
        ];
    }

    #[DataProvider('laboratoryCourses')]
    public function test_laboratory_requirements_cannot_be_replaced_by_field_placement(string $category, int $lecture, int $lab, bool $legacyField, bool $integrated): void
    {
        $department = Departments::create(['department_name' => 'Science', 'department_code' => 'SCI']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'SCI', 'name' => 'Science']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $curriculum = Curriculum::create(['department_id' => $department->id, 'program_id' => $program->id, 'code' => 'SCI-2026', 'name' => 'Science', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $section = Sections::create(['department_id' => $department->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'semester_id' => $semester->id, 'section_name' => 'SCI 1A', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        $course = Course::create(['department_id' => $department->id, 'course_code' => 'SCI 101', 'course_name' => 'Science', 'course_category' => $category, 'lecture_hours' => $lecture, 'lab_hours' => $lab, 'units' => 3, 'room_type_required' => $legacyField ? 'field' : 'laboratory', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $labRoom = Rooms::create(['room_code' => 'LAB', 'room_type' => 'laboratory', 'department_id' => $department->id, 'status' => 'available']);
        Rooms::create(['room_code' => 'LECTURE', 'room_type' => 'lecture', 'department_id' => $department->id, 'status' => 'available']);
        $field = Rooms::create(['room_code' => 'FIELD', 'room_type' => 'field', 'department_id' => null, 'status' => 'available']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $this->actingAs($user);

        $this->assertFalse(SchedulingPolicy::isFieldCourse($course, fieldCourseCodes: [$course->course_code]));
        $this->assertSame('laboratory', SchedulingPolicy::effectiveRoomType($course, $department->id, 'field', [$course->course_code]));
        $this->postJson('/api/courses', [...$course->getAttributes(), 'course_code' => 'SCI 102', 'room_type_required' => 'field'])
            ->assertUnprocessable()->assertJsonValidationErrors('room_type_required');
        $this->patchJson('/api/courses/'.$course->id, ['room_type_required' => 'field'])
            ->assertUnprocessable()->assertJsonValidationErrors('room_type_required');

        $placement = ['semester_id' => $semester->id, 'department_id' => $department->id, 'section_id' => $section->id, 'course_id' => $course->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'field', 'room_id' => $field->id];
        $this->postJson('/api/schedules', $placement)->assertUnprocessable();
        $this->postJson('/api/schedules/batch', ['operations' => [$placement]])->assertUnprocessable();
        $this->assertDatabaseCount('schedules', 0);

        $snapshot = app(SchedulingSnapshotRepository::class)->capture($semester->id, $department->id, [$section->id], [$course->id]);
        $options = app(AvailableSlotFinder::class)->find(snapshot: $snapshot, sectionId: $section->id, courseId: $course->id, durationSlots: 6, modes: ['on-site', 'field']);
        $this->assertNotEmpty($options['slots']);
        $this->assertNotContains('field', array_column($options['slots'], 'mode'));

        $config = ['section_id' => $section->id, 'course_ids' => [$course->id],
            'selected_split_session_course_ids' => $integrated ? [$course->id] : [],
            'delivery_modes_by_course_id' => [$course->id => 'on-site']];
        $payload = ['semester_id' => $semester->id, 'department_id' => $department->id, 'year_level' => 1,
            'section_ids' => [$section->id], 'section_configs' => [$config],
            'rule_overrides' => ['field_course_codes' => [$course->course_code]]];
        $preview = $this->postJson('/api/schedule-recommendations/year-level-preview', $payload)->assertOk();
        $rows = $preview->json('schedules');
        $this->assertCount($integrated ? 2 : 1, $rows);
        foreach ($rows as $row) {
            $this->assertSame('on-site', $row['mode']);
            $room = Rooms::find($row['room_id']);
            $this->assertSame(($row['meeting_type'] ?? null) === 'lecture' ? 'lecture' : 'laboratory', $room?->room_type);
        }
        $payload['section_configs'][0]['delivery_modes_by_course_id'][$course->id] = 'field';
        $this->postJson('/api/schedule-recommendations/year-level-preview', $payload)->assertUnprocessable();

        $saved = $this->postJson('/api/schedules', [...$placement, 'mode' => 'on-site', 'room_id' => $labRoom->id])->assertCreated();
        $this->patchJson('/api/schedules/'.$saved->json('id'), ['mode' => 'field', 'room_id' => $field->id])->assertUnprocessable();
        $this->assertSame('on-site', Schedule::findOrFail($saved->json('id'))->mode);
        if ($legacyField) {
            $this->patchJson('/api/courses/'.$course->id, ['course_name' => 'Updated Science'])
                ->assertOk()->assertJsonPath('room_type_required', 'laboratory');
        }
    }
}
