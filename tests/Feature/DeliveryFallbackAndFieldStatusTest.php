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
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Generation\GenerateSchedulePlan;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A lecture goes online only after every face-to-face placement has failed,
 * and a course is Field only while the department's configuration says so.
 */
class DeliveryFallbackAndFieldStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One lecture room with two free Monday windows. The one-hour course is
     * forced onto Monday and placed first; its greedy choice takes the window
     * the two-hour course needs. A plain lecture's online candidates carried
     * no fallback marker, so the physical-first pass kept them and stranded
     * the second course online instead of moving the first.
     */
    public function test_a_lecture_is_not_pushed_online_while_another_arrangement_keeps_both_in_rooms(): void
    {
        $context = $this->scaffold();
        [$short, $long] = [$this->course($context, 'GE 101', 1), $this->course($context, 'GE 102', 2)];

        $room = $this->lectureRoom($context, 'LEC-1');
        $this->bookRoom($context, $room, [
            ['Monday', '07:00:00', '09:00:00'],
            ['Monday', '11:00:00', '13:00:00'],
            ['Monday', '14:00:00', '20:30:00'],
        ]);
        DB::table('department_forced_course_days')->insert([
            'department_id' => $context['department']->id,
            'course_id' => $short->id,
            'day' => 'Monday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plans = app(GenerateSchedulePlan::class)->generate(
            semesterId: (int) $context['semester']->id,
            departmentId: (int) $context['department']->id,
            configuration: new GenerationConfiguration(
                sectionId: (int) $context['section']->id,
                courseIds: [(int) $short->id, (int) $long->id],
                maxSolutions: 1,
                seed: 1234,
            ),
            configurationWarningsConfirmed: true,
        );

        $this->assertNotSame([], $plans);
        $this->assertNotSame([], $plans[0]->rows, 'Generation produced no schedule rows.');
        foreach ($plans[0]->rows as $row) {
            $this->assertSame(
                'on-site',
                $row->mode,
                "Course {$row->courseId} went online although both lectures fit the free room windows.",
            );
            $this->assertSame((int) $room->id, (int) $row->roomId);
        }
    }

    public function test_an_online_candidate_is_a_fallback_unless_the_course_was_set_online(): void
    {
        $context = $this->scaffold();
        // No lecture room at all: online is the only placement left.
        $course = $this->course($context, 'GE 101', 3);

        $solver = app(CspSolver::class);
        $closed = $solver->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: [(int) $course->id],
            maxSolutions: 1,
            seed: 1,
            throwOnEmptyDomain: false,
            allowOnlineFallback: false,
        );
        $this->assertSame([], $closed, 'Closing the online fallback left an online placement in the search.');

        $open = $solver->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: [(int) $course->id],
            maxSolutions: 1,
            seed: 1,
        );
        $this->assertNotSame([], $open);
        $this->assertSame('online', $open[0]['schedules'][0]['mode']);
        $this->assertTrue($open[0]['schedules'][0]['lecture_online_fallback'] ?? false, 'A lecture moved online must be reported as moved.');

        // Chosen online: not a fallback, so closing fallbacks keeps it.
        $chosen = $solver->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: [(int) $course->id],
            maxSolutions: 1,
            deliveryModesByCourseId: [(int) $course->id => 'online'],
            seed: 1,
            allowOnlineFallback: false,
        );
        $this->assertNotSame([], $chosen);
        $this->assertSame('online', $chosen[0]['schedules'][0]['mode']);
        $this->assertArrayNotHasKey('lecture_online_fallback', $chosen[0]['schedules'][0]);
    }

    /**
     * The queue worker outlives many settings saves. Its static field-code
     * cache used to survive them, so a course taken off the field list was
     * still generated as Field. The solver now reads the list from its
     * snapshot, captured when the run starts.
     */
    public function test_the_solver_reads_field_status_from_its_snapshot_not_a_stale_process_cache(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'PE 1', 2, 'minor');
        $this->lectureRoom($context, 'LEC-1');

        $this->configureFieldCode($context, 'PE 1');
        $this->assertTrue(SchedulingPolicy::isFieldCourse($course, (int) $context['department']->id));

        // Removed by another process: this process's cache is not told.
        DB::table('field_course_settings')->delete();

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: [(int) $course->id],
            maxSolutions: 1,
            seed: 1,
        );

        $this->assertNotSame([], $solutions);
        foreach ($solutions[0]['schedules'] as $row) {
            $this->assertNotSame('field', $row['mode'], 'A course no longer on the field list was generated as Field.');
        }
    }

    public function test_a_queued_job_starts_from_the_current_field_list(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'PE 1', 2, 'minor');

        $this->configureFieldCode($context, 'PE 1');
        $this->assertTrue(SchedulingPolicy::isFieldCourse($course, (int) $context['department']->id));
        DB::table('field_course_settings')->delete();

        Event::dispatch(new JobProcessing('database', $this->createMock(Job::class)));

        $this->assertFalse(SchedulingPolicy::isFieldCourse($course, (int) $context['department']->id));
    }

    /** A course's name never makes it Field; only the record or the department list does. */
    public function test_nstp_is_not_field_unless_the_department_configures_it(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'NSTP 1', 3, 'minor');
        $this->lectureRoom($context, 'LEC-1');

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: [(int) $course->id],
            maxSolutions: 1,
            seed: 1,
        );

        $this->assertNotSame([], $solutions);
        $this->assertSame('on-site', $solutions[0]['schedules'][0]['mode']);
    }

    /** @return array{semester: Semester, department: Departments, section: Sections, curriculum: Curriculum} */
    private function scaffold(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'College of Arts',
            'department_code' => 'CAS',
        ]);
        $section = Sections::create([
            'section_name' => 'AB 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        $curriculum = Curriculum::create([
            'name' => 'Arts Curriculum',
            'department_id' => $department->id,
            'code' => 'AB-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        return ['semester' => $semester, 'department' => $department, 'section' => $section, 'curriculum' => $curriculum];
    }

    /** @param array{department: Departments, curriculum: Curriculum} $context */
    private function course(array $context, string $code, int $units, string $category = 'major'): Course
    {
        $course = Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => $units,
            'lab_hours' => 0,
            'units' => $units,
            'course_category' => $category,
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'status' => 'active',
        ]);
        $context['curriculum']->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return $course;
    }

    /** @param array{department: Departments} $context */
    private function lectureRoom(array $context, string $code): Rooms
    {
        return Rooms::create([
            'room_code' => $code,
            'building' => 'Main',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $context['department']->id,
            'max_concurrent_classes' => 1,
        ]);
    }

    /** @param array{department: Departments} $context */
    private function configureFieldCode(array $context, string $code): void
    {
        SchedulingPolicy::clearFieldCourseCache();
        DB::table('field_course_settings')->insert([
            'department_id' => $context['department']->id,
            'course_code' => $code,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Books the room solid on every day but Monday, and on Monday for the
     * given windows only.
     *
     * @param  array{semester: Semester, department: Departments}  $context
     * @param  list<array{0: string, 1: string, 2: string}>  $mondayBusy
     */
    private function bookRoom(array $context, Rooms $room, array $mondayBusy): void
    {
        $busy = $mondayBusy;
        foreach (SchedulingPolicy::DAYS as $day) {
            if ($day !== 'Monday') {
                $busy[] = [$day, '07:00:00', '20:30:00'];
            }
        }

        $blocker = Sections::create([
            'section_name' => 'AB 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);
        $filler = $this->course($context, 'GE 199', 1);

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
}
