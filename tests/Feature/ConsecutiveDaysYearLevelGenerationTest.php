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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Generate Schedule end to end: a year level whose Clinical course is ticked
 * to meet Monday, Tuesday and Wednesday is previewed and then saved the way
 * the wizard saves it, so the runs pass the kernel and the Rule Engine both.
 */
class ConsecutiveDaysYearLevelGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_year_level_is_generated_and_saved_with_each_sections_run(): void
    {
        [$user, $payload, $clinical] = $this->scenario(['NUR 1A', 'NUR 1B']);

        $preview = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', $payload);

        $this->assertSame(200, $preview->status(), json_encode($preview->json(), JSON_PRETTY_PRINT));
        $rows = collect($preview->json('schedules'));
        $runs = $rows->where('course_id', (int) $clinical->id)->groupBy('section_id');
        $this->assertCount(2, $runs);
        foreach ($runs as $sectionRows) {
            $days = $sectionRows->pluck('day')->all();
            sort($days);
            // Every section meets on the ticked days, three hours each day.
            $this->assertSame(['Monday', 'Tuesday', 'Wednesday'], $days);
            $this->assertCount(1, $sectionRows->pluck('split_group_id')->unique());
            $this->assertCount(1, $sectionRows->pluck('start_time')->unique());
            $this->assertSame(180, SchedulingPolicy::timeToMinutes($sectionRows->first()['end_time']) - SchedulingPolicy::timeToMinutes($sectionRows->first()['start_time']));
        }
        // One laboratory: the two sections share it at different times.
        $this->assertCount(2, $runs->map(static fn ($sectionRows) => $sectionRows->first()['start_time'])->unique());

        $save = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => $rows->map(static fn (array $row): array => [
                'semester_id' => (int) $row['semester_id'],
                'section_id' => (int) $row['section_id'],
                'course_id' => (int) $row['course_id'],
                'room_id' => $row['mode'] === 'online' ? null : ((int) ($row['room_id'] ?? 0) ?: null),
                'department_id' => (int) $row['department_id'],
                'day' => $row['day'],
                'start_time' => substr((string) $row['start_time'], 0, 5),
                'end_time' => substr((string) $row['end_time'], 0, 5),
                'mode' => $row['mode'] ?? 'on-site',
                'is_hybrid' => (bool) ($row['is_hybrid'] ?? false),
                'preferred_pattern' => $row['preferred_pattern'] ?? null,
                'split_group_id' => $row['split_group_id'] ?? null,
                'meeting_type' => $row['meeting_type'] ?? null,
                'meeting_index' => $row['meeting_index'] ?? null,
                'status' => 'draft',
            ])->values()->all(),
            'replace_section_ids' => array_column($payload['section_configs'], 'section_id'),
            'replace_semester_id' => $payload['semester_id'],
        ]);

        $this->assertSame(200, $save->status(), json_encode($save->json(), JSON_PRETTY_PRINT));
        $this->assertSame(6, Schedule::query()->where('course_id', $clinical->id)->count());
    }

    /**
     * @param  list<string>  $sectionNames
     * @return array{0: User, 1: array<string, mixed>, 2: Course}
     */
    private function scenario(array $sectionNames): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'College of Nursing',
            'department_code' => 'NUR',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSN', 'name' => 'Nursing']);
        $curriculum = Curriculum::create(['name' => 'Nursing Curriculum', 'department_id' => $department->id, 'code' => 'BSN-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $sections = array_map(static fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]), $sectionNames);
        $shared = [
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
            'course_category' => 'major',
        ];
        // Three units: three hours on each of the three days.
        $clinical = Course::create($shared + [
            'course_code' => 'NCM 101',
            'course_name' => 'Clinical Duty',
            'lecture_hours' => 0,
            'lab_hours' => 3,
            'units' => 3,
            'room_type_required' => 'laboratory',
        ]);
        $lecture = Course::create($shared + [
            'course_code' => 'NCM 100',
            'course_name' => 'Theoretical Foundations',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'room_type_required' => 'lecture',
        ]);
        foreach ([$clinical, $lecture] as $course) {
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        }
        Rooms::create(['room_code' => 'SKILLS-1', 'building' => 'Health', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $department->id]);
        foreach ($sections as $index => $section) {
            Rooms::create(['room_code' => 'NUR 10'.($index + 1), 'building' => 'Health', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        }
        DB::table('course_consecutive_day_rules')->insert([
            'department_id' => $department->id,
            'course_id' => $clinical->id,
            'section_id' => null,
            'day_count' => 3,
            'preferred_start_day' => 'Monday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => (int) $section->id,
                'course_ids' => [(int) $clinical->id, (int) $lecture->id],
            ], $sections),
        ], $clinical];
    }
}
