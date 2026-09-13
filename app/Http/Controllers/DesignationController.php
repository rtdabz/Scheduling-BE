<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD for the administrative designations an instructor may hold.
 *
 * The list is institution data, not a fixed enum -- a school adds Program
 * Chairperson, Laboratory Head, Research Coordinator and so on over time, each
 * carrying its own deload. Nothing here hardcodes a designation name.
 */
class DesignationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Pickers want only what can still be assigned; the management screen
        // wants the inactive ones too, so it asks for them explicitly.
        $activeOnly = $request->boolean('active_only');

        $designations = Designation::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->ordered()
            ->withCount('faculties')
            ->get();

        return response()->json($designations);
    }

    public function show(Designation $designation): JsonResponse
    {
        return response()->json($designation->loadCount('faculties'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $designation = Designation::create($this->normalize($validated));
        $this->flush();

        return response()->json([
            'message' => 'Designation created successfully.',
            'data' => $designation->loadCount('faculties'),
        ], 201);
    }

    public function update(Request $request, Designation $designation): JsonResponse
    {
        $validated = $request->validate($this->rules($designation->id));
        $payload = $this->normalize($validated, $designation);

        // The deload an instructor carries is a copy of their designation's, so
        // that SchedulingPolicy reads one column rather than a join. Changing
        // the designation's figure therefore has to rewrite the holders, or the
        // number on the management screen stops matching the Basic Load the
        // scheduler actually places against.
        $deloadChanged = array_key_exists('deload_units', $payload)
            && (int) $payload['deload_units'] !== (int) $designation->deload_units;

        $holdersUpdated = 0;

        DB::transaction(function () use ($designation, $payload, $deloadChanged, &$holdersUpdated): void {
            $designation->update($payload);

            if ($deloadChanged) {
                $holdersUpdated = $designation->faculties()
                    ->update(['deload_units' => (int) $payload['deload_units']]);
            }
        });

        $this->flush();

        return response()->json([
            'message' => 'Designation updated successfully.',
            'holders_updated' => $holdersUpdated,
            'data' => $designation->fresh()->loadCount('faculties'),
        ]);
    }

    public function destroy(Designation $designation): JsonResponse
    {
        // Soft-deleting would leave the holders pointing at a row nothing can
        // see while their deload stayed in place, so the holders have to be
        // released first -- deliberately the caller's decision, not a silent
        // side effect that changes someone's Basic Load without warning.
        $holders = $designation->faculties()->count();

        if ($holders > 0) {
            return response()->json([
                'message' => "This designation cannot be archived while {$holders} instructor(s) hold it. "
                    .'Clear it from those instructors first.',
                'holders_count' => $holders,
            ], 409);
        }

        $designation->delete();
        $this->flush();

        return response()->json(['message' => 'Designation archived successfully.']);
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                $ignoreId === null ? 'required' : 'sometimes',
                'required', 'string', 'max:255',
                Rule::unique('designations', 'name')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            // No upper bound is imposed here: a full deload is a real
            // arrangement, and the ceiling is max_units, which varies per
            // instructor. FacultyBasicLoad already floors the result at zero.
            'deload_units' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated, ?Designation $existing = null): array
    {
        $payload = $validated;

        if (array_key_exists('name', $payload)) {
            $payload['name'] = trim((string) $payload['name']);
        }

        foreach (['code', 'description'] as $optional) {
            if (array_key_exists($optional, $payload)) {
                $value = trim((string) ($payload[$optional] ?? ''));
                $payload[$optional] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('code', $payload) && $payload['code'] !== null) {
            $payload['code'] = strtoupper($payload['code']);
        }

        if (array_key_exists('sort_order', $payload) && $payload['sort_order'] === null) {
            $payload['sort_order'] = 0;
        }

        if ($existing === null) {
            $payload += ['deload_units' => 0, 'status' => 'active', 'sort_order' => 0];
        }

        return $payload;
    }

    /**
     * A designation change moves an instructor's deload, which every cached
     * roster and the initial-data payload carry.
     */
    private function flush(): void
    {
        ApiCache::forgetGroups(['faculty.index', 'initial.data']);
    }
}
