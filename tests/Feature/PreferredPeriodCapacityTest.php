<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A preferred period is about a third of the teaching day, and the generator
 * steps a meeting's legal start times by its own length, so a 4.5-hour window
 * admits exactly one start for a three-hour class. A pinned section therefore
 * gets one such class per day, and a curriculum with more of them than there are
 * teaching days cannot be pinned at all -- no room, split or retry changes it.
 *
 * Measured on the real BSBA first-year load (seven three-unit courses plus a
 * two-unit PATH FIT): the search stage used to spend ~8 seconds per run proving
 * this and then report only that no timetable fit the first section, hiding both
 * the reason and the fact that every pinned section was equally impossible.
 */
class PreferredPeriodCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_three_hour_courses_than_teaching_days_is_refused_up_front(): void
    {
        [$user, $payload, $sections] = $this->scenario(courseCount: 8, period: 'morning');

        $response = $this->preview($user, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('stage', 'feasibility')
            ->assertJsonPath('blocking_constraints.0.code', 'period_capacity_exceeded')
            ->assertJsonPath('blocking_constraints.0.context.section_id', (int) $sections[0]->id)
            ->assertJsonPath('blocking_constraints.0.context.meetings_per_day', 1)
            ->assertJsonPath('blocking_constraints.0.context.required_days', 8)
            ->assertJsonPath('blocking_constraints.0.context.available_days', 7);

        $this->assertStringContainsString(
            'Morning (7:00 AM - 11:30 AM)',
            (string) $response->json('blocking_constraints.0.message'),
        );
        $this->assertSame(0, Schedule::query()->count());
    }

    /**
     * Seven fit, using Sunday as the seventh day for the majors. The bound is
     * exactly tight, so this is the case that would break first if the day
     * arithmetic were made any more pessimistic.
     */
    public function test_exactly_as_many_courses_as_teaching_days_still_generates(): void
    {
        [$user, $payload] = $this->scenario(courseCount: 7, period: 'morning');

        $this->preview($user, $payload)->assertOk();
    }

    /**
     * A Split remains a two-meeting shape. When the selected period cannot
     * accommodate all of those meetings, generation returns explicit
     * recommendations instead of silently converting courses to one meeting.
     */
    public function test_splitting_the_courses_lets_the_same_pinned_section_generate(): void
    {
        [$user, $payload, , $courseIds] = $this->scenario(
            courseCount: 8,
            period: 'morning',
            majorLectureSplitEnabled: true,
        );
        $payload['section_configs'][0]['selected_gec_course_ids'] = $courseIds;

        $response = $this->preview($user, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'year_level_generation_failed')
            ->assertJsonPath('stage', 'search');

        $titles = collect($response->json('recommendations'))->pluck('title')->all();
        $this->assertContains('Recommend Regular Meeting', $titles);
        $this->assertContains('Recommend Hybrid Split', $titles);
        $this->assertSame(0, Schedule::query()->count());
    }

    /** Without a period the whole 13.5-hour day is available, so nothing binds. */
    public function test_a_flexible_section_carries_the_same_load_without_complaint(): void
    {
        [$user, $payload] = $this->scenario(courseCount: 8, period: null);

        $this->preview($user, $payload)->assertOk();
    }

    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/api/schedule-recommendations/year-level-preview',
            $payload,
        );
    }

    /** @return array{0: User, 1: array<string, mixed>, 2: list<Sections>, 3: list<int>} */
    private function scenario(
        int $courseCount,
        ?string $period,
        bool $majorLectureSplitEnabled = false,
    ): array {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'CBA',
            'major_lecture_split_schedule_override_enabled' => $majorLectureSplitEnabled,
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
        $curriculum = Curriculum::create(['name' => 'BSBA', 'department_id' => $department->id, 'code' => 'CMO17', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        $courseIds = [];
        for ($i = 1; $i <= $courseCount; $i++) {
            $course = Course::create([
                'course_code' => "BAC 1{$i}",
                'course_name' => "Course {$i}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courseIds[] = (int) $course->id;
        }

        // Generous room supply: the capacity bound is on the section's own
        // timetable, so rooms must not be what makes this fail.
        for ($i = 1; $i <= 12; $i++) {
            Rooms::create(['room_code' => "CBA 1{$i}", 'building' => 'CBA', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }

        $section = Sections::create([
            'section_name' => 'BSBA 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $config = ['section_id' => (int) $section->id, 'course_ids' => $courseIds];
        if ($period !== null) {
            $config['preferred_period'] = $period;
        }

        return [$user, [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => [$config],
        ], [$section], $courseIds];
    }
}
