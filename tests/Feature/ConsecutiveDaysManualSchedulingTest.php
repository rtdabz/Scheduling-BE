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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Manual Scheduling of a Consecutive Days class: the run is saved as linked
 * meetings and offered as whole runs. Saved meetings relocate independently.
 */
class ConsecutiveDaysManualSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_is_saved_as_one_linked_group(): void
    {
        $context = $this->scaffold();

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Thursday', 'Friday', 'Saturday']),
        ]);

        $response->assertOk();
        $rows = Schedule::query()->where('course_id', $context['course']->id)->get();
        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->pluck('split_group_id')->unique());
        $this->assertEqualsCanonicalizing(['Thursday', 'Friday', 'Saturday'], $rows->pluck('day')->all());
        $this->assertSame(['consecutive:3'], $rows->pluck('preferred_pattern')->unique()->values()->all());
    }

    public function test_saved_rows_record_the_signed_in_user_as_creator(): void
    {
        $context = $this->scaffold();
        $impostor = User::factory()->create();
        $operations = array_map(
            static fn (array $op): array => [...$op, 'created_by' => $impostor->id],
            $this->runOperations($context, ['Thursday', 'Friday', 'Saturday']),
        );

        $this->actingAs($context['user'])->postJson('/api/schedules/batch', ['operations' => $operations])->assertOk();

        $this->assertSame(
            [(int) $context['user']->id],
            Schedule::query()->where('course_id', $context['course']->id)->pluck('created_by')->map('intval')->unique()->values()->all(),
        );
    }

    public function test_a_run_on_days_apart_is_saved(): void
    {
        $context = $this->scaffold();

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Monday', 'Wednesday', 'Friday']),
        ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Monday', 'Wednesday', 'Friday'],
            Schedule::query()->where('course_id', $context['course']->id)->pluck('day')->all(),
        );
    }

    public function test_available_slots_offer_only_the_ticked_days(): void
    {
        $context = $this->scaffold();
        \Illuminate\Support\Facades\DB::table('department_course_rules')->insert([
            'department_id' => $context['department']->id,
            'course_id' => $context['course']->id,
            'section_id' => null,
            'consecutive_day_count' => 3,
            'preferred_start_day' => 'Monday',
            'meeting_days' => 'Monday,Wednesday,Friday',
        ]);

        $response = $this->actingAs($context['user'])->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'duration_slots' => 2,
            'modes' => ['on-site'],
            'consecutive_days' => 3,
        ]);

        $response->assertOk();
        $runs = array_unique(array_map(static fn (array $slot): string => implode(',', $slot['run_days']), $response->json('slots')));
        $this->assertSame(['Monday,Wednesday,Friday'], array_values($runs));
    }

    public function test_every_day_of_a_run_repeats_the_total_hours(): void
    {
        $context = $this->scaffold();
        // Three laboratory hours on each of three days: nine hours for the linked run.
        $this->savedRun($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '10:00');
        Schedule::query()->where('day', 'Saturday')->delete();
        $saturday = [...$this->row($context), 'day' => 'Saturday', 'start_time' => '07:00', 'end_time' => '10:00', 'preferred_pattern' => 'consecutive:3', 'split_group_id' => 'clinical-run'];
        $durationRules = static fn (array $violations): array => array_values(array_filter(
            array_column($violations, 'rule'),
            static fn (string $rule): bool => $rule === 'class_duration',
        ));
        $engine = app(\App\Services\Scheduling\Engine\RuleEngine::class);

        $this->assertSame([], $durationRules($engine->validate($saturday)), 'the third full-length day completes the run');
        $this->assertSame(['class_duration'], $durationRules($engine->validate([...$saturday, 'end_time' => '11:00'])), 'a longer day would exceed the run');

        // The allowance follows the linked rows, not the marker: a lone marked row holds one course length.
        Schedule::query()->delete();
        $lone = [...$saturday, 'split_group_id' => null];
        $this->assertSame([], $durationRules($engine->validate($lone)));
        $this->assertSame(['class_duration'], $durationRules($engine->validate([...$lone, 'end_time' => '13:00'])));
    }

    public function test_prac_101_is_plotted_saved_and_reloaded_as_six_hours_on_each_of_three_days(): void
    {
        $context = $this->practicum($this->scaffold());

        $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '13:00'),
        ])->assertOk();

        $rows = Schedule::query()->where('course_id', $context['course']->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('07:00', substr((string) $row->start_time, 0, 5));
            $this->assertSame('13:00', substr((string) $row->end_time, 0, 5));
        }
        $this->assertSame(1080, $rows->sum(fn (Schedule $row): int => $this->minutes($row)), '6 hours/day x 3 days = 18 hours');
        $this->assertSame(6, (int) $context['course']->fresh()->units, 'academic units are not multiplied');
    }

    public function test_a_prac_101_run_longer_than_six_hours_a_day_is_refused_as_a_whole_batch(): void
    {
        $context = $this->practicum($this->scaffold());

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '14:00'),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('class_duration', $response->getContent());
        $this->assertSame(0, Schedule::query()->where('course_id', $context['course']->id)->count());
    }

    public function test_a_batch_judges_the_whole_run_against_the_repeated_allowance(): void
    {
        $context = $this->scaffold();
        // Each 3-hour day fits on its own; the run's two extra rows push it past 3 x 3 hours.
        $operations = [
            ...$this->runOperations($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '10:00'),
            [...$this->row($context), 'day' => 'Monday', 'start_time' => '07:00', 'end_time' => '08:00'],
        ];

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', ['operations' => $operations]);

        $response->assertStatus(422);
        $this->assertStringContainsString('class_duration', $response->getContent());
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_an_extra_meeting_beside_a_complete_prac_101_run_is_refused(): void
    {
        $context = $this->practicum($this->scaffold());
        $this->savedRun($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '13:00');

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => [[...$this->row($context), 'day' => 'Monday', 'start_time' => '07:00', 'end_time' => '08:00']],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('class_duration', $response->getContent());
    }

    public function test_editing_a_prac_101_run_keeps_six_hours_on_every_day(): void
    {
        $context = $this->practicum($this->scaffold());
        [$thursday, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '13:00');

        $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Thursday',
            'start_time' => '09:00',
            'end_time' => '15:00',
        ])->assertOk();

        foreach ([$thursday, $friday, $saturday] as $index => $row) {
            $row->refresh();
            $this->assertSame($index === 0 ? '09:00' : '07:00', substr((string) $row->start_time, 0, 5));
            $this->assertSame(360, $this->minutes($row));
        }
    }

    public function test_a_room_clash_during_the_added_hours_of_one_day_is_still_caught(): void
    {
        $context = $this->practicum($this->scaffold());
        $other = Sections::create([
            'section_name' => 'NUR 2A',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);
        // Friday 11:00-12:00 sits inside a six-hour day but outside the old two-hour share.
        Schedule::create([
            ...$this->row($context),
            'section_id' => $other->id,
            'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
            'day' => 'Friday',
            'start_time' => '11:00',
            'end_time' => '12:00',
            'status' => 'finalized',
        ]);

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '13:00'),
        ]);

        $this->assertNotSame(200, $response->status());
        $this->assertSame(0, Schedule::query()->where('course_id', $context['course']->id)->count());
    }

    public function test_moving_one_day_preserves_every_other_meeting(): void
    {
        $context = $this->scaffold();
        [$thursday, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);
        $siblingsBefore = [$friday->getAttributes(), $saturday->getAttributes()];

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Wednesday',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('moved_partners', $response->json());
        $this->assertSame('Wednesday', $thursday->refresh()->day);
        $this->assertSame('09:00', substr((string) $thursday->start_time, 0, 5));
        $this->assertSame($siblingsBefore, [$friday->refresh()->getAttributes(), $saturday->refresh()->getAttributes()]);

        // The link still finds the same siblings after their times stop matching.
        $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Tuesday', 'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertOk();
        $this->assertSame($siblingsBefore, [$friday->refresh()->getAttributes(), $saturday->refresh()->getAttributes()]);
    }

    public function test_a_sibling_at_the_end_of_the_week_does_not_prevent_an_individual_move(): void
    {
        $context = $this->scaffold(sundayClasses: true);
        [$monday, $tuesday, $sunday] = $this->savedRun($context, ['Monday', 'Tuesday', 'Sunday']);
        $siblingsBefore = [$tuesday->getAttributes(), $sunday->getAttributes()];

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$monday->id}", [
            'day' => 'Friday',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $response->assertOk();
        $this->assertSame('Friday', $monday->refresh()->day);
        $this->assertSame($siblingsBefore, [$tuesday->refresh()->getAttributes(), $sunday->refresh()->getAttributes()]);
    }

    public function test_a_run_with_ticked_days_keeps_them_when_moved(): void
    {
        $context = $this->scaffold();
        \Illuminate\Support\Facades\DB::table('department_course_rules')->insert([
            'department_id' => $context['department']->id,
            'course_id' => $context['course']->id,
            'section_id' => null,
            'consecutive_day_count' => 3,
            'preferred_start_day' => 'Monday',
            'meeting_days' => 'Monday,Wednesday,Friday',
        ]);
        [$monday, , $friday] = $this->savedRun($context, ['Monday', 'Wednesday', 'Friday']);

        $this->actingAs($context['user'])->putJson("/api/schedules/{$monday->id}", [
            'day' => 'Tuesday',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ])->assertStatus(422);
        $this->assertSame('Monday', $monday->refresh()->day);
        $this->assertSame('Friday', $friday->refresh()->day);

        // A new time on the same days is fine.
        $this->actingAs($context['user'])->putJson("/api/schedules/{$monday->id}", [
            'day' => 'Monday',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertOk();
        $this->assertSame('07:00', substr((string) $friday->refresh()->start_time, 0, 5));
    }

    public function test_a_locked_siblings_hypothetical_conflict_does_not_block_the_selected_meeting(): void
    {
        $context = $this->scaffold();
        [$thursday, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);
        $friday->update(['status' => 'approved']);
        $before = [$friday->getAttributes(), $saturday->getAttributes()];
        Schedule::create([
            ...$this->row($context),
            'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
            'day' => 'Friday', 'start_time' => '09:00', 'end_time' => '10:00',
        ]);

        $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Thursday', 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertOk();

        $this->assertSame($before, [$friday->refresh()->getAttributes(), $saturday->refresh()->getAttributes()]);
    }

    public function test_a_stationary_sibling_still_causes_a_real_destination_conflict(): void
    {
        $context = $this->scaffold();
        [$thursday, $friday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);
        $before = Schedule::query()->get()->map->getAttributes()->all();

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Friday', 'start_time' => '07:00', 'end_time' => '08:00',
        ])->assertStatus(422);

        $this->assertContains('section_conflict', array_column($response->json('violations'), 'rule'));
        $this->assertContains($friday->id, array_column($response->json('violations'), 'conflicting_schedule_id'));
        $this->assertSame($before, Schedule::query()->get()->map->getAttributes()->all());

        // Even without an overlap, the existing different-day rule still applies.
        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Friday', 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertStatus(422);
        $this->assertContains('split_group_day_separation', array_column($response->json('violations'), 'rule'));
        $this->assertSame($before, Schedule::query()->get()->map->getAttributes()->all());
    }

    #[DataProvider('destinationConflicts')]
    public function test_an_individual_move_keeps_destination_validation(string $conflict): void
    {
        $context = $this->scaffold();
        [$selected] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '10:00');
        $faculty = Faculty::create([
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time',
            'department_id' => $context['department']->id, 'status' => 'active',
        ]);
        $selected->update(['faculty_id' => $faculty->id]);
        $changes = ['day' => 'Thursday', 'start_time' => '09:00', 'end_time' => '12:00'];

        if (in_array($conflict, ['room_conflict', 'faculty_conflict', 'section_conflict'], true)) {
            $otherSection = $context['section']->replicate();
            $otherSection->section_name = 'NUR 2A';
            $otherSection->save();
            Schedule::create([
                ...$this->row($context),
                'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
                'section_id' => $conflict === 'section_conflict' ? $context['section']->id : $otherSection->id,
                'room_id' => $conflict === 'room_conflict' ? $context['room']->id : null,
                'faculty_id' => $conflict === 'faculty_conflict' ? $faculty->id : null,
                'day' => 'Thursday', 'start_time' => '10:00', 'end_time' => '11:00',
            ]);
        } elseif ($conflict === 'room_availability') {
            $context['room']->update(['status' => 'not available']);
        } elseif ($conflict === 'part_time_faculty_availability') {
            $faculty->update(['employment_type' => 'part-time']);
        } elseif ($conflict === 'operating_hours') {
            $changes = ['day' => 'Thursday', 'start_time' => '20:00', 'end_time' => '23:00'];
        } elseif ($conflict === 'class_duration') {
            $changes['end_time'] = '13:00';
        }
        $before = Schedule::query()->get()->map->getAttributes()->all();

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$selected->id}", $changes)->assertStatus(422);

        $this->assertContains($conflict, array_column($response->json('violations') ?? [], 'rule'));
        $this->assertSame($before, Schedule::query()->get()->map->getAttributes()->all());
    }

    public static function destinationConflicts(): array
    {
        return array_map(static fn (string $rule): array => [$rule], [
            'room_conflict', 'faculty_conflict', 'section_conflict', 'room_availability',
            'part_time_faculty_availability', 'operating_hours', 'class_duration',
        ]);
    }

    public function test_changing_one_days_modality_leaves_every_other_meeting_untouched(): void
    {
        $context = $this->lectureRun($this->scaffold());
        [$thursday, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);
        $siblingsBefore = [$friday->getAttributes(), $saturday->getAttributes()];

        // Modality only, then modality with the day's own (unchanged) time, as the builder sends it.
        $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'mode' => 'online', 'room_id' => null,
        ])->assertOk();
        $this->assertSame('online', $thursday->refresh()->mode);
        $this->assertSame($siblingsBefore, [$friday->refresh()->getAttributes(), $saturday->refresh()->getAttributes()]);

        $this->actingAs($context['user'])->putJson("/api/schedules/{$friday->id}", [
            'day' => 'Friday', 'start_time' => '09:00', 'end_time' => '10:00', 'mode' => 'online', 'room_id' => null,
        ])->assertOk();
        $this->assertSame(['online', '09:00'], [$friday->refresh()->mode, substr((string) $friday->start_time, 0, 5)]);
        $this->assertSame('online', $thursday->refresh()->mode);
        $this->assertSame($siblingsBefore[1], $saturday->refresh()->getAttributes());
        $this->assertSame(['on-site', (int) $context['room']->id], [$saturday->mode, (int) $saturday->room_id]);
    }

    public function test_creating_a_run_still_requires_one_mode(): void
    {
        $context = $this->lectureRun($this->scaffold());
        $operations = $this->runOperations($context, ['Thursday', 'Friday', 'Saturday']);
        $operations[1]['mode'] = 'online';
        $operations[1]['room_id'] = null;

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', ['operations' => $operations])->assertStatus(422);

        $this->assertStringContainsString('consecutive_mode', $response->getContent());
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_creating_a_run_still_requires_matching_times(): void
    {
        $context = $this->scaffold();
        $operations = $this->runOperations($context, ['Thursday', 'Friday', 'Saturday']);
        $operations[1]['start_time'] = '09:00';
        $operations[1]['end_time'] = '10:00';

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', ['operations' => $operations])->assertStatus(422);

        $this->assertStringContainsString('split_group_same_time', $response->getContent());
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_conflict_resolution_moves_only_the_selected_consecutive_meeting(): void
    {
        $context = $this->scaffold();
        [$selected, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);
        $blocker = Schedule::create([
            ...$this->row($context),
            'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
            'day' => 'Thursday', 'start_time' => '07:00', 'end_time' => '08:00',
        ])->refresh();
        $before = [$friday->getAttributes(), $saturday->getAttributes(), $blocker->getAttributes()];
        $conflictId = "section_conflict:{$selected->id}:{$blocker->id}";

        $this->actingAs($context['user'])->postJson("/api/conflicts/{$conflictId}/resolve", [
            'action' => 'move_schedule', 'schedule_id' => $selected->id,
            'day' => 'Thursday', 'start_time' => '09:00', 'end_time' => '10:00',
            'reason' => 'Move only the conflicting meeting.',
        ])->assertOk()->assertJsonPath('status', 'resolved');

        $this->assertSame('09:00', substr((string) $selected->refresh()->start_time, 0, 5));
        $this->assertSame($before, [$friday->refresh()->getAttributes(), $saturday->refresh()->getAttributes(), $blocker->refresh()->getAttributes()]);
    }

    public function test_available_slots_offer_only_whole_runs(): void
    {
        $context = $this->scaffold();
        // Another section holds the laboratory all Friday.
        Schedule::create([
            ...$this->row($context),
            'section_id' => Sections::create([
                'section_name' => 'NUR 2A',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $context['department']->id,
                'program_id' => $context['section']->program_id,
                'semester_id' => $context['semester']->id,
                'status' => 'active',
            ])->id,
            'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
            'day' => 'Friday',
            'start_time' => '07:00',
            'end_time' => '21:00',
        ]);

        $response = $this->actingAs($context['user'])->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'duration_slots' => 2,
            'modes' => ['on-site'],
            'consecutive_days' => 3,
        ]);

        $response->assertOk();
        $slots = $response->json('slots');
        $this->assertNotEmpty($slots);
        $runs = array_unique(array_map(static fn (array $slot): string => implode(',', $slot['run_days']), $slots));
        sort($runs);
        // Every run through Friday is gone; Monday-Wednesday and Tuesday-Thursday remain.
        $this->assertSame(['Monday,Tuesday,Wednesday', 'Tuesday,Wednesday,Thursday'], $runs);
        foreach ($slots as $slot) {
            $this->assertSame($slot['run_days'][0], $slot['day'], 'a run is listed on its first day');
        }
    }

    /** @return array{user: User, department: Departments, semester: Semester, section: Sections, course: Course, room: Rooms} */
    private function scaffold(bool $sundayClasses = false): array
    {
        $department = Departments::create([
            'department_name' => 'College of Nursing',
            'department_code' => 'NUR',
            'sunday_classes_enabled' => $sundayClasses,
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSN', 'name' => 'Nursing']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create([
            'room_code' => 'SKILLS-1',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $section = Sections::create([
            'section_name' => 'NUR 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return [
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
            'department' => $department,
            'semester' => $semester,
            'section' => $section,
            'course' => Course::create([...$this->courseAttributes($department), 'course_code' => 'CLIN 101']),
            'room' => $room,
        ];
    }

    /** PRAC 101: a six-hour course, so a three-day run holds 18 hours. */
    private function practicum(array $context): array
    {
        return [...$context, 'course' => Course::create([
            ...$this->courseAttributes($context['department']),
            'course_code' => 'PRAC 101',
            'course_name' => 'Practicum',
            'lab_hours' => 6,
            'units' => 6,
        ])];
    }

    /** A lecture course in a lecture room, so any day of its run may also meet online. */
    private function lectureRun(array $context): array
    {
        return [
            ...$context,
            'course' => Course::create([
                ...$this->courseAttributes($context['department']),
                'course_code' => 'NCM 101',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'room_type_required' => 'lecture',
                'course_category' => 'minor',
            ]),
            'room' => Rooms::create([
                'room_code' => 'LEC-1',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $context['department']->id,
            ]),
        ];
    }

    private function minutes(Schedule $row): int
    {
        return \App\Services\Scheduling\Support\SchedulingPolicy::timeToMinutes((string) $row->end_time)
            - \App\Services\Scheduling\Support\SchedulingPolicy::timeToMinutes((string) $row->start_time);
    }

    /** @return array<string, mixed> 3 units: three hours on each day of a run */
    private function courseAttributes(Departments $department): array
    {
        return [
            'course_name' => 'Clinical Duty',
            'lecture_hours' => 0,
            'lab_hours' => 3,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function row(array $context): array
    {
        return [
            'semester_id' => $context['semester']->id,
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'department_id' => $context['department']->id,
            'room_id' => $context['room']->id,
            'mode' => 'on-site',
            'status' => 'draft',
        ];
    }

    /**
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private function runOperations(array $context, array $days, string $start = '07:00', string $end = '08:00'): array
    {
        return array_map(fn (string $day, int $index): array => [
            ...$this->row($context),
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'preferred_pattern' => 'consecutive:'.count($days),
            'split_group_id' => 'clinical-run',
            'meeting_index' => $index + 1,
        ], $days, array_keys($days));
    }

    /**
     * @param  list<string>  $days
     * @return list<Schedule>
     */
    private function savedRun(array $context, array $days, string $start = '07:00', string $end = '08:00'): array
    {
        return array_map(fn (array $operation): Schedule => Schedule::create($operation)->refresh(), $this->runOperations($context, $days, $start, $end));
    }
}
