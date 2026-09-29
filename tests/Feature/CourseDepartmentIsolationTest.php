<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GEC 1 in IT and GEC 1 in BA are separate records: adding, editing, removing
 * or deleting one never touches the other, whatever the code or name.
 */
class CourseDepartmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Departments $it;

    private Departments $ba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->it = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $this->ba = Departments::create(['department_name' => 'Business Administration', 'department_code' => 'BA']);

        // A department with no program is locked out of course management.
        Program::create(['department_id' => $this->it->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        Program::create(['department_id' => $this->ba->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
    }

    public function test_adding_a_code_another_department_has_creates_a_separate_course(): void
    {
        $itCourse = $this->course('GEC 1', $this->it, ['course_name' => 'Understanding the Self']);
        $baCurriculum = $this->curriculum($this->ba);
        $this->actingAs($this->secretary($this->ba));

        $this->postJson("/api/curriculum/{$baCurriculum->id}/courses/batch-create", ['courses' => [[
            'row_id' => 'r1', 'course_code' => 'gec 1', 'course_name' => 'Self and Society',
            'course_category' => 'minor', 'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'year_level' => 1, 'semester' => 1,
        ]]])->assertOk()->assertJsonPath('results.0.status', 'success');

        $baCourse = Course::where('course_code', 'GEC 1')->where('department_id', $this->ba->id)->firstOrFail();
        $this->assertNotSame($itCourse->id, $baCourse->id);
        $this->assertSame('Self and Society', $baCourse->course_name);
        $this->assertTrue($baCurriculum->courses()->whereKey($baCourse->id)->exists());

        $itCourse->refresh();
        $this->assertSame('Understanding the Self', $itCourse->course_name);
        $this->assertSame(3, (int) $itCourse->units);
    }

    public function test_a_created_course_belongs_to_the_creators_department(): void
    {
        $this->course('GEC 1', $this->it);
        $this->actingAs($this->secretary($this->ba));

        $this->postJson('/api/courses', $this->payload('GEC 1'))
            ->assertCreated()
            ->assertJsonPath('department_id', $this->ba->id);

        $this->postJson('/api/courses', $this->payload('GEC 2') + ['department_id' => $this->it->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('courses', ['course_code' => 'GEC 2']);
    }

    public function test_the_vpaa_must_name_the_courses_department(): void
    {
        $this->actingAs($this->vpaa());

        $this->postJson('/api/courses', $this->payload('GEC 1'))->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertSame(0, Course::whereNull('department_id')->count());
    }

    public function test_creating_a_course_requires_year_level_and_semester(): void
    {
        $this->actingAs($this->secretary($this->ba));
        $payload = collect($this->payload('GEC 1'))->except(['year_level', 'semester'])->all();

        $this->postJson('/api/courses', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year_level', 'semester']);
        $this->assertDatabaseMissing('courses', ['course_code' => 'GEC 1']);
    }

    public function test_editing_a_course_changes_only_that_departments_record(): void
    {
        $itCourse = $this->course('GEC 1', $this->it);
        $baCourse = $this->course('GEC 1', $this->ba);
        $this->actingAs($this->secretary($this->ba));

        $this->putJson("/api/courses/{$baCourse->id}", ['course_name' => 'Renamed', 'units' => 2])->assertOk();
        $this->assertSame('Renamed', $baCourse->refresh()->course_name);
        $this->assertSame('Understanding the Self', $itCourse->refresh()->course_name);
        $this->assertSame(3, (int) $itCourse->units);

        $this->putJson("/api/courses/{$itCourse->id}", ['course_name' => 'Hijacked'])->assertForbidden();
        $this->putJson("/api/courses/{$baCourse->id}", ['department_id' => $this->it->id])->assertForbidden();
        $this->assertSame($this->ba->id, (int) $baCourse->refresh()->department_id);
    }

    public function test_removing_or_deleting_a_course_leaves_other_departments_alone(): void
    {
        $itCourse = $this->course('GEC 1', $this->it);
        $baCourse = $this->course('GEC 1', $this->ba);
        $itCurriculum = $this->curriculum($this->it);
        $baCurriculum = $this->curriculum($this->ba);
        $itCurriculum->courses()->attach($itCourse->id, ['year_level' => 1, 'semester' => 1]);
        $baCurriculum->courses()->attach($baCourse->id, ['year_level' => 1, 'semester' => 1]);
        $this->actingAs($this->secretary($this->ba));

        $this->deleteJson("/api/curriculum/{$baCurriculum->id}/courses/{$baCourse->id}")->assertOk();
        $this->deleteJson("/api/courses/{$baCourse->id}")->assertOk();
        $this->deleteJson("/api/courses/{$itCourse->id}")->assertForbidden();

        $this->assertSoftDeleted('courses', ['id' => $baCourse->id]);
        $this->assertNotSoftDeleted('courses', ['id' => $itCourse->id]);
        $this->assertTrue($itCurriculum->courses()->whereKey($itCourse->id)->exists());
    }

    public function test_a_curriculum_cannot_attach_another_departments_course(): void
    {
        $itCourse = $this->course('GEC 1', $this->it);
        $baCurriculum = $this->curriculum($this->ba);
        $this->actingAs($this->secretary($this->ba));

        $this->postJson("/api/curriculum/{$baCurriculum->id}/courses", [
            'course_id' => $itCourse->id, 'year_level' => 1, 'semester' => 1,
        ])->assertStatus(422);
        $this->assertFalse($baCurriculum->courses()->whereKey($itCourse->id)->exists());
    }

    public function test_course_lists_show_only_the_departments_own_courses(): void
    {
        $itCourse = $this->course('GEC 1', $this->it);
        $baCourse = $this->course('GEC 1', $this->ba);
        $itCurriculum = $this->curriculum($this->it);
        $baCurriculum = $this->curriculum($this->ba);
        $itCurriculum->courses()->attach($itCourse->id, ['year_level' => 1, 'semester' => 1]);
        $baCurriculum->courses()->attach($baCourse->id, ['year_level' => 1, 'semester' => 1]);
        $this->actingAs($this->secretary($this->ba));

        $ids = collect($this->getJson("/api/courses?department_id={$this->ba->id}")->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$baCourse->id], $ids);

        $detail = collect($this->getJson("/api/curriculum/{$baCurriculum->id}/full")->assertOk()->json('semesters.0.courses'));
        $this->assertSame([$baCourse->id], $detail->pluck('id')->all());
        $this->assertSame($this->ba->id, $detail->first()['department_id']);
    }

    public function test_migration_splits_a_shared_course_into_one_copy_per_department(): void
    {
        $delegate = Departments::create(['department_name' => 'Arts and Sciences', 'department_code' => 'CAS']);
        $shared = $this->course('GEC 1', null, ['teaching_department_id' => $delegate->id]);
        $unused = $this->course('GEC 9', null);
        $itCurriculum = $this->curriculum($this->it);
        $baCurriculum = $this->curriculum($this->ba);
        $itCurriculum->courses()->attach($shared->id, ['year_level' => 1, 'semester' => 1]);
        $baCurriculum->courses()->attach($shared->id, ['year_level' => 1, 'semester' => 2]);
        DB::table('department_course_rules')->insert([
            'department_id' => $this->ba->id, 'course_id' => $shared->id, 'is_field' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_09_29_100001_split_shared_courses_by_department.php'))->up();

        $itCopy = Course::where('course_code', 'GEC 1')->where('department_id', $this->it->id)->sole();
        $baCopy = Course::where('course_code', 'GEC 1')->where('department_id', $this->ba->id)->sole();
        $this->assertSame($shared->id, $itCopy->id, 'The first department keeps the original row.');
        $this->assertNotSame($itCopy->id, $baCopy->id);
        $this->assertSame($delegate->id, (int) $baCopy->teaching_department_id);

        $this->assertSame([$itCopy->id], $itCurriculum->courses()->pluck('courses.id')->all());
        $this->assertSame([$baCopy->id], $baCurriculum->courses()->pluck('courses.id')->all());
        $this->assertSame(2, (int) $baCurriculum->courses()->first()->pivot->semester);
        $this->assertDatabaseHas('department_course_rules', ['department_id' => $this->ba->id, 'course_id' => $baCopy->id]);

        $this->assertSoftDeleted('courses', ['id' => $unused->id]);
        $this->assertSame(0, Course::whereNull('department_id')->count());
    }

    private function course(string $code, ?Departments $department, array $overrides = []): Course
    {
        return Course::create($overrides + [
            'course_code' => $code, 'course_name' => 'Understanding the Self',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department?->id, 'status' => 'active',
        ]);
    }

    private function curriculum(Departments $department): Curriculum
    {
        return Curriculum::create([
            'name' => "{$department->department_code} Curriculum", 'code' => "{$department->department_code}-CURR",
            'department_id' => $department->id, 'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $code): array
    {
        return [
            'course_code' => $code, 'course_name' => 'Understanding the Self',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
        ];
    }

    private function secretary(Departments $department): User
    {
        $user = User::create([
            'name' => "{$department->department_code} Secretary", 'username' => strtolower($department->department_code).'_sec',
            'email' => strtolower($department->department_code).'@example.com', 'password' => bcrypt('password'),
            'role' => 'secretary', 'department_id' => $department->id,
        ]);
        $this->grantCapabilities($user, ['schedule.view', 'schedule.create', 'curriculum.manage']);

        return $user;
    }

    private function vpaa(): User
    {
        $user = User::create([
            'name' => 'VPAA', 'username' => 'vpaa', 'email' => 'vpaa@example.com',
            'password' => bcrypt('password'), 'role' => 'vpaa',
        ]);
        $this->grantCapabilities($user, ['schedule.view', 'schedule.create']);

        return $user;
    }
}
