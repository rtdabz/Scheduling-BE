<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A preferred period is a hard window about a third of the teaching day wide, so
 * a meeting that fits the day can still be unplaceable for a pinned section.
 * That used to surface as a search-stage failure blaming the section for
 * competing over rooms; the real cause is one course longer than its window.
 */
class PreferredPeriodWindowFeasibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_course_longer_than_the_period_is_refused_up_front_and_named(): void
    {
        // A six-unit practicum: 6 hours as one meeting, against a 4.5-hour window.
        [$user, $payload, $section, $course] = $this->scenario(units: 6, period: 'morning');

        $response = $this->preview($user, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'year_level_generation_failed')
            ->assertJsonPath('stage', 'feasibility')
            ->assertJsonPath('blocking_constraints.0.code', 'component_duration_exceeds_period')
            ->assertJsonPath('blocking_constraints.0.context.section_id', (int) $section->id)
            ->assertJsonPath('blocking_constraints.0.context.course_id', (int) $course->id)
            ->assertJsonPath('blocking_constraints.0.context.required_slots', 12)
            ->assertJsonPath('blocking_constraints.0.context.available_slots_in_period', 9);

        $this->assertStringContainsString(
            'Morning (7:00 AM - 11:30 AM)',
            (string) $response->json('blocking_constraints.0.message'),
        );
        // The fix that keeps the period is offered first, including the setting
        // it depends on.
        $this->assertStringContainsString(
            'Major Lecture Split Sessions',
            (string) $response->json('blocking_constraints.0.suggested_action'),
        );
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_splitting_the_course_lets_the_same_pinned_section_generate(): void
    {
        [$user, $payload, , $course] = $this->scenario(
            units: 6,
            period: 'morning',
            majorLectureSplitEnabled: true,
        );
        $payload['section_configs'][0]['selected_gec_course_ids'] = [(int) $course->id];

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'))->where('course_id', (int) $course->id)->values();
        $this->assertCount(2, $rows, 'Two halves of three hours each fit the morning window.');
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual('11:30:00', (string) $row['end_time']);
            $this->assertGreaterThanOrEqual('07:00:00', (string) $row['start_time']);
        }
    }

    public function test_a_course_that_fits_the_window_is_not_refused(): void
    {
        // Three units: a three-hour meeting inside a 4.5-hour window.
        [$user, $payload] = $this->scenario(units: 3, period: 'evening');

        $this->preview($user, $payload)->assertOk();
    }

    /**
     * The same course is only a problem for the section that is pinned. Keying
     * the check per course alone would refuse a whole run for a flexible section.
     */
    public function test_a_flexible_section_is_unaffected_by_the_window(): void
    {
        [$user, $payload] = $this->scenario(units: 6, period: null);

        $this->preview($user, $payload)->assertOk();
    }

    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/api/schedule-recommendations/year-level-preview',
            $payload,
        );
    }

    /** @return array{0: User, 1: array<string, mixed>, 2: Sections, 3: Course} */
    private function scenario(int $units, ?string $period, bool $majorLectureSplitEnabled = false): array
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'major_lecture_split_schedule_override_enabled' => $majorLectureSplitEnabled,
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $section = Sections::create([
            'section_name' => 'IT 4A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'PRAC 101',
            'course_name' => 'Practicum',
            'lecture_hours' => $units,
            'lab_hours' => 0,
            'units' => $units,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        for ($i = 1; $i <= 3; $i++) {
            Rooms::create(['room_code' => "IT 10{$i}", 'building' => 'IT', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $config = [
            'section_id' => (int) $section->id,
            'course_ids' => [(int) $course->id],
        ];
        if ($period !== null) {
            $config['preferred_period'] = $period;
        }

        return [$user, [
            'term_id' => (int) $term->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => [$config],
        ], $section, $course];
    }
}
