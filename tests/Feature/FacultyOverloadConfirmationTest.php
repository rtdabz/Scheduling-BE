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
 * Basic Load used to be a wall in Auto-Assign and nothing at all on the
 * assignment page, so an overload was either impossible or invisible — never a
 * decision anyone made. It is now a threshold the user crosses deliberately:
 * assignment continues into the overload allowance and then pro bono, and every
 * assignment that *causes* an overload is confirmed first.
 *
 * Basic Load is `max_units - deload_units`, so an instructor with a 21-unit
 * maximum and 6 units of deload has 15 units of Basic Load. The fixture below
 * grants 3 units of overload and 3 of pro bono on top, putting the bands at
 * 0–15 basic, 16–18 overload, 19–21 pro bono, and anything past 21 beyond the
 * ceiling.
 */
class FacultyOverloadConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRM_MESSAGE = 'This instructor will have an overload. Do you want to proceed?';

    private const CONFIRM_MESSAGE_PLURAL = 'These instructors will have an overload. Do you want to proceed?';

    /** Distinct day/hour per generated row, so nothing collides on time. */
    private int $slot = 0;

    public function test_an_assignment_within_the_basic_load_saves_without_a_prompt(): void
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
            ->assertJsonPath('warnings', []);

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_crossing_the_basic_load_asks_before_writing(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', self::CONFIRM_MESSAGE)
            ->assertJsonCount(1, 'overload_confirmation.instructors')
            ->assertJsonPath('overload_confirmation.instructors.0.faculty_id', $fixture['faculty']->id)
            ->assertJsonPath('overload_confirmation.instructors.0.tier', 'overload')
            ->assertJsonPath('overload_confirmation.instructors.0.tier_label', 'Overload')
            ->assertJsonPath('overload_confirmation.instructors.0.basic_load', 15)
            ->assertJsonPath('overload_confirmation.instructors.0.current_units', 15)
            ->assertJsonPath('overload_confirmation.instructors.0.added_units', 3)
            ->assertJsonPath('overload_confirmation.instructors.0.projected_units', 18)
            ->assertJsonPath('overload_confirmation.instructors.0.unit_ceiling', 21)
            ->assertJsonPath(
                'overload_confirmation.instructors.0.assignment_label',
                "{$target->course->course_code} — {$target->section->section_name}",
            );

        // Answering "No" is simply never sending the flag, so nothing may have
        // been written by the request that raised the question.
        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_confirming_commits_the_overload(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'confirm_overload' => true,
            ])
            ->assertOk()
            ->assertJsonPath('load.tier', 'overload')
            ->assertJsonPath('load.projected_units', 18)
            // Still inside the ceiling, so the soft ceiling warning stays quiet.
            ->assertJsonPath('warnings', []);

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_the_pro_bono_band_is_named_in_the_prompt(): void
    {
        $fixture = $this->fixture();
        // Basic Load plus the whole overload allowance, so the next class is pro bono.
        $this->carryLoad($fixture, 18);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('overload_confirmation.instructors.0.tier', 'probono')
            ->assertJsonPath('overload_confirmation.instructors.0.tier_label', 'Pro-bono')
            ->assertJsonPath('overload_confirmation.instructors.0.projected_units', 21);
    }

    public function test_past_the_ceiling_is_rejected_even_when_overload_is_confirmed(): void
    {
        $fixture = $this->fixture();
        // The full ceiling: Basic Load, overload allowance and pro bono all used.
        $this->carryLoad($fixture, 21);
        $target = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'faculty_unit_ceiling')
            ->assertJsonPath('violations.0.severity', 'hard')
            ->assertJsonPath('violations.0.projected_units', 24)
            ->assertJsonPath('violations.0.unit_ceiling', 21);

        // Overload confirmation only applies inside the configured ceiling.
        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'confirm_overload' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'faculty_unit_ceiling');

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_re_saving_the_instructor_who_already_holds_the_class_does_not_prompt(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);
        $target->update(['faculty_id' => $fixture['faculty']->id]);

        // Already over Basic Load, but this assignment adds nothing, so it does
        // not *cause* an overload and there is nothing to ask about.
        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
            ])
            ->assertOk()
            ->assertJsonPath('load.added_units', 0)
            ->assertJsonPath('load.projected_units', 18)
            ->assertJsonPath('load.tier', 'overload');
    }

    public function test_an_instructor_with_no_basic_load_is_never_prompted(): void
    {
        // Nothing configured means no threshold to cross, mirroring the guard the
        // soft ceiling warning already had.
        $fixture = $this->fixture(['max_units' => 0, 'deload_units' => 0, 'overload_units' => 0, 'probono_units' => 0]);
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

    public function test_the_timetable_route_asks_for_the_same_confirmation(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $target = $this->assignable($fixture, 3);

        // The slot popup and the inline picker both assign through this route.
        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(409)
            ->assertJsonPath('message', self::CONFIRM_MESSAGE)
            ->assertJsonPath('overload_confirmation.instructors.0.tier', 'overload');

        $this->assertNull($target->refresh()->faculty_id);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'confirm_overload' => true,
            ])
            ->assertOk();

        $this->assertSame($fixture['faculty']->id, $target->refresh()->faculty_id);
    }

    public function test_clearing_an_instructor_is_never_gated(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 21);
        $target = $this->assignable($fixture, 3);
        $target->update(['faculty_id' => $fixture['faculty']->id]);

        // Removing load cannot cause an overload, however overloaded they are.
        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$target->id}", ['faculty_id' => null])
            ->assertOk();

        $this->assertNull($target->refresh()->faculty_id);
    }

    public function test_bulk_assignment_asks_once_for_every_overloading_instructor(): void
    {
        $fixture = $this->fixture();
        $second = $this->instructor($fixture, 'Second');

        $this->carryLoad($fixture, 15);
        $this->carryLoad($fixture, 15, $second);

        $first = $this->assignable($fixture, 3);
        $other = $this->assignable($fixture, 3);

        $assignments = [
            ['schedule_ids' => [$first->id], 'faculty_id' => $fixture['faculty']->id],
            ['schedule_ids' => [$other->id], 'faculty_id' => $second->id],
        ];

        $response = $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => $assignments])
            ->assertStatus(409)
            ->assertJsonPath('message', self::CONFIRM_MESSAGE_PLURAL)
            ->assertJsonCount(2, 'overload_confirmation.instructors');

        $reported = collect($response->json('overload_confirmation.instructors'));
        $this->assertEqualsCanonicalizing(
            [(int) $fixture['faculty']->id, (int) $second->id],
            $reported->pluck('faculty_id')->all(),
        );
        $this->assertSame(['overload', 'overload'], $reported->pluck('tier')->all());

        $this->assertNull($first->refresh()->faculty_id);
        $this->assertNull($other->refresh()->faculty_id);

        // One confirmation covers the whole batch rather than one per class.
        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => $assignments,
                'confirm_overload' => true,
            ])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 2);

        $this->assertSame($fixture['faculty']->id, $first->refresh()->faculty_id);
        $this->assertSame($second->id, $other->refresh()->faculty_id);
    }

    public function test_bulk_assignment_projects_the_whole_batch_onto_one_instructor(): void
    {
        $fixture = $this->fixture();
        // Each class alone stays inside Basic Load; together they cross it, which a
        // per-class check would miss.
        $this->carryLoad($fixture, 12);
        $first = $this->assignable($fixture, 3);
        $other = $this->assignable($fixture, 3);

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$first->id, $other->id],
                    'faculty_id' => $fixture['faculty']->id,
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', self::CONFIRM_MESSAGE)
            ->assertJsonPath('overload_confirmation.instructors.0.added_units', 6)
            ->assertJsonPath('overload_confirmation.instructors.0.projected_units', 18)
            ->assertJsonPath('overload_confirmation.instructors.0.assignment_label', '2 classes');
    }

    public function test_the_assignment_picker_reports_each_instructor_load(): void
    {
        $fixture = $this->fixture();
        $this->carryLoad($fixture, 15);
        $this->assignable($fixture, 3);

        // The picker needs the same numbers the gate projects from, so an overload
        // is visible before Save is pressed.
        $this->actingAs($fixture['user'])
            ->getJson('/api/instructor-assignments')
            ->assertOk()
            ->assertJsonPath('faculties.0.assigned_units', 15)
            ->assertJsonPath('faculties.0.required_units', 15)
            ->assertJsonPath('faculties.0.unit_ceiling', 21);
    }

    /**
     * @param  array<string, mixed>  $facultyOverrides
     * @return array<string, mixed>
     */
    private function fixture(array $facultyOverrides = []): array
    {
        $department = Departments::create(['department_name' => 'Load Dept', 'department_code' => 'LOD']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $fixture = [
            'department' => $department,
            'term' => $term,
            'room' => Rooms::create([
                'room_code' => 'LOD101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'user' => User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
        ];

        $fixture['faculty'] = $this->instructor($fixture, 'Load', $facultyOverrides);

        return $fixture;
    }

    /**
     * An instructor with 15 units of Basic Load (21 maximum less 6 deload), 3 units
     * of overload allowance and 3 of pro bono — a 21-unit ceiling.
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
            'probono_units' => 3,
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
            'term_id' => $fixture['term']->id,
            'status' => 'active',
        ]);

        // A slot of its own per row, so nothing in these tests is ever refused for
        // a time conflict — the load gate is the only thing under test.
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $hour = 7 + intdiv($slot, count($days));

        return Schedule::create([
            'term_id' => $fixture['term']->id,
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
