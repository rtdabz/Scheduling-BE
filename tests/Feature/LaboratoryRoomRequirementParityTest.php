<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the server side of the room-type contract that the browser mirrors in
 * useConflict.ts.
 *
 * RuleEngine::checkRoomTypeMatch derives the required room type as
 * `meeting_type ?? (isLaboratoryCourse($course) ? 'laboratory' : room_type_required)`,
 * so an *unsplit* course with a laboratory component needs a laboratory room even
 * when its `room_type_required` column says 'lecture'. The client used to read
 * `room_type_required` alone and offered a lecture room instead.
 */
class LaboratoryRoomRequirementParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsplit_course_with_lab_hours_requires_a_laboratory_room(): void
    {
        [$term, $dept, $section, $lecture, $laboratory] = $this->fixture();
        $course = $this->course('LAB101', lectureHours: 2, labHours: 3, roomTypeRequired: 'lecture');

        $inLectureRoom = $this->rules($this->attempt($term, $dept, $section, $course, $lecture->id));
        $this->assertContains('room_type_match', $inLectureRoom);

        $inLabRoom = $this->rules($this->attempt($term, $dept, $section, $course, $laboratory->id));
        $this->assertNotContains('room_type_match', $inLabRoom);
    }

    public function test_a_lab_only_course_also_requires_a_laboratory_room(): void
    {
        [$term, $dept, $section, $lecture, $laboratory] = $this->fixture();
        $course = $this->course('LAB102', lectureHours: 0, labHours: 3, roomTypeRequired: 'lecture');

        $this->assertContains('room_type_match', $this->rules($this->attempt($term, $dept, $section, $course, $lecture->id)));
        $this->assertNotContains('room_type_match', $this->rules($this->attempt($term, $dept, $section, $course, $laboratory->id)));
    }

    public function test_the_lecture_meeting_of_a_split_may_use_a_lecture_room(): void
    {
        [$term, $dept, $section, $lecture, $laboratory] = $this->fixture();
        $course = $this->course('LAB103', lectureHours: 2, labHours: 3, roomTypeRequired: 'lecture');

        // meeting_type overrides the laboratory inference, which is why the client
        // has to accept either physical room type for a mixed split.
        $lectureMeeting = $this->attempt($term, $dept, $section, $course, $lecture->id);
        $lectureMeeting['meeting_type'] = 'lecture';
        $this->assertNotContains('room_type_match', $this->rules($lectureMeeting));

        $labMeeting = $this->attempt($term, $dept, $section, $course, $laboratory->id);
        $labMeeting['meeting_type'] = 'laboratory';
        $this->assertNotContains('room_type_match', $this->rules($labMeeting));
    }

    public function test_a_lecture_only_course_is_unaffected(): void
    {
        [$term, $dept, $section, $lecture] = $this->fixture();
        $course = $this->course('LEC101', lectureHours: 3, labHours: 0, roomTypeRequired: 'lecture');

        $this->assertNotContains('room_type_match', $this->rules($this->attempt($term, $dept, $section, $course, $lecture->id)));
    }

    /** @return list<string> */
    private function rules(array $attempt): array
    {
        return array_values(array_map(
            static fn (array $violation): string => (string) ($violation['rule'] ?? ''),
            app(RuleEngine::class)->validate($attempt),
        ));
    }

    private function attempt(Terms $term, Departments $dept, Sections $section, Course $course, int $roomId): array
    {
        return [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $dept->id,
            'room_id' => $roomId,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
        ];
    }

    /** @return array{0: Terms, 1: Departments, 2: Sections, 3: Rooms, 4: Rooms} */
    private function fixture(): array
    {
        $term = Terms::firstOrCreate(
            ['academic_year' => '2026-2027', 'semester' => '1st'],
            ['is_active' => true, 'is_enabled' => true],
        );
        $dept = Departments::firstOrCreate(
            ['department_code' => 'LABD'],
            ['department_name' => 'Lab Dept', 'scheduling_profile' => 'laboratory_enabled'],
        );
        $section = Sections::firstOrCreate(
            ['section_name' => 'LAB-1A', 'department_id' => $dept->id, 'term_id' => $term->id],
            ['year_level' => '1', 'semester' => '1st', 'status' => 'active'],
        );
        $lecture = Rooms::firstOrCreate(
            ['room_code' => 'LEC-A'],
            ['room_type' => 'lecture', 'status' => 'available', 'department_id' => $dept->id],
        );
        $laboratory = Rooms::firstOrCreate(
            ['room_code' => 'LAB-A'],
            ['room_type' => 'laboratory', 'status' => 'available', 'department_id' => $dept->id],
        );

        return [$term, $dept, $section, $lecture, $laboratory];
    }

    private function course(string $code, int $lectureHours, int $labHours, string $roomTypeRequired): Course
    {
        return Course::firstOrCreate(
            ['course_code' => $code],
            [
                'course_name' => "Course {$code}",
                'lecture_hours' => $lectureHours,
                'lab_hours' => $labHours,
                'units' => max(1, $lectureHours + $labHours),
                'course_category' => 'major',
                'room_type_required' => $roomTypeRequired,
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => null,
                'status' => 'active',
            ],
        );
    }
}
