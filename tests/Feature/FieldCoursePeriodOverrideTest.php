<?php

namespace Tests\Feature;

use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Field courses must end by the field end time (5:00 PM by default), so an
 * Evening section (4:00 - 8:30 PM) leaves a two-hour field course nowhere to
 * start. Configure's per-course Preferred Meeting moves that course alone to
 * an earlier period; the section's other courses stay in the Evening.
 */
class FieldCoursePeriodOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_field_course_cannot_be_placed_in_an_evening_section(): void
    {
        [$user, $payload, , $fieldCourse] = $this->scenario();

        $response = $this->preview($user, $payload);

        $this->assertNotSame(200, $response->status());
        $this->assertSame([], collect($response->json('schedules') ?? [])
            ->where('course_id', (int) $fieldCourse->id)->values()->all());
    }

    public function test_the_override_moves_only_the_field_course_out_of_the_evening(): void
    {
        [$user, $payload, $major, $fieldCourse] = $this->scenario();
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $fieldCourse->id => ['afternoon'],
        ];

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'));

        $fieldRows = $rows->where('course_id', (int) $fieldCourse->id);
        $this->assertNotEmpty($fieldRows->all());
        foreach ($fieldRows as $row) {
            $this->assertGreaterThanOrEqual('11:30:00', (string) $row['start_time']);
            $this->assertLessThanOrEqual('16:00:00', (string) $row['end_time']);
        }

        $majorRows = $rows->where('course_id', (int) $major->id);
        $this->assertNotEmpty($majorRows->all());
        foreach ($majorRows as $row) {
            $this->assertGreaterThanOrEqual('16:00:00', (string) $row['start_time'], 'The section keeps its Evening period for its other courses.');
        }
    }

    public function test_an_override_outside_the_known_periods_is_rejected(): void
    {
        [$user, $payload, , $fieldCourse] = $this->scenario();
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $fieldCourse->id => ['morning', 'midnight'],
        ];

        $this->preview($user, $payload)->assertStatus(422)
            ->assertJsonValidationErrors('section_configs.0.preferred_periods_by_course_id.'.$fieldCourse->id.'.1');
    }

    /**
     * Morning + Afternoon is one 7:00 AM - 4:00 PM window, so a six-hour
     * meeting fits it although neither 4.5-hour period can hold it alone.
     */
    public function test_ticked_periods_that_touch_form_one_window(): void
    {
        [$user, $payload, $major] = $this->scenario(majorUnits: 6);
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $major->id => ['morning', 'afternoon'],
        ];
        // Keep the field course out of the way; only the major is under test.
        $payload['section_configs'][0]['course_ids'] = [(int) $major->id];

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        foreach (collect($response->json('schedules'))->where('course_id', (int) $major->id) as $row) {
            $this->assertGreaterThanOrEqual('07:00:00', (string) $row['start_time']);
            $this->assertLessThanOrEqual('16:00:00', (string) $row['end_time']);
        }
    }

    public function test_a_course_too_long_for_its_ticked_periods_points_at_configure(): void
    {
        [$user, $payload, $major] = $this->scenario(majorUnits: 6);
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $major->id => ['morning'],
        ];

        $response = $this->preview($user, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('stage', 'feasibility')
            ->assertJsonPath('blocking_constraints.0.code', 'component_duration_exceeds_course_period');
        $this->assertStringContainsString(
            'Preferred Meeting in Configure',
            (string) $response->json('blocking_constraints.0.suggested_action'),
        );
    }

    /**
     * The user's case: Sections A, B and C are Evening, and the field course
     * is ticked Morning + Afternoon in Configure. All three share one field
     * room, so the generator has to spread them across both periods' window.
     */
    public function test_a_field_course_ticked_morning_and_afternoon_is_placed_for_every_evening_section(): void
    {
        [$user, $payload, $major, $fieldCourse] = $this->scenario(sectionNames: ['IT 1A', 'IT 1B', 'IT 1C']);
        foreach ($payload['section_configs'] as $i => $config) {
            $payload['section_configs'][$i]['preferred_periods_by_course_id'] = [
                (int) $fieldCourse->id => ['morning', 'afternoon'],
            ];
        }

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'));

        $fieldRows = $rows->where('course_id', (int) $fieldCourse->id);
        $this->assertSame(3, $fieldRows->pluck('section_id')->unique()->count(), 'Every section gets the field course.');
        foreach ($fieldRows as $row) {
            $this->assertGreaterThanOrEqual('07:00:00', (string) $row['start_time']);
            $this->assertLessThanOrEqual('16:00:00', (string) $row['end_time']);
            $this->assertLessThanOrEqual('17:00:00', (string) $row['end_time'], 'Field courses end by 5:00 PM.');
        }

        foreach ($rows->where('course_id', (int) $major->id) as $row) {
            $this->assertGreaterThanOrEqual('16:00:00', (string) $row['start_time'], 'The sections keep the Evening for their other courses.');
        }
    }

    /**
     * A five-hour field course fits neither 4.5-hour period on its own, so it
     * only generates if Morning + Afternoon act as one 7:00 AM - 4:00 PM
     * window and the meeting may run across 11:30.
     */
    public function test_a_field_course_longer_than_one_period_uses_the_joined_window(): void
    {
        [$user, $payload, , $fieldCourse] = $this->scenario(fieldUnits: 5);
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $fieldCourse->id => ['morning', 'afternoon'],
        ];

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $fieldRows = collect($response->json('schedules'))->where('course_id', (int) $fieldCourse->id);
        $this->assertNotEmpty($fieldRows->all());
        foreach ($fieldRows as $row) {
            $this->assertGreaterThanOrEqual('07:00:00', (string) $row['start_time']);
            $this->assertLessThanOrEqual('16:00:00', (string) $row['end_time']);
        }

        // Morning alone is too short, which the pre-check names.
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $fieldCourse->id => ['morning'],
        ];
        $this->preview($user, $payload)->assertStatus(422)
            ->assertJsonPath('blocking_constraints.0.code', 'component_duration_exceeds_course_period');
    }

    /** The wizard uses the queued endpoint; the ticked periods must reach the job. */
    public function test_the_queued_generator_receives_the_ticked_periods(): void
    {
        Queue::fake();
        [$user, $payload, , $fieldCourse] = $this->scenario();
        $payload['section_configs'][0]['preferred_periods_by_course_id'] = [
            (int) $fieldCourse->id => ['morning', 'afternoon'],
        ];

        $this->actingAs($user)
            ->postJson('/api/schedule-recommendations/year-level-preview/queue', $payload)
            ->assertStatus(202);

        Queue::assertPushed(GenerateYearLevelSchedulePreview::class, function (GenerateYearLevelSchedulePreview $job) use ($fieldCourse): bool {
            $config = array_values($job->configsBySectionId)[0];

            return SchedulingPolicy::coursePreferredPeriods($config, (int) $fieldCourse->id) === ['morning', 'afternoon'];
        });
    }

    public function test_the_course_periods_win_over_the_section_period(): void
    {
        $config = ['preferred_period' => 'evening', 'preferred_periods_by_course_id' => [7 => ['afternoon', 'morning']]];

        $this->assertSame(['morning', 'afternoon'], SchedulingPolicy::coursePreferredPeriods($config, 7));
        $this->assertSame(['evening'], SchedulingPolicy::coursePreferredPeriods($config, 8));
        $this->assertNull(SchedulingPolicy::coursePreferredPeriods([], 7));
        $this->assertSame(['evening'], SchedulingPolicy::coursePreferredPeriods(
            ['preferred_period' => 'evening', 'preferred_periods_by_course_id' => [7 => ['bogus']]],
            7,
        ));

        // Slots are 30 minutes from 07:00: morning [0, 9), afternoon [9, 18), evening [18, 27).
        $this->assertSame([[0, 18]], SchedulingPolicy::preferredPeriodsSlotRanges(['morning', 'afternoon']));
        $this->assertSame([[0, 9], [18, 27]], SchedulingPolicy::preferredPeriodsSlotRanges(['evening', 'morning']));
    }

    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/api/schedule-recommendations/year-level-preview',
            $payload,
        );
    }

    /** @return array{0: User, 1: array<string, mixed>, 2: Course, 3: Course} */
    /** @param  list<string>  $sectionNames */
    private function scenario(int $majorUnits = 3, int $fieldUnits = 2, array $sectionNames = ['IT 1A']): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = array_map(static fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]), $sectionNames);
        $shared = [
            'lab_hours' => 0,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];
        $major = Course::create($shared + [
            'course_code' => 'IT 101',
            'course_name' => 'Introduction to Computing',
            'lecture_hours' => $majorUnits,
            'units' => $majorUnits,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
        ]);
        $fieldCourse = Course::create($shared + [
            'course_code' => 'PATHFIT 1',
            'course_name' => 'Movement Competency Training',
            'lecture_hours' => $fieldUnits,
            'units' => $fieldUnits,
            'course_category' => 'minor',
            'room_type_required' => 'field',
        ]);
        foreach ([$major, $fieldCourse] as $course) {
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        }
        foreach ($sections as $i => $section) {
            Rooms::create(['room_code' => 'IT 10'.($i + 1), 'building' => 'IT', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }
        Rooms::create(['room_code' => 'FIELD 1', 'building' => 'Grounds', 'room_type' => 'field', 'status' => 'available', 'department_id' => $department->id]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => (int) $section->id,
                'course_ids' => [(int) $major->id, (int) $fieldCourse->id],
                'preferred_period' => 'evening',
            ], $sections),
        ], $major, $fieldCourse];
    }
}
