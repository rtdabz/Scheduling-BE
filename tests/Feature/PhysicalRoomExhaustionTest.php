<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Room TBA is a fallback, not a peer of a real room. Whenever an all-physical
 * placement exists, generation must return one; the fallback must still be
 * reachable when it genuinely is the only option.
 */
class PhysicalRoomExhaustionTest extends TestCase
{
    use RefreshDatabase;

    public function test_laboratory_courses_use_real_rooms_when_enough_are_available(): void
    {
        $context = $this->scaffold(['IT 101', 'IT 102']);

        foreach (['LAB-1', 'LAB-2'] as $code) {
            Rooms::create([
                'room_code' => $code,
                'building' => 'Main',
                'room_type' => 'laboratory',
                'status' => 'available',
                'department_id' => $context['department']->id,
                'max_concurrent_classes' => 1,
            ]);
        }

        $plan = $this->generate($context);

        $this->assertNotSame([], $plan->rows, 'Generation produced no schedule rows.');
        $this->assertSame(
            [],
            $plan->unresolvedResources,
            'A laboratory fell back to Room TBA even though a real laboratory room was free.',
        );

        foreach ($plan->rows as $row) {
            $this->assertNotNull(
                $row->roomId,
                "Course {$row->courseId} was placed without a room while laboratories were available.",
            );
        }
    }

    public function test_room_tba_is_still_reachable_when_no_laboratory_room_exists(): void
    {
        $context = $this->scaffold(['IT 101']);

        $plan = $this->generate($context);

        // The strict pass cannot place this course, so the fallback pass must
        // still run and hand back a Room TBA placement rather than no plan.
        $this->assertNotSame([], $plan->rows, 'The Room TBA fallback pass did not run.');
        $this->assertNotSame([], $plan->unresolvedResources);
    }

