<?php

namespace App\Console\Commands;

use App\Services\Scheduling\DepartmentSchedulingAuditService;
use Illuminate\Console\Command;

class AuditDepartmentSchedulingCommand extends Command
{
    protected $signature = 'scheduling:audit-departments {--department= : Audit one department ID} {--json : Print JSON output}';

    protected $description = 'Audit department profiles, course laboratory requirements, and available scheduling rooms.';

    public function handle(DepartmentSchedulingAuditService $audit): int
    {
        $rows = $audit->audit($this->option('department') !== null ? (int) $this->option('department') : null);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table([
            'Code', 'Profile', 'Curriculum', 'Courses', 'Lab Courses', 'Lecture Rooms', 'Lab Rooms', 'Mismatch', 'Lab Settings',
        ], array_map(static fn (array $row): array => [
            $row['department_code'],
            $row['profile'],
            $row['active_curriculum'] ? 'yes' : 'no',
            $row['active_course_count'],
            $row['laboratory_course_count'],
            $row['available_lecture_rooms'],
            $row['available_laboratory_rooms'],
            $row['profile_mismatch'] ? 'yes' : 'no',
            $row['laboratory_settings_enabled'] ? 'yes' : 'no',
        ], $rows));

        return self::SUCCESS;
    }
}
