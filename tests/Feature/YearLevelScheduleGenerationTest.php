<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YearLevelScheduleGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_level_preview_keeps_section_course_modes_independent_and_rolls_back_staging_rows(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $sectionA = Sections::create(['section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']);
        $sectionB = Sections::create(['section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']);
        $course = Course::create(['course_code' => 'GEC 101', 'course_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => null, 'status' => 'active']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [
                ['section_id' => $sectionA->id, 'course_ids' => [$course->id], 'delivery_modes_by_course_id' => [$course->id => 'online']],
                ['section_id' => $sectionB->id, 'course_ids' => [$course->id], 'delivery_modes_by_course_id' => [$course->id => 'on-site']],
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'sections')
            ->assertJsonStructure([
                'score',
                'quality_score',
                'penalty_score',
                'resource_usage_score',
                'fair_distribution_score',
                'resource_fairness_score',
                'schedule_compactness_score',
                'configuration_compliance_score',
                'quality_breakdown' => [
                    'resource_usage',
                    'fair_distribution',
                    'schedule_compactness',
                    'configuration_compliance',
                ],
                'score_breakdown' => [
                    'fully_online_sections',
                    'weekend_usage',
                    'weekday_capacity_migration',
                    'unnecessary_online',
                    'unused_rooms_with_online_classes',
                    'laboratory_room_mismatch',
                    'regular_physical_targets',
                    'laboratory_physical_targets',
                    'physical_distribution',
                    'physical_rate_variance',
                    'first_section_physical_advantage',
                    'laboratory_distribution',
                    'year_level_physical_distribution',
                    'dominant_physical_share',
                    'room_concentration',
                    'room_idle_gaps',
                    'section_idle_gaps',
                    'configuration_violations',
                ],
            ]);
        $response->assertJsonPath('quality_score', $response->json('score'));
        $rows = collect($response->json('schedules'));
        $this->assertSame('online', $rows->firstWhere('section_id', $sectionA->id)['mode']);
        $this->assertSame('on-site', $rows->firstWhere('section_id', $sectionB->id)['mode']);
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_year_level_preview_keeps_split_configuration_independent_per_section(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'lecture_lab_schedule_override_enabled' => true,
        ]);
        $sectionA = Sections::create(['section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']);
        $sectionB = Sections::create(['section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']);
        $course = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        Rooms::create(['room_code' => 'LAB 101', 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => [
                ['section_id' => $sectionA->id, 'course_ids' => [$course->id], 'selected_split_session_course_ids' => [$course->id]],
                ['section_id' => $sectionB->id, 'course_ids' => [$course->id], 'selected_split_session_course_ids' => []],
            ],
        ]);

        $response->assertOk();
        $rows = collect($response->json('schedules'));
        $this->assertCount(2, $rows->where('section_id', $sectionA->id));
        $this->assertNotNull($rows->where('section_id', $sectionA->id)->firstWhere('meeting_type', 'lecture'));
        $this->assertNotNull($rows->where('section_id', $sectionA->id)->firstWhere('meeting_type', 'laboratory'));
        $this->assertCount(1, $rows->where('section_id', $sectionB->id));
        $this->assertArrayNotHasKey('split_group_id', $rows->where('section_id', $sectionB->id)->first());
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_year_level_preview_handles_all_sections_with_split_laboratories(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'lecture_lab_schedule_override_enabled' => true,
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = [];

        for ($index = 1; $index <= 4; $index++) {
            $sections[] = Sections::create([
                'section_name' => "IT 1{$index}",
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        $courses = [];
        for ($index = 1; $index <= 2; $index++) {
            $course = Course::create([
                'course_code' => "IT 10{$index}",
                'course_name' => "Programming {$index}",
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
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courses[] = $course;
        }

        for ($index = 1; $index <= 4; $index++) {
            Rooms::create(['room_code' => "IT {$index}01", 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
            Rooms::create(['room_code' => "LAB {$index}01", 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        }

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $courseIds = array_map(static fn (Course $course): int => (int) $course->id, $courses);

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => $courseIds,
                'selected_split_session_course_ids' => $courseIds,
            ], $sections),
        ]);

        $response->assertOk();
        $rows = collect($response->json('schedules'));
        $this->assertCount(count($sections) * count($courses) * 2, $rows);

        foreach ($sections as $section) {
            foreach ($courses as $course) {
                $courseRows = $rows
                    ->where('section_id', $section->id)
                    ->where('course_id', $course->id);

                $this->assertNotNull($courseRows->firstWhere('meeting_type', 'lecture'));
                $this->assertNotNull($courseRows->firstWhere('meeting_type', 'laboratory'));
            }
        }

        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_year_level_preview_handles_split_heavy_sections_for_every_year_level(): void
    {
        foreach ([1, 2, 3, 4] as $yearLevel) {
            $term = Terms::create([
                'academic_year' => "2026-2027-Y{$yearLevel}",
                'semester' => '1st',
                'is_active' => $yearLevel === 1,
                'is_enabled' => true,
            ]);
            $department = Departments::create([
                'department_name' => "Information Technology {$yearLevel}",
                'department_code' => "IT{$yearLevel}",
                'lecture_lab_schedule_override_enabled' => true,
                'gec_split_schedule_override_enabled' => true,
            ]);
            $curriculum = Curriculum::create([
                'name' => "IT Curriculum {$yearLevel}",
                'department_id' => $department->id,
                'code' => "IT-2026-Y{$yearLevel}",
                'effective_school_year' => '2026-2027',
                'status' => 'active',
            ]);

            $sections = [];
            for ($sectionIndex = 1; $sectionIndex <= 4; $sectionIndex++) {
                $sections[] = Sections::create([
                    'section_name' => "IT {$yearLevel}{$sectionIndex}",
                    'year_level' => (string) $yearLevel,
                    'semester' => '1st',
                    'department_id' => $department->id,
                    'term_id' => $term->id,
                    'status' => 'active',
                ]);
            }

            $majorCourses = [];
            for ($courseIndex = 1; $courseIndex <= 3; $courseIndex++) {
                $course = Course::create([
                    'course_code' => "IT{$yearLevel}0{$courseIndex}",
                    'course_name' => "Major {$yearLevel}.{$courseIndex}",
                    'lecture_hours' => 2,
                    'lab_hours' => 1,
                    'units' => 3,
                    'course_category' => 'major',
                    'room_type_required' => 'laboratory',
                    'year_level' => (string) $yearLevel,
                    'semester' => '1st',
                    'department_id' => $department->id,
                    'status' => 'active',
                ]);
                $curriculum->courses()->attach($course->id, ['year_level' => $yearLevel, 'semester' => 1]);
                $majorCourses[] = $course;
            }

            $minorCourse = Course::create([
                'course_code' => "GEC {$yearLevel}01",
                'course_name' => "Minor {$yearLevel}",
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'minor',
                'room_type_required' => 'lecture',
                'year_level' => (string) $yearLevel,
                'semester' => '1st',
                'department_id' => null,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($minorCourse->id, ['year_level' => $yearLevel, 'semester' => 1]);

            for ($roomIndex = 1; $roomIndex <= 4; $roomIndex++) {
                Rooms::create(['room_code' => "IT{$yearLevel}{$roomIndex}01", 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
                Rooms::create(['room_code' => "LAB{$yearLevel}{$roomIndex}01", 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
            }

            $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
            $majorCourseIds = array_map(static fn (Course $course): int => (int) $course->id, $majorCourses);
            $courseIds = [...$majorCourseIds, (int) $minorCourse->id];

            $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
                'term_id' => $term->id,
                'department_id' => $department->id,
                'year_level' => $yearLevel,
                'section_configs' => array_map(static fn (Sections $section): array => [
                    'section_id' => $section->id,
                    'course_ids' => $courseIds,
                    'selected_split_session_course_ids' => $majorCourseIds,
                    'selected_gec_course_ids' => [(int) $minorCourse->id],
                ], $sections),
            ]);

            $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
            $this->assertCount(count($sections) * ((count($majorCourseIds) * 2) + 2), $response->json('schedules'));
            $this->assertSame(0, Schedule::query()->count());
        }
    }

    public function test_year_level_preview_handles_all_major_and_minor_courses_split(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'lecture_lab_schedule_override_enabled' => true,
            'gec_split_schedule_override_enabled' => true,
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        $sections = [];
        for ($sectionIndex = 1; $sectionIndex <= 4; $sectionIndex++) {
            $sections[] = Sections::create([
                'section_name' => "IT 1{$sectionIndex}",
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
        }

        $majorCourses = [];
        for ($courseIndex = 1; $courseIndex <= 3; $courseIndex++) {
            $course = Course::create([
                'course_code' => "IT 10{$courseIndex}",
                'course_name' => "Major {$courseIndex}",
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
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $majorCourses[] = $course;
        }

        $minorCourses = [];
        for ($courseIndex = 1; $courseIndex <= 3; $courseIndex++) {
            $course = Course::create([
                'course_code' => "GEC 10{$courseIndex}",
                'course_name' => "Minor {$courseIndex}",
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
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $minorCourses[] = $course;
        }

        for ($roomIndex = 1; $roomIndex <= 5; $roomIndex++) {
            Rooms::create(['room_code' => "IT {$roomIndex}01", 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
            Rooms::create(['room_code' => "LAB {$roomIndex}01", 'building' => 'IT Building', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        }

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $majorCourseIds = array_map(static fn (Course $course): int => (int) $course->id, $majorCourses);
        $minorCourseIds = array_map(static fn (Course $course): int => (int) $course->id, $minorCourses);
        $courseIds = [...$majorCourseIds, ...$minorCourseIds];

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => $courseIds,
                'selected_split_session_course_ids' => $majorCourseIds,
                'selected_gec_course_ids' => $minorCourseIds,
            ], $sections),
        ]);

        $this->assertSame(200, $response->status(), json_encode($response->json(), JSON_PRETTY_PRINT));
        $rows = collect($response->json('schedules'));
        $this->assertCount(count($sections) * ((count($majorCourseIds) * 2) + (count($minorCourseIds) * 2)), $rows);
        $minorPatterns = $rows
            ->whereIn('course_id', $minorCourseIds)
            ->pluck('preferred_pattern')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->assertNotContains('MW', $minorPatterns);
        $this->assertNotContains('TTh', $minorPatterns);
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_year_level_preview_applies_configured_gec_split_pattern(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'gec_split_schedule_override_enabled' => true,
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = [
            Sections::create(['section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']),
            Sections::create(['section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active']),
        ];
        $course = Course::create([
            'course_code' => 'GEC 101',
            'course_name' => 'Understanding the Self',
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
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        Rooms::create(['room_code' => 'IT 102', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'term_id' => $term->id,
            'department_id' => $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => [(int) $course->id],
                'selected_gec_course_ids' => [(int) $course->id],
                'preferred_patterns' => [(int) $course->id => 'MW'],
            ], $sections),
        ]);

        $response->assertOk();
        $rows = collect($response->json('schedules'));
        $this->assertCount(count($sections) * 2, $rows);
        $this->assertSame(['MW'], $rows->pluck('preferred_pattern')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['Monday', 'Wednesday'], $rows->pluck('day')->unique()->values()->all());
        $this->assertSame(0, Schedule::query()->count());
    }
}
