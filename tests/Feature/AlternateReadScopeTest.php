<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlternateReadScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_readers_cannot_read_foreign_schedules_or_splits(): void
    {
        $f = $this->fixture();
        foreach ([$f['secretary'], $f['dean']] as $reader) {
            $this->actingAs($reader);
            $this->getJson('/api/schedules/'.$f['c']['schedule']->id)->assertForbidden();
            $this->getJson('/api/schedule-splits/'.$f['c']['split']->id)->assertForbidden();
            $this->assertIds('/api/schedule-splits', [$f['a']['split']->id, $f['b']['split']->id]);
            $this->assertIds('/api/schedule-splits?schedule_id='.$f['c']['schedule']->id, []);
            $this->assertIds('/api/schedule-splits?split_group_id=group-C', []);
            foreach (['a', 'b'] as $key) {
                $this->getJson('/api/schedules/'.$f[$key]['schedule']->id)->assertOk()->assertJsonPath('id', $f[$key]['schedule']->id);
                $this->getJson('/api/schedule-splits/'.$f[$key]['split']->id)->assertOk()->assertJsonPath('schedule.id', $f[$key]['schedule']->id);
            }
        }
    }

    public function test_alternate_section_lists_scope_results_and_cache_to_the_reader(): void
    {
        $f = $this->fixture();
        $semesterUrl = '/api/sections/semester/'.$f['semester']->id;
        $departmentUrl = '/api/sections/department/'.$f['a']['department']->id;
        // Warm the broad cache first, then narrow and broaden the reader scope.
        foreach ([
            [$f['vpaa'], ['a', 'b', 'c'], ['a', 'b']],
            [$f['secretary'], ['a', 'b'], ['a', 'b']],
            [$f['head'], ['a'], ['a']],
            [$f['secretary'], ['a', 'b'], ['a', 'b']],
        ] as [$reader, $semesterKeys, $departmentKeys]) {
            $this->actingAs($reader);
            $this->assertIds($semesterUrl, array_map(fn ($key) => $f[$key]['section']->id, $semesterKeys));
            $this->assertIds($departmentUrl, array_map(fn ($key) => $f[$key]['section']->id, $departmentKeys));
        }
        $foreignUrl = '/api/sections/department/'.$f['c']['department']->id;
        $this->actingAs($f['secretary'])->getJson($foreignUrl)->assertForbidden();
        $this->actingAs($f['head'])->getJson($foreignUrl)->assertForbidden();
        $this->actingAs($f['vpaa']);
        $this->assertIds($foreignUrl, [$f['c']['section']->id]);
    }

    public function test_program_head_reads_follow_existing_program_and_shared_room_scope(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['head']);
        $this->getJson('/api/schedules/'.$f['a']['schedule']->id)->assertOk();
        $this->getJson('/api/schedules/'.$f['b']['schedule']->id)->assertForbidden();
        $this->getJson('/api/schedule-splits/'.$f['b']['split']->id)->assertForbidden();
        $this->assertIds('/api/schedule-splits', [$f['a']['split']->id]);

        // A sibling program's class in a shared room is intentionally visible.
        $f['b']['room']->forceFill(['home_program_id' => null])->save();
        $this->getJson('/api/schedules/'.$f['b']['schedule']->id)->assertOk();
        $this->getJson('/api/schedule-splits/'.$f['b']['split']->id)->assertOk();
        $this->assertIds('/api/schedule-splits', [$f['a']['split']->id, $f['b']['split']->id]);
        $this->getJson('/api/schedules/'.$f['c']['schedule']->id)->assertForbidden();
    }

    public function test_delegated_teaching_reads_remain_available(): void
    {
        $f = $this->fixture();
        $f['c']['course']->update([
            'teaching_department_id' => $f['a']['department']->id,
            'teaching_program_id' => $f['a']['program']->id,
        ]);
        foreach ([$f['secretary'], $f['head']] as $reader) {
            $this->actingAs($reader);
            $this->getJson('/api/schedules/'.$f['c']['schedule']->id)->assertOk();
            $this->getJson('/api/schedule-splits/'.$f['c']['split']->id)->assertOk();
            $this->assertIds('/api/schedules/section/'.$f['c']['section']->id, [$f['c']['schedule']->id]);
            $this->assertIds('/api/schedule-splits?schedule_id='.$f['c']['schedule']->id, [$f['c']['split']->id]);
        }
    }

    public function test_vpaa_schedule_visibility_also_applies_to_splits(): void
    {
        $f = $this->fixture();
        $f['a']['schedule']->update(['status' => 'approved']);
        $f['b']['schedule']->update(['status' => 'approved_by_dean']);
        $this->actingAs($f['vpaa']);
        $this->assertIds('/api/schedule-splits', [$f['a']['split']->id]);
        $this->assertIds('/api/schedule-splits?approval_queue=true', [$f['a']['split']->id, $f['b']['split']->id]);
        $this->getJson('/api/schedule-splits/'.$f['b']['split']->id)->assertForbidden();
        $this->getJson('/api/schedule-splits/'.$f['b']['split']->id.'?approval_queue=true')->assertOk();
        $this->getJson('/api/schedules/'.$f['b']['schedule']->id)->assertForbidden();
        $this->getJson('/api/schedules/'.$f['b']['schedule']->id.'?approval_queue=true')->assertOk();
        $this->getJson('/api/schedule-splits/'.$f['c']['split']->id.'?approval_queue=true')->assertForbidden();
        $f['c']['schedule']->update(['status' => 'finalized']);
        $this->getJson('/api/schedules/'.$f['c']['schedule']->id)->assertOk();
        $this->getJson('/api/schedule-splits/'.$f['c']['split']->id)->assertOk();
    }

    public function test_splits_of_archived_schedules_are_not_readable(): void
    {
        $f = $this->fixture();
        $f['a']['schedule']->delete();
        $this->actingAs($f['secretary']);
        $this->getJson('/api/schedule-splits/'.$f['a']['split']->id)->assertNotFound();
        $this->assertIds('/api/schedule-splits', [$f['b']['split']->id]);
    }

    private function assertIds(string $url, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, array_column($this->getJson($url)->assertOk()->json(), 'id'));
    }

    private function fixture(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'College A', 'department_code' => 'A']);
        $foreign = Departments::create(['department_name' => 'College C', 'department_code' => 'C']);
        $f = ['semester' => $semester];
        foreach (['A' => $department, 'B' => $department, 'C' => $foreign] as $code => $owner) {
            $program = Program::create(['department_id' => $owner->id, 'code' => $code, 'name' => 'Program '.$code]);
            $section = Sections::create(['department_id' => $owner->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'section_name' => $code.'1', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
            $course = Course::create(['department_id' => $owner->id, 'program_id' => $program->id, 'course_code' => $code.'101', 'course_name' => $code, 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
            $room = Rooms::create(['department_id' => $owner->id, 'room_code' => $code.'101', 'room_type' => 'lecture', 'status' => 'available']);
            $room->forceFill(['home_program_id' => $program->id])->save();
            $schedule = Schedule::create(['department_id' => $owner->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id, 'room_id' => $room->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'draft']);
            $split = ScheduleSplit::create(['schedule_id' => $schedule->id, 'split_group_id' => 'group-'.$code, 'meeting_type' => 'lecture', 'meeting_index' => 1]);
            $f[strtolower($code)] = ['department' => $owner, ...compact('program', 'section', 'course', 'room', 'schedule', 'split')];
        }
        $f['secretary'] = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $f['dean'] = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $f['head'] = User::factory()->create(['role' => 'program_head', 'department_id' => $department->id, 'program_id' => $f['a']['program']->id]);
        $f['vpaa'] = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);

        return $f;
    }
}
