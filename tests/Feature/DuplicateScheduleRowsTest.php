<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The same meeting stored twice must reach the solver once. Each generation
 * stamps a fresh split_group_id, so keying the snapshot on it let repeated
 * saves through as separate bookings and inflated room occupancy.
 */
class DuplicateScheduleRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_rows_for_one_meeting_collapse_in_the_snapshot(): void
    {
        $context = $this->scaffold();

        // One meeting, stored four times, each with its own split group id.
        foreach (range(1, 4) as $ignored) {
            $schedule = Schedule::create($this->meeting($context));
            ScheduleSplit::create([
                'schedule_id' => $schedule->id,
                'split_group_id' => (string) Str::uuid(),
                'meeting_type' => 'laboratory',
                'meeting_index' => 0,
            ]);
        }

        $this->assertSame(4, Schedule::query()->count(), 'Fixture did not create the duplicates.');

        $snapshot = app(SchedulingSnapshotRepository::class)->captureForConfiguration(
            (int) $context['semester']->id,
            (int) $context['department']->id,
            new GenerationConfiguration(
                sectionId: (int) $context['other_section']->id,
                courseIds: [(int) $context['course']->id],
            ),
        );

        $this->assertCount(
            1,
            $snapshot->persistedSchedules,
            'Duplicate rows for one meeting reached the solver as separate bookings.',
        );
    }

    public function test_the_two_halves_of_a_split_course_are_not_merged(): void
    {
        $context = $this->scaffold();
        $groupId = (string) Str::uuid();

        $lecture = Schedule::create($this->meeting($context, ['day' => 'Monday', 'start_time' => '09:00:00', 'end_time' => '11:00:00']));
        ScheduleSplit::create(['schedule_id' => $lecture->id, 'split_group_id' => $groupId, 'meeting_type' => 'lecture', 'meeting_index' => 0]);

        $laboratory = Schedule::create($this->meeting($context, ['day' => 'Wednesday', 'start_time' => '13:00:00', 'end_time' => '16:00:00']));
        ScheduleSplit::create(['schedule_id' => $laboratory->id, 'split_group_id' => $groupId, 'meeting_type' => 'laboratory', 'meeting_index' => 1]);

        $snapshot = app(SchedulingSnapshotRepository::class)->captureForConfiguration(
            (int) $context['semester']->id,
            (int) $context['department']->id,
            new GenerationConfiguration(
                sectionId: (int) $context['other_section']->id,
                courseIds: [(int) $context['course']->id],
            ),
        );

        $this->assertCount(
            2,
            $snapshot->persistedSchedules,
            'The lecture and laboratory halves of one split course were wrongly merged.',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function meeting(array $context, array $overrides = []): array
    {
        return array_merge([
            'semester_id' => $context['semester']->id,
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'room_id' => $context['room']->id,
            'department_id' => $context['department']->id,
            'day' => 'Tuesday',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ], $overrides);
    }

    private function scaffold(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);

        // The snapshot is captured for a different section, so the duplicated
        // rows count as outside bookings rather than replaceable ones.
        $otherSection = Sections::create([
            'section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'LAB-1', 'building' => 'Main', 'room_type' => 'laboratory',
            'status' => 'available', 'department_id' => $department->id, 'max_concurrent_classes' => 1,
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum', 'department_id' => $department->id,
            'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming 1',
            'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'laboratory',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return [
            'semester' => $semester,
            'department' => $department,
            'section' => $section,
            'other_section' => $otherSection,
            'room' => $room,
            'course' => $course,
        ];
    }
}
