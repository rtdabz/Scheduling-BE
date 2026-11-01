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
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sunday is the overflow day: generation reaches it only when no Monday to
 * Saturday placement completes the schedule, so the rooms run out first.
 */
class SundayLastResortTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_week_places_nothing_on_sunday(): void
    {
        [$user, $payload] = $this->scenario(sectionCount: 3, courseCount: 6, roomCount: 3);

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'));
        $this->assertNotEmpty($rows);
        $this->assertSame([], $rows->where('day', 'Sunday')->values()->all(), 'Monday to Saturday has room for every class.');
    }

    public function test_sunday_takes_a_class_once_monday_to_saturday_has_no_vacant_room(): void
    {
        [$user, $payload, $context] = $this->scenario(sectionCount: 1, courseCount: 1, roomCount: 1);
        $room = $context['rooms'][0];
        $this->blockRoom($context, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'))->where('section_id', (int) $payload['section_configs'][0]['section_id']);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('Sunday', (string) $row['day']);
            $this->assertSame((int) $room->id, (int) $row['room_id'], 'A real Sunday room beats going roomless on a full weekday.');
        }
    }

    public function test_saturday_fills_before_sunday_once_the_weekdays_are_full(): void
    {
        [$user, $payload, $context] = $this->scenario(sectionCount: 2, courseCount: 2, roomCount: 1);
        $this->blockRoom($context, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);

        $response = $this->preview($user, $payload);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'));
        $this->assertNotEmpty($rows);
        $this->assertSame([], $rows->where('day', 'Sunday')->values()->all(), 'Saturday still has room for every class.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $days
     */
    private function blockRoom(array $context, array $days): void
    {
        $blocker = $this->section($context, 'IT 2A', yearLevel: '2');
        $blockerCourse = $this->course($context, 'IT 199');
        foreach ($days as $day) {
            Schedule::create([
                'semester_id' => $context['semester']->id,
                'section_id' => $blocker->id,
                'course_id' => $blockerCourse->id,
                'room_id' => $context['rooms'][0]->id,
                'department_id' => $context['department']->id,
                'day' => $day,
                'start_time' => SchedulingPolicy::openingTime(),
                'end_time' => SchedulingPolicy::closingTime(),
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }
    }

    private function preview(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/api/schedule-recommendations/year-level-preview',
            $payload,
        );
    }

    /** @return array{0: User, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function scenario(int $sectionCount, int $courseCount, int $roomCount): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $department->forceFill(['sunday_classes_enabled' => true])->save();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $context = compact('semester', 'department', 'program', 'curriculum');

        $sections = array_map(fn (int $i): Sections => $this->section($context, 'IT 1'.chr(65 + $i)), range(0, $sectionCount - 1));
        $courses = array_map(fn (int $i): Course => $this->course($context, 'IT 10'.($i + 1)), range(0, $courseCount - 1));
        foreach ($courses as $course) {
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        }
        $context['rooms'] = array_map(static fn (int $i): Rooms => Rooms::create([
            'room_code' => 'IT 20'.($i + 1),
            'building' => 'IT',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]), range(0, $roomCount - 1));
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => (int) $section->id,
                'course_ids' => array_map(static fn (Course $course): int => (int) $course->id, $courses),
            ], $sections),
        ], $context];
    }

    /** @param  array<string, mixed>  $context */
    private function section(array $context, string $name, string $yearLevel = '1'): Sections
    {
        return Sections::create([
            'section_name' => $name,
            'year_level' => $yearLevel,
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'program_id' => $context['program']->id,
            'curriculum_id' => $context['curriculum']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);
    }

    /** @param  array<string, mixed>  $context */
    private function course(array $context, string $code): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => $code.' Course',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'program_id' => $context['program']->id,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'status' => 'active',
        ]);
    }
}
