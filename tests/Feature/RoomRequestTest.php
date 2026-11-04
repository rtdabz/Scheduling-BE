<?php

namespace Tests\Feature;

use App\Jobs\DeliverSystemNotifications;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\SystemNotification;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Services\SystemNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A secretary borrowing another department's vacant room. The owning
 * department's secretary decides; the VPAA is only notified.
 *
 * The workflow tests cover who may ask and decide and when a room counts as
 * vacant. The parity tests matter most: once a grant is approved, the
 * validator and the generator must agree on exactly which placements it opens.
 */
class RoomRequestTest extends TestCase
{
    use RefreshDatabase;

    /** Every secretary inherits `room.request`; a dean of the same department does not. */
    public function test_an_account_without_the_capability_cannot_request_a_room(): void
    {
        $f = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $f['secretary']->department_id]);

        $this->actingAs($dean)
            ->postJson('/api/room-requests', $this->payload($f))
            ->assertForbidden();
    }

    public function test_secretary_requests_another_departments_room_and_its_secretary_is_asked(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->postJson('/api/room-requests', $this->payload($f))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.room.room_code', 'RM-1')
            ->assertJsonPath('data.requesting_department.code', 'CIT')
            ->assertJsonPath('data.owner_department.code', 'CAS')
            ->assertJsonPath('data.windows.0.start_time', '11:00');

        $this->assertTrue(SystemNotification::query()
            ->where('user_id', $f['ownerSecretary']->id)
            ->where('type', 'room_request_submitted')
            ->exists());
        $this->assertTrue(SystemNotification::query()
            ->where('user_id', $f['vpaa']->id)
            ->where('type', 'room_request_submitted')
            ->exists(), 'The VPAA was not told about the request.');
    }

    /** Requests are secretary to secretary: a program head can neither send nor decide one, nor is notified. */
    public function test_program_heads_take_no_part_in_room_requests(): void
    {
        $f = $this->fixture();
        $requesterHead = User::factory()->create(['role' => 'program_head', 'department_id' => $f['secretary']->department_id]);
        $ownerHead = User::factory()->create(['role' => 'program_head', 'department_id' => $f['owner']->id]);

        $this->actingAs($requesterHead)->postJson('/api/room-requests', $this->payload($f))->assertForbidden();
        $this->actingAs($requesterHead)->getJson('/api/room-requests')->assertForbidden();

        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->json('data.id');
        $this->actingAs($ownerHead)->postJson("/api/room-requests/{$id}/approve")->assertForbidden();
        $this->actingAs($requesterHead)->postJson("/api/room-requests/{$id}/cancel")->assertForbidden();

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/approve")->assertOk();
        $this->assertFalse(SystemNotification::query()
            ->whereIn('user_id', [$requesterHead->id, $ownerHead->id])
            ->where('type', 'like', 'room_request_%')
            ->exists());
    }

    public function test_own_and_shared_rooms_cannot_be_requested(): void
    {
        $f = $this->fixture();
        $own = $this->room('CIT-101', 'lecture', $f['requester']->id);
        $shared = $this->room('SHARED-1', 'lecture', null);

        foreach ([$own, $shared] as $room) {
            $this->actingAs($f['secretary'])
                ->postJson('/api/room-requests', [...$this->payload($f), 'room_id' => $room->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('room_id');
        }
    }

    /** Laboratories stay with their department; only lecture rooms are lent. */
    public function test_a_laboratory_cannot_be_requested(): void
    {
        $f = $this->fixture();
        $lab = $this->room('LAB-1', 'laboratory', $f['owner']->id);

        $this->actingAs($f['secretary'])
            ->postJson('/api/room-requests', [...$this->payload($f), 'room_id' => $lab->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    public function test_a_window_overlapping_an_existing_class_is_refused(): void
    {
        $f = $this->fixture();
        $this->bookRoom($f, $f['owner'], 'Monday', '12:00:00', '14:00:00');

        $this->actingAs($f['secretary'])
            ->postJson('/api/room-requests', $this->payload($f))
            ->assertStatus(422)
            ->assertJsonValidationErrors('windows');
    }

    public function test_only_one_of_two_competing_requests_can_be_approved(): void
    {
        $f = $this->fixture();
        $third = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);
        Program::create(['department_id' => $third->id, 'code' => 'BSCE', 'name' => 'Civil Engineering']);
        $otherSecretary = $this->secretaryFor($third);

        $first = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');
        $second = $this->actingAs($otherSecretary)->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$first}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$second}/approve")->assertStatus(422);

        $this->assertSame('pending', RoomRequest::find($second)->status);
    }

    /** The requester, the owning dean and the VPAA are all refused; only the owning secretary decides. */
    public function test_only_the_owning_departments_secretary_can_review(): void
    {
        $f = $this->fixture();
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->json('data.id');
        $ownerDean = User::factory()->create(['role' => 'dean', 'department_id' => $f['owner']->id]);

        foreach ([$f['secretary'], $ownerDean, $f['vpaa']] as $user) {
            $this->actingAs($user)->postJson("/api/room-requests/{$id}/approve")->assertForbidden();
        }

        $this->assertSame('pending', RoomRequest::find($id)->status);
    }

    public function test_approval_notifies_the_vpaa_that_the_room_is_borrowed(): void
    {
        $f = $this->fixture();
        $this->approvedGrant($f);

        $notice = SystemNotification::query()
            ->where('user_id', $f['vpaa']->id)
            ->where('type', 'room_request_borrowed')
            ->first();
        $this->assertNotNull($notice, 'The VPAA was not told about the borrowing.');
        $this->assertStringContainsString('CIT is borrowing RM-1 from CAS', $notice->message);
    }

    /** The VPAA is only told about borrowing; it cannot take part in it. */
    public function test_the_vpaa_takes_no_part_in_room_requests(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);

        $this->actingAs($f['vpaa'])->getJson('/api/room-requests')->assertForbidden();
        $this->actingAs($f['vpaa'])->postJson('/api/room-requests', $this->payload($f))->assertForbidden();
        $this->actingAs($f['vpaa'])->postJson("/api/room-requests/{$id}/revoke", ['remarks' => 'x'])->assertForbidden();
    }

    public function test_rejection_requires_a_reason_and_notifies_the_requester(): void
    {
        $f = $this->fixture();
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->json('data.id');

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/reject")->assertStatus(422);
        $this->actingAs($f['ownerSecretary'])
            ->postJson("/api/room-requests/{$id}/reject", ['remarks' => 'The lab is under maintenance.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertTrue(SystemNotification::query()
            ->where('user_id', $f['secretary']->id)
            ->where('type', 'room_request_rejected')
            ->exists());
    }

    /** The validator: nothing before approval, inside the window after it, never outside. */
    public function test_validator_honours_the_grant_window(): void
    {
        $f = $this->fixture();
        $attempt = fn (string $day, string $start, string $end): array => [
            'semester_id' => $f['semester']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['requester']->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
        ];

        $this->assertTrue($this->violatesRoomOwnership($attempt('Monday', '11:00:00', '13:00:00')));

        $this->approvedGrant($f);

        $this->assertFalse($this->violatesRoomOwnership($attempt('Monday', '11:00:00', '13:00:00')));
        $this->assertTrue($this->violatesRoomOwnership($attempt('Monday', '10:00:00', '12:00:00')));
        $this->assertTrue($this->violatesRoomOwnership($attempt('Tuesday', '11:00:00', '13:00:00')));
    }

    /** Once lent, the window belongs to the borrower: the owner may not book its own room inside it. */
    public function test_owner_cannot_book_its_room_inside_a_lent_window(): void
    {
        $f = $this->fixture();
        $section = Sections::create([
            'section_name' => 'CAS 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $f['owner']->id,
            'semester_id' => $f['semester']->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'CAS 101',
            'course_name' => 'Owner Lecture',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $f['owner']->id,
            'status' => 'active',
        ]);
        $attempt = fn (string $day, string $start, string $end): array => [
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['owner']->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
        ];
        $kernelRefuses = function (array $attempt): bool {
            $snapshot = app(SchedulingSnapshotRepository::class)->capture(
                semesterId: (int) $attempt['semester_id'],
                departmentId: (int) $attempt['department_id'],
                sectionIds: [(int) $attempt['section_id']],
                courseIds: [(int) $attempt['course_id']],
            );

            return collect((new SchedulingConstraintKernel)->evaluateRow(ScheduleRow::fromArray($attempt), $snapshot))
                ->contains(fn ($violation): bool => $violation->ruleId === 'room_department_alignment');
        };

        $this->assertFalse($this->violatesRoomOwnership($attempt('Monday', '12:00:00', '14:00:00')));

        $this->approvedGrant($f);

        foreach ([['Monday', '12:00:00', '14:00:00'], ['Monday', '10:00:00', '11:30:00']] as [$day, $start, $end]) {
            $this->assertTrue($this->violatesRoomOwnership($attempt($day, $start, $end)), "Validator let the owner into {$day} {$start}.");
            $this->assertTrue($kernelRefuses($attempt($day, $start, $end)), "Kernel let the owner into {$day} {$start}.");
        }
        $this->assertFalse($this->violatesRoomOwnership($attempt('Monday', '13:00:00', '15:00:00')));
        $this->assertFalse($this->violatesRoomOwnership($attempt('Tuesday', '11:00:00', '13:00:00')));
        $this->assertFalse($kernelRefuses($attempt('Monday', '13:00:00', '15:00:00')));
    }

    /** The generator: a granted room is offered, and only inside its window. */
    public function test_generator_places_a_lecture_in_the_granted_window(): void
    {
        $f = $this->fixture();
        $this->approvedGrant($f);

        $plan = $this->generate($f);

        $this->assertCount(1, $plan->rows);
        $row = $plan->rows[0];
        $this->assertSame((int) $f['room']->id, $row->roomId, 'The granted lecture room was not used.');
        $this->assertSame('Monday', $row->day);
        $this->assertSame('11:00:00', $row->startTime);
        $this->assertSame('13:00:00', $row->endTime);
    }

    public function test_generator_ignores_another_departments_room_without_a_grant(): void
    {
        $f = $this->fixture();

        $plan = $this->generate($f);

        foreach ($plan->rows as $row) {
            $this->assertNotSame((int) $f['room']->id, $row->roomId);
        }
    }

    /** The owner can take the room back at any time; the borrower's classes in it become Room TBA. */
    public function test_revoking_a_grant_moves_the_borrowers_classes_to_room_tba(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);
        $class = $this->bookRoom($f, $f['requester'], 'Monday', '11:00:00', '13:00:00', $f['section'], $f['course']);

        $this->actingAs($f['ownerSecretary'])
            ->postJson("/api/room-requests/{$id}/revoke", ['remarks' => 'CAS needs it back.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('released_schedule_count', 1);

        $this->assertNull($class->fresh()->room_id, 'The class kept a room its department can no longer use.');
        $this->assertDatabaseHas('room_recovery_cases', ['room_id' => $f['room']->id, 'trigger' => 'grant_ended']);
        $original = \App\Models\RoomRecoveryCase::firstOrFail()->original_rows;
        $this->assertEquals($class->id, $original[0]['id']);
        $this->assertEquals($f['room']->id, $original[0]['room_id'], 'Recovery preserves the original assignment before Room TBA.');
        $this->assertTrue(SystemNotification::query()
            ->where('user_id', $f['vpaa']->id)
            ->where('type', 'room_request_returned')
            ->exists());
        $this->assertStringContainsString('Room TBA', (string) SystemNotification::query()
            ->where('user_id', $f['secretary']->id)
            ->where('type', 'room_request_revoked')
            ->value('message'));
    }

    /** The borrower can give the room back at any time; only classes under this grant are released. */
    public function test_giving_back_a_room_releases_only_classes_under_that_grant(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);
        $inside = $this->bookRoom($f, $f['requester'], 'Monday', '11:00:00', '13:00:00', $f['section'], $f['course']);
        $owners = $this->bookRoom($f, $f['owner'], 'Tuesday', '07:00:00', '09:00:00');

        $this->actingAs($f['secretary'])
            ->postJson("/api/room-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('released_schedule_count', 1);

        $this->assertNull($inside->fresh()->room_id);
        $this->assertSame((int) $f['room']->id, (int) $owners->fresh()->room_id, "The owner's own class lost its room.");
    }

    public function test_requester_lists_its_own_while_the_owner_sees_both(): void
    {
        $f = $this->fixture();
        $third = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);
        Program::create(['department_id' => $third->id, 'code' => 'BSCE', 'name' => 'Civil Engineering']);

        $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated();
        $this->actingAs($this->secretaryFor($third))->postJson('/api/room-requests', $this->payload($f))->assertCreated();

        $this->actingAs($f['secretary'])->getJson('/api/room-requests')->assertOk()->assertJsonCount(1);
        $this->actingAs($f['ownerSecretary'])->getJson('/api/room-requests')->assertOk()->assertJsonCount(2);
    }

    /** The builder's room list carries the borrowed room, tagged with its windows. */
    public function test_initial_data_offers_the_granted_room_with_its_windows(): void
    {
        $f = $this->fixture();

        $roomIds = fn (): array => collect($this->actingAs($f['secretary'])->getJson('/api/initial-data?include=rooms')->assertOk()->json('rooms'))
            ->pluck('id')->all();
        $this->assertNotContains($f['room']->id, $roomIds());

        $this->approvedGrant($f);

        $room = collect($this->actingAs($f['secretary'])->getJson('/api/initial-data?include=rooms')->json('rooms'))
            ->firstWhere('id', $f['room']->id);
        $this->assertNotNull($room, 'The granted room is missing from initial-data.');
        $this->assertSame([['day' => 'Monday', 'start_time' => '11:00', 'end_time' => '13:00']], $room['grant_windows']);
    }

    public function test_occupancy_lists_classes_in_the_room(): void
    {
        $f = $this->fixture();
        $this->bookRoom($f, $f['owner'], 'Tuesday', '07:00:00', '09:00:00');

        $this->actingAs($f['secretary'])
            ->getJson("/api/room-requests/rooms/{$f['room']->id}/occupancy")
            ->assertOk()
            ->assertJsonPath('occupied.0.day', 'Tuesday')
            ->assertJsonPath('occupied.0.department_code', 'CAS')
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.room_id', $f['room']->id)
            ->assertJsonPath('schedules.0.department_id', $f['owner']->id);
    }

    // RR-01: approval must come from the room's current owner.

    /** A legacy request whose room was reassigned after submission cannot be approved by anyone. */
    public function test_a_stale_owner_cannot_approve_after_the_room_is_reassigned(): void
    {
        $f = $this->fixture();
        $third = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');
        // Simulates data saved before the reassignment guard existed.
        Rooms::whereKey($f['room']->id)->update(['department_id' => $third->id]);

        $this->actingAs($f['ownerSecretary'])->getJson('/api/room-requests')->assertOk()->assertJsonPath('0.owner_changed', true);
        $this->actingAs($f['ownerSecretary'])
            ->postJson("/api/room-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_request');
        $this->actingAs($this->secretaryFor($third))->postJson("/api/room-requests/{$id}/approve")->assertForbidden();
        $this->assertSame('pending', RoomRequest::findOrFail($id)->status);

        // The requester can withdraw the stale request and ask the current owner instead.
        $this->actingAs($f['secretary'])->postJson("/api/room-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($f['secretary'])
            ->postJson('/api/room-requests', $this->payload($f))
            ->assertCreated()
            ->assertJsonPath('data.owner_department.code', 'COE')
            ->assertJsonPath('data.owner_changed', false);
    }

    /** The reproduced sequence: a future-term request now blocks reassigning its room. */
    public function test_an_open_future_term_request_blocks_reassigning_the_room(): void
    {
        $f = $this->fixture();
        $future = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $third = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);
        $this->actingAs($f['secretary'])
            ->postJson('/api/room-requests', ['semester_id' => $future->id] + $this->payload($f))
            ->assertCreated()
            ->assertJsonPath('data.academic_semester.semester', '2nd');

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/rooms/{$f['room']->id}/assign", ['department_id' => $third->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');
        $this->assertSame((int) $f['owner']->id, (int) $f['room']->fresh()->department_id);
    }

    /** Closed requests, and open ones in disabled terms, are history and do not block reassignment. */
    public function test_historical_requests_do_not_block_reassigning_the_room(): void
    {
        $f = $this->fixture();
        $third = Departments::create(['department_name' => 'College of Engineering', 'department_code' => 'COE']);
        $old = Semester::create(['academic_year' => '2025-2026', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => false]);
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/reject", ['remarks' => 'Fully booked.'])->assertOk();
        RoomRequest::create([
            'room_id' => $f['room']->id, 'semester_id' => $old->id, 'requesting_department_id' => $f['requester']->id,
            'owner_department_id' => $f['owner']->id, 'status' => RoomRequest::STATUS_APPROVED, 'purpose' => 'Old term',
        ]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/rooms/{$f['room']->id}/assign", ['department_id' => $third->id])
            ->assertOk();
        $this->assertSame('CAS', RoomRequest::findOrFail($id)->ownerDepartment->department_code, 'Historical ownership was rewritten.');
    }

    // RR-02: grant-ending displacement is part of the timetable record.

    public function test_revoking_records_the_room_tba_change_in_history_and_audit(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);
        $class = $this->bookRoom($f, $f['requester'], 'Monday', '11:00:00', '13:00:00', $f['section'], $f['course']);
        $owners = $this->bookRoom($f, $f['owner'], 'Tuesday', '07:00:00', '09:00:00');

        $this->actingAs($f['ownerSecretary'])
            ->postJson("/api/room-requests/{$id}/revoke", ['remarks' => 'CAS needs it back.'])
            ->assertOk()
            ->assertJsonPath('released_schedule_ids', [$class->id]);

        $version = ScheduleHistoryVersion::where('action', 'room_grant_ended')->sole();
        $this->assertSame((int) $f['ownerSecretary']->id, (int) $version->actor_user_id);
        $this->assertSame('CAS needs it back.', $version->reason);
        $this->assertSame('owner_revoked', $version->change_summary['ended_by']);
        $this->assertSame((int) $id, $version->change_summary['room_request_id']);
        $item = ScheduleHistoryItem::where('history_version_id', $version->id)->sole();
        $this->assertSame((int) $class->id, (int) $item->original_schedule_id);
        $this->assertEquals($f['room']->id, $item->before_snapshot['room_id']);
        $this->assertNull($item->after_snapshot['room_id']);

        $audit = SchedulingAuditLog::where('action', 'room_grant_ended')->sole();
        $this->assertSame((int) $version->id, (int) $audit->history_version_id);
        $this->assertSame([$class->id], $audit->metadata['released_schedule_ids']);
        $this->assertSame('finalized', $class->fresh()->status, 'Releasing a grant must not change schedule status.');
        $this->assertSame((int) $f['room']->id, (int) $owners->fresh()->room_id);
    }

    public function test_giving_back_records_history_and_audit_for_the_borrower(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);
        $this->bookRoom($f, $f['requester'], 'Monday', '11:00:00', '13:00:00', $f['section'], $f['course']);

        $this->actingAs($f['secretary'])->postJson("/api/room-requests/{$id}/cancel")->assertOk();

        $version = ScheduleHistoryVersion::where('action', 'room_grant_ended')->sole();
        $this->assertSame((int) $f['secretary']->id, (int) $version->actor_user_id);
        $this->assertSame('borrower_returned', $version->change_summary['ended_by']);
        $this->assertTrue(SchedulingAuditLog::where('action', 'room_grant_ended')->where('user_id', $f['secretary']->id)->exists());
    }

    /** If the history write fails, the grant, recovery case, and Room TBA change all roll back. */
    public function test_a_history_failure_rolls_back_the_whole_grant_release(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);
        $class = $this->bookRoom($f, $f['requester'], 'Monday', '11:00:00', '13:00:00', $f['section'], $f['course']);
        $this->mock(ScheduleHistoryRecorder::class)
            ->shouldReceive('record')->andThrow(new \RuntimeException('history store unavailable'));

        $this->actingAs($f['ownerSecretary'])
            ->postJson("/api/room-requests/{$id}/revoke", ['remarks' => 'CAS needs it back.'])
            ->assertServerError();

        $this->assertSame('approved', RoomRequest::findOrFail($id)->status);
        $this->assertSame((int) $f['room']->id, (int) $class->fresh()->room_id);
        $this->assertDatabaseCount('room_recovery_cases', 0);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'room_grant_ended']);
    }

    // RR-03: a committed action reports success even when notifications fail.

    public function test_notification_failures_do_not_misreport_committed_actions(): void
    {
        $f = $this->fixture();
        $this->mock(SystemNotificationService::class)
            ->shouldReceive('createForUsers')->andThrow(new \RuntimeException('notifications unavailable'));

        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))
            ->assertCreated()->json('data.id');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($f['secretary'])->postJson("/api/room-requests/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $second = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))
            ->assertCreated()->json('data.id');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$second}/reject", ['remarks' => 'Booked.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(2, RoomRequest::count(), 'A failed notification duplicated or lost a request.');
    }

    /** Delivery is queued with retries; a failed attempt writes nothing, so a retry cannot duplicate. */
    public function test_notifications_are_queued_and_a_retry_delivers_them_once(): void
    {
        $f = $this->fixture();
        Queue::fake();

        $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated();

        $job = null;
        Queue::assertPushed(DeliverSystemNotifications::class, function (DeliverSystemNotifications $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $this->assertGreaterThan(1, $job->tries);
        $recipients = collect($job->deliveries)->pluck('user_ids')->flatten()->all();
        $this->assertContains((int) $f['ownerSecretary']->id, $recipients);
        $this->assertContains((int) $f['vpaa']->id, $recipients);

        $real = app(SystemNotificationService::class);
        $calls = 0;
        $flaky = \Mockery::mock(SystemNotificationService::class);
        $flaky->shouldReceive('createForUsers')->andReturnUsing(function (...$args) use ($real, &$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('second delivery failed');
            }
            $real->createForUsers(...$args);
        });
        try {
            $job->handle($flaky);
            $this->fail('The flaky delivery did not fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('second delivery failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('system_notifications', 0);

        $job->handle($real);
        $this->assertSame(1, SystemNotification::where('user_id', $f['ownerSecretary']->id)->where('type', 'room_request_submitted')->count());
        $this->assertSame(1, SystemNotification::where('user_id', $f['vpaa']->id)->where('type', 'room_request_submitted')->count());
    }

    // RR-06: an explicit semester scopes lists and occupancy.

    public function test_requests_are_listed_and_checked_for_an_explicit_semester(): void
    {
        $f = $this->fixture();
        $future = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated();
        $this->actingAs($f['secretary'])->postJson('/api/room-requests', ['semester_id' => $future->id] + $this->payload($f))->assertCreated();

        $this->actingAs($f['ownerSecretary'])
            ->getJson("/api/room-requests?semester_id={$f['semester']->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.academic_semester.id', $f['semester']->id);
        $this->actingAs($f['secretary'])
            ->getJson("/api/room-requests/rooms/{$f['room']->id}/occupancy?semester_id={$future->id}")
            ->assertOk()
            ->assertJsonPath('semester.id', $future->id);
    }

    // RR-08: rooms with open requests or grants cannot be archived.

    public function test_a_pending_request_blocks_archiving_its_room(): void
    {
        $f = $this->fixture();
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');

        $this->actingAs($f['vpaa'])->deleteJson("/api/rooms/{$f['room']->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This room cannot be archived while it has pending room requests or approved room grants. Reject, cancel, or revoke them first.']);
        $this->assertNotSoftDeleted('rooms', ['id' => $f['room']->id]);

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/reject", ['remarks' => 'Closing the room.'])->assertOk();
        $this->actingAs($f['vpaa'])->deleteJson("/api/rooms/{$f['room']->id}")->assertOk();

        $this->actingAs($f['secretary'])->getJson('/api/room-requests')
            ->assertOk()
            ->assertJsonPath('0.room.room_code', 'RM-1')
            ->assertJsonPath('0.room.is_archived', true)
            ->assertJsonPath('0.status', 'rejected');
    }

    public function test_an_approved_grant_blocks_archiving_its_building(): void
    {
        $f = $this->fixture();
        $id = $this->approvedGrant($f);

        $this->actingAs($f['vpaa'])->postJson('/api/buildings/archive', ['building' => 'Main'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This building cannot be archived while its rooms have pending room requests or approved room grants: RM-1. Reject, cancel, or revoke them first.']);
        $this->assertNotSoftDeleted('rooms', ['id' => $f['room']->id]);

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/revoke", ['remarks' => 'Building closing.'])->assertOk();
        $this->actingAs($f['vpaa'])->postJson('/api/buildings/archive', ['building' => 'Main'])->assertOk();
        $this->assertSoftDeleted('rooms', ['id' => $f['room']->id]);
    }

    /** A request whose room was archived before this guard existed is refused clearly, and can still be closed. */
    public function test_a_request_for_an_already_archived_room_cannot_be_approved_but_can_be_rejected(): void
    {
        $f = $this->fixture();
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');
        $f['room']->delete();

        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_request');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/reject", ['remarks' => 'Room archived.'])->assertOk();
    }

    // RR-09: the backend compares exact minutes, so off-grid boundaries are honoured.

    public function test_off_grid_occupancy_is_compared_by_exact_minutes(): void
    {
        $f = $this->fixture();
        $this->bookRoom($f, $f['owner'], 'Monday', '07:00:00', '08:40:00');
        $window = fn (string $start, string $end): array => ['windows' => [['day' => 'Monday', 'start_time' => $start, 'end_time' => $end]]] + $this->payload($f);

        $this->actingAs($f['secretary'])->postJson('/api/room-requests', $window('08:30', '10:00'))
            ->assertStatus(422)->assertJsonValidationErrors('windows');
        $this->actingAs($f['secretary'])->postJson('/api/room-requests', $window('08:40', '10:40'))
            ->assertCreated()->assertJsonPath('data.windows.0.end_time', '10:40');
    }

    private function violatesRoomOwnership(array $attempt): bool
    {
        return collect(app(RuleEngine::class)->validate($attempt))
            ->contains(fn (array $violation): bool => $violation['rule'] === 'room_department_alignment');
    }

    private function approvedGrant(array $f): int
    {
        $id = $this->actingAs($f['secretary'])->postJson('/api/room-requests', $this->payload($f))->assertCreated()->json('data.id');
        $this->actingAs($f['ownerSecretary'])->postJson("/api/room-requests/{$id}/approve")->assertOk();

        return (int) $id;
    }

    private function payload(array $f): array
    {
        return [
            'room_id' => $f['room']->id,
            'semester_id' => $f['semester']->id,
            'purpose' => 'IT lecture classes',
            'windows' => [['day' => 'Monday', 'start_time' => '11:00', 'end_time' => '13:00']],
        ];
    }

    private function generate(array $f)
    {
        $plans = app(GenerateSchedulePlan::class)->generate(
            semesterId: (int) $f['semester']->id,
            departmentId: (int) $f['requester']->id,
            configuration: new GenerationConfiguration(
                sectionId: (int) $f['section']->id,
                courseIds: [(int) $f['course']->id],
                maxSolutions: 1,
                seed: 1234,
            ),
            configurationWarningsConfirmed: true,
        );

        $this->assertNotSame([], $plans, 'Generation returned no plan at all.');

        return $plans[0];
    }

    private function room(string $code, string $type, ?int $departmentId): Rooms
    {
        return Rooms::create([
            'room_code' => $code,
            'building' => 'Main',
            'room_type' => $type,
            'status' => 'available',
            'department_id' => $departmentId,
            'max_concurrent_classes' => 1,
        ]);
    }

    private function bookRoom(array $f, Departments $department, string $day, string $start, string $end, ?Sections $section = null, ?Course $course = null): Schedule
    {
        $section ??= Sections::create([
            'section_name' => $department->department_code.' 1Z',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $f['semester']->id,
            'status' => 'active',
        ]);
        $course ??= Course::create([
            'course_code' => $department->department_code.' 199',
            'course_name' => 'Occupying Course',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        return Schedule::create([
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $f['room']->id,
            'department_id' => $department->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
            'status' => 'finalized',
        ]);
    }

    private function secretaryFor(Departments $department): User
    {
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $secretary->givePermissionTo(['schedule.view', 'room.request']);

        return $secretary->fresh();
    }

    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $requester = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);
        $owner = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
        ]);
        Program::create(['department_id' => $requester->id, 'code' => 'BSIT', 'name' => 'Information Technology']);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $requester->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $requester->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Lecture Course IT 101',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $requester->id,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return [
            'semester' => $semester,
            'requester' => $requester,
            'owner' => $owner,
            'section' => $section,
            'course' => $course,
            'room' => $this->room('RM-1', 'lecture', $owner->id),
            'secretary' => $this->secretaryFor($requester),
            'ownerSecretary' => $this->secretaryFor($owner),
            'vpaa' => User::factory()->create(['role' => 'vpaa', 'department_id' => null]),
        ];
    }
}
