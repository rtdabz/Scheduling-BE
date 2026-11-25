<?php

namespace Tests\Feature;

use App\Http\Controllers\Faculty\CourseTeachingAssignmentController;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The department office (Secretary or Director) manages only the programs without an active Program
 * Head; a Program Head's program is view-only to it, and its Course Assignment is locked. Programs
 * are judged one by one, so a department with both kinds keeps full access to the first.
 *
 * CAS: Sociology (no Program Head) and Community Development (has one). CCS: another college.
 */
class ProgramHeadManagedOfficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGED = ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE;

    /** @return list<array{0: string}> */
    public static function officeRoles(): array
    {
        return [['secretary'], ['director']];
    }

    #[DataProvider('officeRoles')]
    public function test_sections_of_a_program_head_program_are_view_only(string $role): void
    {
        $f = $this->fixture($role);
        $cdSection = $this->section($f, 'CD 1A', $f['cd'], $f['cdCurriculum']);
        $socSection = $this->section($f, 'SOC 1A', $f['soc'], $f['socCurriculum']);

        $this->actingAs($f['office'])->postJson('/api/sections', $this->sectionPayload($f, 'CD 1B', $f['cd']))
            ->assertForbidden()->assertJsonPath('message', self::MANAGED);
        $this->actingAs($f['office'])->postJson('/api/sections/batch', ['sections' => [$this->sectionPayload($f, 'CD 1C', $f['cd'])]])
            ->assertForbidden();
        $this->actingAs($f['office'])->putJson("/api/sections/{$cdSection->id}", ['section_name' => 'CD 1Z'])->assertForbidden();
        $this->actingAs($f['office'])->deleteJson("/api/sections/{$cdSection->id}")->assertForbidden();
        $this->actingAs($f['office'])->postJson('/api/sections/assign-curriculum', [
            'semester_id' => $f['semester']->id, 'department_id' => $f['cas']->id, 'year_level' => 1,
            'curriculum_id' => $f['cdCurriculum']->id, 'section_ids' => [$cdSection->id],
        ])->assertForbidden();
        $this->assertSame('CD 1A', $cdSection->fresh()->section_name);

        // Sociology keeps full management, and both programs stay visible.
        $this->actingAs($f['office'])->postJson('/api/sections', $this->sectionPayload($f, 'SOC 1B', $f['soc']))->assertCreated();
        $this->actingAs($f['office'])->putJson("/api/sections/{$socSection->id}", ['section_name' => 'SOC 1Z'])->assertOk();
        $names = collect($this->actingAs($f['office'])->getJson('/api/sections')->assertOk()->json())->pluck('section_name');
        $this->assertContains('CD 1A', $names);
        $this->assertContains('SOC 1Z', $names);
    }

    #[DataProvider('officeRoles')]
    public function test_curriculum_of_a_program_head_program_is_view_only(string $role): void
    {
        $f = $this->fixture($role);
        $cd = $f['cdCurriculum'];

        $this->actingAs($f['office'])->postJson('/api/curriculum', $this->curriculumPayload('CD-2', $f['cd']))
            ->assertForbidden()->assertJsonPath('message', self::MANAGED);
        $this->actingAs($f['office'])->putJson("/api/curriculum/{$cd->id}", ['name' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('message', self::MANAGED);
        $this->actingAs($f['office'])->patchJson("/api/curriculum/{$cd->id}/status", ['status' => 'deactivated'])->assertForbidden();
        $this->actingAs($f['office'])->deleteJson("/api/curriculum/{$cd->id}")->assertForbidden();
        $this->actingAs($f['office'])->postJson("/api/curriculum/{$cd->id}/duplicate")->assertForbidden();
        $this->actingAs($f['office'])->deleteJson("/api/curriculum/{$cd->id}/courses/{$f['gec']->id}")->assertForbidden();
        // Moving an office curriculum into the Program Head's program is refused too.
        $this->actingAs($f['office'])->putJson("/api/curriculum/{$f['socCurriculum']->id}", ['program_id' => $f['cd']->id])->assertForbidden();
        $this->assertSame('active', $cd->fresh()->status);

        $this->actingAs($f['office'])->getJson("/api/curriculum/{$cd->id}")->assertOk();
        $this->actingAs($f['office'])->getJson("/api/curriculum/{$cd->id}/full")->assertOk();

        $this->actingAs($f['office'])->putJson("/api/curriculum/{$f['socCurriculum']->id}", ['name' => 'Sociology 2026'])->assertOk();
        $this->actingAs($f['office'])->postJson('/api/curriculum', $this->curriculumPayload('SOC-2', $f['soc']))->assertCreated();
        $this->actingAs($f['office'])->postJson('/api/curriculum', $this->curriculumPayload('CAS-SHARED'))->assertCreated();
    }

    #[DataProvider('officeRoles')]
    public function test_curriculum_import_into_a_program_head_program_is_refused(string $role): void
    {
        $f = $this->fixture($role);
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        $payload['metadata']['program_id'] = $f['cd']->id;

        $this->actingAs($f['office'])->postJson('/api/curriculum-imports/validate', $payload)
            ->assertForbidden()->assertJsonPath('message', self::MANAGED);

        $payload['metadata']['program_id'] = $f['soc']->id;
        $this->actingAs($f['office'])->postJson('/api/curriculum-imports/validate', $payload)->assertOk();
    }

    #[DataProvider('officeRoles')]
    public function test_courses_used_by_a_program_head_program_are_view_only(string $role): void
    {
        $f = $this->fixture($role);
        $courseMessage = ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_COURSE_MESSAGE;

        $this->actingAs($f['office'])->putJson("/api/courses/{$f['cdCourse']->id}", ['course_name' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('message', $courseMessage);
        $this->actingAs($f['office'])->deleteJson("/api/courses/{$f['cdCourse']->id}")->assertForbidden();
        // A GE course Sociology also uses is still protected: editing it would change CD's curriculum.
        $this->actingAs($f['office'])->putJson("/api/courses/{$f['gec']->id}", ['course_name' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('message', $courseMessage);
        $this->actingAs($f['office'])->postJson('/api/courses', $this->coursePayload($f, 'CD 120', $f['cd']))
            ->assertForbidden()->assertJsonPath('message', self::MANAGED);
        $this->assertSame('CD 110 Course', $f['cdCourse']->fresh()->course_name);

        $this->actingAs($f['office'])->putJson("/api/courses/{$f['socCourse']->id}", ['course_name' => 'Social Theory'])->assertOk();
        $this->actingAs($f['office'])->postJson('/api/courses', $this->coursePayload($f, 'SOC 120', $f['soc']))->assertCreated();

        $flags = collect($this->actingAs($f['office'])->getJson("/api/courses?department_id={$f['cas']->id}")->assertOk()->json())
            ->mapWithKeys(fn (array $course): array => [$course['course_code'] => $course['program_head_managed'] ?? null]);
        $this->assertTrue($flags['CD 110']);
        $this->assertTrue($flags['GEC 101']);
        $this->assertFalse($flags['SOC 110']);
    }

    #[DataProvider('officeRoles')]
    public function test_course_assignment_is_locked_for_program_head_programs(string $role): void
    {
        $f = $this->fixture($role);

        $courses = collect($this->actingAs($f['office'])->getJson('/api/course-teaching-assignments')->assertOk()->json('courses'))
            ->keyBy('course_code');
        $this->assertArrayNotHasKey('CD 110', $courses->all(), 'A course only Community Development uses must not be listed.');
        $this->assertFalse($courses['SOC 101']['program_head_managed']);
        $this->assertTrue($courses['GEC 101']['program_head_managed']);

        $target = ['teaching_department_id' => $f['ccs']->id];
        $this->actingAs($f['office'])->patchJson("/api/course-teaching-assignments/{$f['cdCourse']->id}", $target)->assertForbidden();
        $this->actingAs($f['office'])->postJson('/api/course-teaching-assignments/batch', ['course_ids' => [$f['gec']->id], ...$target])
            ->assertForbidden()
            ->assertJsonPath('program_head_managed_course_ids', [$f['gec']->id]);
        $this->actingAs($f['office'])->postJson('/api/course-teaching-assignments/batch-remove', ['course_ids' => [$f['cdCourse']->id]])->assertForbidden();
        $this->assertNull($f['gec']->fresh()->teaching_department_id);

        $this->actingAs($f['office'])->postJson('/api/course-teaching-assignments/batch', ['course_ids' => [$f['socMinor']->id], ...$target])->assertOk();
        $this->assertSame($f['ccs']->id, (int) $f['socMinor']->fresh()->teaching_department_id);
    }

    #[DataProvider('officeRoles')]
    public function test_course_assignment_closes_when_every_program_has_a_program_head(string $role): void
    {
        $f = $this->fixture($role);
        $this->user('program_head', $f['cas'], $f['soc']);

        $this->actingAs($f['office'])->getJson('/api/course-teaching-assignments')
            ->assertForbidden()
            ->assertJsonPath('reason', 'program_head_managed')
            ->assertJsonPath('message', CourseTeachingAssignmentController::OFFICE_LOCKED_MESSAGE);
        $this->actingAs($f['office'])->postJson('/api/course-teaching-assignments/batch', [
            'course_ids' => [$f['socMinor']->id], 'teaching_department_id' => $f['ccs']->id,
        ])->assertForbidden();
        $this->actingAs($f['office'])->getJson('/api/me')->assertJsonPath('can_edit_program_ids', []);
    }

    #[DataProvider('officeRoles')]
    public function test_access_follows_program_head_deactivation_reactivation_and_archival(string $role): void
    {
        $f = $this->fixture($role);
        $rename = fn () => $this->actingAs($f['office'])->putJson("/api/curriculum/{$f['cdCurriculum']->id}", ['name' => 'CD '.uniqid()]);

        $this->actingAs($f['office'])->getJson('/api/me')->assertJsonPath('can_edit_program_ids', [$f['soc']->id]);
        $rename()->assertForbidden();

        $f['cdHead']->update(['is_active' => false]);
        $this->actingAs($f['office'])->getJson('/api/me')->assertJsonPath('can_edit_program_ids', [$f['soc']->id, $f['cd']->id]);
        $rename()->assertOk();
        $this->actingAs($f['office'])->putJson("/api/courses/{$f['cdCourse']->id}", ['course_name' => 'Community Work'])->assertOk();

        $f['cdHead']->update(['is_active' => true]);
        $rename()->assertForbidden();

        $f['cdHead']->delete();
        $rename()->assertOk();
    }

    public function test_program_head_and_dean_permissions_are_unchanged(): void
    {
        $f = $this->fixture('secretary');

        $this->actingAs($f['cdHead'])->putJson("/api/curriculum/{$f['cdCurriculum']->id}", ['name' => 'Community Development 2026'])->assertOk();
        $this->actingAs($f['cdHead'])->postJson('/api/sections', $this->sectionPayload($f, 'CD 1A', $f['cd']))->assertCreated();
        // The Program Head still cannot manage the sibling program.
        $this->actingAs($f['cdHead'])->postJson('/api/sections', $this->sectionPayload($f, 'SOC 1A', $f['soc']))->assertForbidden();

        $dean = $this->user('dean', $f['cas']);
        $this->actingAs($dean)->getJson('/api/me')->assertJsonPath('can_edit_program_ids', []);
        $this->actingAs($dean)->getJson("/api/curriculum/{$f['cdCurriculum']->id}")->assertOk();
    }

    /** @return array<string, mixed> */
    private function fixture(string $officeRole): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $ccs = Departments::create(['department_name' => 'College of Computing', 'department_code' => 'CCS']);
        Program::create(['department_id' => $ccs->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $soc = Program::create(['department_id' => $cas->id, 'code' => 'ABSOC', 'name' => 'Sociology']);
        $cd = Program::create(['department_id' => $cas->id, 'code' => 'BSCD', 'name' => 'Community Development']);

        $course = fn (string $code, string $category, ?Program $program) => Course::create([
            'course_code' => $code, 'course_name' => "{$code} Course", 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => $category, 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $cas->id, 'program_id' => $program?->id, 'status' => 'active',
        ]);
        $socCourse = $course('SOC 110', 'major', $soc);
        $socMinor = $course('SOC 101', 'minor', null);
        $cdCourse = $course('CD 110', 'major', $cd);
        $gec = $course('GEC 101', 'minor', null);

        $curriculum = function (Program $program, array $courses) use ($cas): Curriculum {
            $curriculum = Curriculum::create([
                'name' => "Curriculum {$program->code}", 'code' => "CURR-{$program->code}",
                'department_id' => $cas->id, 'program_id' => $program->id,
                'effective_school_year' => '2026-2027', 'status' => 'active',
            ]);
            foreach ($courses as $c) {
                DB::table('curriculum_course')->insert(['curriculum_id' => $curriculum->id, 'course_id' => $c->id, 'year_level' => 1, 'semester' => 1]);
            }

            return $curriculum;
        };

        return [
            'semester' => $semester, 'cas' => $cas, 'ccs' => $ccs, 'soc' => $soc, 'cd' => $cd,
            'socCourse' => $socCourse, 'socMinor' => $socMinor, 'cdCourse' => $cdCourse, 'gec' => $gec,
            'socCurriculum' => $curriculum($soc, [$socCourse, $socMinor, $gec]),
            'cdCurriculum' => $curriculum($cd, [$cdCourse, $gec]),
            'office' => $this->user($officeRole, $cas),
            'cdHead' => $this->user('program_head', $cas, $cd),
        ];
    }

    private function user(string $role, Departments $department, ?Program $program = null): User
    {
        return $this->grantCapabilities(
            User::factory()->create(['role' => $role, 'department_id' => $department->id, 'program_id' => $program?->id]),
            config("capabilities.role_defaults.{$role}"),
        );
    }

    private function section(array $f, string $name, Program $program, Curriculum $curriculum): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st', 'status' => 'active',
            'department_id' => $f['cas']->id, 'program_id' => $program->id, 'curriculum_id' => $curriculum->id,
            'semester_id' => $f['semester']->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionPayload(array $f, string $name, Program $program): array
    {
        return ['section_name' => $name, 'year_level' => 1, 'department_id' => $f['cas']->id, 'program_id' => $program->id];
    }

    /** @return array<string, mixed> */
    private function curriculumPayload(string $code, ?Program $program = null): array
    {
        return ['name' => "Curriculum {$code}", 'code' => $code, 'program_id' => $program?->id, 'effective_school_year' => '2026-2027'];
    }

    /** @return array<string, mixed> */
    private function coursePayload(array $f, string $code, Program $program): array
    {
        return [
            'course_code' => $code, 'course_name' => "{$code} Course", 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $f['cas']->id, 'program_id' => $program->id,
        ];
    }
}
