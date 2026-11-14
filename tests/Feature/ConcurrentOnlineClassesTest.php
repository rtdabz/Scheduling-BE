<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Online is a shared resource like the field: any number of sections may take
 * the same online course at the same time, with no room. Section and instructor
 * double-bookings stay blocked on every path.
 */
class ConcurrentOnlineClassesTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Departments $department;

    private Sections $sectionA;

    private Sections $sectionB;

    private Course $course;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->department = Departments::create(['department_name' => 'College of Information Technology', 'department_code' => 'CIT']);
        $program = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        [$this->sectionA, $this->sectionB] = array_map(fn (string $name): Sections => Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->department->id,
            'program_id' => $program->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]), ['IT 1A', 'IT 1B']);
        $this->course = $this->course('GEC 1');
        $this->user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]));
    }

    public function test_manual_save_places_the_same_online_course_for_another_section_at_once(): void
    {
        $this->persist($this->sectionA, $this->course, 'Monday', '08:00', '09:30');

        $this->actingAs($this->user)->postJson('/api/schedules', $this->row($this->sectionB, $this->course, 'Monday', '08:30', '10:00'))
            ->assertSuccessful();

        $this->assertSame(2, Schedule::query()->where('course_id', $this->course->id)->where('mode', 'online')->whereNull('room_id')->count());
    }

    public function test_manual_save_still_refuses_a_section_double_booking_online(): void
    {
        $this->persist($this->sectionB, $this->course('GEC 2'), 'Monday', '08:00', '09:30');

        $this->actingAs($this->user)->postJson('/api/schedules', $this->row($this->sectionB, $this->course, 'Monday', '08:30', '10:00'))
            ->assertUnprocessable();

        $this->assertSame(0, Schedule::query()->where('course_id', $this->course->id)->count());
    }

    public function test_batch_save_places_the_same_online_course_for_two_sections_at_once(): void
    {
        $this->actingAs($this->user)->postJson('/api/schedules/batch', ['operations' => [
            $this->row($this->sectionA, $this->course, 'Tuesday', '10:00', '11:30'),
            $this->row($this->sectionB, $this->course, 'Tuesday', '10:00', '11:30'),
        ]])->assertOk();

        $this->assertSame(2, Schedule::query()->where('course_id', $this->course->id)->where('day', 'Tuesday')->count());
    }

    public function test_batch_save_still_refuses_an_instructor_teaching_both_online_sections_at_once(): void
    {
        $faculty = Faculty::create([
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
        $this->persist($this->sectionA, $this->course, 'Tuesday', '10:00', '11:30', ['faculty_id' => $faculty->id]);

        $this->actingAs($this->user)->postJson('/api/schedules/batch', ['operations' => [
            [...$this->row($this->sectionB, $this->course, 'Tuesday', '10:00', '11:30'), 'faculty_id' => $faculty->id],
        ]])->assertUnprocessable();

        $this->assertSame(0, Schedule::query()->where('section_id', $this->sectionB->id)->count());
    }

    public function test_recommendations_offer_the_online_slot_another_section_already_uses(): void
    {
        $this->persist($this->sectionA, $this->course, 'Monday', '08:00', '09:30');
        // The section's own class blocks Tuesday morning.
        $this->persist($this->sectionB, $this->course('GEC 2'), 'Tuesday', '08:00', '09:30');

        $slots = $this->actingAs($this->user)->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $this->sectionB->id,
            'course_id' => $this->course->id,
            'duration_slots' => 3,
            'modes' => ['online'],
        ])->assertOk()->json('slots');

        $starts = static fn (string $day): array => array_values(array_map(
            static fn (array $slot): string => substr((string) $slot['start_time'], 0, 5),
            array_filter($slots, static fn (array $slot): bool => $slot['day'] === $day && $slot['mode'] === 'online'),
        ));
        // Slots step by the class length: 07:00-08:30 and 08:30-10:00 both overlap
        // section A's Monday meeting and are still offered.
        $this->assertContains('07:00', $starts('Monday'));
        $this->assertContains('08:30', $starts('Monday'));
        $this->assertNotContains('07:00', $starts('Tuesday'));
        $this->assertNotContains('08:30', $starts('Tuesday'));
    }

    public function test_generation_places_the_course_online_beside_another_sections_online_meetings(): void
    {
        // Section A takes the course online all day, every day the solver may use.
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $this->persist($this->sectionA, $this->course, $day, '07:00', '20:30');
        }
        // Section B is busy all Monday, so generation must keep off that day.
        $this->persist($this->sectionB, $this->course('GEC 2'), 'Monday', '07:00', '20:30');

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $this->sectionB->id,
            courseIds: [$this->course->id],
            maxSolutions: 1,
            deliveryMode: 'online',
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertNotEmpty($solutions[0]['schedules']);
        foreach ($solutions[0]['schedules'] as $schedule) {
            $this->assertSame('online', $schedule['mode']);
            $this->assertNull($schedule['room_id']);
            $this->assertNotSame('Monday', $schedule['day']);
        }
    }

    /** @return array<string, mixed> */
    private function row(Sections $section, Course $course, string $day, string $start, string $end): array
    {
        return [
            'semester_id' => $this->semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $this->department->id,
            'room_id' => null,
            'mode' => 'online',
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'draft',
        ];
    }

    private function persist(Sections $section, Course $course, string $day, string $start, string $end, array $extra = []): Schedule
    {
        return Schedule::create([...$this->row($section, $course, $day, $start, $end), ...$extra]);
    }

    private function course(string $code): Course
    {
        $course = Course::create([
            'course_code' => $code, 'course_name' => "Course {$code}", 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
        $curriculum = Curriculum::query()->firstOrCreate(
            ['code' => 'IT-2026'],
            ['name' => 'IT Curriculum', 'department_id' => $this->department->id, 'effective_school_year' => '2026-2027', 'status' => 'active'],
        );
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return $course;
    }
}
