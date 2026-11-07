<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Classification rules no longer have a match type or a status: every rule is an enabled prefix.
 * Exact-code rules become prefixes of the same code (an exact rule whose code is already a prefix rule
 * in the department is dropped, keeping the prefix), and disabled rules are enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Drafts validated against the old rules must be re-validated.
            $changed = DB::table('curriculum_classification_rules')
                ->where(fn ($query) => $query->where('match_kind', 'exact')->orWhere('enabled', false))
                ->distinct()
                ->pluck('department_id');
            if ($changed->isNotEmpty()) {
                DB::table('departments')->whereIn('id', $changed)->increment('curriculum_import_settings_revision');
            }

            $prefixes = DB::table('curriculum_classification_rules')
                ->where('match_kind', 'prefix')
                ->get(['department_id', 'match_value'])
                ->map(fn ($rule): string => $rule->department_id.'|'.$rule->match_value)
                ->flip();

            $exact = DB::table('curriculum_classification_rules')->where('match_kind', 'exact')->orderBy('id')->get(['id', 'department_id', 'match_value']);
            foreach ($exact as $rule) {
                $identity = $rule->department_id.'|'.$rule->match_value;
                if ($prefixes->has($identity)) {
                    DB::table('curriculum_classification_rules')->where('id', $rule->id)->delete();

                    continue;
                }
                DB::table('curriculum_classification_rules')->where('id', $rule->id)->update(['match_kind' => 'prefix', 'updated_at' => now()]);
                $prefixes->put($identity, true);
            }

            DB::table('curriculum_classification_rules')->where('enabled', false)->update(['enabled' => true, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // The original match types and statuses are not recoverable; rules stay enabled prefixes.
    }
};
