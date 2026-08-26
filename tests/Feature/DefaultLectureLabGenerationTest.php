<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\CspSolver;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefaultLectureLabGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_honors_forced_course_day_rule(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'NSTP 1',
            'course_name' => 'National Service Training Program',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'field',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'FIELD',
            'building' => 'Campus',
            'room_type' => 'field',
            'status' => 'available',
            'department_id' => null,
        ]);

        DB::table('department_forced_course_days')->insert([
            'department_id' => $department->id,
            'course_id' => $course->id,
            'day' => 'Saturday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertSame('Saturday', $solutions[0]['schedules'][0]['day']);
    }

    public function test_minor_course_can_be_forced_to_saturday(): void
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
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'GEE 1',
            'course_name' => 'General Education Elective',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);
        Rooms::create([
            'room_code' => 'IT 101',
            'building' => 'IT Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        DB::table('department_forced_course_days')->insert([
            'department_id' => $department->id,
            'course_id' => $course->id,
            'day' => 'Saturday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 4321,
        );

        $this->assertNotEmpty($solutions);
        $this->assertSame('Saturday', $solutions[0]['schedules'][0]['day']);
    }

    public function test_generation_reconsiders_valid_candidates_to_remove_same_day_gaps(): void
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
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $room = Rooms::create([
            'room_code' => 'IT 101',
            'building' => 'IT Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $courseIds = [];
        foreach ([1, 2] as $index) {
            $course = Course::create([
                'course_code' => "IT 10{$index}",
                'course_name' => "Lecture Course {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $courseIds[] = (int) $course->id;
            DB::table('department_forced_course_days')->insert([
                'department_id' => $department->id,
                'course_id' => $course->id,
                'day' => 'Monday',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            deliveryModesByCourseId: array_fill_keys($courseIds, 'on-site'),
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $rows = collect($solutions[0]['schedules'])
            ->sortBy('start_time')
            ->values();

        $this->assertCount(2, $rows);
        $this->assertSame('Monday', $rows[0]['day']);
        $this->assertSame($rows[0]['end_time'], $rows[1]['start_time']);
        $this->assertSame($room->id, $rows[0]['room_id']);
        $this->assertSame($room->id, $rows[1]['room_id']);
    }

    public function test_major_course_with_lecture_and_lab_stays_single_block_when_override_is_disabled(): void
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
            'lecture_lab_schedule_override_enabled' => false,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 100',
            'course_name' => 'Computing Fundamentals',
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

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(1, $solutions[0]['schedules']);
        $this->assertArrayNotHasKey('split_group_id', $solutions[0]['schedules'][0]);
        $this->assertSame('laboratory', $solutions[0]['schedules'][0]['meeting_type']);
        $this->assertSame('on-site', $solutions[0]['schedules'][0]['mode']);
        $this->assertNotNull($solutions[0]['schedules'][0]['room_id']);
        $this->assertSame(180, $this->durationMinutes($solutions[0]['schedules'][0]));
    }

    public function test_lecture_only_major_course_is_marked_as_lecture(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 110',
            'course_name' => 'Lecture Only Major',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'IT 105',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(1, $solutions[0]['schedules']);
        $this->assertSame('lecture', $solutions[0]['schedules'][0]['meeting_type']);
        $this->assertSame('on-site', $solutions[0]['schedules'][0]['mode']);
    }

    public function test_csp_does_not_force_lecture_subjects_online_when_rooms_are_available(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 2A',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        foreach (['IT 2B', 'IT 2C', 'IT 2D'] as $sectionName) {
            Sections::create([
                'section_name' => $sectionName,
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        foreach (['IT 201', 'IT 202'] as $roomCode) {
            Rooms::create([
                'room_code' => $roomCode,
                'building' => 'IT Building',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]);
        }

        $courseIds = [];
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        for ($index = 1; $index <= 4; $index++) {
            $course = Course::create([
                'course_code' => "IT 20{$index}",
                'course_name' => "Lecture Course {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $courseIds[] = $course->id;
            $curriculum->courses()->attach($course->id, [
                'year_level' => 2,
                'semester' => 1,
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $modes = collect($solutions[0]['schedules'])
            ->where('meeting_type', 'lecture')
            ->pluck('mode');

        $this->assertSame(4, $modes->count());
        $this->assertSame(0, $modes->filter(fn (string $mode): bool => $mode === 'online')->count());
        $this->assertSame(4, $modes->filter(fn (string $mode): bool => $mode === 'on-site')->count());
    }

    public function test_minor_lecture_courses_can_use_available_classrooms(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 2A',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        foreach (['IT 2B', 'IT 2C', 'IT 2D'] as $sectionName) {
            Sections::create([
                'section_name' => $sectionName,
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        foreach (['IT 211', 'IT 212'] as $roomCode) {
            Rooms::create([
                'room_code' => $roomCode,
                'building' => 'IT Building',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]);
        }

        $curriculum = Curriculum::create([
            'name' => 'IT Minor Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-GEC-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $courseIds = [];
        for ($index = 1; $index <= 4; $index++) {
            $course = Course::create([
                'course_code' => "GEC 20{$index}",
                'course_name' => "Minor Course {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'minor',
                'room_type_required' => 'lecture',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => null,
                'status' => 'active',
            ]);
            $courseIds[] = $course->id;
            $curriculum->courses()->attach($course->id, [
                'year_level' => 2,
                'semester' => 1,
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $modes = collect($solutions[0]['schedules'])->pluck('mode');

        $this->assertSame(0, $modes->filter(fn (string $mode): bool => $mode === 'online')->count());
        $this->assertSame(4, $modes->filter(fn (string $mode): bool => $mode === 'on-site')->count());
    }

    public function test_csp_keeps_valid_physical_candidates_for_later_sections(): void
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
        ]);

        $earlySection = Sections::create([
            'section_name' => 'IT 3A',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $laterSection = Sections::create([
            'section_name' => 'IT 3B',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        foreach (['IT 301', 'IT 302'] as $roomCode) {
            Rooms::create([
                'room_code' => $roomCode,
                'building' => 'IT Building',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]);
        }

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum 3',
            'department_id' => $department->id,
            'code' => 'IT-2026-3',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $courseIds = [];
        for ($index = 1; $index <= 4; $index++) {
            $course = Course::create([
                'course_code' => "IT 30{$index}",
                'course_name' => "Advanced Lecture {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '3',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $courseIds[] = $course->id;
            $curriculum->courses()->attach($course->id, [
                'year_level' => 3,
                'semester' => 1,
            ]);

            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $earlySection->id,
                'department_id' => $department->id,
                'course_id' => $course->id,
                'room_id' => null,
                'day' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday'][$index - 1],
                'start_time' => '07:00:00',
                'end_time' => '10:00:00',
                'mode' => 'online',
                'status' => 'draft',
                'meeting_type' => 'lecture',
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $laterSection->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $modes = collect($solutions[0]['schedules'])->pluck('mode');

        $this->assertSame(4, $modes->filter(fn (string $mode): bool => $mode === 'on-site')->count());
        $this->assertSame(0, $modes->filter(fn (string $mode): bool => $mode === 'online')->count());
    }

    public function test_major_course_with_lecture_and_lab_generates_separate_components_when_override_is_enabled(): void
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
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
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

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $rows = $solutions[0]['schedules'];
        $this->assertCount(2, $rows);

        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');

        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame($lecture['split_group_id'], $laboratory['split_group_id']);
        $this->assertSame('online', $lecture['mode']);
        $this->assertNull($lecture['room_id']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame(120, $this->durationMinutes($lecture));
        $this->assertSame(180, $this->durationMinutes($laboratory));
    }

    public function test_split_session_places_lecture_online_by_default(): void
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
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
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
            'room_code' => 'IT 105',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $lecture = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'laboratory');

        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame('online', $lecture['mode']);
        $this->assertNull($lecture['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
    }

    public function test_default_lecture_lab_generation_places_lecture_online_when_no_classroom_exists(): void
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
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 102',
            'course_name' => 'Computer Programming 2',
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

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $rows = $solutions[0]['schedules'];
        $lecture = collect($rows)->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($rows)->firstWhere('meeting_type', 'laboratory');

        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame('online', $lecture['mode']);
        $this->assertNull($lecture['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
    }

    public function test_laboratory_generation_tries_other_slots_before_room_tba(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 105',
            'course_name' => 'Laboratory Scheduling',
            'lecture_hours' => 0,
            'lab_hours' => 3,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $otherSection = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $existingCourse = Course::create([
            'course_code' => 'IT 106',
            'course_name' => 'Existing Laboratory',
            'lecture_hours' => 0,
            'lab_hours' => 3,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $otherSection->id,
            'course_id' => $existingCourse->id,
            'room_id' => $labRoom->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '07:00:00',
            'end_time' => '13:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $row = $solutions[0]['schedules'][0];
        $this->assertSame('on-site', $row['mode']);
        $this->assertSame($labRoom->id, $row['room_id']);
    }

    public function test_default_lecture_lab_generation_uses_room_tba_when_laboratory_is_unavailable(): void
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
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 103',
            'course_name' => 'Data Structures',
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
            'room_code' => 'IT 105',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $laboratory = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'laboratory');
        $this->assertNotNull($laboratory);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertNull($laboratory['room_id']);
    }

    public function test_missing_laboratory_course_keeps_room_tba_even_when_online_is_requested(): void
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
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 104',
            'course_name' => 'Algorithms',
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
            'room_code' => 'IT 105',
            'building' => 'Building 4',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            deliveryMode: 'online',
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertSame('on-site', $solutions[0]['schedules'][0]['mode']);
        $this->assertNull($solutions[0]['schedules'][0]['room_id']);
    }

    public function test_generation_falls_back_online_when_existing_classrooms_are_fully_booked(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $targetSection = Sections::create([
            'section_name' => 'BSBA 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $blockingSection = Sections::create([
            'section_name' => 'BSBA 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'MGT 101',
            'course_name' => 'Principles of Management',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $blockingSection->id,
                'department_id' => $department->id,
                'course_id' => $course->id,
                'room_id' => $room->id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $targetSection->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertSame('online', $solutions[0]['schedules'][0]['mode']);
        $this->assertNull($solutions[0]['schedules'][0]['room_id']);
    }

    public function test_generation_allows_more_than_five_online_fallbacks_when_rooms_are_fully_booked(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $targetSection = Sections::create([
            'section_name' => 'BSBA 3D',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $blockingSection = Sections::create([
            'section_name' => 'BSBA 3C',
            'year_level' => '3',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'BA 301',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $courseIds = [];
        for ($index = 1; $index <= 6; $index++) {
            $course = Course::create([
                'course_code' => "MGT 30{$index}",
                'course_name' => "Business Course {$index}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '3',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $courseIds[] = $course->id;
        }

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $blockingSection->id,
                'department_id' => $department->id,
                'course_id' => $courseIds[0],
                'room_id' => $room->id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $targetSection->id,
            courseIds: $courseIds,
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(6, $solutions[0]['schedules']);
        $this->assertSame(6, collect($solutions[0]['schedules'])->where('mode', 'online')->count());
        $this->assertTrue(collect($solutions[0]['schedules'])->every(fn (array $row): bool => $row['room_id'] === null));
    }

    public function test_generation_skips_zero_duration_internship_courses(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $section = Sections::create([
            'section_name' => 'BSBA 4A',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $regularCourse = Course::create([
            'course_code' => 'PMC 401',
            'course_name' => 'Financial Analysis and Reporting',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $internship = Course::create([
            'course_code' => 'INTERN 101',
            'course_name' => 'Prac Work w/ Integ Learning (300 hrs)',
            'lecture_hours' => 0,
            'lab_hours' => 0,
            'units' => 0,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '4',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        Rooms::create([
            'room_code' => 'BA 401',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$regularCourse->id, $internship->id],
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);
        $this->assertCount(1, $solutions[0]['schedules']);
        $this->assertSame($regularCourse->id, $solutions[0]['schedules'][0]['course_id']);
    }

    public function test_generation_uses_fixed_start_time_patterns_by_duration(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'BSBA',
        ]);

        $section = Sections::create([
            'section_name' => 'BSBA 2A',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $courses = [
            Course::create([
                'course_code' => 'MGT 201',
                'course_name' => 'Three Hour Course',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            Course::create([
                'course_code' => 'MGT 202',
                'course_name' => 'Two Hour Course',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'units' => 2,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
        ];

        Rooms::create([
            'room_code' => 'BA 201',
            'building' => 'Business Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: array_map(static fn (Course $course): int => $course->id, $courses),
            maxSolutions: 1,
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $allowedStartsByMinutes = [
            180 => ['07:00:00', '10:00:00', '13:00:00', '16:00:00'],
            120 => ['07:00:00', '09:00:00', '11:00:00', '13:00:00', '15:00:00', '17:00:00'],
        ];

        foreach ($solutions[0]['schedules'] as $schedule) {
            $durationMinutes = $this->durationMinutes($schedule);

            $this->assertContains(
                $schedule['start_time'],
                $allowedStartsByMinutes[$durationMinutes],
                "{$schedule['start_time']} is not allowed for a {$durationMinutes}-minute class.",
            );
            $this->assertLessThanOrEqual('19:00:00', $schedule['end_time']);
        }

        $this->assertSame(
            ['07:00:00', '08:30:00', '10:00:00', '11:30:00', '13:00:00', '14:30:00', '16:00:00', '17:30:00'],
            array_map(
                static fn (int $slot): string => SchedulingPolicy::slotToTime($slot),
                SchedulingPolicy::generatedStartSlotsForDuration(3),
            ),
        );
    }

    public function test_selected_split_lab_stays_face_to_face_when_course_mode_is_online(): void
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
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $section = Sections::create([
            'section_name' => 'IT 2B',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 110',
            'course_name' => 'Object Oriented Programming',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        $labRoom = Rooms::create([
            'room_code' => 'CompLab1',
            'building' => 'Building 4',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: $section->id,
            courseIds: [$course->id],
            maxSolutions: 1,
            selectedLectureLabCourseIds: [$course->id],
            deliveryModesByCourseId: [$course->id => 'online'],
            seed: 1234,
        );

        $this->assertNotEmpty($solutions);

        $lecture = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'lecture');
        $laboratory = collect($solutions[0]['schedules'])->firstWhere('meeting_type', 'laboratory');

        $this->assertNotNull($lecture);
        $this->assertNotNull($laboratory);
        $this->assertSame('online', $lecture['mode']);
        $this->assertNull($lecture['room_id']);
        $this->assertSame('on-site', $laboratory['mode']);
        $this->assertSame($labRoom->id, $laboratory['room_id']);
    }

    private function durationMinutes(array $row): int
    {
        return (int) ((strtotime($row['end_time']) - strtotime($row['start_time'])) / 60);
    }
}
