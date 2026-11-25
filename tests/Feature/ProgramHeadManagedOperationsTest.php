<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumImport;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Curriculum Import, Room Requests and Reassign Room Schedule follow program ownership: the
 * department office (Secretary or Director) acts only for programs without an active Program Head,
 * and a Program Head only for their own program. Each program is judged on its own.
 *
 * Department CAS has programs A and B (or only A in the single-program case). CCS lends a room.
 */
class ProgramHeadManagedOperationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     *                                                                           office role, programs, programs that have a Program Head
     */
    public static function scenarios(): array
    {
        $cases = [];
        foreach (['secretary', 'director'] as $role) {
            $cases["{$role}: no Program Heads"] = [$role, ['A', 'B'], []];
            $cases["{$role}: one program, one Program Head"] = [$role, ['A'], ['A']];
            $cases["{$role}: multiple Program Heads"] = [$role, ['A', 'B'], ['A', 'B']];
            $cases["{$role}: mixed"] = [$role, ['A', 'B'], ['A']];
        }

        return $cases;
    }

    /**
     * @param  list<string>  $programCodes
     * @param  list<string>  $headed
     */
    #[DataProvider('scenarios')]
    public function test_the_office_acts_only_for_programs_without_a_program_head(string $role, array $programCodes, array $headed): void
    {
        $f = $this->fixture($role, $programCodes, $headed);

        foreach ($f['programs'] as $code => $program) {
            $allowed = ! in_array($code, $headed, true);

            $this->importAs($f['office'], $program)->assertStatus($allowed ? 200 : 403);
            $this->requestRoomAs($f, $f['office'], $program)->assertStatus($allowed ? 201 : 403);
            $this->previewAs($f['office'], $f['rows'][$code])->assertStatus($allowed ? 200 : 403);
            $reuse = $this->actingAs($f['office'])->postJson("/api/schedule-history/{$f['histories'][$code]->id}/reuse");
            $this->assertSame($allowed ? 201 : 403, $reuse->status(), $reuse->getContent());

            $listed = collect($this->actingAs($f['office'])->getJson("/api/room-recovery?include_schedules=1&semester_id={$f['semester']->id}")->assertOk()->json('schedules'))
                ->firstWhere('id', $f['rows'][$code]->id);
            $this->assertNotNull($listed, "The office still sees {$code}'s classes");
            $this->assertSame($allowed, $listed['can_reassign']);
        }

        $this->actingAs($f['office'])->getJson('/api/me')->assertJsonPath(
            'can_edit_program_ids',
            collect($f['programs'])->reject(fn (Program $p, string $code): bool => in_array($code, $headed, true))->pluck('id')->values()->all(),
        );
    }

    /**
     * @param  list<string>  $programCodes
     * @param  list<string>  $headed
     */
    #[DataProvider('scenarios')]
    public function test_a_program_head_acts_only_for_their_own_program(string $role, array $programCodes, array $headed): void
    {
        $f = $this->fixture($role, $programCodes, $headed);

        foreach ($f['heads'] as $code => $head) {
            foreach ($f['programs'] as $other => $program) {
                $own = $other === $code;

                // The existing import workflow always normalizes a Program Head's target to their own program.
                $this->importAs($head, $program)->assertOk()->assertJsonPath('metadata.program_id', $f['programs'][$code]->id);
                $this->requestRoomAs($f, $head, $program)->assertStatus($own ? 201 : 403);
                $this->actingAs($head)->postJson("/api/schedule-history/{$f['histories'][$other]->id}/reuse")->assertStatus($own ? 201 : 403);
                $this->assertSame($own, $this->previewAs($head, $f['rows'][$other])->status() === 200, "{$code} head reassignment for {$other}");
            }

            $plan = $this->previewAs($head, $f['rows'][$code])->assertOk()->json();
            $this->actingAs($head)->postJson("/api/room-recovery/plans/{$plan['plan_id']}/submit", ['group_keys' => array_column($plan['groups'], 'key')])
                ->assertOk()->assertJsonPath('status', 'pending_dean');
        }
    }

    public static function officeRoles(): array
    {
        return [['secretary'], ['director']];
    }

    #[DataProvider('officeRoles')]
    public function test_an_omitted_import_program_cannot_bypass_a_mixed_departments_heads(string $role): void
    {
        $f = $this->fixture($role, ['A', 'B'], ['A']);
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        unset($payload['metadata']['program_id']);
        $this->actingAs($f['office'])->postJson('/api/curriculum-imports/validate', $payload)->assertForbidden();
        $this->actingAs($f['office'])->postJson('/api/curriculum-imports', $payload + [
            'request_key' => 'unscoped-import-attempt', 'fingerprint' => str_repeat('a', 64), 'expected_settings_revision' => 1, 'confirmed' => true,
        ])->assertForbidden();
        $this->assertSame(0, CurriculumImport::count());
        $this->importAs($f['office'], $f['programs']['B'])->assertOk();
    }

    #[DataProvider('officeRoles')]
    public function test_a_legacy_department_room_request_does_not_bypass_current_program_ownership(string $role): void
    {
        $f = $this->fixture($role, ['A', 'B'], []);
        $id = $this->actingAs($f['office'])->postJson('/api/room-requests', [
            'room_id' => $f['room']->id, 'purpose' => 'Legacy department request',
            'windows' => [['day' => 'Friday', 'start_time' => '08:00', 'end_time' => '09:00']],
        ])->assertCreated()->json('data.id');
        $head = $this->user('program_head', $f['cas'], $f['programs']['A']);
        $this->actingAs($f['office'])->postJson("/api/room-requests/{$id}/cancel")->assertForbidden();
        $head->update(['is_active' => false]);
        $this->actingAs($f['office'])->postJson("/api/room-requests/{$id}/cancel")->assertOk();
    }

    #[DataProvider('officeRoles')]
    public function test_reassignment_resolves_legacy_curriculum_and_course_ownership_and_denies_ambiguous_rows(string $role): void
    {
        $f = $this->fixture($role, ['A', 'B'], ['A']);
        $row = $f['rows']['A'];
        $curriculum = Curriculum::create(['name' => 'Legacy A', 'code' => 'LEGACY-A', 'department_id' => $f['cas']->id, 'program_id' => $f['programs']['A']->id, 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $row->update(['program_id' => null, 'curriculum_id' => $curriculum->id]);
        $row->section->update(['program_id' => null, 'curriculum_id' => $curriculum->id]);
        $this->previewAs($f['office'], $row)->assertForbidden();
        $curriculum->update(['program_id' => null]);
        $this->previewAs($f['office'], $row)->assertForbidden();
        $row->course->update(['program_id' => null]);
        $this->previewAs($f['office'], $row)->assertForbidden();
        $listed = collect($this->actingAs($f['office'])->getJson('/api/room-recovery?include_schedules=1')->assertOk()->json('schedules'))->firstWhere('id', $row->id);
        $this->assertFalse($listed['can_reassign']);
        $f['heads']['A']->delete();
        $this->previewAs($f['office'], $row)->assertOk();
    }

    #[DataProvider('officeRoles')]
    public function test_permissions_follow_program_head_assignment_deactivation_and_archival(string $role): void
    {
        $f = $this->fixture($role, ['A', 'B'], []);
        $a = $f['programs']['A'];
        $mayAct = function () use ($f, $a): bool {
            $import = $this->importAs($f['office'], $a)->status() === 200;
            $preview = $this->previewAs($f['office'], $f['rows']['A'])->status() === 200;
            $this->assertSame($import, $preview, 'Import and reassignment must agree.');

            return $import;
        };

        $this->assertTrue($mayAct());

        $head = $this->user('program_head', $f['cas'], $a);
        $this->assertFalse($mayAct(), 'Assigning a Program Head takes the program from the office.');
        $this->assertSame(403, $this->requestRoomAs($f, $f['office'], $a)->status());

        $head->update(['is_active' => false]);
        $this->assertTrue($mayAct(), 'A deactivated Program Head returns the program to the office.');

        $head->update(['is_active' => true]);
        $this->assertFalse($mayAct());

        $head->delete();
        $this->assertTrue($mayAct(), 'An archived Program Head returns the program to the office.');
        $this->assertSame(201, $this->requestRoomAs($f, $f['office'], $a)->status());
    }

    #[DataProvider('officeRoles')]
    public function test_direct_commits_and_both_reassignment_routes_recheck_program_ownership(string $role): void
    {
        $f = $this->fixture($role, ['A', 'B'], []);
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        $payload['metadata']['program_id'] = $f['programs']['A']->id;
        $payload['groups'] = [];
        $payload['rows'] = [[
            'row_id' => 'new-course', 'year_level' => 1, 'semester' => 1,
            'code' => 'IMPORT101', 'title' => 'Imported course', 'lecture_units' => 1,
            'lab_units' => 0, 'total_units' => 1, 'category' => 'major',
        ]];
        $validation = $this->actingAs($f['office'])->postJson('/api/curriculum-imports/validate', $payload)
            ->assertOk()->assertJsonPath('valid', true);
        $commit = $payload + [
            'request_key' => 'ownership-change-import', 'fingerprint' => $validation->json('fingerprint'),
            'expected_settings_revision' => $validation->json('settings_revision'), 'confirmed' => true,
        ];
        $plan = $this->previewAs($f['office'], $f['rows']['A'])->assertOk()->json();
        $this->assertNotEmpty($plan['groups']);
        $selected = ['group_keys' => array_column($plan['groups'], 'key')];
        $pending = $this->requestRoomAs($f, $f['office'], $f['programs']['A'])->assertCreated()->json('data.id');

        $head = $this->user('program_head', $f['cas'], $f['programs']['A']);
        $before = $f['rows']['A']->fresh()->getAttributes();
        $this->actingAs($f['office'])->postJson('/api/curriculum-imports', $commit)->assertForbidden();
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, CurriculumImport::count());
        foreach (['apply', 'submit'] as $route) {
            $this->actingAs($f['office'])->postJson("/api/room-recovery/plans/{$plan['plan_id']}/{$route}", $selected)->assertForbidden();
        }
        $this->assertSame($before, $f['rows']['A']->fresh()->getAttributes());
        $this->actingAs($f['office'])->postJson("/api/room-requests/{$pending}/cancel")->assertForbidden();
        $this->requestRoomAs($f, $f['office'], $f['programs']['B'])->assertCreated();
        $this->actingAs($f['office'])->postJson('/api/room-requests', [
            'room_id' => $f['room']->id, 'purpose' => 'Department request',
            'windows' => [['day' => 'Friday', 'start_time' => '08:00', 'end_time' => '09:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('program_id');

        $head->update(['is_active' => false]);
        $this->actingAs($f['office'])->postJson('/api/curriculum-imports', $commit)->assertCreated();
        $this->actingAs($f['office'])->postJson("/api/room-requests/{$pending}/cancel")->assertOk();
        $this->actingAs($f['office'])->postJson("/api/room-recovery/plans/{$plan['plan_id']}/submit", $selected)
            ->assertOk()->assertJsonPath('status', 'pending_dean');
        $this->actingAs($f['dean'])->postJson("/api/room-recovery/plans/{$plan['plan_id']}/approve")
            ->assertOk()->assertJsonPath('status', 'pending_vpaa');
        $this->actingAs($f['vpaa'])->postJson("/api/room-recovery/plans/{$plan['plan_id']}/approve")
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('applied', true);
    }

    public function test_dean_and_vpaa_access_is_unchanged(): void
    {
        $f = $this->fixture('secretary', ['A', 'B'], ['A']);
        $dean = $f['dean'];
        $vpaa = $f['vpaa'];

        foreach ([$dean, $vpaa] as $reviewer) {
            $this->importAs($reviewer, $f['programs']['B'])->assertForbidden();
            $this->requestRoomAs($f, $reviewer, $f['programs']['B'])->assertForbidden();
            $this->previewAs($reviewer, $f['rows']['B'])->assertForbidden();
            $this->actingAs($reviewer)->postJson("/api/schedule-history/{$f['histories']['B']->id}/reuse")->assertForbidden();
        }
        $this->actingAs($dean)->getJson('/api/room-recovery')->assertOk();
    }

    /**
     * @param  list<string>  $programCodes
     * @param  list<string>  $headed
     * @return array<string, mixed>
     */
    private function fixture(string $officeRole, array $programCodes, array $headed): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $ccs = Departments::create(['department_name' => 'College of Computing', 'department_code' => 'CCS']);
        Program::create(['department_id' => $ccs->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $closed = Rooms::create(['room_code' => 'CAS-1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $cas->id]);
        $replacement = Rooms::create(['room_code' => 'CAS-2', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $cas->id]);
        $reuseRoom = Rooms::create(['room_code' => 'REUSE-SHARED', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => null]);
        $lendable = Rooms::create(['room_code' => 'CCS-1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $ccs->id]);

        $programs = [];
        $heads = [];
        $rows = [];
        $histories = [];
        $hour = 7;
        foreach ($programCodes as $code) {
            $program = Program::create(['department_id' => $cas->id, 'code' => "P{$code}", 'name' => "Program {$code}"]);
            $section = Sections::create(['section_name' => "{$code}-1", 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
            $course = Course::create(['course_code' => "{$code}101", 'course_name' => "Course {$code}", 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas->id, 'program_id' => $program->id, 'status' => 'active']);
            $hour++;
            $rows[$code] = Schedule::create(['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id, 'program_id' => $program->id, 'room_id' => $closed->id, 'department_id' => $cas->id, 'day' => 'Tuesday', 'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:00', $hour + 1), 'mode' => 'on-site', 'status' => 'faculty_assignment']);
            $programs[$code] = $program;
            $reuseSection = Sections::create(['section_name' => "{$code}-reuse", 'year_level' => '1', 'semester' => '1st', 'department_id' => $cas->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
            $histories[$code] = ScheduleHistoryVersion::create(['semester_id' => $semester->id, 'department_id' => $cas->id, 'action' => 'schedule_approved_by_vpaa', 'source' => 'department_workflow']);
            ScheduleHistoryItem::create(['history_version_id' => $histories[$code]->id, 'original_schedule_id' => null,
                'after_snapshot' => ['section_id' => $reuseSection->id, 'course_id' => $course->id, 'department_id' => $cas->id, 'program_id' => $program->id,
                    'room_id' => $reuseRoom->id, 'day' => 'Wednesday', 'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:00', $hour + 1), 'mode' => 'on-site'],
                'snapshot_metadata' => ['section_name' => $reuseSection->section_name, 'section_year_level' => 1],
            ]);
            if (in_array($code, $headed, true)) {
                $heads[$code] = $this->user('program_head', $cas, $program);
            }
        }

        $vpaa = $this->user('vpaa', null);
        $dean = $this->user('dean', $cas);
        $this->actingAs($vpaa)->postJson("/api/rooms/{$closed->id}/close", ['reason' => 'Maintenance'])->assertOk();
        $this->user('secretary', $ccs);

        return [
            'semester' => $semester, 'cas' => $cas, 'room' => $lendable, 'dean' => $dean, 'vpaa' => $vpaa, 'programs' => $programs, 'heads' => $heads, 'histories' => $histories,
            'rows' => array_map(fn (Schedule $row): Schedule => $row->fresh(), $rows),
            'office' => $this->user($officeRole, $cas),
        ];
    }

    private function user(string $role, ?Departments $department, ?Program $program = null): User
    {
        return $this->grantCapabilities(
            User::factory()->create(['role' => $role, 'department_id' => $department?->id, 'program_id' => $program?->id]),
            config("capabilities.role_defaults.{$role}"),
        );
    }

    private function importAs(User $user, Program $program): TestResponse
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        $payload['metadata']['program_id'] = $program->id;

        return $this->actingAs($user)->postJson('/api/curriculum-imports/validate', $payload);
    }

    private function requestRoomAs(array $f, User $user, Program $program): TestResponse
    {
        static $hour = 6;
        $hour++;

        return $this->actingAs($user)->postJson('/api/room-requests', [
            'room_id' => $f['room']->id, 'semester_id' => $f['semester']->id, 'purpose' => 'Lecture classes', 'program_id' => $program->id,
            'windows' => [['day' => 'Monday', 'start_time' => sprintf('%02d:00', $hour % 12 + 7), 'end_time' => sprintf('%02d:00', $hour % 12 + 8)]],
        ]);
    }

    private function previewAs(User $user, Schedule $row): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/room-recovery/preview', ['schedule_ids' => [$row->id]]);
    }
}
