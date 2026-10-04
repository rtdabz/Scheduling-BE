<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Designation\StoreDesignationRequest;
use App\Http\Requests\Designation\UpdateDesignationRequest;
use App\Models\Designation;
use App\Services\FacultyDesignationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DesignationController extends Controller
{
    public function __construct(private readonly FacultyDesignationService $holdings) {}

    public function index(Request $request): JsonResponse
    {
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

    public function holders(Designation $designation): JsonResponse
    {
        $holders = $designation->faculties()
            ->with('department:id,department_name,department_code')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['faculties.id', 'first_name', 'last_name', 'middle_name', 'suffix', 'employment_type', 'department_id', 'deload_units', 'status'])
            ->map(fn ($faculty) => [
                'id' => $faculty->id,
                'first_name' => $faculty->first_name,
                'last_name' => $faculty->last_name,
                'middle_name' => $faculty->middle_name,
                'suffix' => $faculty->suffix,
                'employment_type' => $faculty->employment_type,
                'deload_units' => (int) $faculty->deload_units,
                'status' => $faculty->status,
                'department' => $faculty->department?->only(['id', 'department_name', 'department_code']),
            ]);

        return response()->json($holders);
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

        $deloadChanged = array_key_exists('deload_units', $payload)
            && (int) $payload['deload_units'] !== (int) $designation->deload_units;

        $holdersUpdated = 0;

        DB::transaction(function () use ($designation, $payload, $deloadChanged, &$holdersUpdated): void {
            $designation->update($payload);

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

    private function flush(): void
    {
        ApiCache::forgetGroups(['faculty.index', 'initial.data']);
    }
}
