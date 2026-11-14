<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room eligibility follows a course's Lecture and Laboratory Units: a Major with no laboratory
 * units meets in lecture rooms only, in the Schedule Generator and in Manual Plotting alike.
 */
class MajorLaboratoryUnitsRoomEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Departments $department;

    private Sections $section;

    private Curriculum $curriculum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $this->section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
        $this->curriculum = Curriculum::create([
            'name' => 'IT Curriculum', 'department_id' => $this->department->id, 'code' => 'IT-2026',
            'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);
    }

    public function test_generator_never_places_a_major_without_laboratory_units_in_a_laboratory(): void
    {
        $course = $this->course(lecture: 3, lab: 0);
        $this->room('CompLab 1', 'laboratory', allowLectureUsage: true);
        $this->room('Kitchen Lab', 'laboratory');

        $row = $this->solve($course);

        // With only laboratories on offer, the lecture falls back online instead of taking one.
        $this->assertSame('online', $row['mode']);
        $this->assertNull($row['room_id']);
    }

    public function test_generator_places_a_major_without_laboratory_units_in_a_lecture_room(): void
    {
        $course = $this->course(lecture: 3, lab: 0);
        $this->room('CompLab 1', 'laboratory', allowLectureUsage: true);
        $lecture = $this->room('R101', 'lecture');

        $row = $this->solve($course);

        $this->assertSame('on-site', $row['mode']);
        $this->assertSame($lecture->id, $row['room_id']);
    }

    public function test_units_decide_over_a_stored_laboratory_room_type(): void
    {
        // A Major stored as needing a laboratory, though its units are all lecture.
        $course = $this->course(lecture: 3, lab: 0, roomType: 'laboratory');
        $this->room('CompLab 1', 'laboratory');
        $lecture = $this->room('R101', 'lecture');

        $this->assertSame('lecture', SchedulingPolicy::effectiveRoomType($course, (int) $this->department->id));
        $this->assertSame($lecture->id, $this->solve($course)['room_id']);
    }

    public function test_generator_still_places_a_major_with_laboratory_units_in_a_laboratory(): void
    {
        $course = $this->course(lecture: 0, lab: 3, roomType: 'laboratory');
        $lab = $this->room('CompLab 1', 'laboratory');
        $this->room('R101', 'lecture');

        $row = $this->solve($course);

        $this->assertSame('on-site', $row['mode']);
        $this->assertSame($lab->id, $row['room_id']);
    }

    public function test_manual_validation_rejects_a_laboratory_with_the_lecture_room_message(): void
    {
        $course = $this->course(lecture: 3, lab: 0);
        $lab = $this->room('CompLab 1', 'laboratory', allowLectureUsage: true);

        foreach ([null, 'lecture'] as $meetingType) {
            $violation = app(RuleEngine::class)->checkRoomTypeMatch(
                courseId: (int) $course->id,
                roomId: (int) $lab->id,
                deliveryMode: 'on-site',
                meetingType: $meetingType,
                departmentId: (int) $this->department->id,
            );

            $this->assertSame('room_type_match', $violation['rule'] ?? null);
            $this->assertSame('This course has no laboratory units. Please select a lecture room.', $violation['message'] ?? null);
        }
    }

    public function test_manual_validation_refuses_a_laboratory_meeting_for_a_major_without_laboratory_units(): void
    {
        $course = $this->course(lecture: 3, lab: 0);
        $lecture = $this->room('R101', 'lecture');
        $lab = $this->room('CompLab 1', 'laboratory');

        foreach ([$lecture, $lab] as $room) {
            $violation = app(RuleEngine::class)->checkRoomTypeMatch(
                courseId: (int) $course->id,
                roomId: (int) $room->id,
                deliveryMode: 'on-site',
                meetingType: 'laboratory',
                departmentId: (int) $this->department->id,
            );

            $this->assertSame('room_type_match', $violation['rule'] ?? null);
        }
    }

    public function test_manual_validation_accepts_a_laboratory_for_a_major_with_laboratory_units(): void
    {
        $course = $this->course(lecture: 0, lab: 3, roomType: 'laboratory');
        $lab = $this->room('CompLab 1', 'laboratory');

        $this->assertNull(app(RuleEngine::class)->checkRoomTypeMatch(
            courseId: (int) $course->id,
            roomId: (int) $lab->id,
            deliveryMode: 'on-site',
            departmentId: (int) $this->department->id,
        ));
    }

    public function test_manual_recommendations_exclude_laboratories_for_a_major_without_laboratory_units(): void
    {
        $course = $this->course(lecture: 3, lab: 0);
        $this->room('CompLab 1', 'laboratory', allowLectureUsage: true);
        $this->room('R101', 'lecture');

        $result = $this->find($course, 6);

        $this->assertNotEmpty($result['slots']);
        $this->assertSame(['lecture'], array_values(array_unique(array_column($result['slots'], 'room_type'))));
        $this->assertNotContains('CompLab 1', array_column($result['rooms'], 'room_code'));
    }

    public function test_manual_recommendations_offer_laboratories_to_a_major_with_laboratory_units(): void
    {
        $course = $this->course(lecture: 0, lab: 3, roomType: 'laboratory');
        $this->room('CompLab 1', 'laboratory');

        $this->assertContains('CompLab 1', array_column($this->find($course, 6)['rooms'], 'room_code'));
    }

    private function course(int $lecture, int $lab, string $roomType = 'lecture'): Course
    {
        $course = Course::create([
            'course_code' => 'IT 10'.($lecture * 10 + $lab),
            'course_name' => 'Major Course',
            'lecture_hours' => $lecture,
            'lab_hours' => $lab,
            'units' => $lecture + $lab,
            'course_category' => 'major',
            'room_type_required' => $roomType,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $this->curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return $course;
    }

    private function room(string $code, string $type, bool $allowLectureUsage = false): Rooms
    {
        return Rooms::create([
            'room_code' => $code,
            'building' => 'Building 4',
            'room_type' => $type,
            'allow_lecture_usage' => $allowLectureUsage,
            'status' => 'available',
            'department_id' => $this->department->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function solve(Course $course): array
    {
        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $this->section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            maxIterations: 50000,
            timeoutSeconds: 4,
        );

        $this->assertNotEmpty($solutions);

        return $solutions[0]['schedules'][0];
    }

    private function find(Course $course, int $durationSlots): array
    {
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: (int) $this->semester->id,
            departmentId: (int) $this->department->id,
            sectionIds: [(int) $this->section->id],
            courseIds: [(int) $course->id],
        );

        return app(AvailableSlotFinder::class)->find(
            snapshot: $snapshot,
            sectionId: (int) $this->section->id,
            courseId: (int) $course->id,
            durationSlots: $durationSlots,
            modes: ['on-site'],
        );
    }
}
