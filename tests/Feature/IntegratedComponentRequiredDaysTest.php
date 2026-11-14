<?php

namespace Tests\Feature;

use App\Jobs\GenerateYearLevelSchedulePreview;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\Families\MeetingGroupConstraints;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Generation\ComponentRequiredDays;
use App\Services\Scheduling\Generation\ScheduleRequirement;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Recommendations\GenerationAdjustmentInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Separate, run-scoped Lecture and Laboratory Required Days for Integrated
 * courses: generation, validation, review and the generated save must agree.
 */
class IntegratedComponentRequiredDaysTest extends TestCase
{
    use RefreshDatabase;

    // ---- requirements --------------------------------------------------

    public function test_requirements_carry_each_component_day_and_round_trip(): void
    {
        $f = $this->fixture();
        $requirements = $this->requirements($f, ['lecture' => 'Monday', 'laboratory' => 'Thursday']);
        $byType = collect($requirements)->keyBy('component_type');

        $this->assertSame('Monday', $byType['lecture']['required_day']);
        $this->assertSame('Thursday', $byType['laboratory']['required_day']);
        foreach ($requirements as $requirement) {
            $this->assertSame($requirement, ScheduleRequirement::fromArray($requirement)->toArray());
        }

        $unpinned = $this->requirements($f, []);
        foreach ($unpinned as $requirement) {
            $this->assertArrayNotHasKey('required_day', $requirement, 'unpinned requirements keep their old shape');
            $this->assertNull(ScheduleRequirement::fromArray($requirement)->requiredDay);
        }
    }

    // ---- generation ----------------------------------------------------

    /** @return array<string, array{0: bool, 1: array<string, string>}> */
    public static function pinnedCases(): array
    {
        return [
            'on-site lecture Mon / lab Thu' => [false, ['lecture' => 'Monday', 'laboratory' => 'Thursday']],
            'on-site lecture Thu / lab Mon' => [false, ['lecture' => 'Thursday', 'laboratory' => 'Monday']],
            'hybrid lecture Mon / lab Thu' => [true, ['lecture' => 'Monday', 'laboratory' => 'Thursday']],
            'hybrid lecture Thu / lab Mon' => [true, ['lecture' => 'Thursday', 'laboratory' => 'Monday']],
            'off-pattern pair Mon / Wed' => [false, ['lecture' => 'Wednesday', 'laboratory' => 'Monday']],
            'only the laboratory pinned' => [false, ['laboratory' => 'Friday']],
            'only the lecture pinned' => [true, ['lecture' => 'Tuesday']],
        ];
    }

    /** @dataProvider pinnedCases */
    public function test_generation_honors_each_component_day(bool $hybrid, array $pins): void
    {
        $f = $this->fixture();
        foreach ([11, 2222] as $seed) {
            $rows = $this->generate($f, $pins, $hybrid, $seed);

            $this->assertCount(2, $rows, 'the course stays Integrated with two meetings');
            $byType = collect($rows)->keyBy('meeting_type');
            $this->assertEqualsCanonicalizing(['lecture', 'laboratory'], $byType->keys()->all());
            foreach ($pins as $component => $day) {
                $this->assertSame($day, $byType[$component]['day'], "{$component} on its Required Day (seed {$seed})");
            }
            $this->assertNotSame($byType['lecture']['day'], $byType['laboratory']['day']);
            $this->assertSame(120, $this->minutes($byType['lecture']), 'lecture hours are kept');
            $this->assertSame(60, $this->minutes($byType['laboratory']), 'laboratory hours are kept');
            $this->assertSame($hybrid ? 'online' : 'on-site', $byType['lecture']['mode']);
            $this->assertSame('on-site', $byType['laboratory']['mode']);
        }
    }

    public function test_equal_component_durations_are_matched_by_type_not_position(): void
    {
        $f = $this->fixture(lectureHours: 2, labHours: 2);
        $rows = $this->generate($f, ['lecture' => 'Thursday', 'laboratory' => 'Tuesday'], false, 77);
        $byType = collect($rows)->keyBy('meeting_type');

        $this->assertSame('Thursday', $byType['lecture']['day']);
        $this->assertSame('Tuesday', $byType['laboratory']['day']);
        $this->assertSame($f['lab']->id, $byType['laboratory']['room_id']);
    }

