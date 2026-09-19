<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The two Hybrid types, and the Preferred Room check.
 *
 * - Hybrid Split: one online and one face-to-face lecture, each
 *   SchedulingPolicy::HYBRID_SPLIT_MEETING_MINUTES long.
 * - Integrated Hybrid: an online lecture and an on-site laboratory as two
 *   separate sessions, each sized from the course, never a fixed 2 h + 3 h.
 *
 * Every generated row is run back through RuleEngine: a shape the Generator
 * places but the save refuses is the bug these tests exist to catch.
 */
class HybridShapesAndPreferredRoomTest extends TestCase
{
    use RefreshDatabase;

    private Sections $section;

    private Departments $department;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $this->department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $this->section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $this->department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $this->section->setRelation('department', $this->department);
    }

    public function test_hybrid_split_is_one_online_and_one_face_to_face_session_of_the_fixed_length(): void
    {
        $course = $this->course('GEC 1', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $this->room('LEC 1', 'lecture');

        $rows = $this->generate($course, ['hybrid_split' => true]);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['online', 'on-site'], array_column($rows, 'mode'));
        foreach ($rows as $row) {
            $this->assertSame(SchedulingPolicy::HYBRID_SPLIT_MEETING_MINUTES, $this->minutes($row));
            $this->assertSame('lecture', $row['meeting_type']);
        }
        $this->assertNotSame($rows[0]['day'], $rows[1]['day']);
        $this->assertSaveAccepts($course, $rows);
    }

    public function test_hybrid_split_alternates_which_day_meets_face_to_face(): void
    {
        // Every Online-first order used to rank in the online tier, so every
        // section met F2F on its first day and online on its second. With the
        // two orders tied, consecutive courses (and sections) take opposite ones.
        $this->room('LEC 1', 'lecture');
        $firstDayModes = [];
        foreach (['GEC 1', 'GEC 2'] as $code) {
            $course = $this->course($code, lecture: 3, laboratory: 0, units: 3, category: 'minor');
            $rows = $this->generate($course, ['hybrid_split' => true]);
            $this->assertSaveAccepts($course, $rows);

            usort($rows, fn (array $a, array $b): int => array_search($a['day'], SchedulingPolicy::DAYS, true)
                <=> array_search($b['day'], SchedulingPolicy::DAYS, true));
            $firstDayModes[] = $rows[0]['mode'];
        }

        $this->assertEqualsCanonicalizing(['online', 'on-site'], $firstDayModes);
    }

    public function test_friday_and_saturday_pair_only_when_the_run_allows_it(): void
    {
        $course = $this->course('GEC 1', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $solver = app(CspSolver::class);
        $pairs = new \ReflectionMethod($solver, 'balancedSplitDayPairs');
        $flag = new \ReflectionProperty($solver, 'allowFridaySaturdaySplit');

        $this->assertSame(
            [['Monday', 'Wednesday'], ['Tuesday', 'Thursday']],
            $pairs->invoke($solver, $course, true),
        );

        $flag->setValue($solver, true);
        // Tried after MW and TTh, so it only takes what those cannot hold.
        $this->assertSame(
            [['Monday', 'Wednesday'], ['Tuesday', 'Thursday'], ['Friday', 'Saturday']],
            $pairs->invoke($solver, $course, true),
        );
    }

    public function test_friday_and_saturday_split_session_generates_and_saves(): void
    {
        $course = $this->course('GEC 1', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $this->room('LEC 1', 'lecture');
        $id = (int) $course->id;

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $this->section->id,
            courseIds: [$id],
            balancedSplitCourseIds: [$id],
            requirementsByCourseId: app(ScheduleRequirementBuilderResolver::class)->build($this->section, [$id], []),
            allowedDays: ['Friday', 'Saturday'],
            allowFridaySaturdaySplit: true,
            maxSolutions: 1,
            seed: 4321,
        );
        $this->assertNotEmpty($solutions, 'the generator produced no Friday + Saturday split');
        $rows = $solutions[0]['schedules'];

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Friday', 'Saturday'], array_column($rows, 'day'));
        $this->assertSame(
            [],
            app(RuleEngine::class)->validateConfiguredMeetingGroups($rows),
            'the save refuses the Friday + Saturday pair the generator placed',
        );
        foreach ($rows as $row) {
            $rules = array_column(app(RuleEngine::class)->validate([
                'semester_id' => (int) $this->section->semester_id,
                'section_id' => (int) $this->section->id,
                'course_id' => $id,
                'room_id' => $row['room_id'] ?? null,
                'day' => $row['day'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'mode' => $row['mode'] ?? 'on-site',
                'meeting_type' => $row['meeting_type'] ?? null,
                'split_group_id' => $row['split_group_id'] ?? null,
                'preferred_pattern' => $row['preferred_pattern'] ?? null,
            ]), 'rule');
            foreach (['minor_day_constraint', 'room_type_match', 'minor_split_pattern'] as $rule) {
                $this->assertNotContains($rule, $rules);
            }
        }
    }

    public function test_hybrid_split_never_puts_a_minor_in_a_laboratory_or_room_tba(): void
    {
        // Only the lecture room is legal for a minor's face-to-face meeting:
        // RoomTypeRule refuses a laboratory (even one flagged for lectures)
        // and Room TBA belongs to laboratories alone.
        $course = $this->course('GEC 2', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $lecture = $this->room('LEC 1', 'lecture');
        $this->room('LAB 1', 'laboratory', allowLectureUsage: true);
        $this->room('LAB 2', 'laboratory', allowLectureUsage: true);

        foreach ([11, 222, 3333] as $seed) {
            $rows = $this->generate($course, ['hybrid_split' => true], $seed);
            $faceToFace = collect($rows)->firstWhere('mode', 'on-site');

            $this->assertSame((int) $lecture->id, (int) $faceToFace['room_id'], "seed {$seed}");
            $this->assertSaveAccepts($course, $rows);
        }
    }

    public function test_hybrid_split_offers_no_candidate_the_save_would_refuse_when_only_laboratories_exist(): void
    {
        // With no lecture room, the old domain put the face-to-face meeting in
        // a laboratory or Room TBA -- both refused at save. Now it offers no
        // Hybrid Split candidate at all, and the run says so.
        $course = $this->course('GEC 3', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $this->room('LAB 1', 'laboratory', allowLectureUsage: true);

        try {
            $rows = $this->generate($course, ['hybrid_split' => true]);
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSaveAccepts($course, $rows);
    }

    public function test_integrated_hybrid_sizes_each_session_from_the_course(): void
    {
        // 3 lecture units + 2 laboratory units: a 3 h online lecture and a
        // 6 h on-site laboratory, not a fixed 2 h + 3 h.
        $course = $this->course('IT 205', lecture: 3, laboratory: 2, units: 5, category: 'major');
        $this->room('LAB 1', 'laboratory');

        $rows = $this->generate($course, ['integrated_hybrid' => true]);

        $this->assertIntegratedHybrid($rows, lectureMinutes: 180, laboratoryMinutes: 360);
        $this->assertSaveAccepts($course, $rows);
    }

    public function test_integrated_hybrid_stays_two_sessions_even_with_a_preferred_pattern(): void
    {
        // Used to fall through to the generic pattern builder, which split the
        // combined 5 h across two days as if it were one class.
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $this->room('LAB 1', 'laboratory');

        $rows = $this->generate($course, ['integrated_hybrid' => true, 'pattern' => 'MW']);

        $this->assertIntegratedHybrid($rows, lectureMinutes: 120, laboratoryMinutes: 180);
    }

    public function test_integrated_hybrid_uses_the_lengths_chosen_for_each_session(): void
    {
        // Default would be 2 h + 3 h; the user picks 1.5 h online + 3.5 h F2F.
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $this->room('LAB 1', 'laboratory');
        $config = ['selected_split_session_course_ids' => [(int) $course->id]];
        $components = CourseSetupOverrides::normalizeComponents(
            $this->section,
            [(int) $course->id => ['lecture' => 90, 'laboratory' => 210]],
            [(int) $course->id],
            $config,
        );

        $rows = $this->generate($course, ['integrated_hybrid' => true], options: [
            CourseSetupOverrides::COMPONENTS_KEY => $components,
        ]);

        $this->assertIntegratedHybrid($rows, lectureMinutes: 90, laboratoryMinutes: 210);
        $this->assertSaveAccepts($course, $rows);
    }

    public function test_integrated_hybrid_lengths_may_not_exceed_the_course_week(): void
    {
        // 2 lecture + 1 laboratory unit carries at most 5 h a week.
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');

        $this->expectException(ValidationException::class);

        CourseSetupOverrides::normalizeComponents(
            $this->section,
            [(int) $course->id => ['lecture' => 180, 'laboratory' => 180]],
            [(int) $course->id],
            ['selected_split_session_course_ids' => [(int) $course->id]],
        );
    }

    public function test_the_preferred_laboratory_is_chosen_for_an_integrated_hybrid(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $labs = collect(range(1, 8))->map(fn (int $n): Rooms => $this->room("LAB {$n}", 'laboratory'));
        $preferred = $labs->last();

        $rows = $this->generate($course, ['integrated_hybrid' => true], options: [
            CourseSetupOverrides::PREFERRED_ROOMS_KEY => [(int) $course->id => (int) $preferred->id],
        ]);

        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');
        $this->assertSame((int) $preferred->id, (int) $laboratory['room_id']);
    }

    public function test_a_preferred_room_of_the_wrong_type_is_refused(): void
    {
        $minor = $this->course('GEC 1', lecture: 3, laboratory: 0, units: 3, category: 'minor');
        $lab = $this->room('LAB 1', 'laboratory', allowLectureUsage: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('LAB 1 is a laboratory room, but this course meets in a lecture room.');

        CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $minor->id => (int) $lab->id], [(int) $minor->id]);
    }

    public function test_integrated_hybrid_takes_a_laboratory_as_its_preferred_room(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $lab = $this->room('LAB 1', 'laboratory');
        $lecture = $this->room('LEC 1', 'lecture');
        $config = ['selected_split_session_course_ids' => [(int) $course->id]];

        $this->assertSame(
            [(int) $course->id => (int) $lab->id],
            CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $course->id => (int) $lab->id], [(int) $course->id], $config),
        );

        $this->expectException(ValidationException::class);
        CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $course->id => (int) $lecture->id], [(int) $course->id], $config);
    }

    public function test_a_preferred_room_that_is_closed_or_out_of_reach_is_refused(): void
    {
        $course = $this->course('IT 101', lecture: 3, laboratory: 0, units: 3, category: 'major');
        $closed = $this->room('LEC 9', 'lecture', status: 'not available');
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $foreign = Rooms::create(['room_code' => 'OTH 1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $other->id]);

        foreach ([$closed, $foreign] as $room) {
            try {
                CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $course->id => (int) $room->id], [(int) $course->id]);
                $this->fail("{$room->room_code} was accepted");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_online_course_drops_its_preferred_room_instead_of_failing(): void
    {
        $course = $this->course('IT 101', lecture: 3, laboratory: 0, units: 3, category: 'major');
        $room = $this->room('LEC 1', 'lecture');

        $this->assertSame([], CourseSetupOverrides::normalizePreferredRooms(
            $this->section,
            [(int) $course->id => (int) $room->id],
            [(int) $course->id],
            ['delivery_modes_by_course_id' => [(int) $course->id => 'online']],
        ));
    }

    public function test_integrated_on_site_keeps_lecture_and_laboratory_face_to_face(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $lectureRoom = $this->room('LEC 1', 'lecture');
        $laboratoryRoom = $this->room('LAB 1', 'laboratory');

        $rows = $this->generate($course, ['integrated_hybrid' => true], options: [
            'delivery_modes_by_course_id' => [(int) $course->id => 'on-site'],
        ]);

        $this->assertCount(2, $rows, 'lecture and laboratory are two separate sessions');
        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');
        $this->assertSame('on-site', $lecture['mode']);
        $this->assertSame((int) $lectureRoom->id, (int) $lecture['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame((int) $laboratoryRoom->id, (int) $laboratory['room_id']);
        $this->assertSaveAccepts($course, $rows);
    }

    public function test_integrated_without_on_site_delivery_stays_hybrid(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, units: 3, category: 'major');
        $this->room('LEC 1', 'lecture');
        $this->room('LAB 1', 'laboratory');

        $rows = $this->generate($course, ['integrated_hybrid' => true]);

        $this->assertSame('online', collect($rows)->firstWhere('meeting_type', 'lecture')['mode']);
        $this->assertSame('on-site', collect($rows)->firstWhere('meeting_type', 'laboratory')['mode']);
    }

    public function test_a_field_course_takes_a_field_room_as_its_preferred_room(): void
    {
        $course = $this->course('PATHFIT 1', lecture: 2, laboratory: 0, units: 2, category: 'minor');
        $course->update(['room_type_required' => 'field']);
        $this->room('OVAL', 'field');
        $gym = $this->room('GYM', 'field');
        $lecture = $this->room('LEC 1', 'lecture');

        $this->assertSame(
            [(int) $course->id => (int) $gym->id],
            CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $course->id => (int) $gym->id], [(int) $course->id]),
        );

        $rows = $this->generate($course, [], options: [
            CourseSetupOverrides::PREFERRED_ROOMS_KEY => [(int) $course->id => (int) $gym->id],
        ]);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame((int) $gym->id, (int) $row['room_id']);
        }
        $this->assertSaveAccepts($course, $rows);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('LEC 1 is a lecture room, but this course meets in a field room.');
        CourseSetupOverrides::normalizePreferredRooms($this->section, [(int) $course->id => (int) $lecture->id], [(int) $course->id]);
    }

    private function course(string $code, int $lecture, int $laboratory, int $units, string $category): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => $code.' course',
            'lecture_hours' => $lecture,
            'lab_hours' => $laboratory,
            'units' => $units,
            'course_category' => $category,
            'room_type_required' => $laboratory > 0 ? 'laboratory' : 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
    }

    private function room(string $code, string $type, bool $allowLectureUsage = false, string $status = 'available'): Rooms
    {
        return Rooms::create([
            'room_code' => $code,
            'building' => 'Building 1',
            'room_type' => $type,
            'allow_lecture_usage' => $allowLectureUsage,
            'status' => $status,
            'department_id' => $this->department->id,
        ]);
    }

    /**
     * @param  array{hybrid_split?: bool, integrated_hybrid?: bool, pattern?: string}  $shape
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function generate(Course $course, array $shape, int $seed = 4321, array $options = []): array
    {
        $id = (int) $course->id;
        $integrated = (bool) ($shape['integrated_hybrid'] ?? false);
        $split = (bool) ($shape['hybrid_split'] ?? false);
        $options += ['selected_split_session_course_ids' => $integrated ? [$id] : []];

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $this->section->id,
            courseIds: [$id],
            isHybrid: $integrated,
            preferredPatternsByCourseId: isset($shape['pattern']) ? [$id => $shape['pattern']] : [],
            selectedLectureLabCourseIds: $integrated ? [$id] : [],
            balancedSplitCourseIds: $split ? [$id] : [],
            hybridSplitCourseIds: $split ? [$id] : [],
            deliveryModesByCourseId: $options['delivery_modes_by_course_id'] ?? [],
            requirementsByCourseId: app(ScheduleRequirementBuilderResolver::class)->build($this->section, [$id], $options),
            maxSolutions: 1,
            seed: $seed,
        );

        $this->assertNotEmpty($solutions, 'the generator produced no solution');

        return $solutions[0]['schedules'];
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertIntegratedHybrid(array $rows, int $lectureMinutes, int $laboratoryMinutes): void
    {
        $this->assertCount(2, $rows, 'lecture and laboratory are two separate sessions');
        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');
        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame('online', $lecture['mode']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame($lectureMinutes, $this->minutes($lecture));
        $this->assertSame($laboratoryMinutes, $this->minutes($laboratory));
        $this->assertNotSame($lecture['day'], $laboratory['day']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertSaveAccepts(Course $course, array $rows): void
    {
        foreach ($rows as $row) {
            $violations = app(RuleEngine::class)->validate([
                'semester_id' => (int) $this->section->semester_id,
                'section_id' => (int) $this->section->id,
                'course_id' => (int) $course->id,
                'room_id' => $row['room_id'] ?? null,
                'day' => $row['day'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'mode' => $row['mode'] ?? 'on-site',
                'meeting_type' => $row['meeting_type'] ?? null,
                'is_hybrid' => (bool) ($row['is_hybrid'] ?? true),
            ]);
            $rules = array_column($violations, 'rule');

            foreach (['room_type_match', 'hybrid_component_shape', 'hybrid_eligibility', 'hybrid_component_type'] as $rule) {
                $this->assertNotContains($rule, $rules, "{$rule}: ".json_encode($violations));
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function minutes(array $row): int
    {
        return SchedulingPolicy::timeToMinutes((string) $row['end_time'])
            - SchedulingPolicy::timeToMinutes((string) $row['start_time']);
    }
}
