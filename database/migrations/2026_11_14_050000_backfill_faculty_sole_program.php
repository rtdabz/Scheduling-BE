<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Instructors with no program in a department that has exactly one program
     * belong to that program; otherwise its major courses reject them.
     */
    public function up(): void
    {
        $soleProgramByDepartment = DB::table('programs')
            ->whereNull('deleted_at')
            ->select('department_id', DB::raw('MIN(id) as program_id'))
            ->groupBy('department_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('program_id', 'department_id');

        foreach ($soleProgramByDepartment as $departmentId => $programId) {
            DB::table('faculties')
                ->where('department_id', $departmentId)
                ->whereNull('program_id')
                ->update(['program_id' => $programId]);
        }

        \App\Support\ApiCache::forgetGroups(['faculty.index', 'initial.data']);
    }

    public function down(): void
    {
        // Irreversible data backfill: the earlier "no program" state is not recorded.
    }
};
