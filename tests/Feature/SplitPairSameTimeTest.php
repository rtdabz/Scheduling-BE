<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both meetings of a Hybrid Split (and a Split Session) share one time slot:
 * relocating one meeting carries its partner, and a save that splits the time
 * is refused (split_group_same_time).
 */
class SplitPairSameTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_one_hybrid_split_meeting_moves_its_partner_to_the_same_time(): void
    {
        [$user, $onSite, $online] = $this->hybridSplitPair();

        $response = $this->actingAs($user)->putJson("/api/schedules/{$onSite->id}", [
            'day' => 'Monday',
            'start_time' => '10:00',
            'end_time' => '11:30',
        ]);

        $response->assertOk();
        // Returned so the timetable shows the partner's new time at once.
        $response->assertJsonPath('moved_partners.0.id', $online->id);
        $response->assertJsonPath('moved_partners.0.day', 'Wednesday');
        $online->refresh();
        $this->assertSame('Wednesday', $online->day);
        $this->assertSame('10:00', substr((string) $online->start_time, 0, 5));
        $this->assertSame('11:30', substr((string) $online->end_time, 0, 5));
    }

    public function test_a_partner_that_cannot_take_the_new_time_blocks_the_move(): void
    {
        [$user, $onSite, $online, $context] = $this->hybridSplitPair();
        $blocker = Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'SPT102', 'course_name' => 'Blocker']);
        Schedule::create([
            ...$context['row'],
            'course_id' => $blocker->id,
            'day' => 'Wednesday',
            'start_time' => '10:00',
            'end_time' => '11:30',
            'room_id' => null,
            'mode' => 'online',
        ]);

        $response = $this->actingAs($user)->putJson("/api/schedules/{$onSite->id}", [
            'start_time' => '10:00',
            'end_time' => '11:30',
        ]);

        $response->assertStatus(422);
        $this->assertSame('08:00', substr((string) $onSite->refresh()->start_time, 0, 5));
        $this->assertSame('08:00', substr((string) $online->refresh()->start_time, 0, 5));
    }

    public function test_a_batch_save_with_split_times_is_refused(): void
    {
        [$user, $onSite, $online] = $this->hybridSplitPair();

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [
                ['id' => $onSite->id, 'start_time' => '10:00', 'end_time' => '11:30'],
                ['id' => $online->id],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertContains('split_group_same_time', array_column($response->json('violations') ?? [], 'rule'));
    }

    /** @return array{0: User, 1: Schedule, 2: Schedule, 3: array{department: Departments, row: array<string, mixed>}} */
    private function hybridSplitPair(): array
    {
        $department = Departments::create(['department_name' => 'Split Pair Dept', 'department_code' => 'SPT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'SPTP', 'name' => 'Split Pair Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create([
            'room_code' => 'SPT101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $section = Sections::create([
            'section_name' => 'SPT-1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([...$this->courseAttributes($department), 'course_code' => 'SPT101', 'course_name' => 'Split Pair Course']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $row = [
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $department->id,
            'status' => 'draft',
        ];
        $pair = [
            ...$row,
            'start_time' => '08:00',
            'end_time' => '09:30',
            'is_hybrid' => true,
            'split_group_id' => 'hybrid-split-pair',
            'meeting_type' => 'lecture',
        ];
        $onSite = Schedule::create([...$pair, 'day' => 'Monday', 'room_id' => $room->id, 'mode' => 'on-site', 'meeting_index' => 1]);
        $online = Schedule::create([...$pair, 'day' => 'Wednesday', 'room_id' => null, 'mode' => 'online', 'meeting_index' => 2]);

        return [$user, $onSite, $online, ['department' => $department, 'row' => $row]];
    }

    /** @return array<string, mixed> 3 units = two 90-minute Hybrid Split meetings */
    private function courseAttributes(Departments $department): array
    {
        return [
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ];
    }
}
