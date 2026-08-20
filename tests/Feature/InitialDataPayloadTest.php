<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
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

    /** @return array{0: User} */
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

        return [$user];
    }
}
