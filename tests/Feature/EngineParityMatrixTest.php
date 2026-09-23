<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Generation\ScheduleRequirement;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Services\TimeslotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The engine's safety net: one placement, one answer, whichever path judges it.
 *
 * RuleEngine judges manual saves; the constraint kernel judges generated plans
 * at preview and commit. They are separate implementations over different data
 * (live database vs. snapshot), so every rule both implement is exercised here
 * on real records, and each scenario names the rule it expects so a scenario
 * can never pass by both sides reporting nothing.
 */
class EngineParityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Rules both validators implement. RuleEngine-only record rules are out of scope. */
    private const SHARED_RULES = [
        'class_duration',
        'faculty_active',
        'faculty_conflict',
        'field_evening_window',
        'operating_hours',
        'part_time_faculty_availability',
        'preferred_pattern',
        'room_availability',
        'room_conflict',
        'room_department_alignment',
        'room_type_match',
        'section_conflict',
        'slot_grid',
        'subject_section_time_conflict',
    ];

    private Semester $semester;

    private Departments $department;

    private Departments $otherDepartment;

    private Curriculum $curriculum;

    private Sections $section;

    private Rooms $lectureRoom;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SchedulingPolicy::clearTimeCache();
        SchedulingPolicy::clearFieldCourseCache();
        app(TimeslotService::class)->settings();

        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->department = Departments::create(['department_name' => 'Matrix', 'department_code' => 'MTX', 'scheduling_profile' => 'laboratory_enabled']);
        $this->otherDepartment = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $this->curriculum = Curriculum::create([
            'name' => 'Matrix Curriculum', 'department_id' => $this->department->id, 'code' => 'MTX-2026',
            'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);
        $this->section = Sections::create([
            'section_name' => 'MTX 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
        $this->lectureRoom = $this->room('lecture');
    }

    protected function tearDown(): void
    {
        SchedulingPolicy::clearTimeCache();
        SchedulingPolicy::clearFieldCourseCache();

        parent::tearDown();
    }

    public function test_a_clean_placement_passes_both(): void
    {
        $this->assertParity([], $this->attempt());
    }

    public function test_room_rules_agree(): void
    {
        $this->assertParity(['room_availability'], $this->attempt(['room_id' => $this->room('lecture', ['status' => 'not available'])->id]));
        $this->assertParity(['room_department_alignment'], $this->attempt(['room_id' => $this->room('lecture', ['department_id' => $this->otherDepartment->id])->id]));

        $lab = $this->room('laboratory');
        $this->assertParity(['room_type_match'], $this->attempt(['room_id' => $lab->id]));

        $this->persist(['room_id' => $this->lectureRoom->id, 'section_id' => $this->otherSection()->id]);
        $this->assertParity(['room_conflict'], $this->attempt());
    }

    public function test_a_lecture_flagged_laboratory_may_host_a_lecture_only_major(): void
    {
        $lab = $this->room('laboratory', ['allow_lecture_usage' => true]);

        $this->assertParity([], $this->attempt(['room_id' => $lab->id]));
    }

    public function test_section_rules_agree(): void
    {
        $this->persist(['course_id' => $this->course()->id, 'room_id' => null, 'mode' => 'online']);
        $this->assertParity(['section_conflict'], $this->attempt());
    }

    public function test_instructor_rules_agree(): void
    {
        $inactive = $this->faculty(['status' => 'inactive']);
        $this->assertParity(['faculty_active'], $this->attempt(['faculty_id' => $inactive->id]));

        $partTimer = $this->faculty(['employment_type' => 'part-time']);
        $partTimer->availabilities()->create(['day_index' => 0, 'start_time' => '13:00', 'end_time' => '17:00']);
        $this->assertParity(['part_time_faculty_availability'], $this->attempt(['faculty_id' => $partTimer->id]));

        // No windows recorded at all: unrestricted.
        $unrecorded = $this->faculty(['employment_type' => 'part-time']);
        $this->assertParity([], $this->attempt(['faculty_id' => $unrecorded->id]));

        $busy = $this->faculty();
        $this->persist(['faculty_id' => $busy->id, 'section_id' => $this->otherSection()->id, 'room_id' => null, 'mode' => 'online']);
        $this->assertParity(['faculty_conflict'], $this->attempt(['faculty_id' => $busy->id]));
    }

    public function test_the_same_online_course_cannot_run_twice_at_once(): void
    {
        $course = $this->course();
        $this->persist(['course_id' => $course->id, 'section_id' => $this->otherSection()->id, 'room_id' => null, 'mode' => 'online']);

        $this->assertParity(['subject_section_time_conflict'], $this->attempt(['course_id' => $course->id, 'room_id' => null, 'mode' => 'online']));
    }

    public function test_time_rules_agree(): void
    {
        // Default hours are 07:00-20:30 on a 30-minute grid.
        $this->assertParity(['operating_hours'], $this->attempt(['start_time' => '20:00', 'end_time' => '21:30']));
        $this->assertParity(['slot_grid'], $this->attempt(['start_time' => '08:15', 'end_time' => '09:15']));

        $field = $this->course(['room_type_required' => 'field', 'course_category' => 'minor']);
        $this->assertParity(['field_evening_window'], $this->attempt([
            'course_id' => $field->id, 'room_id' => $this->room('field')->id, 'mode' => 'field', 'start_time' => '17:00', 'end_time' => '18:00',
        ]));
    }

    public function test_day_rules_agree(): void
    {
        $this->assertParity(['preferred_pattern'], $this->attempt(['day' => 'Friday', 'preferred_pattern' => 'MW']));

        // Every day is a teaching day: a major on-site Sunday, a minor online
        // Sunday and a field Saturday all agree on no violation, on both sides.
        $this->assertParity([], $this->attempt(['day' => 'Sunday']));

        $minor = $this->course(['course_category' => 'minor']);
        $this->assertParity([], $this->attempt(['course_id' => $minor->id, 'day' => 'Sunday', 'room_id' => null, 'mode' => 'online']));

        $field = $this->course(['room_type_required' => 'field', 'course_category' => 'minor']);
        $this->assertParity([], $this->attempt([
            'course_id' => $field->id, 'room_id' => $this->room('field')->id, 'mode' => 'field', 'day' => 'Saturday',
        ]));
    }

    public function test_class_duration_agrees_for_meetings_generation_keeps(): void
    {
        // 3 units = 3 hours a week; a second 3-hour meeting doubles it.
        foreach (['faculty_assignment', 'rejected'] as $status) {
            $course = $this->course();
            $this->persist(['course_id' => $course->id, 'day' => 'Friday', 'start_time' => '08:00', 'end_time' => '11:00', 'status' => $status, 'room_id' => null, 'mode' => 'online']);

            $this->assertParity(['class_duration'], $this->attempt(['course_id' => $course->id, 'start_time' => '08:00', 'end_time' => '11:00']), $status);
        }
    }

    public function test_class_duration_leaves_an_integrated_sessions_length_to_the_user(): void
    {
        // 2 lecture + 1 laboratory unit carries 5 h as a unit total. A linked
        // 3 h laboratory plus a 3 h lecture is 6 h, but each Integrated session
        // takes the length the user set, so neither engine holds it to 5 h.
        $course = $this->course(['lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3]);
        $this->persist([
            'course_id' => $course->id, 'split_group_id' => 'integrated-1', 'meeting_type' => 'laboratory',
            'day' => 'Friday', 'start_time' => '08:00', 'end_time' => '11:00', 'room_id' => null, 'mode' => 'online',
        ]);

        $this->assertParity([], $this->attempt([
            'course_id' => $course->id, 'split_group_id' => 'integrated-1', 'meeting_type' => 'lecture',
            'start_time' => '08:00', 'end_time' => '11:00',
        ]), 'linked lecture');

        // An unlinked block is not an Integrated session and keeps the ceiling.
        $this->assertParity(['class_duration'], $this->attempt([
            'course_id' => $course->id, 'start_time' => '08:00', 'end_time' => '11:00', 'room_id' => $this->room('laboratory')->id,
        ]), 'unlinked block');
    }

    /**
     * Not a disagreement: regenerating a course replaces its draft, completed
     * and revision meetings on commit, so the kernel rightly leaves them out,
     * while a manual save adds to them and RuleEngine rightly counts them.
     */
    public function test_generation_replaces_the_regenerated_courses_own_revision_meetings(): void
    {
        $course = $this->course();
        $this->persist(['course_id' => $course->id, 'day' => 'Friday', 'start_time' => '08:00', 'end_time' => '11:00', 'status' => 'revision', 'room_id' => null, 'mode' => 'online']);
        $attempt = $this->attempt(['course_id' => $course->id, 'start_time' => '08:00', 'end_time' => '11:00']);

        $this->assertSame(['class_duration'], $this->engineRules($attempt));
        $this->assertSame([], $this->kernelRules($attempt));
    }

    /**
     * Regenerating one course must still see the section's other courses,
     * whatever their status: commit deletes only the regenerated courses' rows.
     */
    public function test_generation_still_sees_the_sections_other_courses(): void
    {
        foreach (['draft', 'completed', 'revision'] as $status) {
            $this->persist(['course_id' => $this->course()->id, 'status' => $status, 'room_id' => null, 'mode' => 'online']);

            $this->assertParity(['section_conflict'], $this->attempt(), $status);
            Schedule::query()->delete();
        }
    }

    public function test_meeting_group_rules_agree(): void
    {
        // 3 units = two 90-minute Hybrid Split meetings, one online and one on-site.
        $course = $this->course();
        $meeting = static fn (string $day, string $mode): array => [
            'day' => $day, 'start_time' => '08:00', 'end_time' => '09:30', 'mode' => $mode, 'meeting_type' => 'lecture',
        ];

        $this->assertGroupParity([], $course, 'hybrid', [$meeting('Monday', 'online'), $meeting('Wednesday', 'on-site')]);
        $this->assertGroupParity(['split_group_day_separation'], $course, 'hybrid', [$meeting('Monday', 'online'), $meeting('Monday', 'on-site')]);
        $this->assertGroupParity(['hybrid_components'], $course, 'hybrid', [$meeting('Monday', 'online'), $meeting('Wednesday', 'online')]);

        // Both meetings of a Hybrid Split or Split Session share one time slot.
        $later = static fn (array $row): array => [...$row, 'start_time' => '10:00', 'end_time' => '11:30'];
        $this->assertGroupParity(['split_group_same_time'], $course, 'hybrid', [$meeting('Monday', 'online'), $later($meeting('Wednesday', 'on-site'))]);
        $split = static fn (string $day): array => [...$meeting($day, 'on-site'), 'preferred_pattern' => 'MW'];
        $this->assertGroupParity([], $course, 'minor_split', [$split('Monday'), $split('Wednesday')]);
        $this->assertGroupParity(['split_group_same_time'], $course, 'minor_split', [$split('Monday'), $later($split('Wednesday'))]);
    }

    /**
     * RuleEngine judges linked meetings through validateConfiguredMeetingGroups
     * (batch save); the kernel through evaluateMeetingGroup (generation).
     *
     * @param  list<string>  $expected
     * @param  list<array<string, mixed>>  $meetings
     */
    private function assertGroupParity(array $expected, Course $course, string $type, array $meetings): void
    {
        $operations = array_map(fn (array $meeting): array => [
            ...$this->attempt(['course_id' => $course->id, 'room_id' => null]),
            ...$meeting,
            'split_group_id' => 'group-1',
            'is_hybrid' => $type === 'hybrid',
        ], $meetings);

        $engine = array_values(array_unique(array_column(app(RuleEngine::class)->validateConfiguredMeetingGroups($operations), 'rule')));
        sort($engine);

        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: $this->semester->id,
            departmentId: $this->department->id,
            sectionIds: [$this->section->id],
            courseIds: [$course->id],
        );
        $group = new MeetingGroup(
            groupId: 'group-1',
            sectionId: $this->section->id,
            courseId: $course->id,
            type: $type,
            requirements: [new ScheduleRequirement(
                courseId: $course->id,
                componentType: 'lecture',
                durationSlots: 3,
                eligibleRoomTypes: ['lecture'],
                allowedDeliveryModes: ['online', 'on-site'],
                isSplitComponent: true,
            )],
            rows: array_map(static fn (array $operation): ScheduleRow => ScheduleRow::fromArray($operation), $operations),
        );
        $kernel = array_values(array_unique(array_map(
            static fn ($violation): string => $violation->ruleId,
            (new SchedulingConstraintKernel)->evaluateMeetingGroup($group, $snapshot),
        )));
        sort($kernel);

        sort($expected);
        $this->assertSame($expected, $engine, 'RuleEngine');
        $this->assertSame($engine, $kernel, 'Kernel disagrees with RuleEngine');
    }

    // Part B: whatever the generator proposes, under any setting, both
    // validators accept. A placement the validator refuses is not a candidate.

    public function test_generated_plans_pass_both_validators_with_default_settings(): void
    {
        $this->assertGeneratedPlanAccepted();
    }

    public function test_generated_plans_pass_both_validators_with_narrow_operating_hours(): void
    {
        app(TimeslotService::class)->settings()->update(['opening_time' => '08:00:00', 'closing_time' => '17:00:00', 'field_end_time' => '16:00:00']);
        SchedulingPolicy::clearTimeCache();

        $rows = $this->assertGeneratedPlanAccepted();

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual('08:00', substr($row['start_time'], 0, 5));
            $this->assertLessThanOrEqual('17:00', substr($row['end_time'], 0, 5));
        }
    }

    public function test_generated_plans_pass_both_validators_with_a_custom_lab_duration(): void
    {
        $this->department->update(['custom_lab_duration_override_enabled' => true, 'custom_lab_duration_5_hours_enabled' => true]);

        $this->assertGeneratedPlanAccepted();
    }

    public function test_generated_plans_pass_both_validators_when_sunday_is_not_online_only(): void
    {
        $this->department->update(['sunday_online_only_enabled' => false]);

        $this->assertGeneratedPlanAccepted();
    }

    public function test_generated_plans_pass_both_validators_with_a_configured_field_course(): void
    {
        $this->room('field');
        $pathfit = $this->course(['course_code' => 'PATHFIT 1', 'course_category' => 'minor', 'units' => 2, 'lecture_hours' => 2]);
        DB::table('field_course_settings')->insert([
            'course_code' => 'PATHFIT 1', 'department_id' => $this->department->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SchedulingPolicy::clearFieldCourseCache();

        $this->assertGeneratedPlanAccepted([$pathfit]);
    }

    /**
     * Generates one section's plan, requires the kernel to have found no hard
     * violation, then saves every row through RuleEngine one by one, as a manual
     * save would, so the plan's rows are also judged against each other.
     *
     * @param  list<Course>  $extraCourses
     * @return list<array<string, mixed>>
     */
    private function assertGeneratedPlanAccepted(array $extraCourses = []): array
    {
        $this->room('laboratory');
        $courses = [
            ...$extraCourses,
            $this->course(),
            $this->course(),
            $this->course(['course_category' => 'minor']),
            $this->course(['lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']),
        ];

        $plans = app(GenerateSchedulePlan::class)->generate(
            semesterId: $this->semester->id,
            departmentId: $this->department->id,
            configuration: new GenerationConfiguration(
                sectionId: $this->section->id,
                courseIds: array_map(static fn (Course $course): int => (int) $course->id, $courses),
                maxSolutions: 1,
                seed: 1234,
            ),
            configurationWarningsConfirmed: true,
        );

        $this->assertNotSame([], $plans, 'Generation returned no plan.');
        $plan = $plans[0];
        $this->assertNotSame([], $plan->rows, 'Generation produced no rows: '.implode(', ', array_map(static fn ($violation): string => $violation->ruleId, $plan->violations)));
        $this->assertFalse($plan->hasHardViolations(), 'Kernel refused its own generator: '.implode(', ', array_map(static fn ($violation): string => $violation->ruleId, $plan->violations)));

        $rows = [];
        foreach ($plan->rows as $row) {
            $attempt = $row->toArray();
            $hard = array_values(array_filter(
                array_column(app(RuleEngine::class)->validate($attempt), 'rule'),
                static fn (string $rule): bool => (SchedulingPolicy::CONSTRAINT_CATALOG[$rule]['severity'] ?? 'hard') === 'hard',
            ));
            $this->assertSame([], $hard, "RuleEngine refused a generated row: {$attempt['day']} {$attempt['start_time']}-{$attempt['end_time']} course {$attempt['course_id']}");

            Schedule::create($attempt);
            $rows[] = $attempt;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string, mixed>  $attempt
     */
    private function assertParity(array $expected, array $attempt, string $label = ''): void
    {
        sort($expected);
        $engine = $this->engineRules($attempt);
        $kernel = $this->kernelRules($attempt);

        $this->assertSame($expected, $engine, trim("RuleEngine {$label}"));
        $this->assertSame($engine, $kernel, trim("Kernel disagrees with RuleEngine {$label}"));
    }

    /** @return list<string> */
    private function engineRules(array $attempt): array
    {
        return $this->shared(array_column(app(RuleEngine::class)->validate($attempt), 'rule'));
    }

    /**
     * Captured the way generation captures it, so the kernel sees exactly the
     * rooms, schedules and settings a real preview or commit would.
     *
     * @return list<string>
     */
    private function kernelRules(array $attempt): array
    {
        SchedulingPolicy::clearFieldCourseCache();
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: $this->semester->id,
            departmentId: $this->department->id,
            sectionIds: [(int) $attempt['section_id']],
            courseIds: [(int) $attempt['course_id']],
        );
        $violations = (new SchedulingConstraintKernel)->evaluateRow(ScheduleRow::fromArray($attempt), $snapshot);

        return $this->shared(array_map(static fn ($violation): string => $violation->ruleId, $violations));
    }

    /** @return list<string> */
    private function shared(array $rules): array
    {
        $rules = array_values(array_unique(array_intersect($rules, self::SHARED_RULES)));
        sort($rules);

        return $rules;
    }

    /** @return array<string, mixed> */
    private function attempt(array $overrides = []): array
    {
        return array_merge([
            'semester_id' => $this->semester->id,
            'section_id' => $this->section->id,
            'course_id' => $overrides['course_id'] ?? $this->course()->id,
            'faculty_id' => null,
            'room_id' => $this->lectureRoom->id,
            'department_id' => $this->department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
        ], $overrides);
    }

    /**
     * Committed (faculty_assignment) unless told otherwise: a generation
     * snapshot leaves out the target section's draft rows, which a run replaces.
     */
    private function persist(array $overrides = []): Schedule
    {
        return Schedule::create(array_merge($this->attempt(), ['status' => 'faculty_assignment'], $overrides));
    }

    /** A lecture-only major on the section's curriculum, unless told otherwise. */
    private function course(array $attributes = []): Course
    {
        $n = ++$this->sequence;
        $course = Course::create(array_merge([
            'course_code' => "MTX{$n}", 'course_name' => "Matrix {$n}", 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ], $attributes));
        $this->curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return $course;
    }

    private function otherSection(): Sections
    {
        $n = ++$this->sequence;

        return Sections::create([
            'section_name' => "MTX 1-{$n}", 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
    }

    private function room(string $type, array $attributes = []): Rooms
    {
        $n = ++$this->sequence;

        return Rooms::create(array_merge([
            'room_code' => "MTX-R{$n}", 'room_type' => $type, 'status' => 'available', 'department_id' => $this->department->id,
        ], $attributes));
    }

    private function faculty(array $attributes = []): Faculty
    {
        $n = ++$this->sequence;

        return Faculty::create(array_merge([
            'first_name' => 'Matrix', 'last_name' => "Faculty {$n}", 'employment_type' => 'full-time',
            'department_id' => $this->department->id, 'status' => 'active',
        ], $attributes));
    }
}
