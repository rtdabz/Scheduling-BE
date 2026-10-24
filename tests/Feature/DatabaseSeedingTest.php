<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_and_seed_can_be_repeated_on_a_new_database(): void
    {
        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();
        $departmentCount = Departments::count();
        $roomCount = Rooms::count();

        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();

        $this->assertSame(count(DepartmentSeeder::DEPARTMENTS), $departmentCount);
        $this->assertGreaterThan(0, $roomCount);
        $this->assertSame($departmentCount, Departments::count());
        $this->assertSame($roomCount, Rooms::count());
    }

    public function test_seeding_preserves_existing_department_codes_ids_and_links(): void
    {
        $education = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'COED',
        ]);
        $midwifery = Departments::create([
            'department_name' => 'College of Midwifery',
            'department_code' => 'CMID',
        ]);
        $program = Program::create([
            'department_id' => $education->id,
            'code' => 'BEED',
            'name' => 'Bachelor of Elementary Education',
        ]);

        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();
        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();

        $this->assertDatabaseHas('departments', ['id' => $education->id, 'department_code' => 'COED']);
        $this->assertDatabaseHas('departments', ['id' => $midwifery->id, 'department_code' => 'CMID']);
        $this->assertDatabaseHas('programs', ['id' => $program->id, 'department_id' => $education->id]);
        $this->assertDatabaseHas('rooms', ['room_code' => 'Educ 101', 'department_id' => $education->id]);
        $this->assertDatabaseHas('rooms', ['room_code' => 'NEE 101', 'department_id' => $midwifery->id]);
        $this->assertSame(count(DepartmentSeeder::DEPARTMENTS), Departments::count());
    }

    public function test_seeding_keeps_existing_names_when_the_default_code_matches(): void
    {
        $department = Departments::create([
            'department_name' => 'College of Computing',
            'department_code' => 'CIT',
        ]);

        $this->seed(DepartmentSeeder::class);

        $this->assertSame('College of Computing', $department->fresh()->department_name);
        $this->assertSame(count(DepartmentSeeder::DEPARTMENTS), Departments::count());
    }

    public function test_seeding_does_not_restore_or_duplicate_archived_departments(): void
    {
        $department = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'COED',
        ]);
        $department->delete();

        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
        $this->assertSame(count(DepartmentSeeder::DEPARTMENTS), Departments::withTrashed()->count());
        $this->assertDatabaseMissing('rooms', ['department_id' => $department->id]);
    }
}
