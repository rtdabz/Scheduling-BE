<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Single-meeting classes that occupy a real lecture room are steered to Friday
 * and Saturday so Monday-Thursday lecture-room capacity stays available for the
 * MW and TTh split-session patterns. The preference must never make a section
 * unschedulable, and must not disturb laboratory, online or field placements.
 */
class SingleMeetingLateWeekPriorityTest extends TestCase
{
    use RefreshDatabase;

    private const LATE_WEEK = ['Friday', 'Saturday'];

    public function test_single_meeting_lecture_classes_are_placed_late_in_the_week(): void
    {
        $context = $this->scaffold(lectureRooms: 2);
        $courses = $this->lectureCourses($context, ['IT 101', 'IT 102']);

        $rows = $this->generate($context, $courses);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertContains(
                $row['day'],
                self::LATE_WEEK,
                "Single-meeting lecture landed on {$row['day']} while Friday/Saturday were open.",
            );
        }
    }

    public function test_monday_to_thursday_is_still_used_when_late_week_is_full(): void
    {
        // The only lecture room is booked solid on Friday and Saturday by
        // another section, so the preference cannot be satisfied. The search
        // must fall back to Monday-Thursday rather than fail or drop the room.
        $context = $this->scaffold(lectureRooms: 1);
        $courses = $this->lectureCourses($context, ['IT 101', 'IT 102']);

        $this->bookLectureRoomOn($context, self::LATE_WEEK);

        $rows = $this->generate($context, $courses);

        $this->assertCount(2, $rows, 'The late-week preference made the section unschedulable.');
        foreach ($rows as $row) {
            $this->assertNotNull($row['room_id'], 'A course lost its room to the day preference.');
            $this->assertNotContains(
                $row['day'],
                self::LATE_WEEK,
                'Friday and Saturday were fully booked, so this placement is impossible.',
            );
        }
    }

    /** @param list<string> $days */
    private function bookLectureRoomOn(array $context, array $days): void
    {
        $blocker = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'term_id' => $context['term']->id,
            'status' => 'active',
        ]);

        $filler = $this->course($context, 'IT 199', [
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'room_type_required' => 'lecture',
        ]);

        $room = Rooms::where('room_type', 'lecture')->firstOrFail();

        foreach ($days as $day) {
            \App\Models\Schedule::create([
                'term_id' => $context['term']->id,
                'section_id' => $blocker->id,
                'course_id' => $filler->id,
                'room_id' => $room->id,
                'department_id' => $context['department']->id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '20:30:00',
                'mode' => 'on-site',
                'status' => 'finalized',
            ]);
        }
    }

    public function test_laboratory_single_meetings_keep_their_existing_day_placement(): void
    {
        $context = $this->scaffold(lectureRooms: 0, laboratoryRooms: 2);

        $courses = [];
        foreach (['IT 201', 'IT 202'] as $code) {
            $courses[] = $this->course($context, $code, [
                'lecture_hours' => 0,
                'lab_hours' => 1,
                'units' => 2,
                'room_type_required' => 'laboratory',
            ]);
        }

        $rows = $this->generate($context, $courses);

        $this->assertCount(2, $rows);
        $this->assertNotEmpty(
            array_filter($rows, static fn (array $row): bool => ! in_array($row['day'], self::LATE_WEEK, true)),
            'Laboratory meetings were pushed late in the week; only lecture rooms are in scope.',
        );
    }

    /** @return array{term: Terms, department: Departments, section: Sections, curriculum: Curriculum} */
    private function scaffold(int $lectureRooms = 0, int $laboratoryRooms = 0): array
    {
        $term = Terms::create([
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
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        for ($index = 1; $index <= $lectureRooms; $index++) {
            Rooms::create([
                'room_code' => "LEC-{$index}",
                'building' => 'Main',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
                'max_concurrent_classes' => 1,
            ]);
        }

        for ($index = 1; $index <= $laboratoryRooms; $index++) {
            Rooms::create([
                'room_code' => "LAB-{$index}",
                'building' => 'Main',
                'room_type' => 'laboratory',
                'status' => 'available',
                'department_id' => $department->id,
                'max_concurrent_classes' => 1,
            ]);
        }

        return [
            'term' => $term,
            'department' => $department,
            'section' => $section,
            'curriculum' => Curriculum::create([
                'name' => 'IT Curriculum',
                'department_id' => $department->id,
                'code' => 'IT-2026',
                'effective_school_year' => '2026-2027',
                'status' => 'active',
            ]),
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<Course>
     */
    private function lectureCourses(array $context, array $codes): array
    {
        return array_map(
            fn (string $code): Course => $this->course($context, $code, [
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'units' => 2,
                'room_type_required' => 'lecture',
            ]),
            $codes,
        );
    }

    private function course(array $context, string $code, array $attributes): Course
    {
        $course = Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'course_category' => 'major',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'status' => 'active',
            ...$attributes,
        ]);

        $context['curriculum']->courses()->attach($course->id, [
            'year_level' => 1,
            'semester' => 1,
        ]);

        return $course;
    }

    /**
     * @param  list<Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function generate(array $context, array $courses): array
    {
        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: array_map(static fn (Course $course): int => (int) $course->id, $courses),
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions, 'Generation produced no solution.');

        return $solutions[0]['schedules'];
    }
}
