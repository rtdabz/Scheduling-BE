<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clears laboratory override flags left behind on standard departments.
 *
 * Switching a department to the `standard` profile only wrote
 * `scheduling_profile`, so any previously-enabled laboratory flags stayed true.
 * From then on the state was unreachable in both directions:
 * SchedulingSettingsController refuses to *enable* them on a standard department,
 * and the secretary Settings page greys the toggles out — while CspSolver still
 * read them (audit finding #37).
 *
 * DepartmentsController now resets them as part of the transition; this cleans up
 * rows that already drifted. The solver's reads are deliberately left ungated:
 * five existing generation tests set the flag on a department whose profile
 * defaults to `standard` and expect it honoured, so gating the read would change
 * generation behaviour well beyond what the finding reported.
 */
return new class extends Migration
{
    private const LABORATORY_FLAGS = [
        'lecture_lab_schedule_override_enabled',
        'custom_lab_duration_override_enabled',
        'custom_lab_duration_6_hours_enabled',
        'custom_lab_duration_5_hours_enabled',
        'custom_lab_duration_other_enabled',
    ];

    public function up(): void
    {
        DB::table('departments')
            ->where(function ($query): void {
                $query->where('scheduling_profile', 'standard')
                    ->orWhereNull('scheduling_profile');
            })
            ->update(array_fill_keys(self::LABORATORY_FLAGS, false));
    }

    public function down(): void
    {
        // The previous values are not recoverable, and leaving the flags cleared is
        // the consistent state for a standard department.
    }
};
