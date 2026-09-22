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
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The placement dialog's exhaustive slot list: every room, Monday to Sunday,
 * filtered to what the constraint kernel actually accepts.
 */
class AvailableSlotFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_offers_every_room_and_weekday_for_an_open_week(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $this->lectureRoom($context, 'LEC-1');
        $this->lectureRoom($context, 'LEC-2');

        $result = $this->find($context, $course, 4);

        $this->assertGreaterThan(50, $result['total'], 'An empty week across two rooms should offer many slots.');
        $this->assertCount(2, $result['rooms']);
        foreach ($result['rooms'] as $room) {
            $this->assertGreaterThan(0, $room['slot_count']);
        }

        $days = array_values(array_unique(array_column($result['slots'], 'day')));
        sort($days);
        // A major may use Sunday online only, so an on-site scan stops at Saturday.
        $this->assertSame(
            ['Friday', 'Monday', 'Saturday', 'Thursday', 'Tuesday', 'Wednesday'],
            $days,
        );
    }

    public function test_it_sorts_by_day_then_time_then_room(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $this->lectureRoom($context, 'LEC-2');
        $this->lectureRoom($context, 'LEC-1');

        $slots = $this->find($context, $course, 4)['slots'];

        $previous = null;
        foreach ($slots as $slot) {
            $key = [$slot['day_index'], $slot['start_slot'], $slot['room_code']];
            if ($previous !== null) {
                $this->assertTrue($key >= $previous, 'Slots are not ordered by day, then time, then room.');
            }
            $previous = $key;
        }
    }

    public function test_a_booked_room_loses_only_the_slots_that_overlap(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $room = $this->lectureRoom($context, 'LEC-1');

        $before = $this->find($context, $course, 4);
        $this->bookRoom($context, $room, 'Monday', '07:00:00', '09:00:00');
        SchedulingPolicy::clearTimeCache();
        $after = $this->find($context, $course, 4);

        $this->assertLessThan($before['total'], $after['total'], 'Booking a room freed no slots at all.');

        foreach ($after['slots'] as $slot) {
            $overlapsTheBooking = $slot['day'] === 'Monday'
                && $slot['start_time'] < '09:00:00'
                && $slot['end_time'] > '07:00:00';
            $this->assertFalse($overlapsTheBooking, "A slot overlapping the booked room was still offered: {$slot['day']} {$slot['start_time']}.");
        }
    }

    public function test_a_field_course_is_offered_the_field_and_only_weekdays(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'PATH FIT 1', 2, 'minor');
        $this->lectureRoom($context, 'LEC-1');
        Rooms::create([
            'room_code' => 'FIELD',
            'building' => null,
            'room_type' => 'field',
            'status' => 'available',
            'department_id' => null,
            'max_concurrent_classes' => 5,
        ]);
        DB::table('field_course_settings')->insert([
            'department_id' => $context['department']->id,
            'course_code' => 'PATH FIT 1',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SchedulingPolicy::clearFieldCourseCache();

        $result = $this->find($context, $course, 4, ['field']);

        $this->assertSame(['FIELD'], array_column($result['rooms'], 'room_code'));
        $this->assertSame(
            ['Friday', 'Monday', 'Thursday', 'Tuesday', 'Wednesday'],
            $this->sortedDays($result['slots']),
        );
    }

    public function test_the_endpoint_answers_with_slots_and_room_counts(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $this->lectureRoom($context, 'LEC-1');
        $this->requireProgram($context);

        $response = $this->actingAs($this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $context['department']->id,
        ])))->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $context['section']->id,
            'course_id' => $course->id,
            'duration_slots' => 4,
            'mode' => 'on-site',
        ]);

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('total'));
        $this->assertContains('LEC-1', array_column($response->json('rooms'), 'room_code'));
        $this->assertFalse($response->json('truncated'));
        $this->assertArrayHasKey('start_time', $response->json('slots.0'));
    }

    /**
     * A split whose second meeting is online had no online slot to pick: the
     * query was locked to the first meeting's mode, so the list showed rooms
     * only and the online half of the split was unreachable.
     */
    public function test_it_offers_online_alongside_rooms_by_default(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'GEC 1', 3, 'minor');
        $this->lectureRoom($context, 'LEC-1');

        $result = $this->find($context, $course, 3, AvailableSlotFinder::MODES);

        $byMode = [];
        foreach ($result['rooms'] as $room) {
            $byMode[$room['mode']] = $room['room_code'];
        }

        $this->assertSame('Online', $byMode['online'] ?? null);
        $this->assertSame('LEC-1', $byMode['on-site'] ?? null);
        // A lecture-only minor may also meet in the field, against the virtual
        // field room the snapshot synthesizes when the department has none.
        $this->assertSame('FIELD', $byMode['field'] ?? null);

        $modes = array_values(array_unique(array_column($result['slots'], 'mode')));
        sort($modes);
        $this->assertSame(['field', 'on-site', 'online'], $modes);
    }

    /** A laboratory course cannot go online, so no online slot is offered. */
    public function test_a_laboratory_course_is_offered_no_online_slot(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 210', 3, 'major');
        $course->update(['lab_hours' => 3, 'lecture_hours' => 0, 'room_type_required' => 'laboratory']);
        Rooms::create([
            'room_code' => 'LAB-1',
            'building' => 'Main',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $context['department']->id,
            'max_concurrent_classes' => 1,
        ]);

        $result = $this->find($context, $course, 6, AvailableSlotFinder::MODES);

        $this->assertSame(
            ['on-site'],
            array_values(array_unique(array_column($result['slots'], 'mode'))),
        );
    }

    /** Schedule endpoints refuse a department with no program. */
    private function requireProgram(array $context): void
    {
        Program::create([
            'department_id' => $context['department']->id,
            'code' => 'BSIT',
            'name' => 'Bachelor of Science in Information Technology',
        ]);
    }

    /**
     * The Integrated shape is an on-site laboratory plus an online lecture. The
     * lecture half is where the online option has to come from: asked without a
     * meeting type, the finder rightly refuses online for a laboratory course,
     * so a conflict on the online lecture had no slot that would fix it.
     */
    public function test_the_lecture_half_of_a_laboratory_course_may_be_online(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 5, 'major');
        $course->update(['lecture_hours' => 2, 'lab_hours' => 3, 'room_type_required' => 'laboratory']);
        Rooms::create([
            'room_code' => 'CompLab2',
            'building' => 'Main',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $context['department']->id,
            'max_concurrent_classes' => 1,
        ]);

        $laboratory = $this->find($context, $course, 6, AvailableSlotFinder::MODES, 'laboratory');
        $lecture = $this->find($context, $course, 4, AvailableSlotFinder::MODES, 'lecture');

        $this->assertSame(
            ['on-site'],
            array_values(array_unique(array_column($laboratory['slots'], 'mode'))),
            'The laboratory half must stay on-site.',
        );
        $this->assertContains(
            'online',
            array_column($lecture['slots'], 'mode'),
            'The lecture half of a laboratory course may be online.',
        );
    }

    /**
     * split_group_day_separation refuses two meetings of one course on the same
     * day, so the day the partner meeting holds is never offered — a slot there
     * could only ever fail on save.
     */
    public function test_it_lists_from_the_start_day_then_the_weekdays_and_the_weekend_last(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $this->lectureRoom($context, 'LEC-1');

        $slots = $this->find($context, $course, 4, searchFromDay: 'Wednesday')['slots'];

        $days = array_values(array_unique(array_column($slots, 'day')));
        // The weekdays wrap round from Wednesday; whatever weekend days the
        // rules allow come after all of them.
        $this->assertSame(['Wednesday', 'Thursday', 'Friday', 'Monday', 'Tuesday'], array_slice($days, 0, 5));
        $this->assertSame([], array_diff(array_slice($days, 5), ['Saturday', 'Sunday']));
    }

    public function test_it_never_offers_the_day_a_linked_meeting_already_holds(): void
    {
        $context = $this->scaffold();
        $course = $this->course($context, 'IT 101', 2, 'major');
        $this->lectureRoom($context, 'LEC-1');

        $result = $this->find($context, $course, 4, ['on-site'], null, ['Monday']);

        $this->assertNotContains('Monday', array_column($result['slots'], 'day'));
        $this->assertContains('Tuesday', array_column($result['slots'], 'day'));

        // The room counts come from the same pass, so a badge can never promise
        // slots the list does not show.
        $this->assertSame(
            count($result['slots']),
            array_sum(array_column($result['rooms'], 'slot_count')),
        );
    }

    /** @return list<string> */
    private function sortedDays(array $slots): array
    {
        $days = array_values(array_unique(array_column($slots, 'day')));
        sort($days);

        return $days;
    }

    /**
     * @param  array{semester: Semester, department: Departments, section: Sections}  $context
     * @param  list<string>  $modes
     */
    private function find(array $context, Course $course, int $durationSlots, array $modes = ['on-site'], ?string $meetingType = null, array $excludedDays = [], ?string $searchFromDay = null): array
    {
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: (int) $context['semester']->id,
            departmentId: (int) $context['department']->id,
            sectionIds: [(int) $context['section']->id],
            courseIds: [(int) $course->id],
        );

        return app(AvailableSlotFinder::class)->find(
            snapshot: $snapshot,
            sectionId: (int) $context['section']->id,
            courseId: (int) $course->id,
            durationSlots: $durationSlots,
            modes: $modes,
            meetingType: $meetingType,
            excludedDays: $excludedDays,
            searchFromDay: $searchFromDay,
        );
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
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
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

        return ['semester' => $semester, 'department' => $department, 'section' => $section, 'curriculum' => $curriculum];
    }

    /** @param array{department: Departments, curriculum: Curriculum} $context */
    private function course(array $context, string $code, int $units, string $category): Course
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

    /** @param array{semester: Semester, department: Departments, curriculum: Curriculum} $context */
    private function bookRoom(array $context, Rooms $room, string $day, string $start, string $end): void
    {
        $blocker = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);

        Schedule::create([
            'semester_id' => $context['semester']->id,
            'section_id' => $blocker->id,
            'course_id' => $this->course($context, 'IT 199', 1, 'major')->id,
            'room_id' => $room->id,
            'department_id' => $context['department']->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
            'status' => 'draft',
        ]);
    }
}
