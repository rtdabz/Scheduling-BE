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
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Generation reports every course that would not fit instead of stopping at
 * the first, and the draft review re-checks the whole unsaved timetable,
 * offering ranked fixes for what is left.
 */
class GenerationDraftReviewTest extends TestCase
{
    use RefreshDatabase;

    public static function unplacedRequirements(): array
    {
        return ['even total' => [3, 2, 3, false], 'off-grid halves' => [2.5, 1, 5, false], 'request-local run' => [3, 1, 6, true]];
    }

    #[DataProvider('unplacedRequirements')]
    public function test_a_run_without_a_complete_timetable_returns_a_draft_with_the_courses_left_out(float $units, int $meetingCount, int $meetingSlots, bool $consecutive): void
    {
        $fixture = $this->fixture();
        ['section' => $section, 'blocked' => $blocked, 'placed' => $placed] = $fixture;
        $blocked->update(['units' => $units]);

        // Every search that includes GEC 101 dead-ends on it; without it the
        // section solves.
        $this->app->instance(CspSolver::class, new class((int) $blocked->id, (int) $placed->id, $section, (int) $fixture['department']->id, (int) $fixture['room']->id) extends CspSolver
        {
            public function __construct(
                private readonly int $blockedId,
                private readonly int $placedId,
                private readonly Sections $section,
                private readonly int $departmentId,
                private readonly int $roomId,
            ) {}

            public function solveRankedFromSchema(array $input): array
            {
                if (in_array($this->blockedId, array_map('intval', $input['course_ids'] ?? []), true)) {
                    return [];
                }

                return [[
                    'rank' => 1,
                    'score' => 0,
                    'schedules' => array_map(fn (string $day): array => [
                        'semester_id' => (int) $this->section->semester_id,
                        'section_id' => (int) $this->section->id,
                        'course_id' => $this->placedId,
                        'faculty_id' => null,
                        'room_id' => $this->roomId,
                        'department_id' => $this->departmentId,
                        'day' => $day,
                        'start_time' => '08:00:00',
                        'end_time' => '09:30:00',
                        'mode' => 'on-site',
                        'is_hybrid' => false,
                        'status' => 'draft',
                    ], ['Monday', 'Wednesday']),
                ]];
            }

            public function deadEndsByCourseId(): array
            {
                return [$this->blockedId => 4];
            }

            public function iterationsUsed(): int
            {
                return 10;
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
        });

        $response = $this->actingAs($fixture['user'])->postJson('/api/schedule-recommendations/year-level-preview', [
            'semester_id' => $fixture['semester']->id,
            'department_id' => $fixture['department']->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => $section->id,
                'course_ids' => [(int) $blocked->id, (int) $placed->id],
                'selected_gec_course_ids' => $consecutive ? [] : [(int) $blocked->id],
            ]],
            ...($consecutive ? ['rule_overrides' => ['consecutive_day_rules' => [[
                'course_id' => $blocked->id, 'section_id' => $section->id, 'day_count' => 3,
                'meeting_days' => ['Tuesday', 'Thursday', 'Friday'],
            ]]]] : []),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonCount(1, 'unplaced_courses')
            ->assertJsonPath('unplaced_courses.0.course_id', (int) $blocked->id)
            ->assertJsonPath('unplaced_courses.0.course_code', 'GEC 101')
            ->assertJsonPath('unplaced_courses.0.section_id', (int) $section->id);
        // Odd totals remain unresolved; two floor-rounded halves would lose time.
        $response->assertJsonPath('unplaced_courses.0.shape', $consecutive ? null : 'split')
            ->assertJsonCount($meetingCount, 'unplaced_courses.0.meetings')
            ->assertJsonPath('unplaced_courses.0.meetings.0.duration_slots', $meetingSlots);
        if ($consecutive) {
            $response->assertJsonPath('unplaced_courses.0.consecutive_rule.day_count', 3)
                ->assertJsonPath('unplaced_courses.0.consecutive_rule.meeting_days', ['Tuesday', 'Thursday', 'Friday']);
            $this->assertDatabaseCount('department_course_rules', 0);
            $options = $this->review($fixture, $response->json('schedules'), $response->json('unplaced_courses'))
                ->assertOk()->json('issues.0.options');
            $this->assertNotEmpty($options);
            foreach ($options as $option) {
                $this->assertSame(['Tuesday', 'Thursday', 'Friday'], array_column($option['rows'], 'day'));
                $this->assertNull($option['label']);
            }
        } elseif ($meetingCount === 1) {
            $this->review($fixture, $response->json('schedules'), $response->json('unplaced_courses'))
                ->assertOk()->assertJsonPath('issues.0.options', []);
        }
        $this->assertNotEmpty($response->json('unplaced_courses.0.reason'));
        $this->assertSame(
            [(int) $placed->id],
            array_values(array_unique(array_map('intval', array_column($response->json('schedules'), 'course_id')))),
        );
    }

    public function test_review_offers_ranked_placements_for_an_unplaced_course_and_drops_it_once_placed(): void
    {
        $fixture = $this->fixture();
        $draft = $this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30'], ['Wednesday', '08:00', '09:30']]);
        $unplaced = [[
            'section_id' => (int) $fixture['section']->id,
            'course_id' => (int) $fixture['blocked']->id,
            'reason' => 'No free time and room fits it.',
            'meetings' => [
                ['duration_slots' => 3, 'meeting_type' => null, 'modes' => ['on-site']],
                ['duration_slots' => 3, 'meeting_type' => null, 'modes' => ['on-site']],
            ],
        ]];

        $response = $this->review($fixture, $draft, $unplaced)->assertOk();

        $response->assertJsonCount(1, 'issues')
            ->assertJsonPath('issues.0.kind', 'unplaced')
            ->assertJsonPath('issues.0.course_code', 'GEC 101');
        $options = $response->json('issues.0.options');
        $this->assertGreaterThanOrEqual(2, count($options));
        $this->assertLessThanOrEqual(5, count($options));
        foreach ($options as $option) {
            $this->assertCount(2, $option['rows']);
            $this->assertNotSame($option['rows'][0]['day'], $option['rows'][1]['day'], 'Split meetings must use two days.');
            $this->assertNotEmpty($option['summary']);
            foreach ($option['rows'] as $row) {
                $this->assertFalse(
                    in_array($row['day'], ['Monday', 'Wednesday'], true) && $row['start_time'] < '09:30' && $row['end_time'] > '08:00',
                    'An option may not overlap the section\'s other class.',
                );
            }
        }

        // Weekdays before Saturday, Saturday before Sunday.
        $tiers = array_map(static fn (array $option): int => max(array_map(
            static fn (array $row): int => match ($row['day']) { 'Saturday' => 2, 'Sunday' => 3, default => 1 },
            $option['rows'],
        )), $options);
        $sorted = $tiers;
        sort($sorted);
        $this->assertSame($sorted, $tiers);

        // Applying the first option and reviewing again: nothing is left.
        $this->review($fixture, [...$draft, ...$options[0]['rows']], $unplaced)
            ->assertOk()
            ->assertJsonCount(0, 'issues');
    }

    public function test_review_reports_conflicting_courses_and_a_fix_clears_both_sides(): void
    {
        $fixture = $this->fixture();
        // Both courses hold the same room at the same time for one section.
        $draft = [
            ...$this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30'], ['Wednesday', '08:00', '09:30']]),
            ...$this->rows($fixture, (int) $fixture['blocked']->id, [['Monday', '08:00', '09:30'], ['Thursday', '13:00', '14:30']]),
        ];

        $response = $this->review($fixture, $draft)->assertOk();
        $issues = collect($response->json('issues'))->keyBy('course_code');

        $this->assertSame(['GEC 101', 'GEC 102'], $issues->keys()->sort()->values()->all());
        $this->assertSame('conflict', $issues['GEC 101']['kind']);
        $this->assertNotEmpty($issues['GEC 101']['problems']);
        // Another time on the clashing meeting's own day comes first.
        $moved = static fn (array $option): string => collect($option['rows'])->firstWhere('day', '!=', 'Thursday')['day'];
        $this->assertSame('Monday', $moved($issues['GEC 101']['options'][0]));
        $fix = $issues['GEC 101']['options'][0];
        // The meeting that did not clash is kept as it was.
        $this->assertContains('Thursday', array_column($fix['rows'], 'day'));
        $this->assertCount(2, $fix['rows']);

        $fixed = [
            ...array_values(array_filter($draft, fn (array $row): bool => $row['course_id'] !== (int) $fixture['blocked']->id)),
            ...$fix['rows'],
        ];
        $this->review($fixture, $fixed)->assertOk()->assertJsonCount(0, 'issues');
    }

    public function test_review_offers_online_split_options_that_save(): void
    {
        $fixture = $this->fixture();
        $draft = $this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30'], ['Wednesday', '08:00', '09:30']]);
        $unplaced = [[
            'section_id' => (int) $fixture['section']->id,
            'course_id' => (int) $fixture['blocked']->id,
            'shape' => 'split',
            'meetings' => [
                ['duration_slots' => 3, 'meeting_type' => 'lecture', 'modes' => ['on-site', 'online']],
                ['duration_slots' => 3, 'meeting_type' => 'lecture', 'modes' => ['on-site', 'online']],
            ],
        ]];

        $options = $this->review($fixture, $draft, $unplaced)->assertOk()->json('issues.0.options');
        $onlineSplits = array_values(array_filter($options, static fn (array $option): bool => $option['label'] === 'Hybrid Split'));
        $splits = array_values(array_filter($options, static fn (array $option): bool => $option['label'] !== 'Hybrid Split'));

        $this->assertNotEmpty($onlineSplits, 'A three-unit lecture course is offered a Hybrid Split.');
        $this->assertLessThanOrEqual(2, count($onlineSplits));
        foreach ($onlineSplits as $option) {
            $rows = $option['rows'];
            $this->assertCount(2, $rows);
            $modes = array_column($rows, 'mode');
            sort($modes);
            $this->assertSame(['on-site', 'online'], $modes);
            $this->assertSame([true, true], array_column($rows, 'is_hybrid'));
            $this->assertSame(['lecture', 'lecture'], array_column($rows, 'meeting_type'));
            $this->assertSame($rows[0]['start_time'], $rows[1]['start_time']);
            $this->assertNotSame($rows[0]['day'], $rows[1]['day']);
            $this->assertSame($rows[0]['split_group_id'], $rows[1]['split_group_id']);
        }
        // A Split Session keeps one time on both days and never goes half
        // online unlabelled.
        foreach ($splits as $option) {
            $this->assertSame($option['rows'][0]['start_time'], $option['rows'][1]['start_time']);
            $this->assertCount(1, array_unique(array_column($option['rows'], 'mode')));
        }

        // The Online Split the review offered is one the save accepts.
        $operations = array_map(static fn (array $row): array => [
            ...$row,
            'start_time' => substr((string) $row['start_time'], 0, 5),
            'end_time' => substr((string) $row['end_time'], 0, 5),
            'status' => 'draft',
        ], [...$draft, ...$onlineSplits[0]['rows']]);

        $this->actingAs($fixture['user'])
            ->postJson('/api/schedules/batch', ['operations' => $operations])
            ->assertSuccessful();
    }

    public function test_review_offers_a_fully_online_class_only_when_no_room_is_left(): void
    {
        $fixture = $this->fixture();
        $unplaced = [[
            'section_id' => (int) $fixture['section']->id,
            'course_id' => (int) $fixture['blocked']->id,
            'meetings' => [['duration_slots' => 6, 'meeting_type' => null, 'modes' => ['on-site']]],
        ]];

        // A room is free: no Fully Online option is offered.
        $labels = array_column($this->review($fixture, [], $unplaced)->assertOk()->json('issues.0.options'), 'label');
        $this->assertNotContains('Online (All)', $labels);

        // The only room is out of service: nothing on site fits.
        $fixture['room']->update(['status' => 'not available']);
        $options = $this->review($fixture, [], $unplaced)->assertOk()->json('issues.0.options');

        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $this->assertSame('Online (All)', $option['label']);
            $rows = $option['rows'];
            $this->assertCount(2, $rows);
            $this->assertSame(['online', 'online'], array_column($rows, 'mode'));
            $this->assertSame([null, null], array_column($rows, 'room_id'));
            $this->assertSame([false, false], array_column($rows, 'is_hybrid'));
            $this->assertSame($rows[0]['start_time'], $rows[1]['start_time']);
            $this->assertNotSame($rows[0]['day'], $rows[1]['day']);
            $this->assertSame($rows[0]['split_group_id'], $rows[1]['split_group_id']);
        }

        // The option the review offered is one the save accepts.
        $operations = array_map(static fn (array $row): array => [
            ...$row,
            'start_time' => substr((string) $row['start_time'], 0, 5),
            'end_time' => substr((string) $row['end_time'], 0, 5),
            'status' => 'draft',
        ], $options[0]['rows']);

        $this->actingAs($fixture['user'])
            ->postJson('/api/schedules/batch', ['operations' => $operations])
            ->assertSuccessful();
    }

