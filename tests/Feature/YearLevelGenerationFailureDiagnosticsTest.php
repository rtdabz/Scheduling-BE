<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YearLevelGenerationFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_feasibility_pre_check_blocks_before_the_search_and_reports_blocking_constraints(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        // One lecture room can offer 24 slots on each of six days (144 room-slots).
        // Four sections of seven three-hour courses need 168, so the shortfall is
        // arithmetic and must be refused without searching.
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $sections = [];
        for ($index = 1; $index <= 4; $index++) {
            $sections[] = Sections::create([
                'section_name' => "IT 1{$index}",
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        $courseIds = [];
        for ($index = 1; $index <= 7; $index++) {
            $course = Course::create([
                'course_code' => "GEC 10{$index}",
                'course_name' => "General Education {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'minor',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => null,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courseIds[] = (int) $course->id;
        }

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => $courseIds,
            ], $sections),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'year_level_generation_failed')
            ->assertJsonPath('stage', 'feasibility')
            ->assertJsonStructure([
                'message',
                'blocking_constraints' => [['code', 'message', 'suggested_action', 'context']],
                'recommendations' => [['id', 'title', 'detected_cause', 'suggested_adjustment', 'impact', 'adjustments']],
                'sections' => [['id', 'name']],
            ]);

        $this->assertContains(
            'insufficient_room_slots',
            array_column($response->json('blocking_constraints'), 'code'),
        );
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_retry_ladder_switches_the_fixed_pattern_and_reports_the_applied_adjustment(): void
    {
        ['user' => $user, 'term' => $term, 'department' => $department, 'section' => $section, 'course' => $course] = $this->patternFixture();

        $this->app->instance(CSPSolver::class, $this->patternGatedSolver('TTh', (int) $course->id, $section, (int) $department->id));

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [(int) $course->id],
                'selected_gec_course_ids' => [(int) $course->id],
                'preferred_patterns' => [(int) $course->id => 'MW'],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('applied_strategy.key', 'alternate_pattern')
            ->assertJsonPath('applied_adjustments.0.type', 'set_pattern')
            ->assertJsonPath('applied_adjustments.0.value', 'TTh')
            ->assertJsonPath('applied_adjustments.0.course_id', (int) $course->id)
            ->assertJsonPath('applied_adjustments.0.section_id', (int) $section->id);

        $outcomes = collect($response->json('generation_attempts'))->pluck('outcome', 'strategy')->all();
        $this->assertSame('failed', $outcomes['preflight_pattern'] ?? null);
        $this->assertSame('succeeded', $outcomes['alternate_pattern'] ?? null);
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_exhausted_retries_return_a_diagnostic_report_with_recommendations(): void
    {
        ['user' => $user, 'term' => $term, 'department' => $department, 'section' => $section, 'course' => $course] = $this->patternFixture();

        // No pattern the ladder can reach satisfies this solver, so every retry
        // strategy fails and the run must end in a diagnostic report.
        $this->app->instance(CSPSolver::class, $this->patternGatedSolver('unreachable', (int) $course->id, $section, (int) $department->id));

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [(int) $course->id],
                'selected_gec_course_ids' => [(int) $course->id],
                'preferred_patterns' => [(int) $course->id => 'MW'],
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'year_level_generation_failed')
            ->assertJsonPath('stage', 'search')
            ->assertJsonPath('bottleneck.type', 'fixed_pattern')
            ->assertJsonPath('bottleneck.section_id', (int) $section->id)
            ->assertJsonPath('bottleneck.course_id', (int) $course->id)
            ->assertJsonPath('bottleneck.course_code', 'GEC 101')
            ->assertJsonStructure([
                'attempts' => [['strategy', 'label', 'outcome']],
                'recommendations' => [['id', 'title', 'detected_cause', 'suggested_adjustment', 'impact', 'adjustments']],
            ]);

        $strategies = collect($response->json('attempts'))->pluck('strategy')->all();
        $this->assertContains('preflight_pattern', $strategies);
        $this->assertContains('alternate_pattern', $strategies);

        $recommendationIds = collect($response->json('recommendations'))->pluck('id')->all();
        $this->assertContains('strategy-alternate_pattern', $recommendationIds);
        $this->assertContains('advisory-resources', $recommendationIds);
        $this->assertNotEmpty($response->json('recommendations.0.adjustments'));
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_successful_baseline_generation_reports_no_applied_adjustment(): void
    {
        ['user' => $user, 'term' => $term, 'department' => $department, 'section' => $section, 'course' => $course] = $this->patternFixture();

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [(int) $course->id],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('applied_strategy', null)
            ->assertJsonPath('applied_adjustments', [])
            ->assertJsonPath('generation_attempts.0.strategy', 'baseline')
            ->assertJsonPath('generation_attempts.0.outcome', 'succeeded');
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_unsplit_laboratory_courses_are_generated_into_laboratory_rooms(): void
    {
        // Mirrors the CIT year-1 scope that produced "IT 101 requires a laboratory
        // room, but 'NEE 204' is a 'lecture' room" at save time: a laboratory-enabled
        // department, seven sections, three unsplit laboratory courses, and a room
        // mix where lecture rooms are the convenient choice.
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
            'lecture_lab_schedule_override_enabled' => true,
            'gec_split_schedule_override_enabled' => true,
        ]);
        $curriculum = Curriculum::create(['name' => 'CIT Curriculum', 'department_id' => $department->id, 'code' => 'CIT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        foreach (['IT 105', 'NEE 204'] as $code) {
            Rooms::create(['room_code' => $code, 'building' => 'NEE', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }
        foreach (['CompLab1', 'CompLab2', 'CompLab3', 'CompLab4'] as $code) {
            Rooms::create(['room_code' => $code, 'building' => 'NEE', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id, 'allow_lecture_usage' => true]);
        }

        $sections = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $suffix) {
            $sections[] = Sections::create([
                'section_name' => "BSIT 1{$suffix}",
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        $laboratoryCourseIds = [];
        foreach (['IT 101' => 'Introduction To Computing', 'IT 102' => 'Computer Programming 1', 'IT 103' => 'Integrated Applications Software'] as $code => $name) {
            $course = Course::create([
                'course_code' => $code,
                'course_name' => $name,
                'lecture_hours' => 2,
                'lab_hours' => 1,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'laboratory',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $laboratoryCourseIds[] = (int) $course->id;
        }

        $gec = Course::create([
            'course_code' => 'GEC 1',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($gec->id, ['year_level' => 1, 'semester' => 1]);

        $courseIds = [...$laboratoryCourseIds, (int) $gec->id];
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            // No selected_split_session_course_ids: the laboratory courses stay unsplit,
            // which is exactly the case that used to fall back to a lecture room.
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => $courseIds,
            ], $sections),
        ]);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));

        $lectureRoomIds = Rooms::query()->where('room_type', 'lecture')->pluck('id')->map('intval')->all();
        $rows = collect($response->json('schedules'));
        $this->assertNotEmpty($rows);

        foreach ($rows->whereIn('course_id', $laboratoryCourseIds) as $row) {
            $this->assertNotContains(
                $row['room_id'] === null ? -1 : (int) $row['room_id'],
                $lectureRoomIds,
                sprintf('An unsplit laboratory course was placed in a lecture room: %s', json_encode($row)),
            );
        }

        // Cross-check every row against the validator that runs at save time, so a
        // preview can never be offered that /schedules/batch would reject.
        $rules = app(RuleEngine::class);
        foreach ($rows as $row) {
            $violation = $rules->checkRoomTypeMatch(
                (int) $row['course_id'],
                $row['room_id'] === null ? null : (int) $row['room_id'],
                (string) ($row['mode'] ?? 'on-site'),
                $row['meeting_type'] ?? null,
            );

            $this->assertNull(
                $violation,
                sprintf('Generated row violates the room-type rule: %s | %s', json_encode($row), json_encode($violation)),
            );
        }

        $this->assertSame(0, Schedule::query()->count());
    }

    /** @return array<string, mixed> */
    private function patternFixture(): array
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'gec_split_schedule_override_enabled' => true,
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $section = Sections::create(['section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']);
        $course = Course::create([
            'course_code' => 'GEC 101',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $room = Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        return [
            'term' => $term,
            'department' => $department,
            'section' => $section,
            'course' => $course,
            'room' => $room,
            'user' => User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
        ];
    }

    /**
     * A solver that only produces a solution when the section is asked for one
     * specific fixed pattern. Everything else returns no solution with zero
     * iterations, which is how a structurally impossible pattern presents.
     */
    private function patternGatedSolver(string $requiredPattern, int $courseId, Sections $section, int $departmentId): CSPSolver
    {
        return new class($requiredPattern, $courseId, $section, $departmentId) extends CSPSolver
        {
            public function __construct(
                private readonly string $requiredPattern,
                private readonly int $gatedCourseId,
                private readonly Sections $gatedSection,
                private readonly int $gatedDepartmentId,
            ) {
                parent::__construct();
            }

            public function solveRankedFromSchema(array $input): array
            {
                $patterns = $input['preferred_patterns'] ?? [];
                $pattern = $patterns[$this->gatedCourseId] ?? $patterns[(string) $this->gatedCourseId] ?? null;

                if ((string) $pattern !== $this->requiredPattern) {
                    return [];
                }

                return [[
                    'rank' => 1,
                    'score' => 0,
                    'schedules' => array_map(fn (string $day): array => [
                        'term_id' => (int) $this->gatedSection->term_id,
                        'section_id' => (int) $this->gatedSection->id,
                        'course_id' => $this->gatedCourseId,
                        'faculty_id' => null,
                        'room_id' => null,
                        'department_id' => $this->gatedDepartmentId,
                        'day' => $day,
                        'start_time' => '07:00:00',
                        'end_time' => '08:30:00',
                        'mode' => 'online',
                        'is_hybrid' => false,
                        'preferred_pattern' => $this->requiredPattern,
                        'status' => 'draft',
                    ], ['Tuesday', 'Thursday']),
                ]];
            }

            public function iterationsUsed(): int
            {
                return 0;
            }

            public function searchLimitReached(): bool
            {
                return false;
            }

            public function departmentRoomFairness(): array
            {
                return [];
            }

            public function generationForcedDaysByCourseId(): array
            {
                return [];
            }
        };
    }
}
