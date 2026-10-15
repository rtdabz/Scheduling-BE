<?php

namespace Tests\Feature;

use App\Events\LiveDataChanged;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Support\LiveUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CrudCacheConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CC']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $curriculum = Curriculum::create(['name' => 'Original', 'code' => 'IT26', 'department_id' => $department->id,
            'program_id' => $program->id, 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $course = Course::create(['course_code' => 'IT101', 'course_name' => 'Computing', 'department_id' => $department->id,
            'program_id' => $program->id, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $section = Sections::create(['section_name' => 'IT1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id,
            'curriculum_id' => $curriculum->id, 'status' => 'active']);
        $faculty = Faculty::create(['first_name' => 'Cache', 'last_name' => 'Teacher', 'department_id' => $department->id,
            'program_id' => $program->id, 'employment_type' => 'part-time', 'max_units' => 21, 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'IT101', 'room_type' => 'lecture', 'department_id' => $department->id, 'status' => 'available']);
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            [...self::SCHEDULE_WORKSPACE_CAPABILITIES, 'curriculum.manage']);
        $this->f = compact('department', 'program', 'semester', 'curriculum', 'course', 'section', 'faculty', 'room', 'secretary');
        $this->actingAs($secretary);
    }

    private function sectionUrls(): array
    {
        return ['/api/sections', '/api/sections/semester/'.$this->f['semester']->id,
            '/api/sections/department/'.$this->f['department']->id];
    }

    public function test_curriculum_rename_refreshes_every_section_list(): void
    {
        foreach ($this->sectionUrls() as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.curriculum.name', 'Original');
        }
        $this->patchJson('/api/curriculum/'.$this->f['curriculum']->id, ['name' => 'Renamed'])->assertOk();
        foreach ($this->sectionUrls() as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.curriculum.name', 'Renamed');
        }
    }

    public function test_restoring_a_program_refreshes_nested_relations(): void
    {
        $this->f['program']->delete();
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        foreach ($this->sectionUrls() as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.program', null);
        }
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.program', null);
        $this->postJson('/api/archives/programs/'.$this->f['program']->id.'/restore')->assertOk();
        foreach ($this->sectionUrls() as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.program.code', 'BSIT');
        }
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.program.code', 'BSIT');
    }

    public function test_availability_replacement_and_clear_refresh_cached_consumers(): void
    {
        $urls = ['/api/faculties' => '0.availabilities', '/api/instructor-assignments' => 'faculties.0.availabilities',
            '/api/initial-data?include=faculties' => 'faculties.0.availabilities'];
        foreach ($urls as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonCount(0, $path);
        }
        $endpoint = '/api/faculties/'.$this->f['faculty']->id.'/availabilities';
        $this->putJson($endpoint, ['availabilities' => [['day_index' => 0, 'start_time' => '08:00', 'end_time' => '11:00']]])->assertOk();
        foreach ($urls as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, $path);
        }
        $this->putJson($endpoint, ['availabilities' => []])->assertOk();
        foreach ($urls as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonCount(0, $path);
        }
    }

    public function test_availability_write_announces_assignment_refresh_to_other_clients(): void
    {
        app(LiveUpdates::class)->flush();
        config(['broadcasting.default' => 'reverb']);
        Event::fake([LiveDataChanged::class]);
        $this->putJson('/api/faculties/'.$this->f['faculty']->id.'/availabilities', ['availabilities' => []])->assertOk();
        app(LiveUpdates::class)->flush('123.456');
        Event::assertDispatched(LiveDataChanged::class,
            fn ($event) => in_array('assignments', $event->topics, true));
    }

    public function test_course_units_refresh_faculty_load_and_assignment_payload(): void
    {
        $this->schedule('faculty_assignment');
        foreach (['/api/faculties' => '0.assigned_units', '/api/instructor-assignments' => 'faculties.0.assigned_units'] as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonPath($path, 3);
        }
        $this->putJson('/api/courses/'.$this->f['course']->id, ['units' => 6])->assertOk();
        foreach (['/api/faculties' => '0.assigned_units', '/api/instructor-assignments' => 'faculties.0.assigned_units'] as $url => $path) {
            $this->getJson($url)->assertOk()->assertJsonPath($path, 6);
        }
    }

    public function test_course_archive_and_restore_refresh_curriculum_course_counts(): void
    {
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.courses_count', 1);
        $this->deleteJson('/api/courses/'.$this->f['course']->id)->assertOk();
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.courses_count', 0);
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.courses_count', 0);
        $this->postJson('/api/archives/courses/'.$this->f['course']->id.'/restore')->assertOk();
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.courses_count', 1);
    }

    public function test_schedule_archive_and_restore_refresh_curriculum_usage_counts(): void
    {
        $schedule = $this->schedule('draft');
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.scheduled_sections_count', 1);
        $this->deleteJson('/api/schedules/'.$schedule->id)->assertOk();
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.scheduled_sections_count', 0);
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.scheduled_sections_count', 0);
        $this->postJson('/api/archives/schedules/'.$schedule->id.'/restore')->assertOk();
        $this->getJson('/api/curriculum')->assertOk()->assertJsonPath('0.scheduled_sections_count', 1);
    }

    public function test_department_rename_refreshes_nested_lookup_data(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $urls = ['/api/rooms', '/api/courses?catalog=true', '/api/faculties', '/api/sections', '/api/curriculum'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.department.department_name', 'Computing');
        }
        $this->putJson('/api/departments/'.$this->f['department']->id, ['department_name' => 'Renamed College'])->assertOk();
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('0.department.department_name', 'Renamed College');
        }
    }

    public function test_semester_edit_refreshes_department_section_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $url = '/api/sections/department/'.$this->f['department']->id;
        $this->getJson($url)->assertOk()->assertJsonPath('0.academic_semester.academic_year', '2026-2027');
        $this->putJson('/api/semesters/'.$this->f['semester']->id, ['academic_year' => '2027-2028'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('0.academic_semester.academic_year', '2027-2028');
    }

    public function test_room_edit_refreshes_assignment_schedule_relations(): void
    {
        $this->schedule('faculty_assignment');
        $this->getJson('/api/instructor-assignments')->assertOk()->assertJsonPath('schedules.0.room.room_code', 'IT101');
        $this->actingAs(User::factory()->create(['role' => 'vpaa']));
        $this->putJson('/api/rooms/'.$this->f['room']->id, ['room_code' => 'IT102'])->assertOk();
        $this->actingAs($this->f['secretary']);
        $this->getJson('/api/instructor-assignments')->assertOk()->assertJsonPath('schedules.0.room.room_code', 'IT102');
    }

    public function test_split_mutations_refresh_initial_data(): void
    {
        $schedule = $this->schedule('draft');
        $url = '/api/initial-data?include=schedules';
        $this->getJson($url)->assertOk()->assertJsonPath('schedules.0.split_group_id', null);
        $split = $this->postJson('/api/schedule-splits', ['schedule_id' => $schedule->id,
            'split_group_id' => 'cache-group', 'meeting_type' => 'lecture', 'meeting_index' => 1])->assertCreated()->json();
        $this->getJson($url)->assertOk()->assertJsonPath('schedules.0.split_group_id', 'cache-group');
        $this->patchJson('/api/schedule-splits/'.$split['id'], ['split_group_id' => 'renamed-group'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('schedules.0.split_group_id', 'renamed-group');
        $this->deleteJson('/api/schedule-splits/'.$split['id'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('schedules.0.split_group_id', null);
    }

    private function schedule(string $status): Schedule
    {
        return Schedule::create(['semester_id' => $this->f['semester']->id, 'section_id' => $this->f['section']->id,
            'curriculum_id' => $this->f['curriculum']->id, 'course_id' => $this->f['course']->id,
            'faculty_id' => $status === 'draft' ? null : $this->f['faculty']->id,
            'room_id' => $this->f['room']->id, 'department_id' => $this->f['department']->id,
            'program_id' => $this->f['program']->id, 'day' => 'Monday', 'start_time' => '08:00:00',
            'end_time' => '11:00:00', 'mode' => 'on-site', 'status' => $status]);
    }
}
