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
 * Assignment runs through Basic Load and the overload allowance without a
 * prompt, and stops at the ceiling (Basic Load + overload). There is no pro bono
 * band and no flag that lets an assignment past the ceiling.
 *
 * The fixture instructor has a 21-unit maximum less 6 deload (15 Basic Load)
 * plus 3 overload: an 18-unit ceiling.
 */
class FacultyUnitCeilingTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct day/hour per generated row, so nothing collides on time. */
    private int $slot = 0;

    public function test_an_assignment_within_the_basic_load_saves(): void
    {
        $fixture = $this->fixture();
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.tier', 'basic')
            ->assertJsonPath('load.basic_load', 15)
            ->assertJsonPath('load.projected_units', 3)
            ->assertJsonPath('load.exceeds_ceiling', false);

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_filling_the_overload_allowance_up_to_the_ceiling_saves(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.tier', 'overload')
            ->assertJsonPath('load.projected_units', 18);

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_going_past_the_ceiling_is_refused(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 18);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Load Instructor would carry 21 units, past the 18-unit limit (basic load + overload). Choose another instructor.')
            ->assertJsonCount(1, 'unit_ceiling_exceeded.instructors')
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.faculty_id', $fixture['faculty']->id)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.tier', 'beyond_ceiling')
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.current_units', 18)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.added_units', 3)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.projected_units', 21)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.unit_ceiling', 18)
            ->assertJsonPath(
                'unit_ceiling_exceeded.instructors.0.assignment_label',
                "{$target->course->course_code} — {$target->section->section_name}",
            );

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_the_old_confirmation_flag_no_longer_lets_it_through(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 18);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'confirm_overload' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_re_saving_the_instructor_who_already_holds_the_class_is_not_refused(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 18);
        $target = $this->assignable($fixture, 3);
        $target->update(['faculty_id' => $fixture['faculty']->id]);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.added_units', 0)
            ->assertJsonPath('load.projected_units', 21)
            ->assertJsonPath('load.tier', 'beyond_ceiling');
    }

    public function test_an_overload_only_instructor_stops_at_their_allowance(): void
    {
        $fixture = $this->fixture(['max_units' => 0, 'deload_units' => 0, 'overload_units' => 15]);
        $first = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$first->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.tier', 'overload');

        $this->carryLoad($fixture, 12);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.basic_load', 0)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.unit_ceiling', 15);

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_an_instructor_with_no_load_configured_is_not_capped(): void
    {
        $fixture = $this->fixture(['max_units' => 0, 'deload_units' => 0, 'overload_units' => 0]);
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.basic_load', 0);

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_the_timetable_route_enforces_the_same_ceiling(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 18);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.tier', 'beyond_ceiling');

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'confirm_overload' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_clearing_an_instructor_is_never_gated(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 21);
        $target = $this->assignable($fixture, 3);
        $target->update(['faculty_id' => $fixture['faculty']->id]);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", ['faculty_id' => null])
            ->assertOk();

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_bulk_assignment_refuses_every_instructor_past_the_ceiling_at_once(): void
    {
        $fixture = $this->fixture();
        $second = $this->instructor($fixture, 'Second');

        $this->carryLoad($fixture, 18);
        $this->carryLoad($fixture, 18, $second);

        $first = $this->assignable($fixture, 3);
        $other = $this->assignable($fixture, 3);

        $response = $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [
                ['schedule_ids' => [$first->id], 'faculty_id' => $fixture['faculty']->id],
                ['schedule_ids' => [$other->id], 'faculty_id' => $second->id],
            ]])
            ->assertStatus(422)
            ->assertJsonCount(2, 'unit_ceiling_exceeded.instructors');

        $this->assertStringStartsWith('These instructors would go past their unit limit', $response->json('message'));
        $this->assertEqualsCanonicalizing(
            [(int) $fixture['faculty']->id, (int) $second->id],
            collect($response->json('unit_ceiling_exceeded.instructors'))->pluck('faculty_id')->all(),
        );
        $this->assertNull($first->refresh()->faculty_id);
        $this->assertNull($other->refresh()->faculty_id);
    }

    public function test_bulk_assignment_projects_the_whole_batch_onto_one_instructor(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $first = $this->assignable($fixture, 3);
        $other = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$first->id, $other->id],
                    'faculty_id' => $fixture['faculty']->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.added_units', 6)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.projected_units', 21)
            ->assertJsonPath('unit_ceiling_exceeded.instructors.0.assignment_label', '2 classes');
    }

    public function test_retirement_keeps_manual_ceiling_refusal_and_eligible_selection(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 18);
        $roomy = $this->instructor($fixture, 'Roomy');
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->getJson("/api/instructor-assignments/{$target->id}/recommendations")
            ->assertStatus(410)->assertJsonPath('options', []);
        $this->patchJson("/api/instructor-assignments/{$target->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)->assertJsonPath('unit_ceiling_exceeded.instructors.0.projected_units', 21);
        $this->assertNull($target->refresh()->faculty_id);
        $this->patchJson("/api/instructor-assignments/{$target->id}", ['faculty_id' => $roomy->id])->assertOk();
    }

    public function test_the_assignment_picker_reports_each_instructor_load(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonPath('faculties.0.assigned_units', 15)
            ->assertJsonPath('faculties.0.required_units', 15)
            ->assertJsonPath('faculties.0.unit_ceiling', 18);
    }

    /**
     * @param  array<string, mixed>  $facultyOverrides
     * @return array<string, mixed>
     */
    private function fixture(array $facultyOverrides = []): array
    {
        $department = Departments::create(['department_name' => 'Load Dept', 'department_code' => 'LOD']);
        // Schedule capabilities and section scheduling both require the
        // department to own a program.
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSLOD',
            'name' => 'Load Program',
        ]);
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
                'room_code' => 'LOD101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];

        $fixture['faculty'] = $this->instructor($fixture, 'Load', $facultyOverrides);

        return $fixture;
    }

    /**
     * An instructor with 15 units of Basic Load (21 maximum less 6 deload) and 3
     * units of overload allowance: an 18-unit ceiling.
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function instructor(array $fixture, string $firstName, array $overrides = []): Faculty
    {
        return Faculty::create(array_merge([
            'first_name' => $firstName,
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $fixture['department']->id,
            'status' => 'active',
            'max_units' => 21,
            'deload_units' => 6,
            'overload_units' => 3,
        ], $overrides));
    }

    /**
     * An unassigned class of $units, sitting at the stage where assignment is legal.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function assignable(array $fixture, int $units): Schedule
    {
        return $this->classRow($fixture, $units, null);
    }

    /**
     * Load the instructor already carries. One row stands in for however many
     * classes make up an existing load, which keeps the arithmetic of each test
     * visible in one number.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function carryLoad(array $fixture, int $units, ?Faculty $faculty = null): Schedule
    {
        return $this->classRow($fixture, $units, $faculty ?? $fixture['faculty']);
    }

    /** @param array<string, mixed> $fixture */
    private function classRow(array $fixture, int $units, ?Faculty $faculty): Schedule
    {
        $slot = $this->slot++;
        $suffix = $slot + 1;

        $course = Course::create([
            'course_code' => "LOD{$suffix}",
            'course_name' => "Load Course {$suffix}",
            'lecture_hours' => $units,
            'lab_hours' => 0,
            'units' => $units,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'status' => 'active',
        ]);

        $section = Sections::create([
            'section_name' => "LOD-1{$suffix}",
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);

        // A slot of its own per row, so nothing in these tests is ever refused for
        // a time conflict: the ceiling is the only thing under test.
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $hour = 7 + intdiv($slot, count($days));

        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => $faculty?->id,
            'day' => $days[$slot % count($days)],
            'start_time' => sprintf('%02d:00', $hour),
            'end_time' => sprintf('%02d:00', $hour + 1),
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]);
    }
}
