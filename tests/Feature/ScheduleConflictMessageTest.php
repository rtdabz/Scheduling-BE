<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Schedule\BatchConflictMessages;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conflict messages name the room, the department holding it, the class and both time windows,
 * read from the actual records, in one shared wording across every validator.
 */
class ScheduleConflictMessageTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Departments $requester;

    private Departments $holder;

    private Rooms $room;

    private Schedule $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->requester = Departments::create(['department_name' => 'General Education', 'department_code' => 'GEN']);
        $this->holder = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $this->room = Rooms::create(['room_code' => 'BA 201', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $this->requester->id]);

        $this->booking = Schedule::create([
            'semester_id' => $this->semester->id,
            'section_id' => $this->section('BSIT 1I', $this->holder)->id,
            'course_id' => $this->course('PATH FIT 2', $this->holder)->id,
            'room_id' => $this->room->id,
            'department_id' => $this->holder->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]);
    }

    public function test_partial_overlap_names_the_holder_and_both_windows(): void
    {
        $this->assertSame(
            'Room BA 201 is unavailable on Mon 07:00–10:00. It is booked by CIT for PATH FIT 2 (BSIT 1I) from 07:00–09:00. Please choose another time or room.',
            $this->engineMessage('room_conflict', $this->attempt(['start_time' => '07:00', 'end_time' => '10:00'])),
        );
    }

    public function test_exact_time_overlap_uses_the_same_wording(): void
    {
        $this->assertSame(
            'Room BA 201 is unavailable on Mon 07:00–09:00. It is booked by CIT for PATH FIT 2 (BSIT 1I) from 07:00–09:00. Please choose another time or room.',
            $this->engineMessage('room_conflict', $this->attempt(['start_time' => '07:00', 'end_time' => '09:00'])),
        );
    }

    public function test_message_follows_the_requested_room_and_time(): void
    {
        $otherRoom = Rooms::create(['room_code' => 'BA 202', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $this->requester->id]);

        $this->assertNull($this->engineMessage('room_conflict', $this->attempt(['room_id' => $otherRoom->id])));
        $this->assertNull($this->engineMessage('room_conflict', $this->attempt(['start_time' => '09:00', 'end_time' => '10:00'])));
        $this->assertStringStartsWith(
            'Room BA 201 is unavailable on Mon 08:30–09:30.',
            (string) $this->engineMessage('room_conflict', $this->attempt(['start_time' => '08:30', 'end_time' => '09:30'])),
        );
    }

    public function test_instructor_and_section_clashes_use_the_shared_structure(): void
    {
        $faculty = Faculty::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time', 'department_id' => $this->holder->id, 'status' => 'active']);
        $this->booking->update(['faculty_id' => $faculty->id]);

        $attempt = $this->attempt([
            'room_id' => null,
            'mode' => 'online',
            'faculty_id' => $faculty->id,
            'section_id' => $this->booking->section_id,
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        $this->assertSame(
            'Instructor Ana Cruz is unavailable on Mon 08:00–10:00. They are already teaching PATH FIT 2 (BSIT 1I) for CIT from 07:00–09:00. Please choose another time or instructor.',
            $this->engineMessage('faculty_conflict', $attempt),
        );
        $this->assertSame(
            'Section BSIT 1I is unavailable on Mon 08:00–10:00. It already has PATH FIT 2 from 07:00–09:00. Please choose another time.',
            $this->engineMessage('section_conflict', $attempt),
        );
    }

    public function test_generator_kernel_reports_the_same_details(): void
    {
        $attempt = $this->attempt(['start_time' => '07:00', 'end_time' => '10:00']);
        $snapshot = app(SchedulingSnapshotRepository::class)->capture(
            semesterId: $this->semester->id,
            departmentId: $this->requester->id,
            sectionIds: [(int) $attempt['section_id']],
            courseIds: [(int) $attempt['course_id'], (int) $this->booking->course_id],
        );

        $messages = [];
        foreach ((new SchedulingConstraintKernel)->evaluateRow(ScheduleRow::fromArray($attempt), $snapshot) as $violation) {
            $messages[$violation->ruleId] = $violation->message;
        }

        $this->assertArrayHasKey('room_conflict', $messages);
        $this->assertStringStartsWith('Room BA 201 is unavailable on Mon 07:00–10:00. It is booked for ', $messages['room_conflict']);
        $this->assertStringEndsWith(' from 07:00–09:00. Please choose another time or room.', $messages['room_conflict']);
    }

    public function test_batch_save_clash_says_it_is_in_the_same_save(): void
    {
        $operations = [
            [...$this->attempt(['start_time' => '07:00', 'end_time' => '09:00']), 'department_id' => $this->holder->id, 'section_id' => $this->booking->section_id, 'course_id' => $this->booking->course_id],
            $this->attempt(['start_time' => '08:00', 'end_time' => '10:00']),
        ];

        $described = app(BatchConflictMessages::class)->describe(app(BatchConflictValidator::class)->validate($operations), $operations);
        $room = collect($described)->firstWhere('rule', 'room_conflict');

        $this->assertSame(
            'Room BA 201 is unavailable on Mon 08:00–10:00. It is booked by CIT for PATH FIT 2 (BSIT 1I) from 07:00–09:00 in this same save. Please choose another time or room.',
            $room['message'] ?? null,
        );
    }

    public function test_conflict_inbox_names_both_classes(): void
    {
        Schedule::create([...$this->attempt(['start_time' => '08:00', 'end_time' => '10:00']), 'status' => 'faculty_assignment']);

        $case = collect(app(ScheduleConflictScanner::class)->scan($this->semester->id))->firstWhere('rule', 'room_conflict');

        $this->assertNotNull($case);
        $this->assertSame(
            'Room BA 201 is double-booked on Mon 08:00–09:00. CIT has PATH FIT 2 (BSIT 1I) from 07:00–09:00, and GEN has GE 101 (BSA 1A) from 08:00–10:00. Move one class to another time or room.',
            $case->message(),
        );
    }

    private function engineMessage(string $rule, array $attempt): ?string
    {
        return collect((new RuleEngine)->validate($attempt))->firstWhere('rule', $rule)['message'] ?? null;
    }

    /** @return array<string, mixed> */
    private function attempt(array $overrides = []): array
    {
        $sectionId = Sections::query()->where('section_name', 'BSA 1A')->value('id') ?? $this->section('BSA 1A', $this->requester)->id;
        $courseId = Course::query()->where('course_code', 'GE 101')->value('id') ?? $this->course('GE 101', $this->requester)->id;

        return array_merge([
            'semester_id' => $this->semester->id,
            'section_id' => $sectionId,
            'course_id' => $courseId,
            'faculty_id' => null,
            'room_id' => $this->room->id,
            'department_id' => $this->requester->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '10:00',
            'mode' => 'on-site',
        ], $overrides);
    }

    private function section(string $name, Departments $department): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
    }

    private function course(string $code, Departments $department): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
    }
}