    public function test_review_refuses_rows_for_sections_outside_the_draft(): void
    {
        $fixture = $this->fixture();
        $other = Sections::create([
            'section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $fixture['department']->id, 'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id, 'status' => 'active',
        ]);
        $rows = $this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30']]);
        $rows[0]['section_id'] = (int) $other->id;

        $this->review($fixture, $rows)->assertStatus(422);
    }

    public function test_review_never_offers_a_room_another_target_sections_kept_class_holds(): void
    {
        $fixture = $this->fixture();
        $other = Sections::create([
            'section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $fixture['department']->id, 'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id, 'status' => 'active',
        ]);
        // IT 1B is in the run, but its GEC 101 is neither in the draft nor
        // unplaced: the save keeps this class, so its room-time is taken.
        Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $other->id,
            'course_id' => $fixture['blocked']->id,
            'department_id' => $fixture['department']->id,
            'room_id' => $fixture['room']->id,
            'day' => 'Tuesday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        $options = $this->review($fixture, [], [[
            'section_id' => (int) $fixture['section']->id,
            'course_id' => (int) $fixture['blocked']->id,
            'meetings' => [['duration_slots' => 3, 'meeting_type' => null, 'modes' => ['on-site']]],
        ]], [(int) $fixture['section']->id, (int) $other->id])->assertOk()->json('issues.0.options');

        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            foreach ($option['rows'] as $row) {
                $this->assertFalse(
                    $row['day'] === 'Tuesday' && (int) $row['room_id'] === (int) $fixture['room']->id
                        && $row['start_time'] < '09:30' && $row['end_time'] > '08:00',
                    'An option may not take the room-time of a class the save keeps.',
                );
            }
        }
    }

    public function test_a_free_physical_interval_without_an_online_partner_is_not_a_hybrid_option(): void
    {
        $fixture = $this->fixture();
        foreach (['Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            Schedule::create([
                'semester_id' => $fixture['semester']->id, 'section_id' => $fixture['section']->id,
                'course_id' => $fixture['placed']->id, 'department_id' => $fixture['department']->id,
                'mode' => 'online', 'room_id' => null, 'day' => $day,
                'start_time' => '00:00', 'end_time' => '23:30', 'status' => 'draft',
            ]);
        }
        $options = $this->review($fixture, [], [[
            'section_id' => (int) $fixture['section']->id, 'course_id' => (int) $fixture['blocked']->id,
            'shape' => 'split', 'meetings' => [
                ['duration_slots' => 3, 'meeting_type' => 'lecture', 'modes' => ['on-site']],
                ['duration_slots' => 3, 'meeting_type' => 'lecture', 'modes' => ['on-site']],
            ],
        ]])->assertOk()->json('issues.0.options');

        $this->assertSame([], $options, 'Monday has physical room time, but no distinct day fits the second meeting.');
    }

    public function test_a_two_day_run_is_not_offered_a_hybrid_or_all_online_pair(): void
    {
        $fixture = $this->fixture();
        DB::table('department_course_rules')->insert([
            'department_id' => $fixture['department']->id, 'course_id' => $fixture['blocked']->id,
            'section_id' => $fixture['section']->id, 'consecutive_day_count' => 2,
        ]);
        $draft = $this->rows($fixture, (int) $fixture['blocked']->id, [['Monday', '08:00', '09:30'], ['Wednesday', '08:00', '09:30']]);
        $draft = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'consecutive:2'], $draft);
        $blocker = $this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30']]);
        $issues = $this->review($fixture, [...$draft, ...$blocker])->assertOk()->json('issues');
        $issue = collect($issues)->firstWhere('course_id', (int) $fixture['blocked']->id);
        $this->assertNotNull($issue);
        $this->assertSame([], $issue['options'], 'The per-day draft search has not found a complete run; it must not substitute a two-meeting alternative.');
    }

    public function test_marker_only_consecutive_runs_keep_their_interval_and_do_not_offer_split_alternatives(): void
    {
        $fixture = $this->fixture();
        $this->assertDatabaseCount('department_course_rules', 0);
        $draft = $this->rows($fixture, (int) $fixture['blocked']->id, [['Monday', '08:00', '09:30'], ['Tuesday', '08:00', '09:30']]);
        $draft = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'consecutive:2'], $draft);
        $blocker = $this->rows($fixture, (int) $fixture['placed']->id, [['Monday', '08:00', '09:30']]);

        foreach (['available', 'not available'] as $roomStatus) {
            $fixture['room']->update(['status' => $roomStatus]);
            $issues = $this->review($fixture, [...$draft, ...$blocker])->assertOk()->json('issues');
            $issue = collect($issues)->firstWhere('course_id', (int) $fixture['blocked']->id);
            $this->assertNotNull($issue);
            foreach ($issue['options'] as $option) {
                $this->assertSame([], MeetingGroupRule::groupMismatches('consecutive', $fixture['blocked'], $option['rows'], 'consecutive:2'));
                foreach ($option['rows'] as $row) {
                    $this->assertSame($option['rows'][0]['start_time'], $row['start_time']);
                    $this->assertSame($option['rows'][0]['end_time'], $row['end_time']);
                    if ($roomStatus === 'available') {
                        $this->assertSame('08:00', substr($row['start_time'], 0, 5));
                        $this->assertSame('09:30', substr($row['end_time'], 0, 5));
                    }
                    $this->assertSame('consecutive:2', $row['preferred_pattern']);
                }
            }
            $labels = array_column($issue['options'], 'label');
            $this->assertNotContains('Hybrid Split', $labels);
            $this->assertNotContains('Online (All)', $labels);
            if ($roomStatus === 'available') {
                $this->assertSame([], $issue['options'], 'Per-day discovery cannot replace only part of the marked run.');
            } else {
                $this->assertNotEmpty($issue['options'], 'A whole-run move can retain its marker and matching intervals.');
                $operations = array_map(static fn (array $row): array => [
                    ...$row,
                    'start_time' => substr($row['start_time'], 0, 5),
                    'end_time' => substr($row['end_time'], 0, 5),
                    'status' => 'draft',
                ], $issue['options'][0]['rows']);
                $this->actingAs($fixture['user'])->postJson('/api/schedules/batch', ['operations' => $operations])->assertSuccessful();
            }
        }
        $this->assertDatabaseCount('department_course_rules', 0);
    }

    private function review(array $fixture, array $rows, array $unplaced = [], ?array $sectionIds = null)
    {
        return $this->actingAs($fixture['user'])->postJson('/api/schedule-recommendations/draft-review', [
            'semester_id' => $fixture['semester']->id,
            'department_id' => $fixture['department']->id,
            'section_ids' => $sectionIds ?? [(int) $fixture['section']->id],
            'rows' => $rows,
            'unplaced' => $unplaced,
        ]);
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $meetings */
    private function rows(array $fixture, int $courseId, array $meetings): array
    {
        return array_map(fn (array $meeting): array => [
            'semester_id' => (int) $fixture['semester']->id,
            'section_id' => (int) $fixture['section']->id,
            'course_id' => $courseId,
            'department_id' => (int) $fixture['department']->id,
            'faculty_id' => null,
            'room_id' => (int) $fixture['room']->id,
            'day' => $meeting[0],
            'start_time' => $meeting[1],
            'end_time' => $meeting[2],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'split_group_id' => count($meetings) > 1 ? 'group-'.$courseId : null,
            'meeting_type' => null,
            'meeting_index' => null,
        ], $meetings);
    }

    private function fixture(): array
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

        $courses = [];
        foreach (['GEC 101' => 'Understanding the Self', 'GEC 102' => 'Readings in Philippine History'] as $code => $name) {
            $courses[$code] = Course::create([
                'course_code' => $code, 'course_name' => $name,
                'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'minor', 'room_type_required' => 'lecture',
                'year_level' => '1', 'semester' => '1st', 'department_id' => null, 'status' => 'active',
            ]);
            $curriculum->courses()->attach($courses[$code]->id, ['year_level' => 1, 'semester' => 1]);
        }

        return [
            'semester' => $semester,
            'department' => $department,
            'program' => $program,
            'section' => $section,
            'blocked' => $courses['GEC 101'],
            'placed' => $courses['GEC 102'],
            'room' => Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }
}
