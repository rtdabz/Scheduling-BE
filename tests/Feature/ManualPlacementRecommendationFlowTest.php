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
use App\Services\Scheduling\Engine\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Schedule Builder's placement dialog asks for alternatives when a manual
 * drop conflicts: preview, then select the chosen rank, then accept it. Each
 * step is exercised here with the payload shape the dialog sends.
 */
class ManualPlacementRecommendationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_select_and_accept_return_a_conflict_free_placement(): void
    {
        [$semester, $department, $section, $otherSection, $course, $room] = $this->createScenario();

        // The only lecture room is taken Monday morning by another section.
        Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $otherSection->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '07:00:00',
            'end_time' => '10:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        $user = $this->secretaryFor($department);
        $payload = [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'split_session_enabled' => false,
            'selected_split_session_course_ids' => [],
            'split_gec_enabled' => false,
            'selected_gec_course_ids' => [],
            'preferred_patterns' => [],
            'tentative_schedules' => [],
            'max_solutions' => 3,
            'timeout_seconds' => 5,
            'seed' => 4242,
        ];

        $preview = $this->actingAs($user)->postJson('/api/schedule-recommendations/preview', $payload);
        $preview->assertOk();
        $recommendations = $preview->json('recommendations');
        $this->assertNotEmpty($recommendations, 'A free room exists for most of the week, so alternatives must be offered.');

        $ruleEngine = app(RuleEngine::class);
        foreach ($recommendations as $recommendation) {
            $this->assertNotEmpty($recommendation['schedules']);
            foreach ($recommendation['schedules'] as $row) {
                $this->assertFalse(
                    $row['day'] === 'Monday' && $row['start_time'] < '10:00' && $row['end_time'] > '07:00' && (int) $row['room_id'] === $room->id,
                    'A recommendation reused the occupied room slot.',
                );
                $violations = $ruleEngine->validate([...$row, 'semester_id' => $semester->id]);
                $this->assertSame([], $violations, 'Every recommended row must pass the Rule Engine: '.json_encode($violations));
            }
        }

        $selected = $this->actingAs($user)->postJson('/api/schedule-recommendations/select', [...$payload, 'selected_rank' => 1]);
        $selected->assertSuccessful();
        $selectedRows = $selected->json('recommendation.recommended_schedules');
        $this->assertNotEmpty($selectedRows);
        $this->assertSame(
            $this->comparableRows($recommendations[0]['schedules']),
            $this->comparableRows($selectedRows),
            'Selecting rank 1 with the same seed must return the placement the dialog showed.',
        );

        $accepted = $this->actingAs($user)->postJson('/api/schedule-recommendations/'.$selected->json('recommendation.id').'/accept');
        $accepted->assertSuccessful();

        foreach ($selectedRows as $row) {
            $this->assertDatabaseHas('schedules', [
                'section_id' => $section->id,
                'course_id' => $course->id,
                'day' => $row['day'],
                'room_id' => $row['room_id'],
            ]);
        }
    }

    public function test_preview_avoids_unsaved_placements_sent_as_tentative_schedules(): void
    {
        [$semester, $department, $section, $otherSection, $course, $room, $secondCourse] = $this->createScenario();
        $user = $this->secretaryFor($department);

        // Nothing is saved yet: the dialog sends the grid's unsaved rows. Block
        // every weekday morning for the section so any recommendation must
        // work around them.
        $tentative = [];
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            $tentative[] = [
                'semester_id' => $semester->id,
                'section_id' => $section->id,
                'course_id' => $secondCourse->id,
                'faculty_id' => null,
                'room_id' => $room->id,
                'department_id' => $department->id,
                'day' => $day,
                'start_time' => '07:00',
                'end_time' => '12:00',
                'mode' => 'on-site',
            ];
        }

        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
            'tentative_schedules' => $tentative,
            'max_solutions' => 3,
            'timeout_seconds' => 5,
            'seed' => 99,
        ]);

        $response->assertOk();
        $recommendations = $response->json('recommendations');
        $this->assertNotEmpty($recommendations);
        foreach ($recommendations as $recommendation) {
            foreach ($recommendation['schedules'] as $row) {
                $this->assertGreaterThanOrEqual('12:00', substr((string) $row['start_time'], 0, 5), 'A recommendation overlapped an unsaved section meeting.');
            }
        }
    }

    /** @return list<array{day: string, start: string, end: string, room: int|null, mode: string}> */
    private function comparableRows(array $rows): array
    {
        $normalized = array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'start' => substr((string) $row['start_time'], 0, 5),
            'end' => substr((string) $row['end_time'], 0, 5),
            'room' => $row['room_id'] === null ? null : (int) $row['room_id'],
            'mode' => (string) $row['mode'],
        ], $rows);
        usort($normalized, static fn (array $a, array $b): int => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        return $normalized;
    }

    private function secretaryFor(Departments $department): User
    {
        return $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]));
    }

    private function createScenario(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Business Administration',
            'department_code' => 'BA',
            'scheduling_profile' => 'standard',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSBA',
            'name' => 'Bachelor of Science in Business Administration',
        ]);
        $makeSection = fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $section = $makeSection('BA 1A');
        $otherSection = $makeSection('BA 1B');
        $makeCourse = fn (string $code): Course => Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
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
        $course = $makeCourse('BA 101');
        $secondCourse = $makeCourse('BA 102');

        $curriculum = Curriculum::create([
            'name' => 'BA Curriculum',
            'department_id' => $department->id,
            'code' => 'BA-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        foreach ([$course, $secondCourse] as $attached) {
            $curriculum->courses()->attach($attached->id, ['year_level' => 1, 'semester' => 1]);
        }

        $room = Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        return [$semester, $department, $section, $otherSection, $course, $room, $secondCourse];
    }
}
