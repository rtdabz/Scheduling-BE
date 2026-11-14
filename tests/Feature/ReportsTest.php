<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The printed Department Schedule and Teaching Load are official records, so
 * only VPAA-approved schedules reach them, and a section prints only once every
 * one of its meetings has cleared approval.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_fully_approved_sections_are_reported(): void
    {
        $f = $this->fixture();
        $approved = $this->section($f, 'IT-1A');
        $partial = $this->section($f, 'IT-1B');
        $draft = $this->section($f, 'IT-1C');

        $this->schedule($f, $approved, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);
        $this->schedule($f, $approved, ['status' => 'faculty_assignment', 'day' => 'Tuesday']);
        $this->schedule($f, $partial, ['status' => 'approved']);
        $this->schedule($f, $partial, ['status' => 'revision', 'day' => 'Tuesday']);
        $this->schedule($f, $draft, ['status' => 'draft']);

        $this->actingAs($f['secretary'])
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.complete_section_count', 1)
            ->assertJsonPath('departments.0.instructor_count', 1)
            ->assertJsonPath('departments.0.programs.0.code', 'BSIT')
            ->assertJsonPath('departments.0.programs.0.complete_section_count', 1);

        $response = $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_name', 'IT-1A')
            ->assertJsonCount(1, 'faculties');

        $statuses = collect($response->json('schedules'))->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['faculty_assignment', 'finalized'], $statuses);
    }

    public function test_the_vpaa_account_is_returned_as_a_signatory(): void
    {
        $f = $this->fixture();
        User::factory()->create(['role' => 'vpaa', 'name' => 'Current VPAA', 'department_id' => null]);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Current VPAA', 'role' => 'vpaa']);
    }

    public function test_department_staff_cannot_print_another_department(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$other->id}")
            ->assertForbidden();
    }

    public function test_vpaa_sees_every_department(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa', 'department_id' => null]));

        $this->actingAs($vpaa)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(2, 'departments');

        $this->actingAs($vpaa)
            ->getJson("/api/reports/departments/{$other->id}")
            ->assertOk();
    }

    public function test_program_filter_limits_instructors_and_sections(): void
    {
        $f = $this->fixture();
        $otherProgram = Program::create(['department_id' => $f['department']->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $csFaculty = Faculty::create([
            'first_name' => 'Cs', 'last_name' => 'Teacher', 'employment_type' => 'full-time', 'max_units' => 18,
            'department_id' => $f['department']->id, 'program_id' => $otherProgram->id, 'status' => 'active',
        ]);
        $itSection = $this->section($f, 'IT-1A');
        $csSection = $this->section($f, 'CS-1A', $otherProgram->id);
        $this->schedule($f, $itSection, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);
        $this->schedule($f, $csSection, ['status' => 'finalized', 'faculty_id' => $csFaculty->id]);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}?program_id={$f['program']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_name', 'IT-1A')
            ->assertJsonCount(1, 'faculties')
            ->assertJsonPath('faculties.0.id', $f['faculty']->id);
    }

    public function test_program_report_includes_department_wide_instructors_teaching_its_sections(): void
    {
        $f = $this->fixture();
        $otherProgram = Program::create(['department_id' => $f['department']->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $shared = fn (string $last) => Faculty::create([
            'first_name' => 'Dept', 'last_name' => $last, 'employment_type' => 'full-time', 'max_units' => 18,
            'department_id' => $f['department']->id, 'program_id' => null, 'status' => 'active',
        ]);
        $teachesIt = $shared('Wide');
        $teachesCsOnly = $shared('Elsewhere');
        $this->schedule($f, $this->section($f, 'IT-1A'), ['status' => 'finalized', 'faculty_id' => $teachesIt->id]);
        $this->schedule($f, $this->section($f, 'CS-1A', $otherProgram->id), ['status' => 'finalized', 'faculty_id' => $teachesCsOnly->id]);
        $this->schedule($f, $this->section($f, 'IT-1B'), ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);

        $this->actingAs($f['secretary'])
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('departments.0.programs.0.code', 'BSCS')
            ->assertJsonPath('departments.0.programs.0.instructor_count', 1)
            ->assertJsonPath('departments.0.programs.1.code', 'BSIT')
            ->assertJsonPath('departments.0.programs.1.instructor_count', 2);

        $ids = collect($this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}?program_id={$f['program']->id}")
            ->assertOk()
            ->json('faculties'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$f['faculty']->id, $teachesIt->id])->sort()->values()->all(), $ids);
    }

    public function test_reports_include_every_semester(): void
    {
        $f = $this->fixture();
        $current = $this->section($f, 'IT-1A');
        $this->schedule($f, $current, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);

        $second = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $f['semester'] = $second;
        $inactive = $this->section($f, 'IT-1B');
        $this->schedule($f, $inactive, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);

        $this->actingAs($f['secretary'])
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('departments.0.complete_section_count', 2);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonPath('active_semester', null)
            ->assertJsonCount(2, 'sections')
            ->assertJsonCount(2, 'schedules')
            ->assertJsonPath('faculties.0.assigned_units', 6);
    }

    public function test_a_requested_term_scopes_counts_sections_schedules_and_loads(): void
    {
        $f = $this->fixture();
        $first = $f['semester'];
        $current = $this->section($f, 'IT-1A');
        $this->schedule($f, $current, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);

        $second = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $f['semester'] = $second;
        $later = $this->section($f, 'IT-1B');
        $this->schedule($f, $later, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);
        // Unapproved work in a third term never offers that term for reporting.
        $draftTerm = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $f['semester'] = $draftTerm;
        $this->schedule($f, $this->section($f, 'IT-1C'), ['status' => 'approved_by_dean', 'faculty_id' => $f['faculty']->id]);

        $overview = $this->actingAs($f['secretary'])
            ->getJson("/api/reports?semester_id={$second->id}")
            ->assertOk()
            ->assertJsonPath('semester.id', $second->id)
            ->assertJsonPath('departments.0.complete_section_count', 1)
            ->assertJsonPath('departments.0.instructor_count', 1);
        $this->assertSame([$second->id, $first->id], collect($overview->json('terms'))->pluck('id')->all());

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}?semester_id={$second->id}")
            ->assertOk()
            ->assertJsonPath('active_semester.id', $second->id)
            ->assertJsonPath('active_semester.semester', '2nd')
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_name', 'IT-1B')
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('faculties.0.assigned_units', 3)
            ->assertJsonCount(2, 'terms');
    }

    public function test_the_overview_includes_each_department_logo(): void
    {
        $f = $this->fixture();
        $logo = 'data:image/png;base64,iVBORw0KGgo=';
        $f['department']->update(['logo' => $logo]);

        $this->actingAs($f['secretary'])
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('departments.0.logo', $logo);
    }

    public function test_an_unknown_term_is_rejected(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])->getJson('/api/reports?semester_id=999999')->assertUnprocessable();
        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}?semester_id=999999")
            ->assertUnprocessable();
    }

    public function test_room_report_downloads_are_logged(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->postJson('/api/reports/log-download', ['report_type' => 'room', 'department_id' => $f['department']->id])
            ->assertOk();

        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'report_downloaded', 'department_id' => $f['department']->id]);
    }

    public function test_room_summary_uses_department_inventory_and_requests_in_both_directions(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $third = Departments::create(['department_name' => 'Education', 'department_code' => 'CED']);
        $otherUser = User::factory()->create(['name' => 'Business Requester', 'department_id' => $other->id]);
        $room = fn (string $code, string $type, string $status = 'available', ?int $departmentId = null) => Rooms::create([
            'room_code' => $code, 'room_type' => $type, 'status' => $status,
            'department_id' => $departmentId ?? $f['department']->id,
        ]);
        $lab = $room('IT-LAB', 'laboratory', 'not available');
        $lab->update(['building' => 'Technology Building']);
        $room('IT-CLOSED', 'lecture', 'not available');
        $room('ONLINE', 'online');
        $room('FIELD', 'field');
        $room('IT-ARCHIVED', 'lecture', 'not available')->delete();
        $room('CBA-LIVE', 'lecture', 'available', $other->id);
        Rooms::create(['room_code' => 'CAMPUS-SHARED', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => null]);
        $otherRoom = $room('CBA-101', 'lecture', 'available', $other->id);
        $oldSemester = Semester::create(['academic_year' => '2025-2026', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => false]);
        $outgoing = RoomRequest::create([
            'room_id' => $otherRoom->id, 'semester_id' => $oldSemester->id,
            'requesting_department_id' => $f['department']->id, 'owner_department_id' => $other->id,
            'requested_by' => $f['secretary']->id, 'status' => 'rejected',
        ]);
        $outgoing->windows()->createMany([
            ['day' => 'Monday', 'start_time' => '13:00:00', 'end_time' => '14:30:00'],
            ['day' => 'Monday', 'start_time' => '08:00:00', 'end_time' => '09:30:00'],
        ]);
        $incoming = RoomRequest::create([
            'room_id' => $f['room']->id, 'semester_id' => $f['semester']->id,
            'requesting_department_id' => $other->id, 'owner_department_id' => $f['department']->id,
            'requested_by' => $otherUser->id, 'status' => 'pending',
        ]);
        $incoming->windows()->create(['day' => 'Friday', 'start_time' => '14:00:00', 'end_time' => '15:30:00']);
        RoomRequest::create([
            'room_id' => $otherRoom->id, 'semester_id' => $f['semester']->id,
            'requesting_department_id' => $third->id, 'owner_department_id' => $other->id,
            'requested_by' => $otherUser->id, 'status' => 'pending',
        ]);
        // Request history keeps the recorded owner and times after a room is reassigned and archived.
        $otherRoom->update(['department_id' => $third->id]);
        $otherRoom->delete();
        $this->schedule($f, $this->section($f, 'IT-1A'), ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);

        $response = $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.status', 'available')
            ->assertJsonPath('room_summary.total_rooms', 3)
            ->assertJsonPath('room_summary.total_laboratories', 1)
            ->assertJsonPath('room_summary.unavailable_rooms', 2)
            ->assertJsonCount(3, 'room_summary.rooms')
            ->assertJsonPath('room_summary.rooms.1', [
                'id' => $lab->id,
                'room_code' => 'IT-LAB',
                'building' => 'Technology Building',
                'room_type' => 'laboratory',
                'status' => 'not available',
                'department_id' => $f['department']->id,
            ])
            ->assertJsonCount(2, 'room_summary.requests')
            ->assertJsonPath('room_summary.requests.0.id', $incoming->id)
            ->assertJsonPath('room_summary.requests.0.direction', 'received')
            ->assertJsonPath('room_summary.requests.0.owner_department', 'IT')
            ->assertJsonPath('room_summary.requests.0.requester', 'CBA')
            ->assertJsonPath('room_summary.requests.0.requested_by', 'Business Requester')
            ->assertJsonPath('room_summary.requests.0.windows', [
                ['day' => 'Friday', 'start_time' => '14:00', 'end_time' => '15:30'],
            ])
            ->assertJsonPath('room_summary.requests.1.id', $outgoing->id)
            ->assertJsonPath('room_summary.requests.1.direction', 'sent')
            ->assertJsonPath('room_summary.requests.1.room_code', 'CBA-101')
            ->assertJsonPath('room_summary.requests.1.owner_department', 'CBA')
            ->assertJsonPath('room_summary.requests.1.requester', 'IT')
            ->assertJsonPath('room_summary.requests.1.requested_by', $f['secretary']->name)
            ->assertJsonPath('room_summary.requests.1.status', 'rejected')
            ->assertJsonPath('room_summary.requests.1.windows', [
                ['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:30'],
                ['day' => 'Monday', 'start_time' => '13:00', 'end_time' => '14:30'],
            ]);
        $this->assertSame(['IT-CLOSED', 'IT-LAB', 'IT101'], collect($response->json('room_summary.rooms'))->pluck('room_code')->all());
    }

    public function test_room_summary_respects_the_program_heads_room_scope(): void
    {
        $f = $this->fixture();
        $otherProgram = Program::create(['department_id' => $f['department']->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $other = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $f['room']->forceFill(['home_program_id' => $f['program']->id])->save();
        $otherRoom = Rooms::create([
            'room_code' => 'CS-101', 'room_type' => 'lecture', 'status' => 'not available', 'department_id' => $f['department']->id,
        ]);
        $otherRoom->forceFill(['home_program_id' => $otherProgram->id])->save();
        Rooms::create([
            'room_code' => 'SHARED-LAB', 'room_type' => 'laboratory', 'status' => 'not available', 'department_id' => $f['department']->id,
        ]);
        $externalRoom = Rooms::create([
            'room_code' => 'CBA-101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $other->id,
        ]);
        foreach ([$f['room'], $otherRoom] as $room) {
            RoomRequest::create([
                'room_id' => $room->id, 'semester_id' => $f['semester']->id,
                'requesting_department_id' => $other->id, 'owner_department_id' => $f['department']->id,
                'requested_by' => $f['secretary']->id, 'status' => 'pending',
            ]);
        }
        RoomRequest::create([
            'room_id' => $externalRoom->id, 'semester_id' => $f['semester']->id,
            'requesting_department_id' => $f['department']->id, 'owner_department_id' => $other->id,
            'requested_by' => $f['secretary']->id, 'status' => 'approved',
        ]);
        $head = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'department_id' => $f['department']->id, 'program_id' => $f['program']->id,
        ]));

        $response = $this->actingAs($head)
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonPath('room_summary.total_rooms', 2)
            ->assertJsonPath('room_summary.total_laboratories', 1)
            ->assertJsonPath('room_summary.unavailable_rooms', 1)
            ->assertJsonCount(2, 'room_summary.rooms')
            ->assertJsonCount(2, 'room_summary.requests');
        $this->assertSame(['IT101', 'SHARED-LAB'], collect($response->json('room_summary.rooms'))->pluck('room_code')->all());
        $this->assertSame(['CBA-101', 'IT101'], collect($response->json('room_summary.requests'))->pluck('room_code')->all());
        $this->assertSame(['sent', 'received'], collect($response->json('room_summary.requests'))->pluck('direction')->all());
        $this->assertSame(['CBA', 'IT'], collect($response->json('room_summary.requests'))->pluck('owner_department')->all());
        $this->assertSame([[], []], collect($response->json('room_summary.requests'))->pluck('windows')->all());

        $this->actingAs($head)
            ->getJson("/api/reports/departments/{$f['department']->id}?program_id={$otherProgram->id}")
            ->assertForbidden();
    }

    public static function roomRequesterRoles(): array
    {
        return [['secretary'], ['director']];
    }

    #[DataProvider('roomRequesterRoles')]
    public function test_room_report_preserves_the_requester_after_account_changes_and_replacement(string $role): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $requester = User::factory()->create([
            'name' => 'Original Requester', 'role' => $role, 'department_id' => $other->id,
        ]);
        $attributes = [
            'room_id' => $f['room']->id, 'semester_id' => $f['semester']->id,
            'requesting_department_id' => $other->id, 'owner_department_id' => $f['department']->id,
            'requested_by' => $requester->id, 'status' => 'rejected',
        ];
        $recorded = RoomRequest::create($attributes);
        $legacyId = DB::table('room_requests')->insertGetId($attributes + [
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assertRecordedIdentity = function () use ($f, $recorded, $legacyId): void {
            $rows = collect($this->actingAs($f['secretary'])
                ->getJson("/api/reports/departments/{$f['department']->id}")
                ->assertOk()->json('room_summary.requests'))->keyBy('id');
            $this->assertSame('Original Requester', $rows[$recorded->id]['requested_by']);
            $this->assertSame('Actor unavailable', $rows[$legacyId]['requested_by']);
        };
        $assertRecordedIdentity();

        $requester->update(['name' => 'Renamed Requester', 'is_active' => false]);
        $assertRecordedIdentity();
        $requester->delete();
        $replacement = User::factory()->create([
            'name' => 'Replacement Requester', 'role' => $role, 'department_id' => $other->id,
        ]);
        $assertRecordedIdentity();

        $this->assertNotSame($requester->id, $replacement->id);
        $this->assertSame($requester->id, $recorded->fresh()->actor_snapshots['requested_by']['id']);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);

        return [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'IT101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'IT101', 'course_name' => 'Intro', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'It', 'last_name' => 'Teacher', 'employment_type' => 'full-time', 'max_units' => 18,
                'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active',
            ]),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /** @param array<string, mixed> $f */
    private function section(array $f, string $name, ?int $programId = null): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $f['department']->id, 'program_id' => $programId ?? $f['program']->id,
            'semester_id' => $f['semester']->id, 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $f */
    private function schedule(array $f, Sections $section, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
        ], $overrides));
    }
}
