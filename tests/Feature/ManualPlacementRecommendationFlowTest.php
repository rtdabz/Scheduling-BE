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

    public function test_select_with_a_plan_id_saves_the_previewed_plan_and_accept_refuses_it_once_stale(): void
    {
        [$semester, $department, $section, $otherSection, $course] = $this->createScenario();
        $user = $this->secretaryFor($department);
        $payload = [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'selected_split_session_course_ids' => [],
            'selected_gec_course_ids' => [],
            'preferred_patterns' => [],
            'tentative_schedules' => [],
            'max_solutions' => 3,
            'timeout_seconds' => 5,
            'seed' => 4242,
        ];

        $preview = $this->actingAs($user)->postJson('/api/schedule-recommendations/preview', $payload);
        $preview->assertOk();
        $previewed = $preview->json('recommendations.0');
        $this->assertNotEmpty($previewed['plan_id'] ?? null);

        // The constraint kernel has no part-time availability or inactive-faculty
        // rule; that is only safe while generation leaves instructors unassigned.
        // If this starts failing, add those families to the kernel first.
        foreach ($preview->json('recommendations') as $recommendation) {
            foreach ($recommendation['schedules'] as $row) {
                $this->assertNull($row['faculty_id'] ?? null, 'Generated rows must not carry an instructor.');
            }
        }

        // Someone takes rank 1's first slot after the preview. Solving again
        // would route around it; saving the previewed plan must not.
        $taken = $previewed['schedules'][0];
        $intruder = Sections::create([
            'section_name' => 'IT 1Z',
            'year_level' => $otherSection->year_level,
            'semester' => $otherSection->semester,
            'department_id' => $department->id,
            'program_id' => $otherSection->program_id,
            'curriculum_id' => $otherSection->curriculum_id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $intruder->id,
            'course_id' => $course->id,
            'room_id' => $taken['room_id'],
            'department_id' => $department->id,
            'day' => $taken['day'],
            'start_time' => $taken['start_time'],
            'end_time' => $taken['end_time'],
            'mode' => $taken['mode'],
            'status' => 'faculty_assignment',
        ]);

        $selected = $this->actingAs($user)->postJson('/api/schedule-recommendations/select', [
            ...$payload,
            'selected_rank' => (int) $previewed['rank'],
            'plan_id' => $previewed['plan_id'],
        ]);
        $selected->assertSuccessful();
        $this->assertSame(
            $this->comparableRows($previewed['schedules']),
            $this->comparableRows($selected->json('recommendation.recommended_schedules')),
            'Select must save the plan the dialog showed, not a fresh solve.',
        );

        // The commit-time snapshot check is what keeps a stale plan out.
        $this->actingAs($user)
            ->postJson('/api/schedule-recommendations/'.$selected->json('recommendation.id').'/accept')
            ->assertStatus(422);
        $this->assertDatabaseMissing('schedules', ['section_id' => $section->id, 'course_id' => $course->id]);
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

    public function test_preview_uses_the_integrated_lengths_the_dialog_chose(): void
    {
        [$semester, $department, $section] = $this->createScenario();

        // A laboratory course needs the profile that admits one.
        $department->update(['scheduling_profile' => 'laboratory_enabled']);

        // 2 lecture units + 1 laboratory unit: the Generator's own shape is a
        // 2 h lecture and a 3 h laboratory.
        $course = Course::create([
            'course_code' => 'BA 210',
            'course_name' => 'Business Analytics',
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
        Curriculum::query()->where('department_id', $department->id)->firstOrFail()
            ->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        Rooms::create([
            'room_code' => 'BA LAB 1',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs($this->secretaryFor($department))
            ->postJson('/api/schedule-recommendations/preview', [
                'section_id' => $section->id,
                'course_ids' => [$course->id],
                'mode' => 'on-site',
                'is_hybrid' => true,
                'split_session_enabled' => true,
                'selected_split_session_course_ids' => [$course->id],
                // What the placement dialog's two Duration selects sent: an
                // hour and a half of lecture, three and a half of laboratory.
                'component_minutes_by_course_id' => [$course->id => ['lecture' => 90, 'laboratory' => 210]],
                'preferred_patterns' => [],
                'tentative_schedules' => [],
                'max_solutions' => 1,
                'timeout_seconds' => 5,
                'seed' => 7,
            ]);

        $response->assertOk();
        $rows = $response->json('recommendations.0.schedules');
        $this->assertNotEmpty($rows, 'A free laboratory exists all week, so an Integrated pair must be offered.');
        $minutes = array_map(
            static fn (array $row): int => (int) round((strtotime((string) $row['end_time']) - strtotime((string) $row['start_time'])) / 60),
            $rows,
        );
        sort($minutes);
        $this->assertSame([90, 210], $minutes, 'The alternatives must use the lengths chosen, not the course\'s own 120 + 180.');

        // Semester scoping is the section's; asserted so the rows belong to
        // this run and not to a stale one.
        foreach ($rows as $row) {
            $this->assertSame((int) $semester->id, (int) $row['semester_id']);
        }
    }

    public function test_preview_keeps_an_online_split_session_online(): void
    {
        [$semester, $department, $section, , $course] = $this->createScenario();

        // What the placement dialog sends once Split Session's Delivery mode
        // is Online: the course's delivery, the split, and its day pair.
        $response = $this->actingAs($this->secretaryFor($department))
            ->postJson('/api/schedule-recommendations/preview', [
                'section_id' => $section->id,
                'course_ids' => [$course->id],
                'mode' => 'online',
                'is_hybrid' => false,
                'split_session_enabled' => false,
                'selected_split_session_course_ids' => [],
                'split_gec_enabled' => true,
                'selected_gec_course_ids' => [$course->id],
                'hybrid_split_course_ids' => [],
                'preferred_patterns' => [$course->id => 'MW'],
                'tentative_schedules' => [],
                'max_solutions' => 3,
                'timeout_seconds' => 5,
                'seed' => 11,
            ]);

        $response->assertOk();
        $recommendations = $response->json('recommendations');
        $this->assertNotEmpty($recommendations);
        $ruleEngine = app(RuleEngine::class);
        foreach ($recommendations as $recommendation) {
            $rows = $recommendation['schedules'];
            $this->assertCount(2, $rows, 'An Online Split is still two meetings.');
            $this->assertSame(['online', 'online'], array_column($rows, 'mode'));
            $this->assertEqualsCanonicalizing(['Monday', 'Wednesday'], array_column($rows, 'day'));
            $this->assertSame($rows[0]['start_time'], $rows[1]['start_time']);
            foreach ($rows as $row) {
                $violations = $ruleEngine->validate([...$row, 'semester_id' => $semester->id]);
                $this->assertSame([], $violations, 'Every recommended row must pass the Rule Engine: '.json_encode($violations));
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
