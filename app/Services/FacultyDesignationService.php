<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place an instructor's designations are written.
 *
 * An instructor may hold as many designations as their Basic Load covers:
 *
 * - `designation_faculty` holds the designations and their order;
 * - `faculties.deload_units` is the sum of their deloads, which is the figure
 *   SchedulingPolicy::facultyBasicLoad() and the generator snapshot read.
 */
class FacultyDesignationService
{
    /**
     * Whether the request says anything about designations at all. The
     * multi-select sends `designation_ids`; older clients still send the single
     * `designation_id`.
     */
    public function submitted(Request $request): bool
    {
        return $request->has('designation_ids') || $request->has('designation_id');
    }

    /**
     * The designation ids a request asks for, in order, de-duplicated. A null or
     * empty value means "no designations".
     *
     * @return list<int>
     */
    public function idsFrom(Request $request): array
    {
        $raw = $request->has('designation_ids')
            ? $request->input('designation_ids')
            : $request->input('designation_id');

        $values = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);

        $ids = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value)) {
                throw ValidationException::withMessages(['designation_ids' => 'Each designation must be an id.']);
            }
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Refuses a list that cannot be held: unknown or archived, a
     * heading that has sub-designations, an inactive one the instructor does
     * not already hold (an inactive designation cannot be newly assigned, but
     * saving an instructor who still holds one must not fail), or deloads that
     * add up to more than the instructor's maximum units.
     *
     * @param  list<int>  $ids
     * @param  int|null  $maxUnits  the instructor's maximum; null skips the load check
     */
    public function validate(array $ids, ?Faculty $faculty = null, ?int $maxUnits = null): void
    {
        if ($ids === []) {
            return;
        }

        $designations = Designation::query()->withCount('children')->whereIn('id', $ids)->get()->keyBy('id');
        $alreadyHeld = $faculty === null
            ? []
            : $faculty->designations()->pluck('designations.id')->map('intval')->all();

        foreach ($ids as $id) {
            $designation = $designations->get($id);
            if ($designation === null) {
                throw ValidationException::withMessages(['designation_ids' => 'One of the selected designations no longer exists.']);
            }
            if ($designation->children_count > 0) {
                throw ValidationException::withMessages([
                    'designation_ids' => "{$designation->name} is a heading. Choose one of its sub-designations instead.",
                ]);
            }
            if ($designation->status !== 'active' && ! in_array($id, $alreadyHeld, true)) {
                throw ValidationException::withMessages([
                    'designation_ids' => "{$designation->label} is inactive and cannot be assigned.",
                ]);
            }
        }

        // The deloads may not add up to more than the maximum, which would
        // leave a negative Basic Load. Re-saving what the instructor already
        // holds never fails, even if their maximum has since been lowered.
        $deload = (int) $designations->sum('deload_units');
        if ($maxUnits !== null && $deload > $maxUnits && array_diff($ids, $alreadyHeld) !== []) {
            throw ValidationException::withMessages([
                'designation_ids' => "These designations deload {$deload} units, more than the {$maxUnits}-unit maximum. Remove one to fit the Basic Load.",
            ]);
        }
    }

    /**
     * Writes the instructor's designations and the deload derived from them.
     *
     * @param  list<int>  $ids
     */
    public function sync(Faculty $faculty, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($faculty, $ids): void {
            $faculty->designations()->sync(
                collect($ids)->mapWithKeys(fn (int $id, int $position): array => [$id => ['position' => $position]])->all(),
            );

            $faculty->forceFill([
                'deload_units' => $this->deloadFor($ids),
            ])->save();
        });
    }

    /**
     * The deload the designations add up to.
     *
     * @param  list<int>  $ids
     */
    public function deloadFor(array $ids): int
    {
        return $ids === [] ? 0 : (int) Designation::query()->whereIn('id', $ids)->sum('deload_units');
    }

    /**
     * Recomputes the copied deload of everyone holding the designation, after
     * its deload changed. Returns how many instructors were rewritten.
     */
    public function refreshHolders(Designation $designation): int
    {
        $holders = $designation->faculties()->with('designations:id,deload_units')->get();

        foreach ($holders as $faculty) {
            $faculty->forceFill([
                'deload_units' => (int) $faculty->designations->sum('deload_units'),
            ])->save();
        }

        return $holders->count();
    }
}
