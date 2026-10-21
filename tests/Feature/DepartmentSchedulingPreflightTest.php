<?php

namespace Tests\Feature;

use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Department\DepartmentSchedulingAuditService;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DepartmentSchedulingPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_generates_lecture_course_without_laboratory_room(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->previewYearLevel(
            $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ])),
            $section,
            $course,
        );

        $response->assertOk();
        $response->assertJsonMissingPath('department_profile');
    }

    public function test_preflight_uses_the_active_curriculum_period_instead_of_global_course_metadata(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $course->update([
            'year_level' => '2',
            'semester' => '2nd',
        ]);
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->previewYearLevel(
            $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ])),
            $section,
            $course,
        );

        $response->assertOk();
        $response->assertJsonMissingPath('department_profile');
    }

    public function test_lecture_course_prefers_a_classroom_over_a_laboratory(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        $lecture = Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        Rooms::create([
            'room_code' => 'BA Lab',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => true,
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->previewYearLevel(
            $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ])),
            $section,
            $course,
        );

        $response->assertOk();
        $this->assertNotEmpty($response->json('schedules'));
        foreach ($response->json('schedules') as $schedule) {
            $this->assertSame($lecture->id, $schedule['room_id']);
        }
    }

    public function test_department_generates_a_laboratory_course_without_a_profile(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $course->update([
            'course_code' => 'BA LAB 101',
            'lab_hours' => 1,
            'room_type_required' => 'laboratory',
        ]);
        $this->attachCourse($department, $course, $section);
        $room = Rooms::create([
            'room_code' => 'BA 102',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $existing = Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '07:00:00',
            'end_time' => '10:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        $response = $this->previewYearLevel(
            $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ])),
            $section,
            $course,
        );

        $response->assertOk()->assertJsonMissingPath('department_profile');
        $this->assertNotEmpty($response->json('schedules'));
        foreach ($response->json('schedules') as $row) {
            $this->assertSame($room->id, $row['room_id']);
            $this->assertSame('on-site', $row['mode']);
        }
        $this->assertDatabaseHas('schedules', ['id' => $existing->id]);
    }

    public function test_department_reports_missing_physical_rooms_without_a_profile_error(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('EDUC', 'Education');
        $this->attachCourse($department, $course, $section);

        $user = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]);
        $user->syncPermissions(['schedule.generate']);

        $response = $this->previewYearLevel($user, $section, $course);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'year_level_generation_failed')
            ->assertJsonPath('blocking_constraints.0.code', 'no_physical_rooms')
            ->assertJsonMissingPath('department_profile');
    }

    /**
     * With no lecture room a lecture-only course falls back to online
     * delivery rather than taking a laboratory it is not entitled to.
     */
    public function test_solver_moves_a_lecture_online_when_no_lecture_room_is_usable(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA Lab',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => false,
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            maxIterations: 1000,
            timeoutSeconds: 1,
        );

        $this->assertNotEmpty($solutions);
        foreach ($solutions[0]['schedules'] as $row) {
            $this->assertSame('online', $row['mode']);
            $this->assertNull($row['room_id']);
        }
    }

    public function test_a_forced_day_the_course_cannot_use_is_named_as_the_cause(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        // With Sunday classes on, a Required Day only empties the domain when
        // something else rules that day out -- here an MW meeting pattern,
        // whose candidates never fall on Sunday.
        $department->forceFill(['sunday_classes_enabled' => true])->save();
        $this->attachCourse($department, $course, $section);
        DepartmentCourseRules::put((int) $department->id, (int) $course->id, null, ['forced_day' => 'Sunday']);
        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BA 1A / BA 101 has a Required Day of Sunday, but this course cannot be scheduled on that day.');

        app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            maxIterations: 1000,
            timeoutSeconds: 1,
            preferredPatternsByCourseId: [$course->id => 'MW'],
        );
    }

    public function test_a_sunday_required_day_names_the_disabled_sunday_setting(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        DepartmentCourseRules::put((int) $department->id, (int) $course->id, null, ['forced_day' => 'Sunday']);
        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BA 1A / BA 101 can only be scheduled on Sunday, but Sunday classes are not enabled for this department.');

        app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            maxIterations: 1000,
            timeoutSeconds: 1,
        );
    }

    public function test_an_fs_pattern_places_both_meetings_on_friday_and_saturday(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 102',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            maxIterations: 1000,
            timeoutSeconds: 1,
            preferredPatternsByCourseId: [$course->id => 'FS'],
        );

        $this->assertNotEmpty($solutions);
        $days = array_column($solutions[0]['schedules'], 'day');
        sort($days);
        $this->assertSame(['Friday', 'Saturday'], $days);
    }

    public function test_department_audit_reports_course_requirements_and_room_counts(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 103',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $row = app(DepartmentSchedulingAuditService::class)->audit($department->id)[0];

        $this->assertArrayNotHasKey('profile', $row);
        $this->assertSame(1, $row['active_course_count']);
        $this->assertSame(1, $row['available_lecture_rooms']);
        $this->assertSame(0, $row['laboratory_course_count']);
    }

    public function test_an_eligible_lecture_major_can_use_a_lab_marked_for_lecture_use(): void
    {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $this->attachCourse($department, $course, $section);
        $room = Rooms::create([
            'room_code' => 'BA Lab',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => true,
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $user = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary', 'department_id' => $department->id,
        ]));

        $response = $this->previewYearLevel($user, $section, $course)->assertOk();
        $this->assertNotEmpty($response->json('schedules'));
        foreach ($response->json('schedules') as $row) {
            $this->assertSame($room->id, $row['room_id']);
            $this->assertSame('on-site', $row['mode']);
        }
    }

    public static function configurationRoomCases(): array
    {
        return [
            'lab course with a lab' => ['laboratory', false, false, true],
            'borrowed lab with a grant' => ['laboratory', true, true, true],
            'foreign lab without a grant' => ['laboratory', true, false, false],
            'classroom cannot serve a lab by default' => ['lecture', false, false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configurationRoomCases')]
    public function test_configuration_checks_course_requirements_and_room_access_even_with_legacy_profile_metadata(
        string $roomType, bool $foreign, bool $granted, bool $canGenerate,
    ): void {
        [$semester, $department, $section, $course] = $this->createBase('BA', 'Business Administration');
        $course->update(['lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $this->attachCourse($department, $course, $section);
        $room = Rooms::create([
            'room_code' => 'Room 101', 'building' => 'Building 1', 'room_type' => $roomType,
            'status' => 'available', 'department_id' => $department->id,
        ]);
        $configuration = new \App\Services\Scheduling\Domain\GenerationConfiguration(
            sectionId: $section->id, courseIds: [$course->id], allowRoomTbaFallback: false,
        );
        $payload = app(\App\Services\Scheduling\Support\SchedulingSnapshotRepository::class)
            ->captureForConfiguration($semester->id, $department->id, $configuration)->toArray();
        // Old serialized metadata cannot veto course eligibility. Foreign rooms
        // referenced by schedules in a snapshot still require an access grant.
        $payload['department_settings']['scheduling_profile'] = 'standard';
        if ($foreign) {
            $payload['rooms'][$room->id]['department_id'] = $department->id + 100;
        }
        if ($granted) {
            $payload['rooms'][$room->id]['grant_windows'] = [
                ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '17:00:00'],
            ];
        }
        $snapshot = \App\Services\Scheduling\Domain\SchedulingSnapshot::fromArray($payload);
        $result = app(\App\Services\Scheduling\Generation\ValidateGenerationConfiguration::class)
            ->validateSnapshot($configuration, $snapshot);

        $this->assertSame($canGenerate, $result->canGenerate(), json_encode($result->toArray()));
        $rules = array_column($result->toArray()['violations'], 'rule_id');
        $this->assertNotContains('department_profile_mismatch', $rules);
        if (! $canGenerate) {
            $this->assertContains('no_physical_rooms', $rules);
        }
    }

    private function previewYearLevel(User $user, Sections $section, Course $course): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'semester_id' => $section->semester_id,
            'department_id' => $section->department_id,
            'year_level' => (int) $section->year_level,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [$course->id],
                'mode' => 'on-site',
            ]],
        ]);
    }

    private function createBase(string $code, string $name): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => $name,
            'department_code' => $code,
        ]);
        // Scheduling endpoints require the department to own at least one
        // program before they will generate anything.
        $program = Program::create([
            'department_id' => $department->id,
            'code' => "BS{$code}",
            'name' => "Bachelor of Science in {$name}",
        ]);
        $section = Sections::create([
            'section_name' => "{$code} 1A",
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => "{$code} 101",
            'course_name' => 'Foundations',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        return [$semester, $department, $section, $course];
    }

    private function attachCourse(Departments $department, Course $course, Sections $section): void
    {
        $curriculum = Curriculum::create([
            'name' => "{$department->department_code} Curriculum",
            'department_id' => $department->id,
            'code' => "{$department->department_code}-2026",
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, [
            'year_level' => (int) $section->year_level,
            'semester' => 1,
        ]);
    }
}
