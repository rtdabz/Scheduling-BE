<?php

namespace App\Http\Controllers;

use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\ScheduleAuthorizationService;
use App\Services\Scheduling\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SectionsController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    // Get all sections
    public function index()
    {
        $sections = Cache::remember(ApiCache::key('sections.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['department', 'term'])
            ->latest()
            ->get());

        return response()->json($sections);
    }

    private function getActiveSystemTerm(): Terms
    {
        return Terms::where('is_active', true)->first()
            ?? Terms::where('is_enabled', true)->orderBy('id')->first()
            ?? Terms::firstOrFail();
    }

    // Create section
    public function store(Request $request)
    {
        $activeTerm = $this->getActiveSystemTerm();
        $validated = $request->validate([
            'section_name'       => 'required|string|max:255',
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('required'),
            'department_id'      => 'required|exists:departments,id',
        ]);

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $validated['term_id'] = $activeTerm->id;
        $validated['semester'] = $activeTerm->semester;
        $validated['status'] = 'active';

        $section = Sections::create($validated);
        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'departments.index',
        ]);

        return response()->json($section->load(['department', 'term']), 201);
    }

    // Create batch sections
    public function batchStore(Request $request)
    {
        $activeTerm = $this->getActiveSystemTerm();
        $validated = $request->validate([
            'sections'                      => 'required|array|min:1|max:50',
            'sections.*.section_name'       => 'required|string|max:255',
            'sections.*.year_level'         => SchedulingPolicy::allowedYearLevelsRule('required'),
            'sections.*.department_id'      => 'required|exists:departments,id',
        ]);

        $departmentIds = collect($validated['sections'])
            ->pluck('department_id')
            ->map(fn ($departmentId) => (int) $departmentId)
            ->unique();
        if ($departmentIds->contains(fn (int $departmentId) => ! $this->authorization->payloadBelongsToDepartment($request, $departmentId))) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $created = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $activeTerm) {
            $list = [];
            foreach ($validated['sections'] as $data) {
                $data['term_id'] = $activeTerm->id;
                $data['semester'] = $activeTerm->semester;
                $data['status'] = 'active';
                $section = Sections::create($data);
                $list[] = $section->load(['department', 'term']);
            }
            return $list;
        });

        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'departments.index',
        ]);

        return response()->json([
            'message'  => count($created) . ' sections created successfully.',
            'sections' => $created,
        ], 201);
    }

    // Get single section
    public function show(Sections $section)
    {
        return response()->json($section->load(['department', 'term']));
    }

    // Update section
    public function update(Request $request, Sections $section)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $section->department_id)) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $validated = $request->validate([
            'section_name'       => 'sometimes|string|max:255',
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('sometimes'),
            'semester'           => SchedulingPolicy::allowedSemestersRule('sometimes'),
            'department_id'      => 'sometimes|exists:departments,id',
            'term_id'            => 'sometimes|exists:terms,id',
            'status'             => SchedulingPolicy::allowedActiveStatusesRule('sometimes'),
        ]);

        if (isset($validated['department_id']) && ! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only move sections within your department.'], 403);
        }

        $section->update($validated);
        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'departments.index',
        ]);

        return response()->json($section->load(['department', 'term']));
    }

    // Delete section
    public function destroy(Request $request, Sections $section)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $section->department_id)) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $section->delete();
        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'departments.index',
        ]);
        return response()->json(['message' => 'Section archived successfully']);
    }

    // Get sections by term
    public function byTerm($termId)
    {
        $sections = Cache::remember(ApiCache::key('sections.by_term', ['term_id' => $termId]), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['department'])
            ->where('term_id', $termId)
            ->get());

        return response()->json($sections);
    }

    // Get sections by department
    public function byDepartment($departmentId)
    {
        $sections = Cache::remember(ApiCache::key('sections.by_department', ['department_id' => $departmentId]), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['term'])
            ->where('department_id', $departmentId)
            ->get());

        return response()->json($sections);
    }
}
