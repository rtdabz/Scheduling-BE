<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ArchiveAuditAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public static function archiveTypes(): array
    {
        return array_map(static fn (string $type): array => [$type], [
            'departments', 'programs', 'designations', 'courses', 'faculties', 'semesters', 'rooms',
        ]);
    }

    #[DataProvider('archiveTypes')]
    public function test_archival_rolls_back_if_its_actor_audit_cannot_be_recorded(string $type): void
    {
        $department = Departments::create(['department_name' => 'Audit Department', 'department_code' => 'AUD']);
        $record = match ($type) {
            'departments' => $department,
            'programs' => Program::create(['department_id' => $department->id, 'code' => 'AUD', 'name' => 'Audit Program']),
            'designations' => Designation::create(['name' => 'Audit Designation', 'deload_units' => 0]),
            'courses' => Course::create([
                'department_id' => $department->id, 'course_code' => 'AUD101', 'course_name' => 'Audit Course',
                'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major',
                'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active',
            ]),
            'faculties' => Faculty::create([
                'department_id' => $department->id, 'first_name' => 'Audit', 'last_name' => 'Instructor',
                'employment_type' => 'full-time', 'max_units' => 18, 'status' => 'active',
            ]),
            'semesters' => Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]),
            'rooms' => Rooms::create(['department_id' => $department->id, 'room_code' => 'AUD101', 'room_type' => 'lecture', 'status' => 'available']),
        };
        $actor = $this->grantCapabilities(User::factory()->create(['name' => 'Archiving VPAA', 'role' => 'vpaa', 'department_id' => null]));
        $rejectAudit = true;
        Event::listen('eloquent.creating: '.SchedulingAuditLog::class, static function (SchedulingAuditLog $log) use (&$rejectAudit): void {
            if ($rejectAudit && $log->action === 'record_archived') {
                throw new RuntimeException('Archive audit unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->deleteJson("/api/{$type}/{$record->id}");
            $this->fail('Archival must fail when its actor audit cannot be saved.');
        } catch (RuntimeException $error) {
            $this->assertSame('Archive audit unavailable', $error->getMessage());
        }
        $this->assertNotSoftDeleted($record->getTable(), ['id' => $record->id]);
        $this->assertSame(0, SchedulingAuditLog::query()->where('action', 'record_archived')->count());

        $rejectAudit = false;
        $this->deleteJson("/api/{$type}/{$record->id}")->assertOk();
        $this->assertSoftDeleted($record->getTable(), ['id' => $record->id]);
        $log = SchedulingAuditLog::query()->where('action', 'record_archived')->sole();
        $this->assertSame($actor->id, $log->recorded_actor['id']);
        $this->assertSame('Archiving VPAA', $log->recorded_actor['name']);
        $this->assertSame($type, $log->metadata['type']);
        $this->assertSame($record->id, $log->metadata['id']);
    }
}
