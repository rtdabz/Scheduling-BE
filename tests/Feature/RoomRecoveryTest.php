<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\RoomRecoveryPlan;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\RoomRecoveryService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoomRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Recovery', 'department_code' => 'REC']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'REC', 'name' => 'Recovery']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $section = Sections::create(['section_name' => 'REC-1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
        $course = Course::create(['course_code' => 'REC101', 'course_name' => 'Recovery', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'R1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $replacement = Rooms::create(['room_code' => 'R2', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $row = Schedule::create(['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id, 'room_id' => $room->id, 'department_id' => $department->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'draft']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $admin = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa']));
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);

        return compact('department', 'program', 'semester', 'section', 'course', 'room', 'replacement', 'row', 'user', 'admin', 'dean');
    }

    private function close(array $f): void
    {
        $this->actingAs($f['admin'])->postJson('/api/rooms/'.$f['room']->id.'/close', ['reason' => 'Maintenance'])->assertOk();
    }

    private function preview(array $f, array $extra = []): array
    {
        return $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id], ...$extra])->assertOk()->json();
    }

    private function approveTransfer(array $f, array $plan): TestResponse
    {
        $before = Schedule::orderBy('id')->get()->map->getRawOriginal()->all();
        $sunday = $f['department']->fresh()->sunday_classes_enabled;
        $this->actingAs($f['user'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit',
            ['group_keys' => array_column($plan['groups'], 'key')])->assertOk()->assertJsonPath('status', 'pending_dean')->assertJsonPath('applied', false);
        $this->assertSame($before, Schedule::orderBy('id')->get()->map->getRawOriginal()->all(), 'Submission must preserve every schedule.');
        $this->assertSame($sunday, $f['department']->fresh()->sunday_classes_enabled, 'Submission must not enable Sunday.');
        $this->approveByDean($f, $plan);
        $response = $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve');
        $this->actingAs($f['user']);

        return $response;
    }

    private function approveByDean(array $f, array $plan): void
    {
        $before = Schedule::orderBy('id')->get()->map->getRawOriginal()->all();
        $sunday = $f['department']->fresh()->sunday_classes_enabled;
        $this->actingAs($f['dean'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')
            ->assertOk()->assertJsonPath('status', 'pending_vpaa')->assertJsonPath('applied', false);
        $this->assertSame($before, Schedule::orderBy('id')->get()->map->getRawOriginal()->all(), 'Dean approval must preserve every schedule.');
        $this->assertSame($sunday, $f['department']->fresh()->sunday_classes_enabled, 'Dean approval must not enable Sunday.');
        $this->actingAs($f['user']);
    }

    public function test_edit_cannot_bypass_closure_capture_for_a_non_active_term(): void
    {
        $f = $this->fixture();
        $f['semester']->update(['is_active' => false]);
        $this->actingAs($f['admin'])->putJson('/api/rooms/'.$f['room']->id, [
            'status' => 'not available', 'room_code' => 'UNSAVED',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('rooms', ['id' => $f['room']->id, 'status' => 'available', 'room_code' => 'R1']);
        $this->assertDatabaseCount('room_recovery_cases', 0);

        $this->close($f);
        $this->assertDatabaseHas('room_recovery_cases', ['room_id' => $f['room']->id, 'semester_id' => $f['semester']->id]);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
    }

    public function test_vacant_room_closure_requires_reason_and_reopening_still_uses_edit(): void
    {
        $f = $this->fixture();
        $room = $f['replacement'];
        $this->actingAs($f['admin'])->putJson('/api/rooms/'.$room->id, ['status' => 'not available'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/rooms/'.$room->id.'/close', ['reason' => ' '])->assertUnprocessable();
        $this->assertEquals('available', $room->fresh()->status);
        $this->postJson('/api/rooms/'.$room->id.'/close', ['reason' => 'Maintenance'])->assertOk();
        $this->putJson('/api/rooms/'.$room->id, ['status' => 'available'])->assertOk();
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_closure_preview_atomic_apply_history_and_idempotent_retry(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $this->close($f);
        $this->assertDatabaseCount('room_recovery_cases', 1);
        $this->actingAs($f['user'])->getJson('/api/room-recovery')->assertOk()->assertJsonPath('cases.0.meeting_count', 1);
        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $this->assertEquals($f['replacement']->id, $plan['groups'][0]['after'][0]['room_id']);
        $body = ['group_keys' => array_column($plan['groups'], 'key')];
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', $body)->assertOk()->assertJsonPath('applied', false);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', $body)->assertOk()->assertJsonPath('status', 'pending_dean');
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->approveByDean($f, $plan);
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertOk();
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertOk();
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseHas('schedule_history_versions', ['action' => 'room_recovery_applied']);
        $this->assertDatabaseCount('schedule_history_versions', 1);
        $this->actingAs($f['user'])->getJson('/api/room-recovery')->assertOk()->assertJsonPath('cases', []);
    }

    public function test_submission_preserves_schedules_notifies_only_department_dean_and_prevents_duplicate_pending_requests(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $other = $this->preview($f);
        $before = $f['row']->fresh()->getRawOriginal();
        $body = ['group_keys' => array_column($plan['groups'], 'key')];
        $url = '/api/room-recovery/plans/'.$plan['plan_id'].'/submit';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('status', 'pending_dean')->assertJsonPath('applied', false);
        $this->postJson($url, $body)->assertOk();
        $this->postJson('/api/room-recovery/plans/'.$other['plan_id'].'/submit', $body)->assertConflict();
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedule_history_versions', 0);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_dean', 'applied_at' => null]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $f['dean']->id, 'type' => 'room_transfer_submitted']);
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $f['admin']->id, 'type' => 'room_transfer_submitted']);
        $this->assertSame(1, DB::table('system_notifications')->where('type', 'room_transfer_submitted')->count());
        $this->getJson('/api/room-recovery?include_schedules=1')->assertOk()
            ->assertJsonPath('schedules.0.pending_transfer_id', $plan['plan_id'])
            ->assertJsonPath('schedules.0.room', 'R1')->assertJsonPath('transfers.0.status', 'pending_dean');
        $this->actingAs($f['admin'])->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers', []);
        $this->actingAs($f['dean'])->getJson('/api/room-recovery/transfers')->assertOk()
            ->assertJsonPath('transfers.0.id', $plan['plan_id'])->assertJsonPath('transfers.0.changes.0.course', 'REC101')
            ->assertJsonPath('transfers.0.changes.0.before.room', 'R1')->assertJsonPath('transfers.0.changes.0.after.room', 'R2');
    }

    public function test_review_requires_secretary_then_department_dean_then_vpaa_without_authoring_permissions(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->actingAs($f['admin'])->postJson($url.'/approve')->assertConflict();
        $this->actingAs($f['user'])->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->postJson($url.'/approve')->assertForbidden();
        $this->postJson($url.'/reject', ['reason' => 'No'])->assertForbidden();
        $this->actingAs($f['admin'])->postJson($url.'/approve')->assertConflict();
        $this->postJson($url.'/reject', ['reason' => 'Too early'])->assertConflict();
        $this->assertFalse($f['dean']->hasCapability('schedule.update'));
        $this->approveByDean($f, $plan);
        $this->actingAs($f['dean'])->postJson($url.'/approve')->assertOk()->assertJsonPath('status', 'pending_vpaa');
        $this->postJson($url.'/reject', ['reason' => 'Too late'])->assertConflict();
        $this->assertDatabaseHas('system_notifications', ['user_id' => $f['admin']->id, 'type' => 'room_transfer_dean_approved']);
        $this->assertSame(1, DB::table('system_notifications')->where('type', 'room_transfer_dean_approved')->count());
        $this->actingAs($f['user'])->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk()->assertJsonPath('status', 'pending_vpaa');
        $duplicate = $this->preview($f);
        $this->postJson('/api/room-recovery/plans/'.$duplicate['plan_id'].'/submit', ['group_keys' => array_column($duplicate['groups'], 'key')])->assertConflict();
        $f['admin']->syncPermissions([]);
        $this->assertFalse($f['admin']->fresh()->hasCapability('schedule.update'));
        $this->travel(1)->days(); // Submitted requests outlive the twenty-minute preview.
        $this->actingAs($f['admin']->fresh())->postJson($url.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($url.'/approve')->assertOk();
        $this->postJson($url.'/reject', ['reason' => 'Too late'])->assertConflict();
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'approved',
            'reviewed_by' => $f['admin']->id, 'dean_reviewed_by' => $f['dean']->id]);
        $this->assertDatabaseCount('schedule_history_versions', 1);
        // The requester and the forwarding Dean both learn the final decision.
        $this->assertDatabaseHas('system_notifications', ['user_id' => $f['dean']->id, 'type' => 'room_transfer_approved']);
        $this->assertSame(2, DB::table('system_notifications')->where('type', 'room_transfer_approved')->count());
    }

    public function test_foreign_dean_and_reviewers_without_their_stage_permission_are_denied(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $department = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $foreign = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $this->actingAs($foreign)->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers', []);
        $this->postJson($url.'/approve')->assertForbidden();
        $this->postJson($url.'/reject', ['reason' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseMissing('system_notifications', ['user_id' => $foreign->id, 'type' => 'room_transfer_submitted']);
        $f['dean']->syncRoles([]);
        // A wrong-stage permission cannot substitute for the Dean's permission.
        $f['dean']->syncPermissions(['schedule.approve_vpaa']);
        $this->actingAs($f['dean']->fresh())->postJson($url.'/approve')->assertForbidden();
        $this->postJson($url.'/reject', ['reason' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_dean', 'dean_reviewed_at' => null]);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
    }

    public function test_a_non_secretary_cannot_submit_even_an_owned_plan_with_update_permission(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $f['user']->update(['role' => 'program_head', 'program_id' => $f['program']->id]);
        $this->assertTrue($f['user']->fresh()->hasCapability('schedule.update'));
        $this->actingAs($f['user']->fresh());
        foreach (['submit', 'apply'] as $action) {
            $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/'.$action, ['group_keys' => array_column($plan['groups'], 'key')])->assertForbidden();
        }
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'preview']);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
    }

    #[DataProvider('reviewStages')]
    public function test_failed_decision_notification_rolls_back_the_whole_stage(string $stage): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        if ($stage === 'vpaa') {
            $this->approveByDean($f, $plan);
        }
        $before = $f['row']->fresh()->getRawOriginal();
        $this->mock(SystemNotificationService::class)->shouldReceive($stage === 'dean' ? 'notifyRoles' : 'createForUsers')
            ->once()->andThrow(new \RuntimeException('Notification write failed'));
        $this->actingAs($stage === 'dean' ? $f['dean'] : $f['admin'])->postJson($url.'/approve')->assertStatus(500);
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_'.$stage, 'applied_at' => null, 'reviewed_at' => null]);
        $this->assertDatabaseCount('schedule_history_versions', 0);
    }

    public function test_upgrade_returns_unreviewed_requests_to_the_dean_without_changing_schedules(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $before = $f['row']->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_23_140000_add_dean_review_to_room_transfers.php');
        $migration->down();
        DB::table('system_notifications')->where('type', 'room_transfer_submitted')->delete();
        $migration->up();
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_dean', 'dean_reviewed_at' => null]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $f['dean']->id, 'type' => 'room_transfer_submitted']);
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertConflict();
    }

    public static function reviewStages(): array
    {
        return [['dean'], ['vpaa']];
    }

    #[DataProvider('reviewStages')]
    public function test_rejection_preserves_original_schedule_and_notifies_the_requester(string $stage): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $before = $f['row']->fresh()->getRawOriginal();
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $body = ['group_keys' => array_column($plan['groups'], 'key')];
        $this->postJson($url.'/submit', $body)->assertOk();
        if ($stage === 'vpaa') {
            $this->approveByDean($f, $plan);
        }
        $reviewer = $stage === 'dean' ? $f['dean'] : $f['admin'];
        $this->actingAs($reviewer)->postJson($url.'/reject', ['reason' => ' '])->assertUnprocessable();
        $this->postJson($url.'/reject', ['reason' => 'Use the original room after maintenance'])->assertOk()->assertJsonPath('status', 'rejected_by_'.$stage);
        $this->postJson($url.'/reject', ['reason' => 'Retry'])->assertOk();
        $this->postJson($url.'/approve')->assertConflict();
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedule_history_versions', 0);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'rejected_by_'.$stage,
            'rejection_reason' => 'Use the original room after maintenance', 'applied_at' => null, 'reviewed_by' => $reviewer->id]);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $f['user']->id, 'type' => 'room_transfer_rejected', 'remarks' => 'Use the original room after maintenance']);
        $this->assertSame($stage === 'vpaa' ? 2 : 1, DB::table('system_notifications')->where('type', 'room_transfer_rejected')->count());
        $this->actingAs($f['user'])->postJson($url.'/submit', $body)->assertConflict();
        $new = $this->preview($f);
        $this->postJson('/api/room-recovery/plans/'.$new['plan_id'].'/submit', ['group_keys' => array_column($new['groups'], 'key')])->assertOk();
    }

    public static function pendingChanges(): array
    {
        return array_map(fn ($change) => [$change], ['source', 'booking', 'room', 'access']);
    }

    #[DataProvider('pendingChanges')]
    public function test_changes_while_pending_block_approval_without_partial_writes(string $change): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->approveByDean($f, $plan);
        if ($change === 'source') {
            $f['row']->update(['day' => 'Tuesday']);
        } elseif ($change === 'booking') {
            $section = $f['section']->replicate()->fill(['section_name' => 'New booking']);
            $section->save();
            Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $f['replacement']->id]);
        } elseif ($change === 'room') {
            $f['replacement']->update(['status' => 'not available']);
        } else {
            $owner = Departments::create(['department_name' => 'Owner', 'department_code' => 'OWN']);
            $f['replacement']->update(['department_id' => $owner->id]);
        }
        $before = Schedule::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($f['admin'])->postJson($url.'/approve')->assertConflict();
        $this->assertSame($before, Schedule::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_vpaa', 'reviewed_at' => null, 'applied_at' => null]);
        $this->assertDatabaseCount('schedule_history_versions', 0);
        $this->assertDatabaseMissing('system_notifications', ['type' => 'room_transfer_approved']);
        // A stale request can still be rejected, allowing the requester to prepare a fresh proposal.
        $this->postJson($url.'/reject', ['reason' => 'Please refresh the proposal'])->assertOk();
    }

    public function test_transfer_requests_are_scoped_and_another_user_cannot_submit_the_stored_plan(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $body = ['group_keys' => array_column($plan['groups'], 'key')];
        $this->postJson($url.'/submit', $body)->assertOk();
        $department = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        Program::create(['department_id' => $department->id, 'code' => 'OTH', 'name' => 'Other']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $this->actingAs($user)->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers', []);
        $this->postJson($url.'/submit', $body)->assertNotFound();
        $this->postJson($url.'/apply', $body)->assertNotFound();
    }

    public function test_expired_preview_cannot_be_submitted(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $this->travel(21)->minutes();
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertConflict();
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'preview']);
    }

    public function test_submission_rolls_back_if_the_dean_notification_cannot_be_persisted(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $before = $f['row']->fresh()->getRawOriginal();
        $this->mock(SystemNotificationService::class)->shouldReceive('notifyRoles')->once()->andThrow(new \RuntimeException('Notification write failed'));
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertStatus(500);
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'preview', 'submitted_at' => null]);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'room_transfer_submitted']);
        $this->assertDatabaseMissing('system_notifications', ['type' => 'room_transfer_submitted']);
    }

    public function test_only_the_submitted_complete_groups_are_applied_and_the_selection_cannot_change(): void
    {
        $f = $this->fixture();
        $section = $f['section']->replicate()->fill(['section_name' => 'REC-2']);
        $section->save();
        $second = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'day' => 'Tuesday']);
        $this->close($f);
        $plan = $this->preview($f, ['schedule_ids' => [$f['row']->id, $second->id]]);
        $this->assertCount(2, $plan['groups']);
        $selected = $plan['groups'][0];
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => [$selected['key']]])->assertOk();
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertConflict();
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertEquals($f['room']->id, $second->fresh()->room_id);
        $this->approveByDean($f, $plan);
        $this->actingAs($f['admin'])->postJson($url.'/approve')->assertOk();
        $selectedId = $selected['before'][0]['id'];
        foreach ([$f['row'], $second] as $row) {
            $this->assertEquals($row->id === $selectedId ? $f['replacement']->id : $f['room']->id, $row->fresh()->room_id);
        }
    }

    public function test_transfer_list_identifies_unavailable_room_and_required_room_type(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1&semester_id='.$f['semester']->id)
            ->assertOk()
            ->assertJsonPath('schedules.0.needs_attention', true)
            ->assertJsonPath('schedules.0.attention_reason', 'Room unavailable')
            ->assertJsonPath('schedules.0.room_status', 'not available')
            ->assertJsonPath('schedules.0.required_room_type', 'lecture');
    }

    public function test_availability_changes_refresh_secretary_transfers_without_changing_schedules(): void
    {
        $f = $this->fixture();
        $section = $f['section']->replicate()->fill(['section_name' => 'REC-2']);
        $section->save();
        $other = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $f['replacement']->id, 'day' => 'Tuesday']);
        $before = Schedule::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->actingAs($f['user'])->getJson('/api/rooms')->assertOk()
            ->assertJsonFragment(['id' => $f['room']->id, 'status' => 'available']);

        $this->close($f);

        $this->actingAs($f['user'])->getJson('/api/rooms')->assertOk()
            ->assertJsonFragment(['id' => $f['room']->id, 'status' => 'not available']);
        $this->getJson('/api/room-recovery?include_schedules=1')->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('schedules.0.id', $f['row']->id)
            ->assertJsonPath('schedules.0.needs_attention', true)
            ->assertJsonPath('schedules.1.id', $other->id)
            ->assertJsonPath('schedules.1.needs_attention', false);
        $this->postJson('/api/room-recovery/preview', [
            'schedule_ids' => [$other->id], 'destination_room_ids' => [$f['room']->id],
        ])->assertOk()->assertJsonPath('groups', [])->assertJsonPath('unresolved.0.reason_code', 'no_suitable_room');

        $this->actingAs($f['admin'])->putJson('/api/rooms/'.$f['room']->id, ['status' => 'available'])->assertOk();
        $this->actingAs($f['user'])->getJson('/api/rooms')->assertOk()
            ->assertJsonFragment(['id' => $f['room']->id, 'status' => 'available']);
        $this->getJson('/api/room-recovery?include_schedules=1')->assertOk()
            ->assertJsonPath('pending_count', 0)->assertJsonPath('cases', [])
            ->assertJsonPath('schedules.0.needs_attention', false);
        $this->postJson('/api/room-recovery/preview', [
            'schedule_ids' => [$other->id], 'destination_room_ids' => [$f['room']->id],
        ])->assertOk()->assertJsonPath('groups.0.after.0.room_id', $f['room']->id);
        $this->assertSame($before, Schedule::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('schedule_history_versions', 0);
        $this->assertDatabaseCount('room_recovery_cases', 1);
    }

    public function test_transfer_list_detects_the_active_second_semester_without_a_selection(): void
    {
        $f = $this->fixture();
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => true, 'is_enabled' => true]);
        $section = $f['section']->replicate()->fill(['semester_id' => $semester->id, 'semester' => '2nd']);
        $section->save();
        $row = Schedule::create([...$f['row']->toArray(), 'semester_id' => $semester->id, 'section_id' => $section->id]);
        $this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1')->assertOk()
            ->assertJsonPath('semester_id', $semester->id)
            ->assertJsonCount(1, 'schedules')->assertJsonPath('schedules.0.id', $row->id);
    }

    public function test_pending_count_is_current_and_does_not_count_duplicate_incidents(): void
    {
        $f = $this->fixture();
        $this->close($f);
        app(RoomRecoveryService::class)->recordImpact($f['room']->id, collect([$f['row']->fresh()]), 'room_closed', 'Repeated alert', $f['admin']->id);
        $this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1')->assertOk()
            ->assertJsonPath('pending_count', 1)->assertJsonCount(1, 'cases');
        $missing = Schedule::create([...$f['row']->toArray(), 'room_id' => null, 'day' => 'Tuesday']);
        $this->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 2)->assertJsonPath('schedules', []);
        $f['room']->refresh()->update(['status' => 'available']);
        $this->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 1)->assertJsonPath('cases', []);
        $missing->update(['room_id' => $f['replacement']->id]);
        $this->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 0);
    }

    public function test_pending_count_rechecks_borrowing_approval_and_rejection_without_stale_caches(): void
    {
        $f = $this->fixture();
        $owner = Departments::create(['department_name' => 'Lending', 'department_code' => 'LND']);
        $room = Rooms::create(['room_code' => 'BORROWED', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $owner->id]);
        $f['row']->update(['room_id' => $room->id]);
        $loan = RoomRequest::create(['room_id' => $room->id, 'semester_id' => $f['semester']->id,
            'owner_department_id' => $owner->id, 'requesting_department_id' => $f['department']->id,
            'requested_by' => $f['user']->id, 'purpose' => 'Classroom', 'status' => RoomRequest::STATUS_PENDING]);
        $loan->windows()->create(['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00']);
        $this->actingAs($f['user'])->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 1);
        $loan->update(['status' => RoomRequest::STATUS_APPROVED]);
        $this->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 0);
        $loan->update(['status' => RoomRequest::STATUS_REJECTED]);
        $this->getJson('/api/room-recovery')->assertOk()->assertJsonPath('pending_count', 1);
    }

    public function test_room_assessment_keeps_existing_room_rules(): void
    {
        $f = $this->fixture();
        $foreign = Departments::create(['department_name' => 'Foreign', 'department_code' => 'FRN']);
        $foreignRoom = Rooms::create(['room_code' => 'FOREIGN', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $foreign->id]);
        $lab = Rooms::create(['room_code' => 'LAB', 'room_type' => 'laboratory', 'allow_lecture_usage' => false, 'status' => 'available', 'department_id' => $f['department']->id]);
        $closed = Rooms::create(['room_code' => 'CLOSED', 'room_type' => 'lecture', 'status' => 'not available', 'department_id' => $f['department']->id]);
        $expected = [$f['row']->id => false];
        foreach ([[$foreignRoom->id, 'on-site', true], [$lab->id, 'on-site', true], [$closed->id, 'on-site', true], [null, 'on-site', true], [null, 'online', false]] as [$roomId, $mode, $problem]) {
            $row = Schedule::create([...$f['row']->toArray(), 'room_id' => $roomId, 'mode' => $mode]);
            $expected[$row->id] = $problem;
        }
        $rows = Schedule::whereIn('id', array_keys($expected))->get();
        $actual = app(RoomRecoveryService::class)->roomProblems($rows);
        $this->assertSame($expected, $actual);
        foreach ($rows as $row) {
            $legacy = $row->mode === 'on-site' && ($row->room_id === null || collect((new RuleEngine)->validateStanding($row->toArray()))->contains(
                fn ($issue) => in_array($issue['rule'], ['room_availability', 'room_department_alignment', 'room_type_match'], true),
            ));
            $this->assertSame($legacy, $actual[$row->id]);
        }
    }

    public function test_transfer_list_query_count_does_not_grow_with_each_meeting(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user']);
        $queries = function () {
            SchedulingPolicy::clearFieldCourseCache();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson('/api/room-recovery?include_schedules=1')->assertOk()->assertJsonPath('pending_count', 0);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $small = $queries();
        for ($index = 0; $index < 50; $index++) {
            Schedule::create($f['row']->toArray());
        }
        $this->assertLessThanOrEqual($small + 3, $queries(), 'Room checks must batch lookups instead of issuing per-meeting queries.');
    }

    public function test_transfer_list_includes_missing_laboratory_without_a_closure_case(): void
    {
        $f = $this->fixture();
        $f['course']->update(['lecture_hours' => 0, 'lab_hours' => 1, 'room_type_required' => 'lecture']);
        $f['row']->update(['room_id' => null]);
        $this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1&semester_id='.$f['semester']->id)
            ->assertOk()
            ->assertJsonPath('cases', [])
            ->assertJsonPath('schedules.0.attention_reason', 'Room required')
            ->assertJsonPath('schedules.0.required_room_type', 'laboratory')
            ->assertJsonPath('schedules.0.needs_attention', true);
    }

    public function test_delegated_teaching_does_not_include_foreign_owned_classes_in_room_transfers_or_alerts(): void
    {
        $f = $this->fixture();
        $teaching = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $teaching->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $teaching->id]));
        $f['course']->update(['course_code' => 'IT 104', 'teaching_department_id' => $teaching->id]);
        $f['section']->update(['section_name' => 'BSBA 1B']);

        $section = $f['section']->replicate()->fill(['section_name' => 'BSIT 1A', 'department_id' => $teaching->id, 'program_id' => $program->id]);
        $section->save();
        $course = $f['course']->replicate()->fill(['department_id' => $teaching->id, 'teaching_department_id' => null]);
        $course->save();
        $room = Rooms::create(['room_code' => 'IT Lab', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $teaching->id]);
        $row = $f['row']->replicate()->fill(['department_id' => $teaching->id, 'section_id' => $section->id, 'course_id' => $course->id, 'room_id' => $room->id]);
        $row->save();
        $this->close($f);
        $this->close([...$f, 'room' => $room]);

        // Teaching delegation still grants the existing schedule visibility for instructor assignment.
        $this->actingAs($user)->getJson('/api/schedules/'.$f['row']->id)->assertOk();
        $url = '/api/room-recovery?include_schedules=1&semester_id='.$f['semester']->id;
        $this->getJson($url)->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonCount(1, 'schedules')->assertJsonPath('schedules.0.id', $row->id)
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.schedule_ids', [$row->id])
            ->assertJsonPath('cases.0.meeting_count', 1);
        $this->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id, $row->id]])->assertNotFound();

        $this->actingAs($f['user'])->getJson($url)->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonCount(1, 'schedules')->assertJsonPath('schedules.0.id', $f['row']->id)
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.schedule_ids', [$f['row']->id]);
    }

    public function test_stale_destination_rejects_all_writes(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $f['replacement']->update(['status' => 'not available']);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', ['group_keys' => array_column($plan['groups'], 'key')])->assertStatus(409);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseCount('schedule_history_versions', 0);
    }

    public function test_secretary_cannot_close_room_and_foreign_department_cannot_see_case(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->postJson('/api/rooms/'.$f['room']->id.'/close', ['reason' => 'No'])->assertForbidden();
        $this->close($f);
        $department = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $this->actingAs($user)->getJson('/api/room-recovery')->assertOk()->assertJsonPath('cases', []);
        $this->postJson('/api/room-recovery/preview', ['schedule_ids' => [$f['row']->id]])->assertForbidden();
    }

    public static function revisionStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['submitted', 'approved_by_dean', 'conditionally_approved', 'approved', 'reassignment', 'finalized']);
    }

    #[DataProvider('revisionStatuses')]
    public function test_locked_rows_are_reported_without_proposals(string $status): void
    {
        $f = $this->fixture();
        $f['row']->update(['status' => $status]);
        $this->close($f);
        $plan = $this->preview($f);
        $this->assertSame([], $plan['groups']);
        $this->assertStringContainsString('revision', $plan['unresolved'][0]['reason']);
        $this->assertSame('revision_required', $plan['unresolved'][0]['reason_code']);
        $this->assertSame('REC101', $plan['meetings'][0]['course']);
        $this->assertSame('REC-1', $plan['meetings'][0]['section']);
    }

    public function test_same_time_replacement_during_faculty_assignment_updates_the_original_only(): void
    {
        $f = $this->fixture();
        $faculty = Faculty::create(['first_name' => 'Room', 'last_name' => 'Transfer', 'employment_type' => 'full-time', 'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $section = $f['section']->replicate()->fill(['section_name' => 'Other section']);
        $section->save();
        $unrelated = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'day' => 'Tuesday', 'room_id' => $f['replacement']->id]);
        $before = $f['row']->fresh()->getAttributes();
        $untouched = $unrelated->fresh()->getAttributes();
        $this->close($f);

        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $after = $plan['groups'][0]['after'][0];
        foreach (['id', 'day', 'start_time', 'end_time', 'faculty_id', 'faculty_assignment_done', 'status'] as $field) {
            $this->assertEquals($before[$field], $after[$field], $field);
        }
        $this->assertEquals($f['replacement']->id, $after['room_id']);
        $this->assertEquals($before, $f['row']->fresh()->getAttributes(), 'Preview must not save.');
        $this->approveTransfer($f, $plan)->assertOk();
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertOk();
        $saved = $f['row']->fresh()->getAttributes();
        $this->assertEquals($f['replacement']->id, $saved['room_id']);
        unset($before['room_id'], $before['updated_at'], $saved['room_id'], $saved['updated_at']);
        $this->assertEquals($before, $saved);
        $this->assertEquals($untouched, $unrelated->fresh()->getAttributes());
        $this->assertDatabaseCount('schedules', 2);
        $this->assertDatabaseCount('schedule_history_versions', 1);
    }

    public function test_faculty_assignment_time_changes_require_explicit_opt_in_and_keep_the_instructor(): void
    {
        $f = $this->fixture();
        $faculty = Faculty::create(['first_name' => 'Assigned', 'last_name' => 'Instructor', 'employment_type' => 'full-time', 'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $section = $f['section']->replicate()->fill(['section_name' => 'Other section']);
        $section->save();
        $blocker = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $f['replacement']->id]);
        $untouched = $blocker->fresh()->getAttributes();
        $this->close($f);
        $this->assertSame([], $this->preview($f)['groups']);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertCount(1, $plan['groups']);
        $after = $plan['groups'][0]['after'][0];
        $this->assertSame('Monday', $after['day']);
        $this->assertNotSame($f['row']->start_time, $after['start_time']);
        $this->assertEquals($faculty->id, $after['faculty_id']);
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertEquals($untouched, $blocker->fresh()->getAttributes());
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertEquals($faculty->id, $f['row']->fresh()->faculty_id);
        $this->assertTrue($f['row']->fresh()->faculty_assignment_done);
        $this->assertSame('faculty_assignment', $f['row']->fresh()->status);
    }

    public function test_faculty_assignment_rechecks_time_restrictions_on_apply(): void
    {
        $f = $this->fixture();
        $f['row']->update(['status' => 'faculty_assignment']);
        $this->close($f);
        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $stored = RoomRecoveryPlan::findOrFail($plan['plan_id']);
        $payload = $stored->payload;
        $payload['groups'][0]['after'][0]['day'] = 'Tuesday';
        $stored->update(['payload' => $payload]);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', ['group_keys' => array_column($plan['groups'], 'key')])->assertUnprocessable();
        $this->assertEquals('Monday', $f['row']->fresh()->day);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseCount('schedule_history_versions', 0);
    }

    public function test_linked_online_partner_is_labeled_and_unchanged_by_a_same_time_replacement(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['status' => 'faculty_assignment', 'is_hybrid' => true, 'split_group_id' => 'room-only-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $online = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'mode' => 'online', 'room_id' => null, 'meeting_index' => 2]);
        $untouched = $online->fresh()->getAttributes();
        $this->close($f);
        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $this->assertCount(2, $plan['groups'][0]['after']);
        $labels = collect($plan['meetings'])->keyBy('id');
        $this->assertSame('REC101', $labels[$online->id]['course']);
        $this->assertSame('REC-1', $labels[$online->id]['section']);
        $this->assertSame('online', $labels[$online->id]['mode']);
        $this->travel(1)->minutes();
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertEquals($untouched, $online->fresh()->getAttributes());
        $this->assertDatabaseCount('schedules', 2);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
    }

    public function test_transfer_details_list_linked_online_sessions_and_print_the_full_section_timetable(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['status' => 'faculty_assignment', 'is_hybrid' => true, 'split_group_id' => 'details-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $online = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'mode' => 'online', 'room_id' => null, 'meeting_index' => 2]);
        $otherCourse = $f['course']->replicate()->fill(['course_code' => 'REC102']);
        $otherCourse->save();
        // An unaffected class of the same section still appears in the printed timetable.
        $unaffected = Schedule::create([...$f['row']->toArray(), 'course_id' => $otherCourse->id, 'split_group_id' => null, 'is_hybrid' => false,
            'day' => 'Friday', 'room_id' => $f['replacement']->id, 'meeting_index' => 1]);
        $before = Schedule::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        foreach ([$f['user'], $f['dean']] as $reviewer) {
            $details = $this->actingAs($reviewer)->getJson('/api/room-recovery/transfers')->assertOk();
            $details->assertJsonPath('transfers.0.changes.1.schedule_id', $online->id)
                ->assertJsonPath('transfers.0.changes.1.mode', 'online')
                ->assertJsonPath('transfers.0.changes.1.before.room', 'Online')->assertJsonPath('transfers.0.changes.1.after.room', 'Online');
            $timetable = collect($details->json('transfers.0.preview_schedules'))->keyBy('id');
            $this->assertEqualsCanonicalizing([$f['row']->id, $online->id, $unaffected->id], $timetable->keys()->all());
            $this->assertSame('R2', $timetable[$f['row']->id]['room']['room_code']);
            $this->assertEquals($f['replacement']->id, $timetable[$f['row']->id]['room_id']);
            $this->assertNull($timetable[$online->id]['room']);
            $this->assertSame('online', $timetable[$online->id]['mode']);
            $this->assertSame('REC102', $timetable[$unaffected->id]['course']['course_code']);
            $this->assertSame('REC-1', $timetable[$unaffected->id]['section']['section_name']);
            $details->assertJsonPath('transfers.0.department_id', $f['department']->id)
                ->assertJsonCount(2, 'transfers.0.changes')->assertJsonPath('transfers.0.changes.0.mode', 'on-site')
                ->assertJsonPath('transfers.0.changes.0.schedule_id', $f['row']->id)
                ->assertJsonPath('transfers.0.changes.0.preview_schedule.id', $f['row']->id)
                ->assertJsonPath('transfers.0.changes.0.preview_schedule.room.room_code', 'R2')
                ->assertJsonPath('transfers.0.changes.0.preview_schedule.course.course_name', 'Recovery')
                ->assertJsonPath('transfers.0.changes.0.preview_schedule.section.section_name', 'REC-1')
                ->assertJsonPath('transfers.0.preview_semester.id', $f['semester']->id);
            $this->assertContains($online->id, $details->json('transfers.0.schedule_ids'));
        }
        $this->assertCount(2, RoomRecoveryPlan::findOrFail($plan['plan_id'])->payload['groups'][0]['after']);
        $this->approveByDean($f, $plan);
        $this->assertSame($before, Schedule::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->actingAs($f['admin'])->getJson('/api/room-recovery/transfers')->assertOk()
            ->assertJsonCount(2, 'transfers.0.changes')->assertJsonPath('transfers.0.status', 'pending_vpaa');
        $this->postJson($url.'/approve')->assertOk()->assertJsonPath('applied', true);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertSame($before[1], $online->fresh()->getRawOriginal());
    }

    public function test_transfer_preview_uses_scoped_official_records_and_preserves_program_head_roles(): void
    {
        $f = $this->fixture();
        $foreignDepartment = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $foreignDean = User::factory()->create(['role' => 'dean', 'department_id' => $foreignDepartment->id]);
        $inactiveDean = User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id, 'is_active' => false]);
        $this->close($f);
        $plan = $this->preview($f);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit',
            ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        // Officials are read from current records, including appointments made after submission.
        $head = User::factory()->create([
            'role' => 'program_head', 'department_id' => $f['department']->id, 'program_id' => $f['program']->id,
        ]);

        foreach ([$f['user'], $f['dean']] as $viewer) {
            $response = $this->actingAs($viewer)->getJson('/api/room-recovery/transfers')->assertOk();
            $officials = collect($response->json('transfers.0.preview_users'))->keyBy('id');
            $response->assertJsonPath('transfers.0.submitted_by', $f['user']->name)
                ->assertJsonPath('transfers.0.changes.0.preview_schedule.section.program_id', $f['program']->id);
            foreach ([$head, $f['user'], $f['dean'], $f['admin']] as $official) {
                $this->assertSame($official->only(['id', 'name', 'role', 'department_id', 'program_id']), $officials[$official->id]);
            }
            $this->assertCount(4, $officials);
            $this->assertFalse($officials->has($foreignDean->id));
            $this->assertFalse($officials->has($inactiveDean->id));
        }

        $this->approveByDean($f, $plan);
        $response = $this->actingAs($f['admin'])->getJson('/api/room-recovery/transfers')->assertOk();
        $this->assertEqualsCanonicalizing([$head->id, $f['user']->id, $f['dean']->id, $f['admin']->id],
            array_column($response->json('transfers.0.preview_users'), 'id'));
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertOk()->assertJsonPath('applied', true);
    }

    public function test_new_destination_booking_after_preview_prevents_writes(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $section = $f['section']->replicate()->fill(['section_name' => 'Other section']);
        $section->save();
        $blocker = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $f['replacement']->id]);
        $untouched = $blocker->fresh()->getAttributes();
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', ['group_keys' => array_column($plan['groups'], 'key')])->assertStatus(409);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertEquals($untouched, $blocker->fresh()->getAttributes());
        $this->assertDatabaseCount('schedules', 2);
        $this->assertDatabaseCount('schedule_history_versions', 0);
    }

    public function test_laboratory_replacement_preserves_the_hybrid_lecture_partner(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $f['room']->update(['room_type' => 'laboratory']);
        $f['replacement']->update(['room_type' => 'laboratory']);
        $f['row']->update(['status' => 'faculty_assignment', 'is_hybrid' => true, 'split_group_id' => 'lab-replacement',
            'day' => 'Saturday', 'start_time' => '07:00', 'end_time' => '08:00', 'meeting_type' => 'laboratory', 'meeting_index' => 1]);
        $online = Schedule::create([...$f['row']->toArray(), 'day' => 'Friday', 'start_time' => '17:30', 'end_time' => '19:30',
            'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture', 'meeting_index' => 2]);
        $untouched = $online->fresh()->getAttributes();
        $this->close($f);
        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $this->travel(1)->minutes();
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertEquals($untouched, $online->fresh()->getAttributes());
        $this->assertDatabaseHas('schedules', ['id' => $f['row']->id, 'day' => 'Saturday', 'start_time' => '07:00', 'end_time' => '08:00',
            'room_id' => $f['replacement']->id, 'status' => 'faculty_assignment']);
        $this->assertDatabaseCount('schedules', 2);
    }

    public function test_laboratory_is_not_replaced_by_a_lecture_room(): void
    {
        $f = $this->fixture();
        $f['course']->update(['lecture_hours' => 0, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $f['room']->update(['room_type' => 'laboratory']);
        $f['row']->update(['meeting_type' => 'laboratory']);
        $this->close($f);
        $plan = $this->preview($f);
        $this->assertSame([], $plan['groups']);
        $this->assertNotEmpty($plan['unresolved']);
    }

    public function test_new_room_exploration_does_not_write_schedules(): void
    {
        $f = $this->fixture();
        $plan = $this->preview($f, ['destination_room_ids' => [$f['replacement']->id]]);
        $this->assertCount(1, $plan['groups']);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseCount('schedule_history_versions', 0);
    }

    public function test_joint_preview_does_not_double_book_one_destination(): void
    {
        $f = $this->fixture();
        $otherRoom = Rooms::create(['room_code' => 'R3', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        $section = $f['section']->replicate();
        $section->section_name = 'REC-2';
        $section->save();
        $second = $f['row']->replicate();
        $second->section_id = $section->id;
        $second->room_id = $otherRoom->id;
        $second->save();
        $this->close($f);
        $this->actingAs($f['admin'])->postJson('/api/rooms/'.$otherRoom->id.'/close', ['reason' => 'Maintenance'])->assertOk();
        $plan = $this->preview($f, ['schedule_ids' => [$f['row']->id, $second->id]]);
        $this->assertCount(1, $plan['groups']);
        $this->assertCount(1, $plan['unresolved']);
    }

    public function test_linked_meetings_are_recovered_as_one_complete_group(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['split_group_id' => 'recovery-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $other = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'meeting_index' => 2]);
        $this->close($f);
        $plan = $this->preview($f, ['schedule_ids' => [$f['row']->id, $other->id]]);
        $this->assertCount(1, $plan['groups']);
        $this->assertCount(2, $plan['groups'][0]['after']);
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertEquals($f['replacement']->id, $other->fresh()->room_id);
    }

    public function test_history_failure_rolls_back_schedule_and_plan(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $this->mock(ScheduleHistoryRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated write failure'));
        $this->approveTransfer($f, $plan)->assertStatus(500);
        $this->assertEquals($f['room']->id, $f['row']->fresh()->room_id);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'applied_at' => null]);
    }

    public function test_time_changes_require_explicit_choice(): void
    {
        $f = $this->fixture();
        $section = $f['section']->replicate();
        $section->section_name = 'REC-2';
        $section->save();
        $blocker = $f['row']->replicate();
        $blocker->section_id = $section->id;
        $blocker->room_id = $f['replacement']->id;
        $blocker->save();
        $this->close($f);
        $this->assertSame([], $this->preview($f)['groups']);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertCount(1, $plan['groups']);
        $this->assertSame('Monday', $plan['groups'][0]['after'][0]['day'], 'Prefer another time on the original day.');
        $this->approveTransfer($f, $plan)->assertOk();
    }

    private function blockReplacementDays(array $f, array $days): void
    {
        $section = $f['section']->replicate()->fill(['section_name' => 'Room blockers '.$f['replacement']->id]);
        $section->save();
        foreach ($days as $day) {
            Schedule::create([
                'semester_id' => $f['semester']->id, 'section_id' => $section->id, 'course_id' => $f['course']->id,
                'room_id' => $f['replacement']->id, 'department_id' => $f['department']->id,
                'day' => $day, 'start_time' => SchedulingPolicy::openingTime(), 'end_time' => SchedulingPolicy::closingTime(),
                'mode' => 'on-site', 'status' => 'draft',
            ]);
        }
    }

    public static function sundayModes(): array
    {
        return ['Sunday enabled' => [true], 'Sunday disabled' => [false]];
    }

    #[DataProvider('sundayModes')]
    public function test_single_meeting_includes_sunday_automatically_when_day_changes_are_allowed(bool $sunday): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => $sunday])->save();
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $this->close($f);
        $this->assertSame([], $this->preview($f)['groups']);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertSame($sunday, $f['department']->fresh()->sunday_classes_enabled, 'Preview must not change settings.');
        $this->assertCount(1, $plan['groups']);
        $this->assertSame('Sunday', $plan['groups'][0]['after'][0]['day']);
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertSame('Sunday', $f['row']->fresh()->day);
        $this->assertTrue($f['department']->fresh()->sunday_classes_enabled);
        $this->assertNotContains('sunday_classes', array_column((new RuleEngine)->validateStanding($f['row']->fresh()->toArray()), 'rule'));
    }

    #[DataProvider('sundayModes')]
    public function test_linked_day_transfer_includes_sunday_automatically_without_breaking_spacing(bool $sunday): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => $sunday])->save();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $faculty = Faculty::create(['first_name' => 'Linked', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['split_group_id' => 'day-transfer-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30',
            'status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $other = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'meeting_index' => 2]);
        $this->blockReplacementDays($f, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday']);
        $this->close($f);
        $extra = ['schedule_ids' => [$f['row']->id, $other->id]];
        $this->assertSame([], $this->preview($f, $extra)['groups']);
        $plan = $this->preview($f, [...$extra, 'allow_time_changes' => true]);
        $this->assertSame($sunday, $f['department']->fresh()->sunday_classes_enabled, 'Preview must not change settings.');
        $this->assertCount(1, $plan['groups']);
        $rows = $plan['groups'][0]['after'];
        $this->assertSame(['Friday', 'Sunday'], array_column($rows, 'day'));
        $this->assertSame(['08:00', '08:00'], array_column($rows, 'start_time'));
        $this->assertSame('Monday', $f['row']->fresh()->day, 'Preview must not move classes.');
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertSame('Friday', $f['row']->fresh()->day);
        $this->assertSame('Sunday', $other->fresh()->day);
        $this->assertTrue($f['department']->fresh()->sunday_classes_enabled);
        foreach ([$f['row']->fresh(), $other->fresh()] as $saved) {
            $this->assertEquals($faculty->id, $saved->faculty_id);
            $this->assertSame('faculty_assignment', $saved->status);
            $this->assertTrue($saved->faculty_assignment_done);
        }
    }

    public function test_linked_transfer_prefers_an_available_weekday_pattern_to_a_sunday_pattern(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['split_group_id' => 'weekday-transfer-pair', 'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $other = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'meeting_index' => 2]);
        $this->blockReplacementDays($f, ['Monday', 'Wednesday']);
        $this->close($f);
        $plan = $this->preview($f, ['schedule_ids' => [$f['row']->id, $other->id], 'allow_time_changes' => true]);
        $this->assertCount(1, $plan['groups']);
        $this->assertSame(['Tuesday', 'Thursday'], array_column($plan['groups'][0]['after'], 'day'));
        $this->assertSame(['08:00', '08:00'], array_column($plan['groups'][0]['after'], 'start_time'));
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertFalse($f['department']->fresh()->sunday_classes_enabled, 'Weekday transfers must not change Sunday settings.');
    }

    public function test_failed_sunday_transfer_rolls_back_automatic_sunday_enablement(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $this->close($f);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertSame('Sunday', $plan['groups'][0]['after'][0]['day']);
        $this->mock(ScheduleHistoryRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated write failure'));
        $this->approveTransfer($f, $plan)->assertStatus(500);
        $this->assertFalse($f['department']->fresh()->sunday_classes_enabled);
        $this->assertSame('Monday', $f['row']->fresh()->day);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'applied_at' => null]);
    }

    public static function sundayInstructorCases(): array
    {
        return ['available instructor' => ['available'], 'Sunday booking' => ['busy'], 'no Sunday availability' => ['unavailable']];
    }

    #[DataProvider('sundayInstructorCases')]
    public function test_sunday_transfers_during_faculty_assignment_retain_and_validate_the_instructor(string $availability): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $faculty = Faculty::create(['first_name' => 'Sunday', 'last_name' => 'Instructor',
            'employment_type' => $availability === 'unavailable' ? 'part-time' : 'full-time',
            'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        if ($availability === 'unavailable') {
            $faculty->availabilities()->create(['day_index' => 0, 'start_time' => SchedulingPolicy::openingTime(), 'end_time' => SchedulingPolicy::closingTime()]);
        }
        if ($availability === 'busy') {
            $section = $f['section']->replicate()->fill(['section_name' => 'Sunday booking']);
            $section->save();
            Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'day' => 'Sunday', 'mode' => 'online', 'room_id' => null,
                'start_time' => SchedulingPolicy::openingTime(), 'end_time' => SchedulingPolicy::closingTime()]);
        }
        $this->close($f);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        if ($availability !== 'available') {
            $this->assertSame([], $plan['groups']);
            $this->assertSame('Monday', $f['row']->fresh()->day);
            $this->assertFalse($f['department']->fresh()->sunday_classes_enabled);

            return;
        }
        $this->assertCount(1, $plan['groups']);
        $this->assertSame('Sunday', $plan['groups'][0]['after'][0]['day']);
        $this->assertEquals($faculty->id, $plan['groups'][0]['after'][0]['faculty_id']);
        $this->approveTransfer($f, $plan)->assertOk();
        $saved = $f['row']->fresh();
        $this->assertSame('Sunday', $saved->day);
        $this->assertEquals($faculty->id, $saved->faculty_id);
        $this->assertTrue($saved->faculty_assignment_done);
        $this->assertSame('faculty_assignment', $saved->status);
    }

    public function test_new_instructor_booking_after_sunday_preview_rejects_the_transfer(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $faculty = Faculty::create(['first_name' => 'Booked', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $this->close($f);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertSame('Sunday', $plan['groups'][0]['after'][0]['day']);
        $section = $f['section']->replicate()->fill(['section_name' => 'New instructor booking']);
        $section->save();
        Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'day' => 'Sunday', 'mode' => 'online', 'room_id' => null,
            'start_time' => SchedulingPolicy::openingTime(), 'end_time' => SchedulingPolicy::closingTime()]);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/apply', ['group_keys' => array_column($plan['groups'], 'key')])->assertStatus(409);
        $this->assertSame('Monday', $f['row']->fresh()->day);
        $this->assertEquals($faculty->id, $f['row']->fresh()->faculty_id);
        $this->assertFalse($f['department']->fresh()->sunday_classes_enabled);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'applied_at' => null]);
    }

    public function test_day_changes_do_not_override_a_fixed_meeting_pattern(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => true])->save();
        $f['course']->update(['units' => 3, 'lecture_hours' => 3, 'course_category' => 'minor']);
        $f['row']->update(['split_group_id' => 'fixed-transfer-pair', 'preferred_pattern' => 'MW',
            'meeting_type' => 'lecture', 'meeting_index' => 1, 'end_time' => '09:30']);
        $other = Schedule::create([...$f['row']->toArray(), 'day' => 'Wednesday', 'meeting_index' => 2]);
        $this->blockReplacementDays($f, ['Monday', 'Wednesday']);
        $this->close($f);
        $plan = $this->preview($f, ['schedule_ids' => [$f['row']->id, $other->id], 'allow_time_changes' => true]);
        $this->assertSame([], $plan['groups']);
        $this->assertSame('MW', $f['row']->fresh()->preferred_pattern);
        $this->assertSame('Monday', $f['row']->fresh()->day);
        $this->assertSame('Wednesday', $other->fresh()->day);
    }

    public static function everyWeekday(): array
    {
        return array_combine(SchedulingPolicy::DAYS, array_map(fn ($day) => [$day], SchedulingPolicy::DAYS));
    }

    #[DataProvider('everyWeekday')]
    public function test_room_transfer_lists_and_applies_every_day_including_sunday(string $day): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => true])->save();
        $f['row']->update(['day' => $day]);
        $this->close($f);
        $this->actingAs($f['user'])->getJson('/api/room-recovery?include_schedules=1')
            ->assertOk()->assertJsonPath('schedules.0.day', $day)->assertJsonPath('schedules.0.needs_attention', true);
        $plan = $this->preview($f);
        $this->assertCount(1, $plan['groups']);
        $this->assertSame($day, $plan['groups'][0]['after'][0]['day']);
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertSame($day, $f['row']->fresh()->day);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
    }

    public function test_sunday_hybrid_transfer_updates_custom_day_markers_and_keeps_the_online_meeting(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $f['course']->update(['units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $f['room']->update(['room_type' => 'laboratory']);
        $f['replacement']->update(['room_type' => 'laboratory']);
        $faculty = Faculty::create(['first_name' => 'Hybrid', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 18, 'department_id' => $f['department']->id, 'status' => 'active']);
        $f['row']->update(['is_hybrid' => true, 'split_group_id' => 'custom-sunday', 'preferred_pattern' => 'days:5-4',
            'meeting_type' => 'laboratory', 'meeting_index' => 1, 'day' => 'Saturday', 'start_time' => '07:00', 'end_time' => '08:00',
            'status' => 'faculty_assignment', 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true]);
        $online = Schedule::create([...$f['row']->toArray(), 'day' => 'Friday', 'mode' => 'online', 'room_id' => null,
            'start_time' => '17:30', 'end_time' => '19:30', 'meeting_type' => 'lecture', 'meeting_index' => 2]);
        $beforeOnline = $online->fresh()->getAttributes();
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $this->close($f);
        $this->assertSame([], $this->preview($f)['groups']);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertCount(1, $plan['groups']);
        $proposed = collect($plan['groups'][0]['after'])->keyBy('id');
        $this->assertSame('Sunday', $proposed[$f['row']->id]['day']);
        $this->assertSame('Friday', $proposed[$online->id]['day']);
        $this->assertSame('17:30', $proposed[$online->id]['start_time']);
        $this->assertSame('days:6-4', $proposed[$online->id]['preferred_pattern']);
        $this->assertSame('days:5-4', $f['row']->fresh()->preferred_pattern, 'Preview must not change the saved pattern.');
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertSame('Sunday', $f['row']->fresh()->day);
        $this->assertSame('days:6-4', $f['row']->fresh()->preferred_pattern);
        $this->assertSame('days:6-4', $online->fresh()->preferred_pattern);
        $this->assertEquals($faculty->id, $f['row']->fresh()->faculty_id);
        $afterOnline = $online->fresh()->getAttributes();
        unset($beforeOnline['preferred_pattern'], $beforeOnline['updated_at'], $afterOnline['preferred_pattern'], $afterOnline['updated_at']);
        $this->assertSame($beforeOnline, $afterOnline);
        $this->assertSame([], (new RuleEngine)->validateStanding([...$f['row']->fresh()->toArray(), 'ignore_schedule_id' => $f['row']->id]));
    }

    public function test_seven_hybrid_groups_share_search_budget_and_transfer_to_sunday_without_moving_online_partners(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $f['course']->update(['units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $f['room']->update(['room_type' => 'laboratory']);
        $f['replacement']->update(['room_type' => 'laboratory']);
        $labs = [$f['replacement']];
        foreach (['LAB-3', 'LAB-4'] as $code) {
            $labs[] = Rooms::create(['room_code' => $code, 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $f['department']->id]);
        }
        for ($index = 0; $index < 20; $index++) {
            Rooms::create(['room_code' => 'LECTURE-'.$index, 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        }
        foreach ($labs as $lab) {
            $this->blockReplacementDays([...$f, 'replacement' => $lab], SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        }
        $ids = [];
        $onlineIds = [];
        $onlinePlacements = [];
        foreach (['Saturday', 'Saturday', 'Saturday', 'Saturday', 'Monday', 'Wednesday', 'Thursday'] as $index => $day) {
            $section = $f['section']->replicate()->fill(['section_name' => 'Sunday batch '.$index]);
            $section->save();
            $start = 7 + ($index < 4 ? 3 * $index : 0);
            $onlineDay = $index < 5 ? 'Tuesday' : 'Friday';
            $onlineStart = 7 + 2 * ($index < 5 ? $index : $index - 5);
            $attributes = ['section_id' => $section->id, 'is_hybrid' => true, 'split_group_id' => 'sunday-batch-'.$index,
                'preferred_pattern' => 'days:'.SchedulingPolicy::dayIndex($day).'-'.SchedulingPolicy::dayIndex($onlineDay), 'meeting_type' => 'laboratory', 'meeting_index' => 1,
                'day' => $day, 'start_time' => sprintf('%02d:00', $start), 'end_time' => sprintf('%02d:00', $start + 1), 'status' => 'faculty_assignment'];
            if ($index === 0) {
                $f['row']->update($attributes);
                $labRow = $f['row']->fresh();
            } else {
                $labRow = Schedule::create([...$f['row']->toArray(), ...$attributes]);
            }
            $online = Schedule::create([...$labRow->toArray(), 'day' => $onlineDay, 'mode' => 'online', 'room_id' => null,
                'start_time' => sprintf('%02d:00', $onlineStart), 'end_time' => sprintf('%02d:00', $onlineStart + 2), 'meeting_type' => 'lecture', 'meeting_index' => 2]);
            $ids[] = $labRow->id;
            $onlineIds[] = $online->id;
            $onlinePlacements[$online->id] = [$online->day, $online->start_time, $online->end_time];
        }
        $this->close($f);
        $plan = $this->preview($f, ['schedule_ids' => $ids, 'allow_time_changes' => true]);
        $this->assertCount(7, $plan['groups']);
        $this->assertSame([], $plan['unresolved']);
        foreach ($plan['groups'] as $group) {
            $this->assertCount(2, $group['after']);
            $physical = collect($group['after'])->firstWhere('mode', 'on-site');
            $this->assertSame('Sunday', $physical['day']);
        }
        $this->approveTransfer($f, $plan)->assertOk();
        $this->assertSame(7, Schedule::whereIn('id', $ids)->where('day', 'Sunday')->count());
        foreach (Schedule::whereIn('id', $onlineIds)->get() as $online) {
            $this->assertSame($onlinePlacements[$online->id], [$online->day, $online->start_time, $online->end_time]);
        }
    }

    private function submitForVpaa(array $f, Schedule $row): string
    {
        $plan = $this->actingAs($f['user'])->postJson('/api/room-recovery/preview', ['schedule_ids' => [$row->id]])->assertOk()->json();
        $this->assertCount(1, $plan['groups']);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->approveByDean($f, $plan);

        return $plan['plan_id'];
    }

    private function secondClosedMeeting(array $f, string $day): Schedule
    {
        $section = $f['section']->replicate()->fill(['section_name' => 'REC-2']);
        $section->save();
        $room = Rooms::create(['room_code' => 'R3', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        $row = Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $room->id, 'day' => $day]);
        $this->actingAs($f['admin'])->postJson('/api/rooms/'.$room->id.'/close', ['reason' => 'Maintenance'])->assertOk();

        return $row;
    }

    public function test_approving_one_transfer_does_not_invalidate_an_unrelated_pending_transfer(): void
    {
        $f = $this->fixture();
        $second = $this->secondClosedMeeting($f, 'Tuesday');
        $this->close($f);
        $first = $this->submitForVpaa($f, $f['row']);
        $other = $this->submitForVpaa($f, $second);
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$first.'/approve')->assertOk()->assertJsonPath('applied', true);
        $this->postJson('/api/room-recovery/plans/'.$other.'/approve')->assertOk()->assertJsonPath('applied', true);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
        $this->assertEquals($f['replacement']->id, $second->fresh()->room_id);
    }

    public function test_unrelated_activity_elsewhere_in_the_semester_does_not_block_approval(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $program = Program::create(['department_id' => $other->id, 'code' => 'OTH', 'name' => 'Other']);
        $section = Sections::create(['section_name' => 'OTH-1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $other->id,
            'program_id' => $program->id, 'semester_id' => $f['semester']->id, 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'FAR', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $other->id]);
        $foreign = Schedule::create(['semester_id' => $f['semester']->id, 'section_id' => $section->id, 'course_id' => $f['course']->id,
            'room_id' => $room->id, 'department_id' => $other->id, 'day' => 'Friday', 'start_time' => '15:00', 'end_time' => '16:00',
            'mode' => 'on-site', 'status' => 'approved_by_dean']);
        $this->close($f);
        $id = $this->submitForVpaa($f, $f['row']);
        $foreign->update(['status' => 'approved']);
        Schedule::create([...$foreign->toArray(), 'day' => 'Thursday']);
        $f['department']->forceFill(['sunday_classes_enabled' => true])->save();
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$id.'/approve')->assertOk()->assertJsonPath('applied', true);
        $this->assertEquals($f['replacement']->id, $f['row']->fresh()->room_id);
    }

    public function test_dean_cannot_forward_a_transfer_that_is_no_longer_valid(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $section = $f['section']->replicate()->fill(['section_name' => 'New booking']);
        $section->save();
        Schedule::create([...$f['row']->toArray(), 'section_id' => $section->id, 'room_id' => $f['replacement']->id]);
        $this->actingAs($f['dean'])->postJson($url.'/approve')->assertConflict()
            ->assertJsonPath('message', 'Room availability, bookings or scheduling rules changed, so the selected transfer is no longer valid. Reject this request and ask for a new proposal.');
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $plan['plan_id'], 'status' => 'pending_dean', 'dean_reviewed_at' => null]);
        $this->assertDatabaseMissing('system_notifications', ['type' => 'room_transfer_dean_approved']);
        $this->postJson($url.'/reject', ['reason' => 'Room was booked'])->assertOk();
    }

    public function test_pending_transfers_cannot_claim_the_same_destination(): void
    {
        $f = $this->fixture();
        $second = $this->secondClosedMeeting($f, 'Monday');
        $this->close($f);
        $first = $this->preview($f);
        $other = $this->preview($f, ['schedule_ids' => [$second->id]]);
        $this->assertEquals($f['replacement']->id, $other['groups'][0]['after'][0]['room_id']);
        $this->postJson('/api/room-recovery/plans/'.$first['plan_id'].'/submit', ['group_keys' => array_column($first['groups'], 'key')])->assertOk();
        $this->postJson('/api/room-recovery/plans/'.$other['plan_id'].'/submit', ['group_keys' => array_column($other['groups'], 'key')])
            ->assertConflict()->assertJsonPath('message', 'Another room transfer awaiting approval already proposes a conflicting room or time. Refresh recommendations.');
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $other['plan_id'], 'status' => 'preview']);
    }

    public function test_decisions_on_closed_requests_explain_their_status(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        $url = '/api/room-recovery/plans/'.$plan['plan_id'];
        $this->postJson($url.'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->actingAs($f['dean'])->postJson($url.'/reject', ['reason' => 'No'])->assertOk();
        $message = 'This transfer was rejected and cannot be approved. Create a new proposal.';
        $this->postJson($url.'/approve')->assertConflict()->assertJsonPath('message', $message);
        $this->actingAs($f['admin'])->postJson($url.'/approve')->assertConflict()->assertJsonPath('message', $message);
    }

    public function test_transfers_applied_before_the_approval_workflow_remain_listed(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $plan = $this->preview($f);
        DB::table('room_recovery_plans')->where('id', $plan['plan_id'])->update(['status' => 'applied', 'selected_groups' => null,
            'applied_groups' => json_encode(array_column($plan['groups'], 'key')), 'applied_at' => now(), 'submitted_at' => null]);
        $this->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers.0.status', 'applied')
            ->assertJsonCount(1, 'transfers.0.changes')->assertJsonPath('transfers.0.changes.0.after.room', 'R2');
        $this->assertNotNull($this->getJson('/api/room-recovery/transfers')->json('transfers.0.submitted_at'));
        $this->actingAs($f['admin'])->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/approve')->assertConflict()
            ->assertJsonPath('message', 'This transfer was already approved.');
    }

    public function test_requests_flag_automatic_sunday_enablement_for_reviewers(): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => false])->save();
        $this->blockReplacementDays($f, SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $this->close($f);
        $plan = $this->preview($f, ['allow_time_changes' => true]);
        $this->assertSame('Sunday', $plan['groups'][0]['after'][0]['day']);
        $this->postJson('/api/room-recovery/plans/'.$plan['plan_id'].'/submit', ['group_keys' => array_column($plan['groups'], 'key')])->assertOk();
        $this->actingAs($f['dean'])->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers.0.enables_sunday', true);
        $f['department']->forceFill(['sunday_classes_enabled' => true])->save();
        $this->getJson('/api/room-recovery/transfers')->assertOk()->assertJsonPath('transfers.0.enables_sunday', false);
    }

    public function test_expired_previews_are_pruned_and_submitted_requests_are_kept(): void
    {
        $f = $this->fixture();
        $this->close($f);
        $abandoned = $this->preview($f);
        $submitted = $this->preview($f);
        $this->postJson('/api/room-recovery/plans/'.$submitted['plan_id'].'/submit', ['group_keys' => array_column($submitted['groups'], 'key')])->assertOk();
        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [RoomRecoveryPlan::class]])->assertSuccessful();
        $this->assertDatabaseMissing('room_recovery_plans', ['id' => $abandoned['plan_id']]);
        $this->assertDatabaseHas('room_recovery_plans', ['id' => $submitted['plan_id'], 'status' => 'pending_dean']);
    }
}
