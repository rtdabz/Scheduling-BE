<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Course Assignment — handing one of CAS's courses to another college — opens
 * only once every CAS schedule has received final VPAA approval. The
 * Cross-Department page (CAS assigning instructors to IT's delegated GEC 101)
 * is never gated.
 */
class CrossDepartmentApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function notYetApprovedStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'completed' => ['completed'],
            'pending dean' => ['submitted'],
            'pending vpaa' => ['approved_by_dean'],
            'conditionally approved, pending vpaa' => ['conditionally_approved'],
            'returned for revision' => ['revision'],
            'rejected by dean' => ['rejected_by_dean'],
            'rejected' => ['rejected'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function vpaaApprovedStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'faculty assignment' => ['faculty_assignment'],
            'reassignment' => ['reassignment'],
            'finalized' => ['finalized'],
        ];
    }

    #[DataProvider('notYetApprovedStatuses')]
    public function test_course_assignment_is_refused_until_vpaa_approval(string $ownStatus): void
    {
        $fixture = $this->fixture();
        $this->ownSchedule($fixture, $ownStatus);
        $fixture['casCourse']->update(['teaching_department_id' => $fixture['it']->id]);
        $secretary = $fixture['casSecretary'];

        $this->actingAs($secretary)
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', false);

        $this->actingAs($secretary)
            ->getJson('/api/course-teaching-assignments')
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', false);

        $this->assertLocked($this->actingAs($secretary)
            ->putJson("/api/course-teaching-assignments/{$fixture['casCourse']->id}", ['teaching_department_id' => null]));

        $this->assertLocked($this->actingAs($secretary)
            ->postJson('/api/course-teaching-assignments/batch', [
                'course_ids' => [$fixture['casCourse']->id],
                'teaching_department_id' => $fixture['cas']->id,
            ]));

        $this->assertLocked($this->actingAs($secretary)
            ->deleteJson("/api/course-teaching-assignments/{$fixture['casCourse']->id}"));

        $this->assertSame($fixture['it']->id, (int) $fixture['casCourse']->fresh()->teaching_department_id);
    }

    #[DataProvider('notYetApprovedStatuses')]
    public function test_cross_department_instructor_assignment_stays_available_before_vpaa_approval(string $ownStatus): void
    {
        $fixture = $this->fixture();
        $this->ownSchedule($fixture, $ownStatus);
        $blocks = $this->delegatedBlocks($fixture);
        $secretary = $fixture['casSecretary'];

        $this->actingAs($secretary)
            ->patchJson("/api/instructor-assignments/{$blocks->first()->id}", ['faculty_id' => $fixture['casInstructor']->id])
            ->assertOk();

        $this->actingAs($secretary)
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => $blocks->pluck('id')->all(),
                'faculty_id' => $fixture['casInstructor']->id,
            ]]])
            ->assertOk();

        $this->actingAs($secretary)
            ->patchJson('/api/schedules/batch-faculty-done', ['ids' => $blocks->pluck('id')->all(), 'done' => true])
            ->assertOk();

        $this->assertTrue($blocks->every(
            fn (Schedule $block): bool => (int) $block->fresh()->faculty_id === $fixture['casInstructor']->id,
        ));
    }

    #[DataProvider('vpaaApprovedStatuses')]
    public function test_course_assignment_opens_once_every_schedule_is_vpaa_approved(string $ownStatus): void
    {
        $fixture = $this->fixture();
        $this->ownSchedule($fixture, $ownStatus);

        $this->actingAs($fixture['casSecretary'])
            ->getJson("/api/departments/{$fixture['cas']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('cross_department_unlocked', true);

        $this->actingAs($fixture['casSecretary'])
            ->putJson("/api/course-teaching-assignments/{$fixture['casCourse']->id}", ['teaching_department_id' => $fixture['it']->id])
            ->assertOk();

        $this->assertSame($fixture['it']->id, (int) $fixture['casCourse']->fresh()->teaching_department_id);
    }

    public function test_one_pending_section_keeps_course_assignment_locked(): void
    {
        $fixture = $this->fixture();
        $this->ownSchedule($fixture, 'faculty_assignment');
        $this->ownSchedule($fixture, 'approved_by_dean', 'AB-1B');

        $this->assertLocked($this->actingAs($fixture['casSecretary'])
            ->putJson("/api/course-teaching-assignments/{$fixture['casCourse']->id}", ['teaching_department_id' => $fixture['it']->id]));
    }

    public function test_the_gate_follows_approval_status_changes(): void
    {
        $fixture = $this->fixture();
        $own = $this->ownSchedule($fixture, 'approved_by_dean');
        $statusUrl = "/api/departments/{$fixture['cas']->id}/schedule-status";
        $assignUrl = "/api/course-teaching-assignments/{$fixture['casCourse']->id}";

        $this->actingAs($fixture['casSecretary'])->getJson($statusUrl)
            ->assertJsonPath('cross_department_unlocked', false);

        // VPAA approves: the department moves to instructor assignment.
        $own->update(['status' => 'faculty_assignment']);
        $this->actingAs($fixture['casSecretary'])->getJson($statusUrl)
            ->assertJsonPath('cross_department_unlocked', true);
        $this->actingAs($fixture['casSecretary'])
            ->putJson($assignUrl, ['teaching_department_id' => $fixture['it']->id])
            ->assertOk();

        // The schedule is sent back for revision: the gate closes again.
        $own->update(['status' => 'revision']);
        $this->actingAs($fixture['casSecretary'])->getJson($statusUrl)
            ->assertJsonPath('cross_department_unlocked', false);
        $this->assertLocked($this->actingAs($fixture['casSecretary'])
            ->putJson($assignUrl, ['teaching_department_id' => null]));
    }

    private function assertLocked(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('message', CrossDepartmentApprovalGate::MESSAGE)
            ->assertJsonPath('error_code', CrossDepartmentApprovalGate::ERROR_CODE);
    }

    /** @param  array<string, mixed>  $fixture */
    private function ownSchedule(array $fixture, string $status, string $sectionName = 'AB-1A'): Schedule
    {
        $section = Sections::create([
            'section_name' => $sectionName,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['cas']->id,
            'program_id' => $fixture['casProgram']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);

        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'course_id' => $fixture['casCourse']->id,
            'room_id' => $fixture['casRoom']->id,
            'department_id' => $fixture['cas']->id,
            'faculty_id' => null,
            'day' => 'Tuesday',
            'start_time' => $sectionName === 'AB-1A' ? '13:00' : '15:00',
            'end_time' => $sectionName === 'AB-1A' ? '14:00' : '16:00',
            'mode' => 'on-site',
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return Collection<int, Schedule>
     */
    private function delegatedBlocks(array $fixture): Collection
    {
        return collect(['Monday', 'Wednesday', 'Friday'])->map(fn (string $day): Schedule => Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $fixture['itSection']->id,
            'course_id' => $fixture['gec']->id,
            'room_id' => $fixture['itRoom']->id,
            'department_id' => $fixture['it']->id,
            'faculty_id' => null,
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $it = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $itProgram = Program::create(['department_id' => $it->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $casProgram = Program::create(['department_id' => $cas->id, 'code' => 'BACAS', 'name' => 'Arts and Sciences']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $course = fn (string $code, int $departmentId, ?int $teachingDepartmentId) => Course::create([
            'course_code' => $code,
            'course_name' => "{$code} Course",
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $departmentId,
            'teaching_department_id' => $teachingDepartmentId,
            'status' => 'active',
        ]);

        return [
            'it' => $it,
            'cas' => $cas,
            'casProgram' => $casProgram,
            'semester' => $semester,
            'itRoom' => Rooms::create(['room_code' => 'CIT 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $it->id]),
            'casRoom' => Rooms::create(['room_code' => 'AB 201', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $cas->id]),
            'itSection' => Sections::create([
                'section_name' => 'BSIT 1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $it->id,
                'program_id' => $itProgram->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'gec' => $course('GEC 101', $it->id, $cas->id),
            'casCourse' => $course('GEC 102', $cas->id, null),
            'casInstructor' => Faculty::create([
                'first_name' => 'Arts',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'department_id' => $cas->id,
                'status' => 'active',
                'max_units' => 21,
                'deload_units' => 6,
                'overload_units' => 3,
            ]),
            'casSecretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id])),
        ];
    }
}
