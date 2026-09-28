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
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Support\ProgramRoomShares;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two programs of one department sharing its only laboratory. The division is
 * decided before anyone generates (ProgramRoomShares): X owns Monday, Wednesday
 * and Friday, Y owns Tuesday, Thursday and Saturday. The validator, the
 * constraint kernel and the generator must all hold each program to its days.
 */
class ProgramRoomShareTest extends TestCase
{
    use RefreshDatabase;

    private const X_DAYS = ['Monday', 'Wednesday', 'Friday'];

    private const Y_DAYS = ['Tuesday', 'Thursday', 'Saturday'];

    public function test_the_laboratory_is_divided_equally_by_whole_days(): void
    {
        $f = $this->fixture();

        $shares = app(ProgramRoomShares::class)->forDepartment($f['cas']->id, $f['semester']->id)[$f['lab']->id];

        foreach (self::X_DAYS as $day) {
            $this->assertSame($f['x']->id, $shares[$day]['program_id'], $day);
        }
        foreach (self::Y_DAYS as $day) {
            $this->assertSame($f['y']->id, $shares[$day]['program_id'], $day);
        }
        $this->assertArrayNotHasKey('Sunday', $shares);
        $this->assertFalse($shares['Monday']['borrowable']);
    }

    /** A class crossing noon fits on the program's own day: the split is by whole days. */
    public function test_the_validator_keeps_each_program_to_its_own_days(): void
    {
        $f = $this->fixture();

        $refusal = $this->roomRefusal($this->attempt($f, $f['sectionY'], 'Monday', '10:00:00', '13:00:00'));
        $this->assertNotNull($refusal);
        $this->assertStringContainsString("Room CAS-LAB is X's on Monday", $refusal);

        $this->assertNull($this->roomRefusal($this->attempt($f, $f['sectionY'], 'Tuesday', '10:00:00', '13:00:00')));
        $this->assertNull($this->roomRefusal($this->attempt($f, $f['sectionX'], 'Monday', '10:00:00', '13:00:00')));
    }

    public function test_the_constraint_kernel_agrees_with_the_validator(): void
    {
        $f = $this->fixture();
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            (int) $f['semester']->id,
            (int) $f['cas']->id,
            [(int) $f['sectionY']->id],
            [(int) $f['course']->id],
        );

        $refused = function (string $day) use ($f, $snapshot): bool {
            $row = new ScheduleRow(
                semesterId: (int) $f['semester']->id,
                sectionId: (int) $f['sectionY']->id,
                courseId: (int) $f['course']->id,
                departmentId: (int) $f['cas']->id,
                day: $day,
                startTime: '10:00:00',
                endTime: '13:00:00',
                mode: 'on-site',
                roomId: (int) $f['lab']->id,
            );

            return in_array('room_department_alignment', array_map(
                static fn (ConstraintViolation $violation): string => $violation->ruleId,
                app(SchedulingConstraintKernel::class)->evaluateRow($row, $snapshot),
            ), true);
        };