    public function test_one_solver_does_not_reuse_a_domain_cached_for_other_days(): void
    {
        $f = $this->fixture();
        $solver = app(CspSolver::class);
        $this->app->instance(CspSolver::class, $solver);
        $solver->beginGenerationContext();

        foreach ([['Monday', 'Thursday'], ['Thursday', 'Monday'], ['Tuesday', 'Friday']] as [$lecture, $laboratory]) {
            $rows = collect($this->generate($f, ['lecture' => $lecture, 'laboratory' => $laboratory], false, 9))->keyBy('meeting_type');
            $this->assertSame($lecture, $rows['lecture']['day']);
            $this->assertSame($laboratory, $rows['laboratory']['day']);
        }
    }

    public function test_unpinned_integrated_generation_is_unchanged(): void
    {
        $f = $this->fixture();
        $rows = $this->generate($f, [], false, 1234);

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['day'], $rows[1]['day']);
    }

    public function test_an_impossible_pin_explains_the_component_instead_of_moving_it(): void
    {
        $f = $this->fixture();
        // Sunday is not a teaching day here, so the laboratory pin is unreachable.
        $f['department']->update(['sunday_classes_enabled' => false]);
        $requirements = $this->requirements($f, ['lecture' => 'Monday', 'laboratory' => 'Sunday']);

        try {
            app(CspSolver::class)->solveRanked(
                sectionId: $f['section']->id,
                courseIds: [$f['course']->id],
                maxSolutions: 1,
                selectedLectureLabCourseIds: [$f['course']->id],
                deliveryModesByCourseId: [$f['course']->id => 'on-site'],
                requirementsByCourseId: [$f['course']->id => $requirements],
                seed: 5,
            );
            $this->fail('An unreachable pin must not fall back to another day.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Sunday', $exception->getMessage());
        }
    }

    public function test_candidate_validation_rejects_missing_swapped_or_off_day_components(): void
    {
        $f = $this->fixture();
        $requirements = array_map(
            static fn (array $requirement): ScheduleRequirement => ScheduleRequirement::fromArray($requirement),
            $this->requirements($f, ['lecture' => 'Monday', 'laboratory' => 'Thursday']),
        );
        $row = fn (string $type, string $day): ScheduleRow => ScheduleRow::fromArray([
            'section_id' => $f['section']->id, 'course_id' => $f['course']->id, 'semester_id' => $f['semester']->id,
            'department_id' => $f['department']->id, 'day' => $day, 'start_time' => '08:00', 'end_time' => '09:00',
            'mode' => 'on-site', 'meeting_type' => $type, 'split_group_id' => 'g1',
        ]);
        $check = fn (array $rows): array => array_column(array_map(
            static fn ($violation): array => $violation->toArray(),
            (new MeetingGroupConstraints)->componentDayViolations(new MeetingGroup('g1', $f['section']->id, $f['course']->id, 'multi_day', $requirements, $rows), ['course_code' => 'IT 201']),
        ), 'rule_id');

        $this->assertSame([], $check([$row('lecture', 'Monday'), $row('laboratory', 'Thursday')]));
        $this->assertCount(2, $check([$row('lecture', 'Thursday'), $row('laboratory', 'Monday')]), 'swapped identity fails');
        $this->assertCount(1, $check([$row('lecture', 'Monday'), $row('laboratory', 'Friday')]), 'off-day fails');
        $this->assertNotEmpty($check([$row('lecture', 'Monday'), $row('lecture', 'Thursday')]), 'a missing component fails');
    }

    // ---- request validation (sync and queued parity) ------------------

    public function test_sync_generation_returns_pinned_rows_and_echoes_the_context(): void
    {
        $f = $this->fixture();

        $response = $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/year-level-preview', $this->payload($f, [
            'lecture' => 'Monday', 'laboratory' => 'Thursday',
        ]))->assertOk();

        $rows = collect($response->json('schedules'))->where('course_id', $f['course']->id)->keyBy('meeting_type');
        $this->assertSame('Monday', $rows['lecture']['day']);
        $this->assertSame('Thursday', $rows['laboratory']['day']);
        $this->assertSame(
            ['lecture' => 'Monday', 'laboratory' => 'Thursday'],
            $response->json('component_required_days.'.$f['section']->id.'.'.$f['course']->id),
        );
    }

    public function test_queued_generation_preserves_normalized_pins_in_the_job(): void
    {
        Queue::fake();
        $f = $this->fixture();

        $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/year-level-preview/queue', $this->payload($f, [
            'lecture' => 'Monday', 'laboratory' => '',
        ]))->assertAccepted();

        Queue::assertPushed(GenerateYearLevelSchedulePreview::class, function (GenerateYearLevelSchedulePreview $job) use ($f): bool {
            $configs = (fn () => $this->configsBySectionId)->call($job);
            $config = $configs[$f['section']->id];
            $lecture = collect($config['requirements_by_course_id'][$f['course']->id])->firstWhere('component_type', 'lecture');

            return $config[ComponentRequiredDays::KEY] === [$f['course']->id => ['lecture' => 'Monday']]
                && $lecture['required_day'] === 'Monday';
        });
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> */
    public static function invalidCases(): array
    {
        return [
            'same day' => [['lecture' => 'Monday', 'laboratory' => 'Monday'], [], 'different days'],
            'unknown component key' => [['lecture' => 'Monday', 'seminar' => 'Friday'], [], ''],
            'invalid day' => [['lecture' => 'Funday'], [], ''],
            'outside Preferred Days' => [['laboratory' => 'Friday'], ['allowed_days' => ['Monday', 'Tuesday', 'Wednesday']], 'Preferred Days'],
            'not Integrated' => [['lecture' => 'Monday'], ['selected_split_session_course_ids' => []], 'not Integrated'],
            'Sunday disabled' => [['laboratory' => 'Sunday'], [], 'Sunday classes are not enabled'],
            'one Preferred Day' => [['lecture' => 'Monday'], ['allowed_days' => ['Monday']], 'two different days'],
        ];
    }

    /** @dataProvider invalidCases */
    public function test_invalid_pins_are_rejected_the_same_way_by_sync_and_queue(array $pins, array $overrides, string $expected): void
    {
        Queue::fake();
        $f = $this->fixture();
        $payload = $this->payload($f, $pins, $overrides);

        $messages = [];
        foreach (['/api/schedule-recommendations/year-level-preview', '/api/schedule-recommendations/year-level-preview/queue'] as $url) {
            $response = $this->actingAs($f['user'])->postJson($url, $payload)->assertStatus(422);
            $messages[] = $response->json('message');
        }

        $this->assertSame($messages[0], $messages[1], 'both generation paths give the same correction');
        if ($expected !== '') {
            $this->assertStringContainsString($expected, $messages[0]);
        }
        Queue::assertNothingPushed();
    }

    public function test_pins_for_a_course_outside_the_generation_are_rejected(): void
    {
        $f = $this->fixture();
        $other = Course::create([
            'course_code' => 'IT 999', 'course_name' => 'Elsewhere', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $f['department']->id, 'status' => 'active',
        ]);
        $payload = $this->payload($f, []);
        $payload['section_configs'][0][ComponentRequiredDays::KEY] = [$other->id => ['lecture' => 'Monday']];

        $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/year-level-preview', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not part of this generation'));
    }

    public function test_a_course_wide_required_day_and_component_days_conflict(): void
    {
        $f = $this->fixture();
        $payload = $this->payload($f, ['lecture' => 'Monday']);
        $payload['rule_overrides'] = ['forced_day_rules' => [['course_id' => $f['course']->id, 'day' => 'Tuesday']]];

        $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/year-level-preview', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'course-wide Required Day'));
    }

    public function test_an_old_request_without_the_map_still_generates(): void
    {
        $f = $this->fixture();
        $payload = $this->payload($f, []);
        unset($payload['section_configs'][0][ComponentRequiredDays::KEY]);

        $response = $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/year-level-preview', $payload)->assertOk();
        $this->assertNull($response->json('component_required_days'));
        $this->assertCount(2, collect($response->json('schedules'))->where('course_id', $f['course']->id));
    }

    public function test_an_adjustment_cannot_silently_drop_integrated_for_a_pinned_course(): void
    {
        $configs = [5 => [
            'course_ids' => [9],
            'selected_split_session_course_ids' => [9],
            ComponentRequiredDays::KEY => [9 => ['lecture' => 'Monday']],
        ]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay Integrated');
        (new GenerationAdjustmentInterpreter)->apply($configs, [[
            'type' => 'disable_lecture_lab_split', 'section_id' => 5, 'course_id' => 9, 'course_code' => 'IT 201',
        ]]);
    }

    // ---- review and save ----------------------------------------------

    public function test_review_flags_off_day_components_and_fixes_keep_the_pin(): void
    {
        $f = $this->fixture();
        $rows = [
            $this->row($f, 'lecture', 'Monday', '08:00', '10:00'),
            $this->row($f, 'laboratory', 'Friday', '08:00', '09:00'),
        ];
        $context = [['section_id' => $f['section']->id, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Monday', 'laboratory' => 'Thursday']]]];

        $this->review($f, $rows)->assertOk()->assertJsonCount(0, 'issues');

        $response = $this->review($f, $rows, $context)->assertOk()->assertJsonCount(1, 'issues');
        $this->assertStringContainsString('Thursday', implode(' ', $response->json('issues.0.problems')));
        $options = $response->json('issues.0.options');
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $byType = collect($option['rows'])->keyBy('meeting_type');
            $this->assertSame('Thursday', $byType['laboratory']['day'], 'a fix never moves a pinned component');
            $this->assertSame('Monday', $byType['lecture']['day']);
        }
    }

    public function test_review_restricts_unplaced_integrated_meetings_to_their_days(): void
    {
        $f = $this->fixture();
        $unplaced = [[
            'section_id' => $f['section']->id, 'course_id' => $f['course']->id, 'reason' => 'No room.',
            'meetings' => [
                ['meeting_type' => 'lecture', 'duration_slots' => 4, 'modes' => ['on-site']],
                ['meeting_type' => 'laboratory', 'duration_slots' => 2, 'modes' => ['on-site']],
            ],
        ]];
        $context = [['section_id' => $f['section']->id, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Tuesday', 'laboratory' => 'Friday']]]];

        $options = $this->review($f, [], $context, $unplaced)->assertOk()->json('issues.0.options');
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $byType = collect($option['rows'])->keyBy('meeting_type');
            $this->assertSame('Tuesday', $byType['lecture']['day']);
            $this->assertSame('Friday', $byType['laboratory']['day']);
        }
    }

    public function test_review_rejects_a_pinned_course_the_draft_does_not_hold(): void
    {
        $f = $this->fixture();
        $context = [['section_id' => $f['section']->id, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Monday']]]];

        $this->review($f, [], $context)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not part of this generation'));
    }

    public function test_review_rejects_a_context_section_outside_the_draft(): void
    {
        $f = $this->fixture();
        $context = [['section_id' => $f['section']->id + 999, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Monday']]]];

        $this->review($f, [], $context)->assertStatus(422);
    }

    public function test_generated_save_rejects_off_day_components_atomically(): void
    {
        $f = $this->fixture();
        $context = [['section_id' => $f['section']->id, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Monday', 'laboratory' => 'Thursday']]]];
        $bad = [
            $this->row($f, 'lecture', 'Monday', '08:00', '10:00'),
            $this->row($f, 'laboratory', 'Friday', '08:00', '09:00'),
        ];

        $response = $this->save($f, $bad, $context)->assertStatus(422);
        $this->assertStringContainsString('Thursday', json_encode($response->json()));
        $this->assertSame(0, Schedule::query()->count(), 'nothing is written');

        // Without the generation context the same manual batch keeps its behavior.
        $this->save($f, $bad)->assertSuccessful();
        Schedule::query()->delete();

        $good = [
            $this->row($f, 'lecture', 'Monday', '08:00', '10:00'),
            $this->row($f, 'laboratory', 'Thursday', '08:00', '09:00'),
        ];
        $this->save($f, $good, $context)->assertSuccessful();
        $this->assertEqualsCanonicalizing(['Monday', 'Thursday'], Schedule::query()->pluck('day')->all());
    }

    public function test_generated_save_rejects_an_incomplete_group(): void
    {
        $f = $this->fixture();
        $context = [['section_id' => $f['section']->id, ComponentRequiredDays::KEY => [$f['course']->id => ['lecture' => 'Monday']]]];

        $this->save($f, [$this->row($f, 'lecture', 'Monday', '08:00', '10:00')], $context)->assertStatus(422);
        $this->assertSame(0, Schedule::query()->count());
    }

    // ---- helpers -------------------------------------------------------

    private function fixture(int $lectureHours = 2, int $labHours = 1): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id,
            'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 201', 'course_name' => 'Programming 2',
            'lecture_hours' => $lectureHours, 'lab_hours' => $labHours, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'laboratory',
            'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $lecture = Rooms::create(['room_code' => 'LEC 1', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $lab = Rooms::create(['room_code' => 'LAB 1', 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        $section->setRelation('department', $department);

        return [
            'semester' => $semester, 'department' => $department, 'section' => $section, 'course' => $course,
            'room' => $lecture, 'lab' => $lab,
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id, 'is_active' => true])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function requirements(array $f, array $pins, bool $hybrid = false): array
    {
        return app(ScheduleRequirementBuilderResolver::class)->build($f['section'], [(int) $f['course']->id], [
            'selected_split_session_course_ids' => [(int) $f['course']->id],
            'delivery_modes_by_course_id' => $hybrid ? [] : [(int) $f['course']->id => 'on-site'],
            ComponentRequiredDays::KEY => $pins === [] ? [] : [(int) $f['course']->id => $pins],
        ])[(int) $f['course']->id];
    }

    /** @return list<array<string, mixed>> */
    private function generate(array $f, array $pins, bool $hybrid, int $seed): array
    {
        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $f['section']->id,
            courseIds: [$f['course']->id],
            maxSolutions: 1,
            isHybrid: $hybrid,
            selectedLectureLabCourseIds: [$f['course']->id],
            deliveryModesByCourseId: $hybrid ? [] : [$f['course']->id => 'on-site'],
            requirementsByCourseId: [$f['course']->id => $this->requirements($f, $pins, $hybrid)],
            seed: $seed,
        );
        $this->assertNotEmpty($solutions);

        return $solutions[0]['schedules'];
    }

    private function payload(array $f, array $pins, array $overrides = []): array
    {
        return [
            'semester_id' => $f['semester']->id,
            'department_id' => $f['department']->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $f['section']->id,
                'course_ids' => [$f['course']->id],
                'selected_split_session_course_ids' => [$f['course']->id],
                'delivery_modes_by_course_id' => [$f['course']->id => 'on-site'],
                ComponentRequiredDays::KEY => [$f['course']->id => $pins],
                ...$overrides,
            ]],
        ];
    }

    private function row(array $f, string $type, string $day, string $start, string $end): array
    {
        return [
            'semester_id' => (int) $f['semester']->id, 'section_id' => (int) $f['section']->id,
            'course_id' => (int) $f['course']->id, 'department_id' => (int) $f['department']->id,
            'faculty_id' => null, 'room_id' => (int) ($type === 'laboratory' ? $f['lab']->id : $f['room']->id),
            'day' => $day, 'start_time' => $start, 'end_time' => $end, 'mode' => 'on-site', 'is_hybrid' => false,
            'split_group_id' => 'group-'.$f['course']->id, 'meeting_type' => $type,
            'meeting_index' => $type === 'lecture' ? 1 : 2,
        ];
    }

    private function review(array $f, array $rows, array $context = [], array $unplaced = [])
    {
        return $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/draft-review', [
            'semester_id' => $f['semester']->id,
            'department_id' => $f['department']->id,
            'section_ids' => [(int) $f['section']->id],
            'rows' => $rows,
            'unplaced' => $unplaced,
            ...($context === [] ? [] : ['section_configs' => $context]),
        ]);
    }

    private function save(array $f, array $rows, array $context = [])
    {
        return $this->actingAs($f['user'])->postJson('/api/schedules/batch', [
            'operations' => array_map(static fn (array $row): array => [...$row, 'status' => 'draft'], $rows),
            'replace_section_ids' => [(int) $f['section']->id],
            'replace_semester_id' => (int) $f['semester']->id,
            ...($context === [] ? [] : ['section_configs' => $context]),
        ]);
    }

    private function minutes(array $row): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr((string) $row['start_time'], 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr((string) $row['end_time'], 0, 5)));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}
