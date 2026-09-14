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
 * An instructor holds up to MAX_PER_FACULTY designations. Three columns are kept
 * in step from that list, so nothing else has to know it is a list:
 *
 * - `designation_faculty` holds the designations and their order;
 * - `faculties.designation_id` is the first of them, for readers that want one;
 * - `faculties.deload_units` is the sum of their deloads, which is the figure
 *   SchedulingPolicy::facultyBasicLoad() and the generator snapshot read.
 */
class FacultyDesignationService
{
    public const MAX_PER_FACULTY = 3;

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
     * Refuses a list that cannot be held: too many, unknown or archived, a
     * heading that has sub-designations, or an inactive one the instructor does
     * not already hold (an inactive designation cannot be newly assigned, but
     * saving an instructor who still holds one must not fail).
     *
     * @param  list<int>  $ids
     */
    public function validate(array $ids, ?Faculty $faculty = null): void
    {
        if (count($ids) > self::MAX_PER_FACULTY) {
            throw ValidationException::withMessages([
                'designation_ids' => 'An instructor can hold at most '.self::MAX_PER_FACULTY.' designations.',
            ]);
        }
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
    }

    /**
     * Writes the instructor's designations and the two columns derived from them.
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
                'designation_id' => $ids[0] ?? null,
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
