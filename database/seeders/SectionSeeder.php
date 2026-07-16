<?php

namespace Database\Seeders;

use App\Models\Departments;
use App\Models\Sections;
use App\Models\Terms;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $activeTerm = Terms::where('is_active', true)->first()
            ?? Terms::where('is_enabled', true)->orderBy('id')->first();

        if (!$activeTerm) {
            throw new \RuntimeException(
                'SectionSeeder requires at least one term to exist. Run TermSeeder first.'
            );
        }

        $departments = Departments::query()
            ->get(['id', 'department_code'])
            ->keyBy('department_code');

        $cit = $departments->get('CIT');

        if (!$cit) {
            throw new \RuntimeException(
                'SectionSeeder requires the CIT department to exist. Run DepartmentSeeder first.'
            );
        }

        $sections = [
            ['section_name' => 'BSIT 1-A', 'year_level' => '1', 'department_id' => $cit->id],
            ['section_name' => 'BSIT 1-B', 'year_level' => '1', 'department_id' => $cit->id],
            ['section_name' => 'BSIT 2-A', 'year_level' => '2', 'department_id' => $cit->id],
            ['section_name' => 'BSIT 2-B', 'year_level' => '2', 'department_id' => $cit->id],
            ['section_name' => 'BSIT 3-A', 'year_level' => '3', 'department_id' => $cit->id],
            ['section_name' => 'BSIT 4-A', 'year_level' => '4', 'department_id' => $cit->id],
        ];

        $starterSections = [
            'CAS' => 'CAS',
            'CBA' => 'CBA',
            'CCJPS' => 'CCJPS',
            'CED' => 'CED',
            'CHM' => 'CHM',
            'CLIS' => 'CLIS',
            'CM' => 'CM',
        ];

        foreach ($starterSections as $departmentCode => $sectionPrefix) {
            $department = $departments->get($departmentCode);
            if (!$department) {
                continue;
            }

            $sections[] = [
                'section_name' => "{$sectionPrefix} 1-A",
                'year_level' => '1',
                'department_id' => $department->id,
            ];
        }

        foreach ($sections as $section) {
            Sections::updateOrCreate(
                [
                    'section_name' => $section['section_name'],
                    'term_id' => $activeTerm->id,
                ],
                [
                    'year_level' => $section['year_level'],
                    'semester' => $activeTerm->semester,
                    'status' => 'active',
                    'department_id' => $section['department_id'],
                    'term_id' => $activeTerm->id,
                ]
            );
        }
    }
}
