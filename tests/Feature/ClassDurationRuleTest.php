<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * class_duration: a section's meetings for one course may not add up to more
 * weekly time than the course carries, however those meetings are shaped.
 */
class ClassDurationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_a_course_twice_for_one_section_is_refused(): void
    {
        $f = $this->fixture();
        $this->persist($f, 'Monday', '08:00', '11:00');

        $violation = $this->durationViolation($f, 'Friday', '08:00', '11:00');

        $this->assertNotNull($violation);
        $this->assertSame(360, $violation['scheduled_minutes']);
        $this->assertSame(180, $violation['allowed_minutes']);
    }

    public function test_a_stretched_single_meeting_is_refused(): void
    {
        $f = $this->fixture();

        $this->assertNotNull($this->durationViolation($f, 'Monday', '08:00', '12:00'));
    }

    public function test_an_mwf_set_of_shorter_meetings_fits(): void
    {
        $f = $this->fixture();
        $this->persist($f, 'Monday', '08:00', '09:00');
        $this->persist($f, 'Wednesday', '08:00', '09:00');

        $this->assertNull($this->durationViolation($f, 'Friday', '08:00', '09:00'));
    }

    public function test_a_lecture_and_laboratory_split_may_use_its_longer_generator_shape(): void
    {
        // 3 units as a single block is 3 hours, but split it is 2 lecture hours
        // plus one 3-hour laboratory: 5 hours, which the Generator can produce.
        $f = $this->fixture(lectureHours: 2, labHours: 1);
        $this->persist($f, 'Monday', '08:00', '10:00');

        $this->assertNull($this->durationViolation($f, 'Tuesday', '08:00', '11:00'));
        $this->assertNotNull($this->durationViolation($f, 'Tuesday', '08:00', '11:30'));
    }

    public function test_data_already_over_does_not_block_an_edit_that_adds_no_time(): void
    {
        $f = $this->fixture();
        $first = $this->persist($f, 'Monday', '08:00', '11:00');
        $this->persist($f, 'Friday', '08:00', '11:00');

        // Re-validating an existing meeting unchanged, as instructor assignment does.
        $this->assertNull($this->durationViolation($f, 'Monday', '08:00', '11:00', ignore: $first->id));
        // Lengthening it still makes things worse, so it is refused.
        $this->assertNotNull($this->durationViolation($f, 'Monday', '08:00', '12:00', ignore: $first->id));
    }

    public function test_rejected_and_revision_meetings_still_count_toward_the_week(): void
    {
        // Both are live classes awaiting a fix and resubmission, not dead rows.
        $f = $this->fixture();
        $meeting = $this->persist($f, 'Monday', '08:00', '11:00');

        foreach (['rejected', 'revision'] as $status) {
            $meeting->update(['status' => $status]);

            $this->assertNotNull($this->durationViolation($f, 'Friday', '08:00', '11:00'), $status);
        }
    }

    /** @return array<string, mixed>|null */
    private function durationViolation(array $f, string $day, string $start, string $end, ?int $ignore = null): ?array
    {
        $violations = app(RuleEngine::class)->validate([
            'semester_id' => $f['semester']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'department_id' => $f['department']->id,
            'room_id' => $f['room']->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
            'ignore_schedule_id' => $ignore,
        ]);

        return collect($violations)->firstWhere('rule', 'class_duration');
    }

    private function persist(array $f, string $day, string $start, string $end): Schedule
    {
        return Schedule::create([
            'semester_id' => $f['semester']->id,
            'section_id' => $f['section']->id,
            'course_id' => $f['course']->id,
            'department_id' => $f['department']->id,
            'room_id' => $f['otherRoom']->id,
            'day' => $day,
            'start_time' => $start.':00',
            'end_time' => $end.':00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(int $lectureHours = 3, int $labHours = 0): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $room = fn (string $code, string $type) => Rooms::create([
            'room_code' => $code, 'building' => 'IT Building', 'room_type' => $type,
            'status' => 'available', 'department_id' => $department->id,
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'section' => Sections::create([
                'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
            ]),
            'course' => Course::create([
                'course_code' => 'IT 101', 'course_name' => 'Programming',
                'lecture_hours' => $lectureHours, 'lab_hours' => $labHours, 'units' => $lectureHours + $labHours,
                'course_category' => 'major',
                'room_type_required' => $labHours > 0 ? 'laboratory' : 'lecture',
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ]),
            'room' => $room('IT 201', $labHours > 0 ? 'laboratory' : 'lecture'),
            // Persisted meetings sit in another room so only duration is under test.
            'otherRoom' => $room('IT 202', $labHours > 0 ? 'laboratory' : 'lecture'),
        ];
    }
}