    /**
     * The lecture half of a split lecture/laboratory course goes online only
     * when no compatible lecture room is free. DefaultLectureLabGenerationTest
     * covers the no-lecture-room case; this covers the opposite one.
     */
    public function test_split_lecture_uses_a_classroom_when_one_is_available(): void
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Programming 1',
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

        Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        // The difference from the existing coverage: a lecture room is free.
        Rooms::create([
            'room_code' => 'LEC-1',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(\App\Services\Scheduling\Engine\CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $lecture = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'lecture');

        $this->assertNotNull($lecture);
        $this->assertSame(
            'on-site',
            $lecture['mode'],
            'The split lecture went online while a lecture room was free.',
        );
        $this->assertNotNull($lecture['room_id']);
    }

    /**
     * A class may begin at the exact minute another ends. The snapshot hands
     * persisted times to the solver as H:i while candidates use H:i:s, so
     * comparing them as raw strings made "11:00" < "11:00:00" true and every
     * back-to-back placement look like a room conflict.
     */
    public function test_a_class_may_start_exactly_when_another_ends_in_the_same_room(): void
    {
        $context = $this->scaffold(['IT 101'], [2]);

        $room = Rooms::create([
            'room_code' => 'LAB-1',
            'building' => 'Main',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $context['department']->id,
            'max_concurrent_classes' => 1,
        ]);

        // LAB-1 is free only on Monday 11:00-13:00, immediately after an
        // existing booking that ends at 11:00.
        $this->occupyLaboratory($context, $room, [
            ['Monday', '07:00:00', '11:00:00'],
            ['Monday', '13:00:00', '20:30:00'],
        ]);

        $plan = $this->generate($context);

        $this->assertCount(1, $plan->rows);
        $row = $plan->rows[0];

        $this->assertNotNull(
            $row->roomId,
            'The only free window abuts an existing booking and was rejected as a conflict.',
        );
        $this->assertSame('Monday', $row->day);
        $this->assertSame('11:00:00', $row->startTime);
    }

    /**
     * The greedy-prefix trap: one laboratory, two free windows, and a course
     * ordered first (by forced day) that fits either of them. Taking the wrong
     * one strands the second course, which can then only reach Room TBA. The
     * strict pass has no TBA to reach for, so it must backtrack the first
     * course instead of stranding the second.
     */
    public function test_first_course_backtracks_rather_than_stranding_the_second_on_room_tba(): void
    {
        $context = $this->scaffold(['IT 101', 'IT 102'], [1, 2]);
        $department = $context['department'];

        $room = Rooms::create([
            'room_code' => 'LAB-1',
            'building' => 'Main',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
            'max_concurrent_classes' => 1,
        ]);

        // Start times step by the meeting duration from the 07:00 opening, so a
        // two-hour class may only begin at 07:00, 09:00, 11:00, ... The free
        // windows are aligned to that grid: 09:00-11:00 fits either course,
        // 13:00-14:00 fits only the one-hour course.
        $this->occupyLaboratory($context, $room, [
            ['Monday', '07:00:00', '09:00:00'],
            ['Monday', '11:00:00', '13:00:00'],
            ['Monday', '14:00:00', '20:30:00'],
        ]);

        // IT 101 is forced onto Monday and is the short course, so the variable
        // ordering places it first. Its greedy choice is the 08:00 window that
        // IT 102 needs in full.
        DB::table('department_forced_course_days')->insert([
            'department_id' => $department->id,
            'course_id' => $context['courses'][0]->id,
            'day' => 'Monday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = $this->generate($context);

        $placements = [];
        foreach ($plan->rows as $row) {
            $placements[$row->courseId] = $row->day.' '.$row->startTime.'-'.$row->endTime
                .' room='.($row->roomId ?? 'TBA');
        }

        $this->assertSame(
            [],
            $plan->unresolvedResources,
            'A course was stranded on Room TBA instead of backtracking the earlier '
            .'placement. Placements: '.json_encode($placements),
        );

        foreach ($plan->rows as $row) {
            $this->assertNotNull(
                $row->roomId,
                'Placements: '.json_encode($placements),
            );
        }
    }

    /**
     * Books the room solid on every day except Monday, and on Monday only for
     * the given windows, so the free Monday gaps are the only options left.
     *
     * @param  array{semester: Semester, department: Departments, section: Sections, courses: list<Course>}  $context
     * @param  list<array{0: string, 1: string, 2: string}>  $mondayBusy
     */
    private function occupyLaboratory(array $context, Rooms $room, array $mondayBusy): void
    {
        $busy = $mondayBusy;
        foreach (SchedulingPolicy::DAYS as $day) {
            if ($day !== 'Monday') {
                $busy[] = [$day, '07:00:00', '20:30:00'];
            }
        }

        $this->bookRoom($context, $room, $busy);
    }

    /**
     * @param  array{semester: Semester, department: Departments, section: Sections, courses: list<Course>}  $context
     * @param  list<array{0: string, 1: string, 2: string}>  $busy
     */
    private function bookRoom(array $context, Rooms $room, array $busy): void
    {
        $blocker = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);

        $filler = Course::create([
            'course_code' => 'IT 199',
            'course_name' => 'Occupying Course',
            'lecture_hours' => 0,
            'lab_hours' => 1,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'status' => 'active',
        ]);

        foreach ($busy as [$day, $start, $end]) {
            Schedule::create([
                'semester_id' => $context['semester']->id,
                'section_id' => $blocker->id,
                'course_id' => $filler->id,
                'room_id' => $room->id,
                'department_id' => $context['department']->id,
                'day' => $day,
                'start_time' => $start,
                'end_time' => $end,
                'mode' => 'on-site',
                'status' => 'finalized',
            ]);
        }
    }

    /**
     * @param  list<string>  $courseCodes
     * @param  list<int>  $unitsPerCourse
     * @return array{semester: Semester, department: Departments, section: Sections, courses: list<Course>}
     */
    private function scaffold(array $courseCodes, array $unitsPerCourse = []): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $courses = [];
        foreach ($courseCodes as $index => $code) {
            $course = Course::create([
                'course_code' => $code,
                'course_name' => "Laboratory Course {$code}",
                'lecture_hours' => 0,
                'lab_hours' => 1,
                'units' => $unitsPerCourse[$index] ?? 2,
                'course_category' => 'major',
                'room_type_required' => 'laboratory',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, [
                'year_level' => 1,
                'semester' => 1,
            ]);
            $courses[] = $course;
        }

        return [
            'semester' => $semester,
            'department' => $department,
            'section' => $section,
            'courses' => $courses,
        ];
    }

    /** @param array{semester: Semester, department: Departments, section: Sections, courses: list<Course>} $context */
    private function generate(array $context): SchedulePlan
    {
        $plans = app(GenerateSchedulePlan::class)->generate(
            semesterId: (int) $context['semester']->id,
            departmentId: (int) $context['department']->id,
            configuration: new GenerationConfiguration(
                sectionId: (int) $context['section']->id,
                courseIds: array_map(
                    static fn (Course $course): int => (int) $course->id,
                    $context['courses'],
                ),
                maxSolutions: 1,
                seed: 1234,
            ),
            configurationWarningsConfirmed: true,
        );

        $this->assertNotSame([], $plans, 'Generation returned no plan at all.');
        $this->assertNotSame(
            'invalid',
            $plans[0]->status->value,
            'Configuration was rejected: '.implode(', ', array_map(
                static fn ($violation): string => $violation->ruleId,
                $plans[0]->violations,
            )),
        );

        return $plans[0];
    }
}
