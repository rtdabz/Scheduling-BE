<?php

namespace App\Http\Controllers;

use App\Http\Requests\Designation\StoreDesignationRequest;
use App\Http\Requests\Designation\UpdateDesignationRequest;
use App\Models\Designation;
use App\Services\FacultyDesignationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for the administrative designations an instructor may hold.
 *
 * The list is institution data, not a fixed enum -- a school adds Program
 * Chairperson, Laboratory Head, Research Coordinator and so on over time, each
 * carrying its own deload. Nothing here hardcodes a designation name.
 */
class DesignationController extends Controller
{
    public function __construct(private readonly FacultyDesignationService $holdings) {}

    public function index(Request $request): JsonResponse
    {
        // Pickers want only what can still be assigned; the management screen
        // wants the inactive ones too, so it asks for them explicitly.
        $activeOnly = $request->boolean('active_only');

        $designations = Designation::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->ordered()
            ->with('parent:id,name')
            ->withCount(['faculties', 'children'])
            ->get();

        return response()->json($designations);
    }

    public function show(Designation $designation): JsonResponse
    {
        return response()->json($designation->load('parent:id,name')->loadCount(['faculties', 'children']));
    }

    public function store(StoreDesignationRequest $request): JsonResponse
    {
        $payload = $this->normalize($request->validated());
        if ($refusal = $this->refuseParent($payload['parent_id'] ?? null)) {
            return $refusal;
        }

        $designation = Designation::create($payload);
        $this->flush();

        return response()->json([
            'message' => 'Designation created successfully.',
            'data' => $designation->load('parent:id,name')->loadCount(['faculties', 'children']),
        ], 201);
    }

    public function update(UpdateDesignationRequest $request, Designation $designation): JsonResponse
    {
        $payload = $this->normalize($request->validated(), $designation);
        if (array_key_exists('parent_id', $payload)
            && ($refusal = $this->refuseParent($payload['parent_id'], $designation))) {
            return $refusal;
        }

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

            // A holder's deload is the sum across every designation they hold,
            // so it is recomputed rather than overwritten with this one figure.
            if ($deloadChanged) {
                $holdersUpdated = $this->holdings->refreshHolders($designation);
            }
        });

        $this->flush();

        return response()->json([
            'message' => 'Designation updated successfully.',
            'holders_updated' => $holdersUpdated,
            'data' => $designation->fresh()->load('parent:id,name')->loadCount(['faculties', 'children']),
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

        $children = $designation->children()->count();
        if ($children > 0) {
            return response()->json([
                'message' => "This designation cannot be archived while it has {$children} sub-designation(s). "
                    .'Archive or move those first.',
                'children_count' => $children,
            ], 409);
        }

        $designation->delete();
        $this->flush();

        return response()->json(['message' => 'Designation archived successfully.']);
    }

    /**
     * Refuses a parent that would break the one-level hierarchy, or a move that
     * would turn a designation instructors hold into a heading nobody can hold.
     */
    private function refuseParent(?int $parentId, ?Designation $designation = null): ?JsonResponse
    {
        if ($parentId === null) {
            return null;
        }

        $refuse = static fn (string $message): JsonResponse => response()->json([
            'message' => $message,
            'errors' => ['parent_id' => [$message]],
        ], 422);

        if ($designation !== null && $parentId === (int) $designation->id) {
            return $refuse('A designation cannot sit under itself.');
        }

        $parent = Designation::query()->withCount('faculties')->find($parentId);
        if ($parent === null) {
            return $refuse('The parent designation no longer exists.');
        }
        if ($parent->parent_id !== null) {
            return $refuse("{$parent->name} is itself a sub-designation. Sub-designations go one level deep.");
        }
        if ($designation !== null && $designation->children()->exists()) {
            return $refuse("{$designation->name} has sub-designations of its own, so it cannot become one.");
        }
        // Once it has sub-designations the parent is a heading, which no
        // instructor may hold -- so it has to be released from its holders first.
        if ($parent->faculties_count > 0 && ! $parent->children()->exists()) {
            return $refuse("{$parent->name} is held by {$parent->faculties_count} instructor(s). Clear it from them before adding sub-designations under it.");
        }

        return null;
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

        if (array_key_exists('parent_id', $payload)) {
            $payload['parent_id'] = $payload['parent_id'] === null || $payload['parent_id'] === '' ? null : (int) $payload['parent_id'];
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
