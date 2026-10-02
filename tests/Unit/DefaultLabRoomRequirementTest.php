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

    public function test_classroom_setting_schedules_a_laboratory_course_in_a_regular_classroom(): void
    {
        $this->useLabRoomType('lecture');

        $this->assertNull($this->mismatch(self::CLASSROOM));
        $this->assertNotNull($this->mismatch(self::LABORATORY));
        // Room TBA stays available to a laboratory meeting.
        $this->assertNull(RoomTypeRule::mismatch(self::LAB_ONLY_COURSE, null, 'on-site', null, $this->departmentId, []));
    }

    public function test_either_setting_accepts_both_rooms(): void
    {
        $this->useLabRoomType('either');

        $this->assertSame(['laboratory', 'lecture'], SchedulingPolicy::labRoomTypes($this->departmentId));
        $this->assertNull($this->mismatch(self::CLASSROOM));
        $this->assertNull($this->mismatch(self::LABORATORY));
    }

    public function test_split_is_allowed_for_courses_with_laboratory_units(): void
    {
        $this->assertTrue(SchedulingPolicy::balancedSplitEligible(self::LAB_ONLY_COURSE, []));
        $this->assertTrue(SchedulingPolicy::balancedSplitEligible(
            [...self::LAB_ONLY_COURSE, 'lecture_hours' => 2, 'lab_hours' => 1],
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

        $this->assertNull($this->mismatch(self::CLASSROOM));
        $this->assertNotNull($this->mismatch(self::CLASSROOM, $other));
    }
}
