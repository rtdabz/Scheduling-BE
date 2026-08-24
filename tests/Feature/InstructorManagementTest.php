<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\UserFacultyProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Instructor Management: the roster CRUD, the load allowances the secretary
 * maintains, and the availability windows the scheduler validates against.
 */
class InstructorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_response_keeps_the_live_teaching_load(): void
    {
        $f = $this->fixture();
        $this->schedule($f, ['status' => 'faculty_assignment']);

        // Renaming an instructor must not make the page believe the load is zero:
        // the UI writes this reply straight into its cache.
        $this->actingAs($f['vpaa'])
            ->putJson("/api/faculties/{$f['faculty']->id}", ['first_name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('first_name', 'Renamed')
            ->assertJsonPath('assigned_units', 3)
            ->assertJsonCount(1, 'assigned_subjects')
            ->assertJsonCount(1, 'assigned_classes');
    }

    public function test_store_response_carries_the_load_fields(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->postJson('/api/faculties', $this->payload($f))
            ->assertCreated()
            ->assertJsonPath('assigned_units', 0)
            ->assertJsonPath('max_units', 21)
            ->assertJsonPath('required_units', 21)
            ->assertJsonPath('unit_ceiling', 21);
    }

    public function test_vpaa_cannot_update_load_allowances(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['max_units' => 30])
            ->assertForbidden();

        $this->assertSame(18, (int) $f['faculty']->refresh()->max_units);
    }

    public function test_unvalidated_columns_cannot_be_mass_assigned(): void
    {
        $f = $this->fixture();
        $victim = User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id]);

        $res = $this->actingAs($f['vpaa'])
            ->postJson('/api/faculties', $this->payload($f) + [
                'administrative_role' => 'dean',
                'user_id' => $victim->id,
            ])
            ->assertCreated();

        $created = Faculty::findOrFail($res->json('id'));
        $this->assertNull($created->administrative_role, 'administrative_role must not be settable through the roster form.');
        $this->assertNull($created->user_id, 'user_id must not be settable through the roster form.');
    }

    public function test_already_linked_user_id_no_longer_crashes(): void
    {
        $f = $this->fixture();
        $linked = User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id]);
        app(UserFacultyProfileService::class)->createFor($linked);

        // Used to hit the unique index on faculties.user_id and return a 500.
        $this->actingAs($f['vpaa'])
            ->postJson('/api/faculties', $this->payload($f) + ['user_id' => $linked->id])
            ->assertCreated();
    }

    public function test_delete_reports_the_schedules_it_releases(): void
    {
        $f = $this->fixture();
        $live = $this->schedule($f, ['status' => 'faculty_assignment']);

        $this->actingAs($f['vpaa'])
            ->deleteJson("/api/faculties/{$f['faculty']->id}")
            ->assertOk()
            ->assertJsonPath('released_schedule_count', 1)
            ->assertJsonPath('released_schedule_ids.0', $live->id);

        $this->assertNull($live->refresh()->faculty_id);
    }

    public function test_index_exposes_the_release_count_for_the_confirmation(): void
    {
        $f = $this->fixture();
        $this->schedule($f, ['status' => 'faculty_assignment']);

        $this->actingAs($f['vpaa'])
            ->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonPath('0.live_schedule_count', 1);
    }

    public function test_only_vpaa_may_create_or_delete_an_instructor(): void
    {
        $f = $this->fixture();

        foreach (['secretary', 'program_head', 'dean'] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $f['department']->id]);
            $this->actingAs($user)
                ->postJson('/api/faculties', $this->payload($f))
                ->assertForbidden();
            $this->actingAs($user)
                ->deleteJson("/api/faculties/{$f['faculty']->id}")
                ->assertForbidden();
        }

        $this->actingAs($f['vpaa'])->postJson('/api/faculties', $this->payload($f))->assertCreated();
    }

    public function test_secretary_may_update_the_load_allowances_only(): void
    {
        $f = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $f['department']->id]);

        $this->actingAs($secretary)
            ->patchJson("/api/faculties/{$f['faculty']->id}", [
                'max_units' => 24,
                'deload_units' => 3,
                'overload_units' => 6,
                'probono_units' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('max_units', 24)
            ->assertJsonPath('required_units', 21)
            ->assertJsonPath('unit_ceiling', 29);

        $this->actingAs($secretary)
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['last_name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Instructor', $f['faculty']->refresh()->last_name);
    }

    public function test_program_head_can_no_longer_write(): void
    {
        $f = $this->fixture();
        $head = User::factory()->create(['role' => 'program_head', 'department_id' => $f['department']->id]);

        $this->actingAs($head)
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['max_units' => 30])
            ->assertForbidden();
    }

    public function test_availability_windows_can_be_read_and_replaced(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->getJson("/api/faculties/{$f['faculty']->id}/availabilities")
            ->assertOk()
            ->assertJsonCount(0, 'availabilities');

        $this->actingAs($f['vpaa'])
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", [
                'availabilities' => [
                    ['day_index' => 0, 'start_time' => '08:00', 'end_time' => '12:00'],
                    ['day_index' => 0, 'start_time' => '13:00', 'end_time' => '16:00'],
                    ['day_index' => 5, 'start_time' => '08:00', 'end_time' => '17:00'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'availabilities')
            ->assertJsonPath('availabilities.0.day_label', 'Monday');

        // A replace is a whole-week replace, not an append.
        $this->actingAs($f['vpaa'])
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", [
                'availabilities' => [['day_index' => 2, 'start_time' => '09:00', 'end_time' => '11:00']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'availabilities')
            ->assertJsonPath('availabilities.0.day_label', 'Wednesday');

        $this->assertSame(1, $f['faculty']->availabilities()->count());
    }

    public function test_availability_rejects_overlaps_and_backwards_windows(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", [
                'availabilities' => [
                    ['day_index' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
                    ['day_index' => 1, 'start_time' => '11:00', 'end_time' => '14:00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('availabilities.1.start_time');

        $this->actingAs($f['vpaa'])
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", [
                'availabilities' => [['day_index' => 1, 'start_time' => '14:00', 'end_time' => '10:00']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('availabilities.0.end_time');
    }

    public function test_availability_is_writable_by_secretary_but_not_program_head(): void
    {
        $f = $this->fixture();
        $window = ['availabilities' => [['day_index' => 3, 'start_time' => '08:00', 'end_time' => '10:00']]];

        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $f['department']->id]);
        $this->actingAs($secretary)
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", $window)
            ->assertOk();

        $head = User::factory()->create(['role' => 'program_head', 'department_id' => $f['department']->id]);
        $this->actingAs($head)
            ->putJson("/api/faculties/{$f['faculty']->id}/availabilities", $window)
            ->assertForbidden();
    }

    public function test_part_time_availability_now_drives_the_rule_engine(): void
    {
        $f = $this->fixture();
        $f['faculty']->update(['employment_type' => 'part-time']);

        $f['faculty']->availabilities()->create([
            'day_index' => 0,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
        ]);

        $violations = app(\App\Services\Scheduling\RuleEngine::class)->validate([
            'term_id' => $f['term']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'faculty_id' => $f['faculty']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
        ]);

        $this->assertContains(
            'part_time_faculty_availability',
            array_column($violations, 'rule'),
            'A morning class must violate an afternoon-only availability window.'
        );
    }

    /**
     * The rule requires the attempt to fit inside a window for that day, so an
     * empty window set blocks a part-timer everywhere. That makes the editor the
     * only way such an instructor becomes assignable at all.
     */
    public function test_a_part_timer_without_windows_cannot_be_assigned(): void
    {
        $f = $this->fixture();
        $f['faculty']->update(['employment_type' => 'part-time']);

        $violations = app(\App\Services\Scheduling\RuleEngine::class)->validate([
            'term_id' => $f['term']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'faculty_id' => $f['faculty']->id,
            'day' => 'Monday',
            'start_time' => '13:00',
            'end_time' => '14:30',
            'mode' => 'on-site',
        ]);

        $this->assertContains('part_time_faculty_availability', array_column($violations, 'rule'));
    }

    public function test_a_class_inside_a_window_clears_the_availability_rule(): void
    {
        $f = $this->fixture();
        $f['faculty']->update(['employment_type' => 'part-time']);
        $f['faculty']->availabilities()->create([
            'day_index' => 0,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
        ]);

        $violations = app(\App\Services\Scheduling\RuleEngine::class)->validate([
            'term_id' => $f['term']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'faculty_id' => $f['faculty']->id,
            'day' => 'Monday',
            'start_time' => '13:00',
            'end_time' => '14:30',
            'mode' => 'on-site',
        ]);

        $this->assertNotContains('part_time_faculty_availability', array_column($violations, 'rule'));
    }

    public function test_program_must_belong_to_the_instructors_department(): void
    {
        $f = $this->fixture();
        $otherDept = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $foreign = Program::create([
            'code' => 'BSOTH',
            'name' => 'Other Program',
            'department_id' => $otherDept->id,
        ]);

        $this->actingAs($f['vpaa'])
            ->postJson('/api/faculties', $this->payload($f) + ['program_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('program_id');
    }

    public function test_program_head_faculty_index_is_scoped_to_the_assigned_program(): void
    {
        $department = Departments::create(['department_name' => 'Education', 'department_code' => 'CED']);
        $bped = Program::create(['department_id' => $department->id, 'code' => 'BPED', 'name' => 'Physical Education']);
        $beed = Program::create(['department_id' => $department->id, 'code' => 'BEED', 'name' => 'Elementary Education']);
        $head = User::factory()->create([
            'role' => 'program_head',
            'department_id' => $department->id,
            'program_id' => $bped->id,
        ]);
        $bpedFaculty = Faculty::create([
            'first_name' => 'BPED', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 21, 'department_id' => $department->id, 'program_id' => $bped->id, 'status' => 'active',
        ]);
        Faculty::create([
            'first_name' => 'BEED', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 21, 'department_id' => $department->id, 'program_id' => $beed->id, 'status' => 'active',
        ]);

        $this->actingAs($head)->getJson('/api/faculties')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $bpedFaculty->id)
            ->assertJsonPath('0.program_id', $bped->id);
    }

    /** @return array<string, mixed> */
    private function payload(array $f): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $f['department']->id,
            'status' => 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Mgmt Dept', 'department_code' => 'MGT']);
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
                'room_code' => 'MGT101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'course' => Course::create([
                'course_code' => 'MGT101',
                'course_name' => 'Mgmt Course',
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
                'section_name' => 'MGT-1A',
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
                'deload_units' => 0,
                'overload_units' => 0,
                'probono_units' => 0,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'vpaa' => User::factory()->create(['role' => 'vpaa', 'department_id' => $department->id]),
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
