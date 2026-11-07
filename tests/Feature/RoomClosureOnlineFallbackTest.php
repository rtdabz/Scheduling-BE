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

/** A closed room's face-to-face classes meet online, flagged for reassignment, until the VPAA approves a replacement room. */
class RoomClosureOnlineFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Fallback', 'department_code' => 'FBK']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'FBK', 'name' => 'Fallback']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $section = Sections::create(['section_name' => 'FBK-1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
        $course = Course::create(['course_code' => 'FBK101', 'course_name' => 'Fallback', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'F1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $replacement = Rooms::create(['room_code' => 'F2', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $base = ['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id, 'department_id' => $department->id,
            'start_time' => '08:00', 'end_time' => '09:00', 'status' => 'draft'];
        $row = Schedule::create([...$base, 'room_id' => $room->id, 'day' => 'Monday', 'mode' => 'on-site']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa']));
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);

        return compact('department', 'semester', 'section', 'course', 'room', 'replacement', 'base', 'row', 'user', 'vpaa', 'dean');
    }

    private function close(array $f): void
    {
        $this->actingAs($f['vpaa'])->postJson('/api/rooms/'.$f['room']->id.'/close', ['reason' => 'Ceiling repair'])->assertOk();
    }

    private function listing(array $f)
    {
        return collect($this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1')->assertOk()->json('schedules'))->keyBy('id');
    }

    public function test_closing_a_room_moves_every_affected_class_online_and_flags_it(): void
    {
        $f = $this->fixture();
        $second = Schedule::create([...$f['base'], 'room_id' => $f['room']->id, 'day' => 'Tuesday', 'mode' => 'on-site']);
        $elsewhere = Schedule::create([...$f['base'], 'room_id' => $f['replacement']->id, 'day' => 'Wednesday', 'mode' => 'on-site']);
        $plannedOnline = Schedule::create([...$f['base'], 'room_id' => null, 'day' => 'Thursday', 'mode' => 'online']);
        $untouched = [$elsewhere->fresh()->getAttributes(), $plannedOnline->fresh()->getAttributes()];

        $this->close($f);

        foreach ([$f['row'], $second] as $row) {
            $row = $row->fresh();
            $this->assertSame('online', $row->mode);
            $this->assertNull($row->room_id);
            $this->assertEquals($f['room']->id, $row->displaced_room_id);
            $this->assertTrue($row->isTemporarilyOnline());
            // Day, time and status are preserved.
            $this->assertSame('08:00', substr($row->start_time, 0, 5));
            $this->assertSame('draft', $row->status);
        }
        $this->assertEquals($untouched, [$elsewhere->fresh()->getAttributes(), $plannedOnline->fresh()->getAttributes()]);
        $this->assertFalse($plannedOnline->fresh()->isTemporarilyOnline());

        // The recovery case keeps the original face-to-face rows.
        $case = \App\Models\RoomRecoveryCase::sole();
        $this->assertEqualsCanonicalizing([$f['row']->id, $second->id], array_column($case->original_rows, 'id'));
        $this->assertSame(['on-site'], array_values(array_unique(array_column($case->original_rows, 'mode'))));
        $this->assertEquals([$f['room']->id], array_values(array_unique(array_column($case->original_rows, 'room_id'))));
        $this->assertDatabaseHas('schedule_history_versions', ['action' => 'room_closed_online']);
        $this->assertDatabaseCount('schedules', 4);
    }

    public function test_reassignment_lists_temporarily_online_classes_but_not_originally_online_ones(): void
    {
        $f = $this->fixture();
        $plannedOnline = Schedule::create([...$f['base'], 'room_id' => null, 'day' => 'Thursday', 'mode' => 'online']);
        $this->close($f);

        $listing = $this->listing($f);
        $this->assertTrue($listing[$f['row']->id]['needs_attention']);
        $this->assertTrue($listing[$f['row']->id]['temporarily_online']);
        $this->assertSame('online', $listing[$f['row']->id]['mode']);
        $this->assertSame('F1', $listing[$f['row']->id]['original_room']);
        $this->assertSame('Temporarily online: room unavailable', $listing[$f['row']->id]['attention_reason']);
        $this->assertFalse($listing->has($plannedOnline->id), 'Classes planned online do not need a room.');
        $this->getJson('/api/room-recovery')->assertJsonPath('pending_count', 1)->assertJsonPath('cases.0.schedule_ids', [$f['row']->id]);
    }

    public function test_temporarily_online_class_returns_face_to_face_only_after_vpaa_approval(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertOk()->json();
        $this->assertSame('on-site', $plan['groups'][0]['after'][0]['mode']);
        $this->assertEquals($f['replacement']->id, $plan['groups'][0]['after'][0]['room_id']);
        $this->assertSame('Online (temporary, F1 unavailable)', $plan['meetings'][0]['room']);

        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk()->assertJsonPath('status', 'pending_dean');
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
        $this->actingAs($f['dean'])->postJson($url.'/approve')->assertOk()->assertJsonPath('status', 'pending_vpaa');
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
        $this->assertSame('online', $f['row']->fresh()->mode);

        $this->actingAs($f['vpaa'])->postJson($url.'/approve')->assertOk()->assertJsonPath('applied', true);
        $row = $f['row']->fresh();
        $this->assertSame('on-site', $row->mode);
        $this->assertEquals($f['replacement']->id, $row->room_id);
        $this->assertNull($row->displaced_room_id);
        $this->assertNull($row->displaced_at);
        $this->assertDatabaseCount('schedules', 1);
        $this->getJson('/api/room-recovery')->assertJsonPath('pending_count', 0)->assertJsonPath('cases', []);
    }

    public function test_rejection_keeps_the_class_online_and_flagged(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertOk()->json();
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->actingAs($f['dean'])->postJson($url.'/reject', ['reason' => 'Use another building'])->assertOk();
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
        $this->assertTrue($this->listing($f)[$f['row']->id]['needs_attention']);
    }

    public function test_reopened_room_can_be_proposed_but_class_stays_online_until_approved(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $this->actingAs($f['vpaa'])->putJson('/api/rooms/'.$f['room']->id, ['status' => 'available'])->assertOk();
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
        $plan = $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', [
            'schedule_ids' => [$f['row']->id], 'destination_room_ids' => [$f['room']->id],
        ])->assertOk()->json();
        $this->assertEquals($f['room']->id, $plan['groups'][0]['after'][0]['room_id']);
    }

    public function test_suggestions_respect_bookings_and_room_type(): void
    {
        $f = $this->fixture();
        $other = $f['section']->replicate()->fill(['section_name' => 'FBK-2']);
        $other->save();
        Schedule::create([...$f['base'], 'section_id' => $other->id, 'room_id' => $f['replacement']->id, 'day' => 'Monday', 'mode' => 'on-site']);
        Rooms::create(['room_code' => 'LAB1', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $f['department']->id]);
        $this->close($f);
        $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertOk()
            ->assertJsonPath('groups', [])->assertJsonPath('unresolved.0.reason_code', 'no_suitable_room');
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
    }

    public function test_hybrid_partner_planned_online_stays_online_when_the_displaced_meeting_returns(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['status' => 'faculty_assignment', 'is_hybrid' => true, 'split_group_id' => 'fallback-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $online = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'mode' => 'online', 'room_id' => null, 'meeting_index' => 2]);
        $untouched = $online->fresh()->getAttributes();
        $this->close($f);
        $this->assertFalse($online->fresh()->isTemporarilyOnline());
        $this->assertFalse($this->listing($f)->has($online->id));

        $plan = $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertOk()->json();
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->actingAs($f['dean'])->postJson($url.'/approve')->assertOk();
        $this->actingAs($f['vpaa'])->postJson($url.'/approve')->assertOk()->assertJsonPath('applied', true);

        $this->assertSame('on-site', $f['row']->fresh()->mode);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertEquals($untouched, $online->fresh()->getAttributes());
    }

    public function test_locked_classes_stay_online_and_flagged_and_require_the_revision_workflow(): void
    {
        $f = $this->fixture();
        $f['row']->update(['status' => 'approved']);
        $this->close($f);
        $this->assertTrue($f['row']->fresh()->isTemporarilyOnline());
        $this->assertTrue($this->listing($f)[$f['row']->id]['revision_required']);
        $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertOk()
            ->assertJsonPath('groups', [])->assertJsonPath('unresolved.0.reason_code', 'revision_required');
    }

    public function test_placing_the_class_face_to_face_in_a_room_clears_the_flag(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $row = $f['row']->fresh();
        $row->update(['mode' => 'on-site', 'room_id' => $f['replacement']->id]);
        $this->assertFalse($row->fresh()->isTemporarilyOnline());
    }
}
