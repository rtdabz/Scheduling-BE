<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Capturing a department snapshot must not look up each section's curriculum one query at a time. */
class SnapshotCurriculumEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_curricula_are_loaded_in_one_query(): void
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Snapshot Dept', 'department_code' => 'SNP']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSSN', 'name' => 'Snapshot']);
        foreach (range(1, 8) as $index) {
            $curriculum = Curriculum::create([
                'name' => "Curriculum $index", 'code' => "SNP$index", 'department_id' => $department->id,
                'program_id' => $program->id, 'effective_school_year' => '2026-2027', 'status' => 'active',
            ]);
            Sections::create([
                'section_name' => "SNP-$index", 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'program_id' => $program->id,
                'curriculum_id' => $curriculum->id, 'semester_id' => $semester->id, 'status' => 'active',
            ]);
        }

        $curriculumQueries = 0;
        DB::listen(function ($query) use (&$curriculumQueries): void {
            if (preg_match('/from "curriculum"/', $query->sql) === 1) {
                $curriculumQueries++;
            }
        });

        $snapshot = app(SchedulingSnapshotRepository::class)->capture($semester->id, $department->id, includeFaculties: false);

        $this->assertCount(8, array_unique($snapshot->curriculumIdBySectionId));
        // One eager load for the sections' curricula and one for the names listed in the snapshot.
        $this->assertLessThanOrEqual(2, $curriculumQueries);
    }
}
