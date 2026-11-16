<?php

namespace Tests\Feature;

use App\Events\UserNotificationsChanged;
use App\Jobs\DeliverSystemNotifications;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\RoomRecoveryService;
use App\Services\SystemNotificationService;
use App\Support\LiveUpdates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SystemNotificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Departments $foreignDepartment;

    private Program $program;

    private Program $otherProgram;

    private Semester $semester;

    private Rooms $room;

    private Sections $section;

    private Schedule $schedule;

    private array $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Departments::create(['department_code' => 'IT', 'department_name' => 'Information Technology']);
        $this->foreignDepartment = Departments::create(['department_code' => 'ART', 'department_name' => 'Arts']);
        $this->program = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'IT']);
        $this->otherProgram = Program::create(['department_id' => $this->department->id, 'code' => 'BSCS', 'name' => 'CS']);
        $this->semester = Semester::create(['semester' => '1st', 'academic_year' => '2026-2027', 'is_active' => true, 'is_enabled' => true]);
        $this->room = Rooms::create(['room_code' => 'IT-101', 'department_id' => $this->department->id, 'room_type' => 'lecture', 'status' => 'available']);
        $course = Course::create(['course_code' => 'IT101', 'course_name' => 'Programming', 'units' => 1, 'lecture_hours' => 1, 'lab_hours' => 0,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->department->id, 'status' => 'active']);
        $this->section = Sections::create(['section_name' => 'BSIT 1A', 'year_level' => '1', 'semester' => '1st', 'semester_id' => $this->semester->id,
            'department_id' => $this->department->id, 'program_id' => $this->program->id, 'status' => 'active']);
        $this->schedule = Schedule::create(['semester_id' => $this->semester->id, 'section_id' => $this->section->id, 'course_id' => $course->id,
            'room_id' => $this->room->id, 'department_id' => $this->department->id, 'program_id' => $this->program->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'draft']);
        foreach (['secretary', 'dean', 'vpaa', 'program_head'] as $role) {
            $this->users[$role] = User::factory()->create(['role' => $role, 'department_id' => $role === 'vpaa' ? null : $this->department->id,
                'program_id' => $role === 'program_head' ? $this->program->id : null]);
        }
        $this->users['other_head'] = User::factory()->create(['role' => 'program_head', 'department_id' => $this->department->id, 'program_id' => $this->otherProgram->id]);
        $this->users['foreign_dean'] = User::factory()->create(['role' => 'dean', 'department_id' => $this->foreignDepartment->id]);
        $this->users['foreign_director'] = User::factory()->create(['role' => 'director', 'department_id' => $this->foreignDepartment->id]);
    }

    public static function audiences(): array
    {
        return [
            'draft activity' => ['schedule_activity', [], ['secretary', 'program_head']],
            'schedule generation is excluded' => ['schedule_generated', [], []],
            'instructor' => ['instructor_assigned', [], ['secretary', 'program_head']],
            'instructors complete' => ['instructor_assignment_completed', [], ['secretary', 'program_head']],
            'delegation' => ['incoming_cross_department_courses', [], ['secretary', 'program_head']],
            'submission' => ['schedule_submitted', [], ['secretary', 'program_head', 'dean']],
            'dean return' => ['schedule_returned_by_dean', [], ['secretary', 'program_head', 'dean']],
            'dean approval' => ['schedule_approved_by_dean', [], ['secretary', 'program_head', 'dean', 'vpaa']],
            'final approval' => ['schedule_approved_by_vpaa', [], ['secretary', 'program_head', 'dean', 'vpaa']],
            'final return' => ['schedule_returned_by_vpaa', [], ['secretary', 'program_head', 'dean', 'vpaa']],
            'recall from dean' => ['schedule_withdrawn', ['withdrawal_stage' => 'dean_review'], ['secretary', 'program_head', 'dean']],
            'recall from vpaa' => ['schedule_withdrawn', ['withdrawal_stage' => 'vpaa_review'], ['secretary', 'program_head', 'dean', 'vpaa']],
            'transfer dean action' => ['room_transfer_submitted', [], ['dean']],
            'transfer final action' => ['room_transfer_dean_approved', [], ['vpaa']],
            'dean escalation' => ['schedule_attention_required', ['approval_stage' => 'dean'], ['dean']],
            'vpaa escalation' => ['schedule_attention_required', ['approval_stage' => 'vpaa'], ['vpaa']],
            'approved schedule escalation' => ['schedule_attention_required', ['approval_stage' => 'approved'], ['dean', 'vpaa']],
            'unknown events fail closed' => ['unregistered_event', [], []],
        ];
    }

    #[DataProvider('audiences')]
    public function test_delivery_and_feed_share_the_same_role_and_program_policy(string $type, array $metadata, array $expected): void
    {
        app(SystemNotificationService::class)->createForUsers(collect($this->users), $type, 'Notice', 'Action completed',
            departmentId: $this->department->id, semesterId: $this->semester->id,
            metadata: [...$metadata, 'program_ids' => [$this->program->id]]);
        foreach ($this->users as $key => $user) {
            $count = in_array($key, $expected, true) ? 1 : 0;
            $this->assertSame($count, SystemNotification::where('user_id', $user->id)->count(), $key);
            $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('unread_count', $count);
        }
    }

    public function test_list_counts_and_read_operations_reauthorize_legacy_rows(): void
    {
        $user = $this->users['dean'];
        $visible = SystemNotification::create(['user_id' => $user->id, 'department_id' => $this->department->id, 'type' => 'schedule_submitted', 'title' => 'Review', 'message' => 'Ready']);
        $wrongRole = SystemNotification::create(['user_id' => $user->id, 'department_id' => $this->department->id, 'type' => 'instructor_assigned', 'title' => 'Old noise', 'message' => 'Private']);
        $wrongDepartment = SystemNotification::create(['user_id' => $user->id, 'department_id' => $this->foreignDepartment->id, 'type' => 'schedule_submitted', 'title' => 'Foreign', 'message' => 'Private']);
        $foreign = SystemNotification::create(['user_id' => $this->users['foreign_dean']->id, 'department_id' => $this->foreignDepartment->id, 'type' => 'schedule_submitted', 'title' => 'Foreign', 'message' => 'Private']);
        $this->actingAs($user)->getJson('/api/notifications?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id)->assertJsonPath('unread_count', 1);
        foreach ([$wrongRole, $wrongDepartment, $foreign] as $notice) {
            $this->patchJson("/api/notifications/{$notice->id}/read")->assertForbidden();
        }
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->assertNotNull($visible->fresh()->read_at);
        foreach ([$wrongRole, $wrongDepartment, $foreign] as $notice) {
            $this->assertNull($notice->fresh()->read_at);
        }
        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_role_department_and_program_changes_revoke_old_notifications(): void
    {
        $service = app(SystemNotificationService::class);
        $service->notifyRoles(['secretary', 'program_head'], 'schedule_activity', 'Saved', 'Saved', departmentId: $this->department->id, metadata: ['program_id' => $this->program->id]);
        $head = $this->users['program_head'];
        $notice = SystemNotification::where('user_id', $head->id)->firstOrFail();
        $this->users['other_head']->update(['is_active' => false]);
        $head->update(['program_id' => $this->otherProgram->id]);
        $this->actingAs($head)->getJson('/api/notifications')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->patchJson("/api/notifications/{$notice->id}/read")->assertForbidden();
        $office = $this->users['secretary'];
        $office->update(['department_id' => $this->foreignDepartment->id, 'role' => 'program_head', 'program_id' => null]);
        $this->actingAs($office)->getJson('/api/notifications')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
    }

    public function test_queued_recipient_models_are_reloaded_and_checked_against_the_original_audience(): void
    {
        $stale = $this->users['secretary'];
        $this->users['vpaa']->update(['is_active' => false]);
        User::whereKey($stale->id)->update(['role' => 'vpaa']);
        app(SystemNotificationService::class)->createForUsers(collect([$stale]), 'room_request_submitted', 'Review', 'Please review',
            departmentId: $this->department->id, metadata: ['audience_roles' => ['secretary', 'director']]);
        $this->assertDatabaseCount('system_notifications', 0);
        $this->users['dean']->update(['is_active' => false]);
        app(SystemNotificationService::class)->notifyRoles(['dean'], 'schedule_submitted', 'Review', 'Ready', departmentId: $this->department->id);
        $this->assertDatabaseCount('system_notifications', 0);
    }

    public function test_equivalent_office_role_changes_keep_relevant_history_but_promotion_revokes_old_audience(): void
    {
        $office = $this->users['secretary'];
        app(SystemNotificationService::class)->notifyRoles(['secretary'], 'schedule_submitted', 'Submitted', 'Ready', departmentId: $this->department->id);
        $office->update(['role' => 'director']);
        $this->actingAs($office)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
        $this->users['dean']->update(['is_active' => false]);
        $office->update(['role' => 'dean']);
        $this->actingAs($office)->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        app(SystemNotificationService::class)->notifyRoles(['dean'], 'schedule_submitted', 'New submission', 'Needs review', departmentId: $this->department->id);
        $this->getJson('/api/notifications')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'New submission');
    }

    public function test_read_and_delivery_signals_are_private_and_only_authorized_recipients_are_pinged(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([UserNotificationsChanged::class]);
        app(LiveUpdates::class)->flush();
        app(SystemNotificationService::class)->createForUsers(collect($this->users), 'room_transfer_submitted', 'Review', 'Review', departmentId: $this->department->id);
        app(LiveUpdates::class)->flush();
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
        Event::assertDispatched(UserNotificationsChanged::class, fn ($event) => $event->userId === $this->users['dean']->id
            && $event->broadcastOn()->name === 'private-App.Models.User.'.$this->users['dean']->id && $event->broadcastWith() === []);
        $notice = SystemNotification::firstOrFail();
        $this->actingAs($this->users['dean'])->patchJson("/api/notifications/{$notice->id}/read")->assertOk();
        app(LiveUpdates::class)->flush();
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 2);
    }

    public function test_duplicate_delivery_is_suppressed_after_read_but_different_events_are_preserved(): void
    {
        $deliver = fn ($metadata, $semesterId) => app(SystemNotificationService::class)->createForUsers(collect([$this->users['dean']]),
            'schedule_submitted', 'Ready', 'Same message', departmentId: $this->department->id, semesterId: $semesterId, metadata: $metadata);
        $deliver(['event_id' => 'one'], $this->semester->id);
        $this->assertDatabaseCount('system_notifications', 1);
        $this->assertDatabaseHas('system_notifications', ['event_id' => 'one']);
        SystemNotification::firstOrFail()->update(['read_at' => now()]);
        $this->travel(5)->minutes();
        $deliver(['event_id' => 'one'], $this->semester->id);
        $this->assertDatabaseCount('system_notifications', 1);
        $deliver(['event_id' => 'two'], $this->semester->id);
        $deliver(['event_id' => 'three'], null);
        $this->assertDatabaseCount('system_notifications', 3);
        $this->actingAs($this->users['dean'])->getJson('/api/notifications?limit=1')->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 2);
        $deliver(['legacy_key' => 'one'], $this->semester->id);
        SystemNotification::latest('id')->firstOrFail()->update(['read_at' => now()]);
        $deliver(['legacy_key' => 'one'], $this->semester->id);
        $this->assertDatabaseCount('system_notifications', 4);
    }

    public function test_legacy_program_scopes_are_backfilled_without_deleting_history(): void
    {
        foreach (['program_head', 'other_head'] as $key) {
            SystemNotification::create(['user_id' => $this->users[$key]->id, 'department_id' => $this->department->id, 'type' => 'schedule_submitted',
                'title' => 'Submitted', 'message' => 'Ready', 'metadata' => ['selected_section_ids' => [$this->section->id]]]);
        }
        (require database_path('migrations/2026_11_13_120000_backfill_system_notification_audience.php'))->up();
        $this->assertDatabaseCount('system_notifications', 2);
        $this->actingAs($this->users['program_head'])->getJson('/api/notifications')->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
        $this->actingAs($this->users['other_head'])->getJson('/api/notifications')->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
    }

    public function test_database_uniqueness_protects_against_concurrent_duplicate_delivery(): void
    {
        $notice = SystemNotification::create(['user_id' => $this->users['dean']->id, 'event_id' => 'same-event',
            'department_id' => $this->department->id, 'type' => 'schedule_submitted', 'title' => 'Review', 'message' => 'Ready']);
        $this->expectException(UniqueConstraintViolationException::class);
        $notice->replicate()->save();
    }

    public function test_a_review_of_remaining_sections_does_not_notify_the_recalled_program(): void
    {
        $otherSection = Sections::create(['section_name' => 'BSCS 1A', 'year_level' => '1', 'semester' => '1st', 'semester_id' => $this->semester->id,
            'department_id' => $this->department->id, 'program_id' => $this->otherProgram->id, 'status' => 'active']);
        $submission = ScheduleSubmission::create(['department_id' => $this->department->id, 'semester_id' => $this->semester->id,
            'status' => 'partially_withdrawn', 'revision_number' => 1]);
        $submission->sections()->attach($this->section->id, ['state' => 'included']);
        $submission->sections()->attach($otherSection->id, ['state' => 'withdrawn']);
        app(SystemNotificationService::class)->notifyRoles(['secretary', 'program_head', 'dean', 'vpaa'],
            'schedule_approved_by_dean', 'Approved', 'Remaining sections approved', departmentId: $this->department->id,
            metadata: ['schedule_submission_id' => $submission->id]);
        $this->assertRecipients('schedule_approved_by_dean', ['secretary', 'program_head', 'dean', 'vpaa']);
    }

    public function test_a_delayed_room_request_job_drops_an_obsolete_approval_request(): void
    {
        $request = RoomRequest::create(['room_id' => $this->room->id, 'semester_id' => $this->semester->id,
            'owner_department_id' => $this->department->id, 'requesting_department_id' => $this->foreignDepartment->id,
            'requested_by' => $this->users['foreign_director']->id, 'status' => RoomRequest::STATUS_APPROVED, 'purpose' => 'Class']);
        $delivery = ['user_ids' => [$this->users['secretary']->id], 'type' => 'room_request_submitted', 'title' => 'Review',
            'message' => 'Review this request', 'actor_id' => null, 'department_id' => $this->department->id,
            'semester_id' => $this->semester->id, 'remarks' => null,
            'metadata' => ['requires_action' => true, 'room_request_id' => $request->id]];
        (new DeliverSystemNotifications([$delivery]))->handle(app(SystemNotificationService::class));
        $this->assertDatabaseCount('system_notifications', 0);
    }

    public function test_room_disruption_alerts_the_affected_program_and_the_current_reviewer(): void
    {
        $this->schedule->update(['status' => 'approved_by_dean']);
        DB::transaction(fn () => app(RoomRecoveryService::class)->recordImpact($this->room->id,
            Schedule::whereKey($this->schedule->id)->get(), 'room_closed', 'Maintenance', $this->users['secretary']->id));
        $this->assertRecipients('room_recovery', ['secretary', 'program_head']);
        $this->assertRecipients('schedule_attention_required', ['vpaa']);
    }

    #[DataProvider('officeRoles')]
    public function test_actual_approval_and_resubmission_workflow_scopes_each_stage(string $officeRole): void
    {
        $office = $this->users['secretary'];
        $office->update(['role' => $officeRole]);
        $scheduler = $this->users['program_head'];
        $base = "/api/departments/{$this->department->id}";
        $this->actingAs($scheduler)->postJson("{$base}/submit-schedules")->assertOk();
        $this->assertRecipients('schedule_submitted', ['secretary', 'program_head', 'dean']);
        $this->actingAs($this->users['dean'])->postJson("{$base}/return-by-dean", ['rejection_reason' => 'Move the class'])->assertOk();
        $this->assertRecipients('schedule_returned_by_dean', ['secretary', 'program_head', 'dean']);
        $this->actingAs($scheduler)->patchJson("/api/schedules/{$this->schedule->id}", ['day' => 'Tuesday'])->assertOk();
        $this->actingAs($scheduler)->postJson("{$base}/submit-schedules")->assertOk();
        $this->assertSame(2, SystemNotification::where('user_id', $this->users['dean']->id)->where('type', 'schedule_submitted')->count());
        $this->actingAs($this->users['dean'])->postJson("{$base}/approve-by-dean")->assertOk();
        $this->assertRecipients('schedule_approved_by_dean', ['secretary', 'program_head', 'dean', 'vpaa']);
        $this->actingAs($this->users['vpaa'])->postJson("{$base}/return-by-vpaa", ['rejection_reason' => 'Move again'])->assertOk();
        $this->assertRecipients('schedule_returned_by_vpaa', ['secretary', 'program_head', 'dean', 'vpaa']);
        $this->actingAs($scheduler)->patchJson("/api/schedules/{$this->schedule->id}", ['day' => 'Wednesday'])->assertOk();
        $this->actingAs($scheduler)->postJson("{$base}/submit-schedules")->assertOk();
        $this->actingAs($this->users['dean'])->postJson("{$base}/approve-by-dean")->assertOk();
        $this->actingAs($this->users['vpaa'])->postJson("{$base}/approve-by-vpaa")->assertOk();
        $this->assertRecipients('schedule_approved_by_vpaa', ['secretary', 'program_head', 'dean', 'vpaa']);
    }

    public static function officeRoles(): array
    {
        return [['secretary'], ['director']];
    }

    public function test_program_room_changes_notify_only_affected_programs_and_no_op_updates_are_quiet(): void
    {
        $third = Program::create(['department_id' => $this->department->id, 'code' => 'THIRD', 'name' => 'Third']);
        $thirdHead = User::factory()->create(['role' => 'program_head', 'department_id' => $this->department->id, 'program_id' => $third->id]);
        $this->room->forceFill(['home_program_id' => $this->program->id])->save();
        $payload = ['assignments' => [['room_id' => $this->room->id, 'program_id' => $this->otherProgram->id]]];
        $this->actingAs($this->users['secretary'])->putJson('/api/program-rooms', $payload)->assertOk();
        $this->assertRecipients('program_rooms_updated', ['secretary', 'program_head', 'other_head']);
        $this->assertSame(0, SystemNotification::where('user_id', $thirdHead->id)->count());
        $this->putJson('/api/program-rooms', $payload)->assertOk();
        $this->assertDatabaseCount('system_notifications', 3);
    }

    public function test_no_op_schedule_updates_are_quiet_and_distinct_rapid_edits_are_preserved(): void
    {
        $url = "/api/schedules/{$this->schedule->id}";
        $this->actingAs($this->users['program_head'])->patchJson($url, ['day' => 'Tuesday'])->assertOk();
        $this->assertSame(2, SystemNotification::where('type', 'schedule_activity')->count());
        $this->patchJson($url, ['day' => 'Tuesday'])->assertOk();
        $this->assertSame(2, SystemNotification::where('type', 'schedule_activity')->count());
        $this->patchJson($url, ['day' => 'Wednesday'])->assertOk();
        $this->assertSame(4, SystemNotification::where('type', 'schedule_activity')->count());
    }

    public function test_cross_department_instructor_updates_reach_only_the_responsible_programs_in_both_departments(): void
    {
        $target = Program::create(['department_id' => $this->foreignDepartment->id, 'code' => 'TEACH', 'name' => 'Teaching']);
        $this->users['teaching_head'] = User::factory()->create(['role' => 'program_head', 'department_id' => $this->foreignDepartment->id, 'program_id' => $target->id]);
        $this->schedule->course->update(['teaching_department_id' => $this->foreignDepartment->id, 'teaching_program_id' => $target->id]);
        app(SystemNotificationService::class)->notifyInstructorAssignmentProgress($this->schedule, $this->users['foreign_director']);
        $this->assertRecipients('instructor_assignment_released', ['secretary', 'program_head', 'foreign_director', 'teaching_head']);
        $this->actingAs($this->users['teaching_head'])->getJson('/api/notifications')->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1);
    }

    public function test_saved_generation_and_conditional_approval_badges_follow_the_workflow(): void
    {
        $payload = $this->schedule->only(['semester_id', 'section_id', 'course_id', 'room_id', 'department_id', 'program_id', 'day', 'start_time', 'end_time', 'mode']);
        $payload['start_time'] = '08:00';
        $payload['end_time'] = '09:00';
        $this->actingAs($this->users['program_head'])->postJson('/api/schedules/batch', ['operations' => [$payload],
            'replace_section_ids' => [$this->section->id], 'replace_semester_id' => $this->semester->id])->assertOk();
        $this->assertRecipients('schedule_generated', []);
        $this->assertSame(0, SystemNotification::count());
        Schedule::query()->update(['status' => 'conditionally_approved']);
        $this->actingAs($this->users['vpaa'])->getJson('/api/schedules/pending-department-count')->assertOk()->assertJsonPath('count', 1);
        $this->actingAs($this->users['dean'])->getJson('/api/schedules/pending-department-count')->assertOk()->assertJsonPath('count', 0);
        $this->actingAs($this->users['secretary'])->getJson('/api/schedules/pending-department-count')->assertForbidden();
    }

    private function assertRecipients(string $type, array $keys): void
    {
        $expected = array_map(fn ($key) => $this->users[$key]->id, $keys);
        $this->assertEqualsCanonicalizing($expected, SystemNotification::where('type', $type)->distinct()->pluck('user_id')->all(), $type);
    }
}
