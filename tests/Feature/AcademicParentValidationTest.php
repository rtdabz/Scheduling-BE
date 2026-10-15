<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicParentValidationTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Departments::create(['department_name' => 'College A', 'department_code' => 'A']);
        $this->program = Program::create(['department_id' => $this->department->id, 'code' => 'A1']);
        // Keep authoring available when the selected parent program is archived.
        Program::create(['department_id' => $this->department->id, 'code' => 'A2']);
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->grantCapabilities($vpaa, ['schedule.view', 'schedule.create']);
        $this->actingAs($vpaa);
    }

    public function test_program_create_and_update_reject_an_archived_department(): void
    {
        $this->department->delete();
        $this->postJson('/api/programs', ['department_id' => $this->department->id, 'code' => 'NEW'])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->patchJson('/api/programs/'.$this->program->id, ['name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertDatabaseMissing('programs', ['code' => 'NEW']);
        $this->assertNull($this->program->fresh()->name);
    }

    public function test_course_create_and_update_reject_an_archived_department(): void
    {
        $course = Course::create($this->payload());
        $this->department->delete();
        $this->postJson('/api/courses', [...$this->payload(), 'course_code' => 'NEW'])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->putJson('/api/courses/'.$course->id, ['course_name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertSame('Course A', $course->fresh()->course_name);
        $this->assertDatabaseMissing('courses', ['course_code' => 'NEW']);
    }

    public function test_secretary_cannot_create_or_reassign_a_course_to_an_archived_program(): void
    {
        $course = Course::create([...$this->payload(), 'program_id' => null]);
        $this->program->delete();
        $this->actingAs(User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]));
        $this->postJson('/api/courses', [...$this->payload(), 'course_code' => 'NEW'])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->putJson('/api/courses/'.$course->id, ['program_id' => $this->program->id])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->assertNull($course->fresh()->program_id);
        $this->assertDatabaseMissing('courses', ['course_code' => 'NEW']);
    }

    public function test_partial_course_updates_validate_retained_program_and_allow_clearing_it(): void
    {
        $course = Course::create($this->payload());
        $this->program->delete();
        $this->putJson('/api/courses/'.$course->id, ['course_name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->putJson('/api/courses/'.$course->id, ['program_id' => null, 'course_name' => 'Changed'])
            ->assertOk()->assertJsonPath('program_id', null);
        $this->assertSame('Changed', $course->fresh()->course_name);
    }

    public function test_department_only_change_cannot_retain_an_incompatible_program(): void
    {
        $course = Course::create($this->payload());
        $other = Departments::create(['department_name' => 'College B', 'department_code' => 'B']);
        $program = Program::create(['department_id' => $other->id, 'code' => 'B1']);
        $this->postJson('/api/courses', [...$this->payload(), 'course_code' => 'NEW', 'program_id' => $program->id])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->putJson('/api/courses/'.$course->id, ['department_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('program_id');
        $this->assertEquals($this->department->id, $course->fresh()->department_id);
        $this->putJson('/api/courses/'.$course->id, ['department_id' => $other->id, 'program_id' => $program->id])
            ->assertOk()->assertJsonPath('program_id', $program->id);
    }

    public function test_course_cannot_move_to_an_archived_department(): void
    {
        $course = Course::create($this->payload());
        $other = Departments::create(['department_name' => 'Archived College', 'department_code' => 'OLD']);
        $other->delete();
        $this->putJson('/api/courses/'.$course->id, ['department_id' => $other->id, 'program_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertEquals($this->department->id, $course->fresh()->department_id);
        $this->assertEquals($this->program->id, $course->program_id);
    }

    public function test_live_parents_and_minor_conversion_remain_supported(): void
    {
        $id = $this->postJson('/api/courses', $this->payload())->assertCreated()->json('id');
        $this->putJson('/api/courses/'.$id, ['course_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('program_id', $this->program->id);
        $this->program->delete();
        $this->putJson('/api/courses/'.$id, ['course_category' => 'minor'])
            ->assertOk()->assertJsonPath('program_id', null);
        $this->postJson('/api/courses', [...$this->payload(), 'course_code' => 'NULL', 'program_id' => null])
            ->assertCreated()->assertJsonPath('program_id', null);
    }

    public function test_batch_course_creation_rejects_an_archived_inherited_department(): void
    {
        $curriculum = Curriculum::create(['department_id' => $this->department->id, 'code' => 'CUR-A', 'name' => 'Curriculum A', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]));
        $this->department->delete();
        $this->postJson('/api/curriculum/'.$curriculum->id.'/courses/batch-create', ['courses' => [[
            ...$this->payload(), 'row_id' => 'row-1', 'year_level' => 1, 'semester' => 1,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('curriculum_course', 0);
    }

    public function test_room_restore_requires_its_retained_home_program(): void
    {
        $room = \App\Models\Rooms::create(['room_code' => 'RESTORE', 'room_type' => 'lecture', 'department_id' => $this->department->id]);
        $room->forceFill(['home_program_id' => $this->program->id])->save();
        $room->delete();
        $this->program->delete();
        $this->postJson('/api/archives/rooms/'.$room->id.'/restore')->assertUnprocessable();
        $this->assertSoftDeleted($room);
        $this->program->restore();
        $this->postJson('/api/archives/rooms/'.$room->id.'/restore')->assertOk();
        $this->assertEquals($this->program->id, $room->fresh()->home_program_id);
    }

    public function test_course_restore_requires_retained_teaching_parents(): void
    {
        $department = Departments::create(['department_name' => 'Teaching College', 'department_code' => 'TC']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'TP']);
        $source = Program::create(['department_id' => $this->department->id, 'code' => 'SOURCE']);
        $course = Course::create([...$this->payload(), 'teaching_department_id' => $department->id,
            'teaching_program_id' => $program->id, 'teaching_source_program_id' => $source->id]);
        $course->delete();
        foreach ([$program, $department, $source] as $parent) {
            $parent->delete();
            $this->postJson('/api/archives/courses/'.$course->id.'/restore')->assertUnprocessable();
            $this->assertSoftDeleted($course);
            $parent->restore();
        }
        $this->postJson('/api/archives/courses/'.$course->id.'/restore')->assertOk();
    }

    private function payload(): array
    {
        return [
            'department_id' => $this->department->id, 'program_id' => $this->program->id,
            'course_code' => 'A101', 'course_name' => 'Course A', 'lecture_hours' => 3,
            'lab_hours' => 0, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
        ];
    }
}
