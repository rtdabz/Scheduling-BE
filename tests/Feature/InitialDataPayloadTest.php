<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the fix for audit finding #10: /initial-data serialized the course
 * collection twice and returned every column of every user.
 */
class InitialDataPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_courses_are_serialized_once(): void
    {
        [$user] = $this->fixture();

        $response = $this->actingAs($user)->getJson('/api/initial-data');

        $response->assertOk();
        $response->assertJsonStructure(['courses']);
        $this->assertArrayNotHasKey(
            'subjects',
            $response->json(),
            'The duplicate `subjects` alias doubled the course payload for every scheduler load.',
        );
    }

    public function test_users_expose_only_the_columns_the_client_reads(): void
    {
        [$user] = $this->fixture();

        $response = $this->actingAs($user)->getJson('/api/initial-data');

        $response->assertOk();
        $users = $response->json('users');
        $this->assertNotEmpty($users);

        foreach ($users as $row) {
            $this->assertSame(
                ['department_id', 'id', 'name', 'role'],
                collect(array_keys($row))->sort()->values()->all(),
            );
        }
    }

    public function test_department_scoped_users_still_include_the_vpaa(): void
    {
        [$user, $dept] = $this->fixture();

        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $otherDept = Departments::create(['department_name' => 'Other Dept', 'department_code' => 'OTH']);
        $stranger = User::factory()->create(['role' => 'secretary', 'department_id' => $otherDept->id]);

        $response = $this->actingAs($user)->getJson('/api/initial-data');

        $response->assertOk();
        $ids = collect($response->json('users'))->pluck('id')->all();

        // The VPAA signs every college's load sheet but belongs to no department,
        // so a plain department filter dropped the account the sheet is stamped from.
        $this->assertContains($vpaa->id, $ids);
        $this->assertContains($user->id, $ids);
        $this->assertNotContains($stranger->id, $ids, 'Users from other departments must stay out of the payload.');
        $this->assertSame($dept->id, $user->department_id);
    }

    public function test_schema_probes_run_once_per_request(): void
    {
        [$user] = $this->fixture();

        $probes = [];
        DB::listen(function ($query) use (&$probes) {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'sqlite_master') && ! str_contains($sql, 'information_schema')) {
                return;
            }

            // The probed table name is the interesting part; how many *distinct*
            // tables a request checks will grow as more are memoized, but each one
            // must be checked at most once.
            preg_match("/'([a-z_]+)'/", $sql, $matches);
            $table = $matches[1] ?? $sql;
            $probes[$table] = ($probes[$table] ?? 0) + 1;
        });

        $this->actingAs($user)->getJson('/api/initial-data')->assertOk();

        // These hasTable calls used to run unconditionally at every call site; they
        // are now memoized, so no table is probed twice in one request.
        $this->assertNotEmpty($probes);
        foreach ($probes as $table => $count) {
            $this->assertSame(1, $count, "Table {$table} was probed {$count} times in one request.");
        }
    }

    public function test_program_head_auto_assign_payload_only_contains_their_program_faculty(): void
    {
        [, $department] = $this->fixture();
        $bped = Program::create(['department_id' => $department->id, 'code' => 'BPED', 'name' => 'Physical Education']);
        $beed = Program::create(['department_id' => $department->id, 'code' => 'BEED', 'name' => 'Elementary Education']);
        $head = User::factory()->create([
            'role' => 'program_head',
            'department_id' => $department->id,
            'program_id' => $bped->id,
        ]);
        $bpedFaculty = Faculty::create([
            'first_name' => 'BPED', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 21, 'department_id' => $department->id, 'program_id' => $bped->id, 'status' => 'active',
        ]);
        Faculty::create([
            'first_name' => 'BEED', 'last_name' => 'Instructor', 'employment_type' => 'full-time',
            'max_units' => 21, 'department_id' => $department->id, 'program_id' => $beed->id, 'status' => 'active',
        ]);

        $this->actingAs($head)->getJson('/api/initial-data')
            ->assertOk()
            ->assertJsonCount(1, 'faculties')
            ->assertJsonPath('faculties.0.id', $bpedFaculty->id)
            ->assertJsonPath('faculties.0.program_id', $bped->id);
    }

    public function test_program_head_payload_only_contains_courses_and_schedules_assigned_to_their_program(): void
    {
        [, $department] = $this->fixture();
        $term = Terms::query()->where('is_active', true)->firstOrFail();
        $sourceDepartment = Departments::create(['department_name' => 'Source Dept', 'department_code' => 'SRC']);
        $sourceSection = Sections::create([
            'section_name' => 'SRC-1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $sourceDepartment->id, 'term_id' => $term->id, 'status' => 'active',
        ]);
        $bped = Program::create(['department_id' => $department->id, 'code' => 'BPED', 'name' => 'Physical Education']);
        $beed = Program::create(['department_id' => $department->id, 'code' => 'BEED', 'name' => 'Elementary Education']);
        $bpedHead = User::factory()->create(['role' => 'program_head', 'department_id' => $department->id, 'program_id' => $bped->id]);
        $beedHead = User::factory()->create(['role' => 'program_head', 'department_id' => $department->id, 'program_id' => $beed->id]);

        $course = fn (string $code, int $programId) => Course::create([
            'course_code' => $code, 'course_name' => $code,
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $sourceDepartment->id,
            'teaching_department_id' => $department->id,
            'teaching_program_id' => $programId,
            'status' => 'active',
        ]);
        $bpedCourse = $course('BPED 101', $bped->id);
        $beedCourse = $course('BEED 101', $beed->id);

        foreach ([$bpedCourse, $beedCourse] as $index => $assignedCourse) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $sourceSection->id,
                'course_id' => $assignedCourse->id,
                'department_id' => $sourceDepartment->id,
                'day' => $index === 0 ? 'Monday' : 'Tuesday',
                'start_time' => '08:00', 'end_time' => '10:00',
                'mode' => 'online', 'status' => 'approved',
            ]);
        }

        $bpedResponse = $this->actingAs($bpedHead)->getJson('/api/initial-data')->assertOk();
        $this->assertSame([$bpedCourse->id], collect($bpedResponse->json('courses'))->pluck('id')->all());
        $this->assertSame([$bpedCourse->id], collect($bpedResponse->json('schedules'))->pluck('course_id')->unique()->values()->all());

        $beedResponse = $this->actingAs($beedHead)->getJson('/api/initial-data')->assertOk();
        $this->assertSame([$beedCourse->id], collect($beedResponse->json('courses'))->pluck('id')->all());
        $this->assertSame([$beedCourse->id], collect($beedResponse->json('schedules'))->pluck('course_id')->unique()->values()->all());
    }

    /** @return array{0: User, 1: Departments} */
    private function fixture(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $dept = Departments::create(['department_name' => 'Payload Dept', 'department_code' => 'PAY']);
        Sections::create([
            'section_name' => 'PAY-1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'term_id' => $term->id, 'status' => 'active',
        ]);
        Rooms::create([
            'room_code' => 'PAY101', 'room_type' => 'lecture',
            'status' => 'available', 'department_id' => $dept->id,
        ]);

        $curriculum = Curriculum::create([
            'name' => 'BSIT 2026',
            'code' => 'PAYCURR',
            'department_id' => $dept->id,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'PAY101', 'course_name' => 'Payload Course',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'status' => 'active',
        ]);
        DB::table('curriculum_course')->insert([
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => '1',
            'semester' => '1',
        ]);

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]);

        return [$user, $dept];
    }
}
