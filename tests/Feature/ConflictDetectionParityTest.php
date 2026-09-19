<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Schedule\BatchConflict;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same placement must get the same conflict answer from every validator:
 * RuleEngine (manual save), BatchConflictValidator (batch save and
 * recommendation accept) and the constraint kernel (generation and commit).
 *
 * They are separate implementations, and they had drifted on the shared
 * resources -- online slots and field rooms -- so a generated schedule could be
 * refused the moment someone edited it. Both are now shared without a limit.
 */
class ConflictDetectionParityTest extends TestCase
{
    use RefreshDatabase;

    /** The rules the three validators all implement; others are out of scope. */
    private const CONFLICT_RULES = [
        'section_conflict',
        'subject_section_time_conflict',
        'faculty_conflict',
        'room_conflict',
    ];

    private Semester $semester;

    private Departments $department;

    private Departments $otherDepartment;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->department = Departments::create(['department_name' => 'Parity', 'department_code' => 'PAR']);
        $this->otherDepartment = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
    }

    public function test_online_classes_are_never_capped(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->persist(['mode' => 'online', 'start_time' => '07:00', 'end_time' => '09:00']);
        }

        $this->assertAllLayers([], $this->attempt(['mode' => 'online', 'start_time' => '08:00', 'end_time' => '10:00']));
    }

    public function test_the_field_is_shared_without_a_limit(): void
    {
        $field = $this->room('field');
        $this->persist(['room_id' => $field->id]);
        $this->persist(['room_id' => $field->id]);
        $this->persist(['room_id' => $field->id, 'department_id' => $this->otherDepartment->id]);

        $this->assertAllLayers([], $this->attempt(['room_id' => $field->id]));
    }

    public function test_a_lecture_room_holds_one_class(): void
    {
        $room = $this->room('lecture');
        $this->persist(['room_id' => $room->id, 'department_id' => $this->otherDepartment->id]);

        $this->assertAllLayers(['room_conflict'], $this->attempt(['room_id' => $room->id]));
    }

    public function test_section_and_instructor_clashes_agree(): void
    {
        $faculty = Faculty::create(['first_name' => 'Par', 'last_name' => 'Ity', 'employment_type' => 'full-time', 'department_id' => $this->department->id, 'status' => 'active']);
        $section = $this->section();
        $this->persist(['section_id' => $section->id]);
        $this->persist(['faculty_id' => $faculty->id]);

        $this->assertAllLayers(
            ['faculty_conflict', 'section_conflict'],
            $this->attempt(['section_id' => $section->id, 'faculty_id' => $faculty->id]),
        );
    }

    public function test_part_time_availability_accepts_back_to_back_windows(): void
    {
        $faculty = Faculty::create(['first_name' => 'Part', 'last_name' => 'Timer', 'employment_type' => 'part-time', 'department_id' => $this->department->id, 'status' => 'active']);
        FacultyAvailability::create(['faculty_id' => $faculty->id, 'day_index' => 0, 'start_time' => '08:00', 'end_time' => '10:00']);
        FacultyAvailability::create(['faculty_id' => $faculty->id, 'day_index' => 0, 'start_time' => '10:00', 'end_time' => '12:00']);

        // Online needs no room, so the attempt reaches the availability rule
        // instead of stopping at reference integrity.

        $covered = $this->engineRules($this->attempt(['faculty_id' => $faculty->id, 'mode' => 'online', 'start_time' => '09:00', 'end_time' => '11:00']), all: true);
        $this->assertNotContains('part_time_faculty_availability', $covered);

        FacultyAvailability::query()->where('start_time', 'like', '10:00%')->update(['start_time' => '10:30']);
        $gap = $this->engineRules($this->attempt(['faculty_id' => $faculty->id, 'mode' => 'online', 'start_time' => '09:00', 'end_time' => '11:00']), all: true);
        $this->assertContains('part_time_faculty_availability', $gap);
    }

    /**
     * RuleEngine and the kernel judge the attempt against persisted rows; the
     * batch validator judges the whole set as unsaved candidates, as a batch save
     * that creates all of them would.
     *
     * @param  list<string>  $expected
     * @param  array<string, mixed>  $attempt
     */
    private function assertAllLayers(array $expected, array $attempt): void
    {
        sort($expected);

        $this->assertSame($expected, $this->engineRules($attempt), 'RuleEngine');
        $this->assertSame($expected, $this->kernelRules($attempt), 'Constraint kernel');
        $this->assertSame($expected, $this->batchRules($attempt), 'BatchConflictValidator');
    }

    /** @return list<string> */
    private function engineRules(array $attempt, bool $all = false): array
    {
        return $this->ruleIds(array_column((new RuleEngine)->validate($attempt), 'rule'), $all);
    }

    /** @return list<string> */
    private function kernelRules(array $attempt): array
    {
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: $this->semester->id,
            departmentId: (int) $attempt['department_id'],
            sectionIds: [(int) $attempt['section_id']],
            courseIds: [(int) $attempt['course_id']],
        );
        $violations = (new SchedulingConstraintKernel)->evaluateRow(ScheduleRow::fromArray($attempt), $snapshot);

        return $this->ruleIds(array_map(static fn ($violation): string => $violation->ruleId, $violations));
    }

    /** @return list<string> */
    private function batchRules(array $attempt): array
    {
        $candidates = Schedule::query()->get()->map(static fn (Schedule $schedule): array => [
            'semester_id' => $schedule->semester_id,
            'section_id' => $schedule->section_id,
            'course_id' => $schedule->course_id,
            'faculty_id' => $schedule->faculty_id,
            'room_id' => $schedule->room_id,
            'department_id' => $schedule->department_id,
            'day' => $schedule->day,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'mode' => $schedule->mode,
        ])->all();
        $candidates[] = $attempt;

        // The candidates replace their persisted copies, as in a batch save.
        $conflicts = app(BatchConflictValidator::class)->validate($candidates, Schedule::query()->pluck('id')->all());

        return $this->ruleIds(array_map(static fn (BatchConflict $conflict): string => $conflict->rule, $conflicts));
    }

    /** @return list<string> */
    private function ruleIds(array $rules, bool $all = false): array
    {
        $rules = array_values(array_unique($all ? $rules : array_intersect($rules, self::CONFLICT_RULES)));
        sort($rules);

        return $rules;
    }

    /** @return array<string, mixed> */
    private function attempt(array $overrides = []): array
    {
        return array_merge($this->row(), $overrides);
    }

    /**
     * Committed, not draft: a generation snapshot leaves out the target
     * section's draft rows because the run replaces them.
     */
    private function persist(array $overrides = []): Schedule
    {
        return Schedule::create(array_merge($this->row(), ['status' => 'faculty_assignment'], $overrides));
    }

    /**
     * Each row gets its own section and course unless told otherwise, so only
     * the resource under test is shared.
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'semester_id' => $this->semester->id,
            'section_id' => $this->section()->id,
            'course_id' => $this->course()->id,
            'faculty_id' => null,
            'room_id' => null,
            'department_id' => $this->department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
        ];
    }

    private function section(): Sections
    {
        $n = ++$this->sequence;

        return Sections::create([
            'section_name' => "PAR-{$n}", 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
    }

    private function course(): Course
    {
        $n = ++$this->sequence;

        return Course::create([
            'course_code' => "PAR{$n}", 'course_name' => "Parity {$n}", 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
    }

    private function room(string $type, array $attributes = []): Rooms
    {
        $n = ++$this->sequence;

        return Rooms::create(array_merge([
            'room_code' => "PAR-R{$n}", 'room_type' => $type, 'status' => 'available', 'department_id' => $this->department->id,
        ], $attributes));
    }
}
