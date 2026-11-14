<?php

namespace Tests\Unit;

use App\Models\Departments;
use App\Services\Scheduling\Engine\Rules\RoomTypeRule;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultLabRoomRequirementTest extends TestCase
{
    use RefreshDatabase;

    /** A laboratory-only (0 LEC + 3 LAB) major. */
    private const LAB_ONLY_COURSE = [
        'course_code' => 'SCE 7',
        'course_category' => 'major',
        'units' => 3,
        'lecture_hours' => 0,
        'lab_hours' => 3,
        'room_type_required' => 'laboratory',
    ];

    private const CLASSROOM = ['room_code' => 'R101', 'room_type' => 'lecture', 'allow_lecture_usage' => false];

    private const LABORATORY = ['room_code' => 'LAB1', 'room_type' => 'laboratory', 'allow_lecture_usage' => false];

    private int $departmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->departmentId = (int) Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
            'status' => 'active',
        ])->id;
    }

    protected function tearDown(): void
    {
        SchedulingPolicy::clearFieldCourseCache();
        parent::tearDown();
    }

    private function useLabRoomType(string $value, ?int $departmentId = null): void
    {
        $department = Departments::query()->findOrFail($departmentId ?? $this->departmentId);
        $department->lab_room_type = $value;
        $department->save();
        SchedulingPolicy::clearFieldCourseCache();
    }

    private function mismatch(array $room, ?int $departmentId = null): ?array
    {
        return RoomTypeRule::mismatch(self::LAB_ONLY_COURSE, $room, 'on-site', null, $departmentId ?? $this->departmentId, []);
    }

    public function test_laboratory_default_keeps_laboratory_courses_out_of_classrooms(): void
    {
        $this->assertSame(['laboratory'], SchedulingPolicy::labRoomTypes($this->departmentId));
        $this->assertNotNull($this->mismatch(self::CLASSROOM));
        $this->assertNull($this->mismatch(self::LABORATORY));
    }

    public function test_legacy_classroom_setting_never_puts_a_laboratory_meeting_in_a_classroom(): void
    {
        $this->useLabRoomType('lecture');

        $this->assertSame(['laboratory'], SchedulingPolicy::labRoomTypes($this->departmentId));
        $this->assertNotNull($this->mismatch(self::CLASSROOM));
        $this->assertNull($this->mismatch(self::LABORATORY));
        // With no laboratory room the meeting waits as Room TBA.
        $this->assertNull(RoomTypeRule::mismatch(self::LAB_ONLY_COURSE, null, 'on-site', null, $this->departmentId, []));
    }

    public function test_legacy_either_setting_no_longer_falls_back_to_a_classroom(): void
    {
        $this->useLabRoomType('either');

        $this->assertSame(['laboratory'], SchedulingPolicy::labRoomTypes($this->departmentId));
        $this->assertFalse(SchedulingPolicy::isLabClassroomFallback('lecture', $this->departmentId));
        $this->assertNotNull($this->mismatch(self::CLASSROOM));
        $this->assertNull($this->mismatch(self::LABORATORY));
    }

    public function test_split_is_not_offered_for_courses_with_laboratory_units(): void
    {
        $this->assertFalse(SchedulingPolicy::balancedSplitEligible(self::LAB_ONLY_COURSE, []));
        $this->assertFalse(SchedulingPolicy::balancedSplitEligible(
            [...self::LAB_ONLY_COURSE, 'lecture_hours' => 2, 'lab_hours' => 1],
            [],
        ));
        $this->assertTrue(SchedulingPolicy::balancedSplitEligible(
            [...self::LAB_ONLY_COURSE, 'lecture_hours' => 3, 'lab_hours' => 0, 'room_type_required' => 'lecture'],
            [],
        ));
    }

    public function test_an_unknown_stored_value_falls_back_to_laboratory(): void
    {
        $this->useLabRoomType('gym');

        $this->assertSame('laboratory', SchedulingPolicy::labRoomType($this->departmentId));
    }

    public function test_the_rule_belongs_to_the_scheduling_department(): void
    {
        $other = (int) Departments::create([
            'department_name' => 'College of Computer Studies',
            'department_code' => 'CCS',
            'status' => 'active',
        ])->id;
        $this->useLabRoomType('lecture');

        // The stored value is still read per department, but no department lets a laboratory
        // meeting use a classroom.
        $this->assertSame('lecture', SchedulingPolicy::labRoomType($this->departmentId));
        $this->assertSame('laboratory', SchedulingPolicy::labRoomType($other));
        $this->assertNotNull($this->mismatch(self::CLASSROOM));
        $this->assertNotNull($this->mismatch(self::CLASSROOM, $other));
    }
}
