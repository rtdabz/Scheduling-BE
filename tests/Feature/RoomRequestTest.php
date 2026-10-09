<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\SystemNotification;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'scheduling_profile' => 'laboratory_enabled',
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
