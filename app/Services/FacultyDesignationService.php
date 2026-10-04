<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyDesignationService
{
    public function submitted(Request $request): bool
    {
        return $request->has('designation_ids') || $request->has('designation_id');
    }

    /**
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

        $deload = (int) $designations->sum('deload_units');
        if ($maxUnits !== null && $deload > $maxUnits && array_diff($ids, $alreadyHeld) !== []) {
            throw ValidationException::withMessages([
                'designation_ids' => "These designations deload {$deload} units, more than the {$maxUnits}-unit maximum. Remove one to fit the Basic Load.",
            ]);
        }
    }

    /**
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
     * @param  list<int>  $ids
     */
    public function deloadFor(array $ids): int
    {
        return $ids === [] ? 0 : (int) Designation::query()->whereIn('id', $ids)->sum('deload_units');
    }

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