        $this->assertTrue($refused('Monday'));
        $this->assertFalse($refused('Tuesday'));
    }

    /**
     * Both programs generate from the same state -- neither has saved -- and
     * still cannot collide: each lands on its own days.
     */
    public function test_both_programs_generating_at_once_land_on_their_own_days(): void
    {
        $f = $this->fixture();

        $rowX = $this->generate($f, $f['sectionX']);
        $rowY = $this->generate($f, $f['sectionY']);

        $this->assertSame((int) $f['lab']->id, $rowX->roomId);
        $this->assertSame((int) $f['lab']->id, $rowY->roomId);
        $this->assertContains($rowX->day, self::X_DAYS);
        $this->assertContains($rowY->day, self::Y_DAYS);
    }

    public function test_another_programs_day_opens_once_that_program_has_saved_every_section(): void
    {
        $f = $this->fixture();
        $mondayForY = $this->attempt($f, $f['sectionY'], 'Monday', '10:00:00', '13:00:00');

        $this->assertNotNull($this->roomRefusal($mondayForY));

        // X's only active section now has a saved schedule: X is done.
        Schedule::create([
            'semester_id' => $f['semester']->id,
            'section_id' => $f['sectionX']->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['lab']->id,
            'department_id' => $f['cas']->id,
            'day' => 'Wednesday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);
        $this->assertNull($this->roomRefusal($mondayForY));

        // Home rooms only: never another program's day.
        $f['cas']->forceFill(['room_sharing_policy' => Departments::ROOM_SHARING_STRICT])->save();
        $this->assertStringContainsString('keeps each program to its own rooms and days', (string) $this->roomRefusal($mondayForY));
    }

    public function test_a_home_room_belongs_to_its_program_every_day(): void
    {
        $f = $this->fixture();
        $f['cas']->forceFill(['room_sharing_policy' => Departments::ROOM_SHARING_HOME_FIRST])->save();
        $f['lab']->forceFill(['home_program_id' => $f['y']->id])->save();

        $this->assertNull($this->roomRefusal($this->attempt($f, $f['sectionY'], 'Monday', '10:00:00', '13:00:00')));
        $this->assertNotNull($this->roomRefusal($this->attempt($f, $f['sectionX'], 'Monday', '10:00:00', '13:00:00')));
    }

    public function test_a_department_with_one_program_is_not_divided(): void
    {
        $f = $this->fixture();
        $f['y']->delete();

        $this->assertSame([], app(ProgramRoomShares::class)->forDepartment($f['cas']->id, $f['semester']->id));
        $this->assertNull($this->roomRefusal($this->attempt($f, $f['sectionX'], 'Tuesday', '10:00:00', '13:00:00')));
    }

    public function test_the_program_rooms_page_shows_the_division(): void
    {
        $f = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $f['cas']->id]);

        $this->actingAs($secretary)
            ->getJson('/api/program-rooms')
            ->assertOk()
            ->assertJsonPath('data.rooms.0.days.Monday.program_id', $f['x']->id)
            ->assertJsonPath('data.rooms.0.days.Tuesday.program_id', $f['y']->id)
            ->assertJsonPath('data.rooms.0.days.Monday.borrowable', false);
    }

    /** @return array<string, mixed> */
    private function attempt(array $f, Sections $section, string $day, string $start, string $end): array
    {
        return [
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['lab']->id,
            'department_id' => $f['cas']->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
        ];
    }

    /** The room_department_alignment message the validator gives, or null. A fresh engine per call: its lookups are memoized. */
    private function roomRefusal(array $attempt): ?string
    {
        $violation = collect(app()->make(RuleEngine::class)->validate($attempt))
            ->first(fn (array $violation): bool => $violation['rule'] === 'room_department_alignment');

        return $violation === null ? null : (string) $violation['message'];
    }

    private function generate(array $f, Sections $section): ScheduleRow
    {
        $plans = app(GenerateSchedulePlan::class)->generate(
            semesterId: (int) $f['semester']->id,
            departmentId: (int) $f['cas']->id,
            configuration: new GenerationConfiguration(
                sectionId: (int) $section->id,
                courseIds: [(int) $f['course']->id],
                maxSolutions: 1,
                seed: 1234,
            ),
            configurationWarningsConfirmed: true,
        );

        $this->assertNotSame([], $plans, 'Generation returned no plan at all.');
        $this->assertCount(1, $plans[0]->rows);

        return $plans[0]->rows[0];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $cas = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $x = Program::create(['department_id' => $cas->id, 'code' => 'X', 'name' => 'Program X']);
        $y = Program::create(['department_id' => $cas->id, 'code' => 'Y', 'name' => 'Program Y']);

        $curriculum = Curriculum::create([
            'name' => 'CAS Curriculum',
            'department_id' => $cas->id,
            'code' => 'CAS-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'SCI 101',
            'course_name' => 'Laboratory Course SCI 101',
            'lecture_hours' => 0,
            'lab_hours' => 1,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        $section = fn (string $name, Program $program): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'semester_id' => $semester->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'status' => 'active',
        ]);

        return [
            'semester' => $semester,
            'cas' => $cas,
            'x' => $x,
            'y' => $y,
            'course' => $course,
            'sectionX' => $section('X 1A', $x),
            'sectionY' => $section('Y 1A', $y),
            'lab' => Rooms::create([
                'room_code' => 'CAS-LAB',
                'building' => 'Main',
                'room_type' => 'laboratory',
                'status' => 'available',
                'department_id' => $cas->id,
                'max_concurrent_classes' => 1,
            ]),
        ];
    }
}
