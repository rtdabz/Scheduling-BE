<?php

namespace App\Http\Controllers;

use App\Http\Requests\Section\BatchStoreSectionsRequest;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SectionsController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    // Get all sections
    public function index()
    {
        $sections = Cache::remember(ApiCache::key('sections.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['department', 'program', 'academicSemester', 'curriculum'])
            ->latest()
            ->get());

        return response()->json($sections);
    }

    /**
     * The curricula a section may follow: its own department's active ones,
     * either program-wide or scoped to the section's program.
     *
     * A department mid-transition runs several, so this cannot be answered by
     * "the active curriculum" — the caller has to choose.
     */
    private function selectableCurriculumIds(int $departmentId, ?int $programId): \Illuminate\Support\Collection
    {
        return Curriculum::query()
            ->selectableFor($departmentId, $programId)
            ->pluck('id')
            ->map('intval');
    }


    private function getActiveSystemSemester(): Semester
    {
        return Semester::where('is_active', true)->first()
            ?? Semester::where('is_enabled', true)->orderBy('id')->first()
            ?? Semester::firstOrFail();
    }

    // Create section
    public function store(StoreSectionRequest $request)
    {
        $validated = $request->validated();
        $activeSemester = $this->getActiveSystemSemester();

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $curriculumId = $validated['curriculum_id'] ?? null;
        if ($curriculumId !== null
            && ! $this->selectableCurriculumIds((int) $validated['department_id'], (int) $validated['program_id'])->contains((int) $curriculumId)) {
            return response()->json(['message' => 'The selected curriculum is not available to this department and program.'], 422);
        }

        // A null here is deliberate: the Sections model fills it in when the
        // department runs exactly one curriculum, and leaves it unset when there
        // is a real choice to make.
        $validated['curriculum_id'] = $curriculumId;
        $validated['semester_id'] = $activeSemester->id;
        $validated['semester'] = $activeSemester->semester;
        $validated['status'] = 'active';

        if (Sections::nameTaken((int) $validated['department_id'], (int) $activeSemester->id, $validated['section_name'])) {
            return $this->duplicateNameResponse(['section_name' => [$this->duplicateNameMessage($validated['section_name'])]]);
        }

        $section = Sections::create($validated);
        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
        ]);

        return response()->json($section->load(['department', 'program', 'academicSemester', 'curriculum']), 201);
    }

    // Create batch sections
    public function batchStore(BatchStoreSectionsRequest $request)
    {
        $validated = $request->validated();
        $activeSemester = $this->getActiveSystemSemester();

        $departmentIds = collect($validated['sections'])
            ->pluck('department_id')
            ->map(fn ($departmentId) => (int) $departmentId)
            ->unique();
        if ($departmentIds->contains(fn (int $departmentId) => ! $this->authorization->payloadBelongsToDepartment($request, $departmentId))) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        foreach ($validated['sections'] as $data) {
            if (! Program::whereKey($data['program_id'])->where('department_id', $data['department_id'])->exists()) {
                return response()->json(['message' => 'Each program must belong to its department.'], 422);
            }

            if (($data['curriculum_id'] ?? null) !== null
                && ! $this->selectableCurriculumIds((int) $data['department_id'], (int) $data['program_id'])->contains((int) $data['curriculum_id'])) {
                return response()->json(['message' => 'A selected curriculum is not available to its department and program.'], 422);
            }
        }

        // A name may appear once per department in the batch, and not at all if a
        // live section in the active semester already holds it.
        $errors = [];
        $seen = [];
        foreach ($validated['sections'] as $index => $data) {
            $key = $data['department_id'].'|'.Sections::normalizeName($data['section_name']);
            if (isset($seen[$key])) {
                $errors["sections.{$index}.section_name"] = ['This name appears more than once in the list.'];
            } elseif (Sections::nameTaken((int) $data['department_id'], (int) $activeSemester->id, $data['section_name'])) {
                $errors["sections.{$index}.section_name"] = [$this->duplicateNameMessage($data['section_name'])];
            }
            $seen[$key] = true;
        }
        if ($errors !== []) {
            return $this->duplicateNameResponse($errors);
        }

        $created = DB::transaction(function () use ($validated, $activeSemester) {
            $list = [];
            foreach ($validated['sections'] as $data) {
                $data['semester_id'] = $activeSemester->id;
                $data['semester'] = $activeSemester->semester;
                $data['status'] = 'active';
                $section = Sections::create($data);
                $list[] = $section->load(['department', 'program', 'academicSemester', 'curriculum']);
            }

            return $list;
        });

        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => count($created).' sections created successfully.',
            'sections' => $created,
        ], 201);
    }

    // Get single section
    public function show(Sections $section)
    {
        return response()->json($section->load(['department', 'program', 'academicSemester', 'curriculum']));
    }

    // Update section
    public function update(UpdateSectionRequest $request, Sections $section)
    {
        // Access to the section's current department is checked in UpdateSectionRequest::authorize().
        $validated = $request->validated();

        if (isset($validated['department_id']) && ! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only move sections within your department.'], 403);
        }

        if (isset($validated['program_id']) && ! Program::whereKey($validated['program_id'])->where('department_id', $validated['department_id'] ?? $section->department_id)->exists()) {
            return response()->json(['message' => 'The selected program must belong to the section department.'], 422);
        }

        if (array_key_exists('curriculum_id', $validated) && $validated['curriculum_id'] !== null) {
            $targetDepartment = (int) ($validated['department_id'] ?? $section->department_id);
            $targetProgram = $validated['program_id'] ?? $section->program_id;
            if (! $this->selectableCurriculumIds($targetDepartment, $targetProgram === null ? null : (int) $targetProgram)
                ->contains((int) $validated['curriculum_id'])) {
                return response()->json(['message' => 'The selected curriculum is not available to this department and program.'], 422);
            }
        }

        $targetName = $validated['section_name'] ?? $section->section_name;
        $targetDepartmentId = (int) ($validated['department_id'] ?? $section->department_id);
        $targetSemesterId = (int) ($validated['semester_id'] ?? $section->semester_id);
        if (Sections::nameTaken($targetDepartmentId, $targetSemesterId, (string) $targetName, (int) $section->id)) {
            return $this->duplicateNameResponse(['section_name' => [$this->duplicateNameMessage((string) $targetName)]]);
        }

        $section->update($validated);
        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
            'curriculum.index',
        ]);

        return response()->json($section->load(['department', 'program', 'academicSemester', 'curriculum']));
    }

    /**
     * Point a whole year level at one curriculum.
     *
     * This is the write behind the generator's curriculum step: the user picks
     * "Year 1 follows the new curriculum" once, rather than editing each section.
     * It is a separate endpoint from update() so the choice is persisted before
     * generation runs, which keeps every other consumer — course lists, teaching
     * assignments, printing — agreeing with what the generator used.
     */
    public function assignCurriculumToYearLevel(Request $request)
    {
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semesters,id',
            'department_id' => 'required|integer|exists:departments,id',
            'year_level' => SchedulingPolicy::allowedYearLevelsRule('required'),
            'curriculum_id' => 'required|integer|exists:curriculum,id',
            // Optional narrowing: a single section moving ahead of its year level.
            'section_ids' => 'sometimes|array|min:1',
            'section_ids.*' => 'integer|exists:sections,id',
        ]);

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage sections for your department.'], 403);
        }

        $curriculum = Curriculum::query()->find((int) $validated['curriculum_id']);
        if ($curriculum === null
            || (int) $curriculum->department_id !== (int) $validated['department_id']
            || (string) $curriculum->status !== 'active') {
            return response()->json(['message' => 'Choose an active curriculum belonging to this department.'], 422);
        }

        $sections = Sections::query()
            ->where('semester_id', (int) $validated['semester_id'])
            ->where('department_id', (int) $validated['department_id'])
            ->where('year_level', (string) $validated['year_level'])
            ->where('status', 'active')
            ->when(
                isset($validated['section_ids']),
                fn ($scope) => $scope->whereIn('id', array_map('intval', $validated['section_ids'])),
            )
            ->get();

        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No active sections were found for the selected year level.'], 422);
        }

        // A program-scoped curriculum cannot be handed to a section of another
        // program, so refuse the whole batch rather than half-applying it.
        if ($curriculum->program_id !== null) {
            $mismatched = $sections->filter(
                static fn (Sections $section): bool => (int) $section->program_id !== (int) $curriculum->program_id,
            );

            if ($mismatched->isNotEmpty()) {
                return response()->json([
                    'message' => sprintf(
                        'Curriculum "%s" belongs to a different program than %s.',
                        (string) $curriculum->name,
                        $mismatched->pluck('section_name')->implode(', '),
                    ),
                ], 422);
            }
        }

        DB::transaction(function () use ($sections, $curriculum): void {
            Sections::whereIn('id', $sections->pluck('id'))
                ->update(['curriculum_id' => (int) $curriculum->id]);
        });

        ApiCache::forgetGroups([
            'sections.index',
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
            'curriculum.index',
            'courses.index',
        ]);

        return response()->json([
            'message' => sprintf(
                '%d section%s now follow%s "%s".',
                $sections->count(),
                $sections->count() === 1 ? '' : 's',
                $sections->count() === 1 ? 's' : '',
                (string) $curriculum->name,
            ),
            'curriculum_id' => (int) $curriculum->id,
            'section_ids' => $sections->pluck('id')->map('intval')->values(),
        ]);
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
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
        ]);

        return response()->json(['message' => 'Section archived successfully']);
    }

    // Get sections by semester
    public function bySemester($semesterId)
    {
        $sections = Cache::remember(ApiCache::key('sections.by_semester', ['semester_id' => $semesterId]), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['department', 'program', 'curriculum'])
            ->where('semester_id', $semesterId)
            ->get());

        return response()->json($sections);
    }

    // Get sections by department
    public function byDepartment($departmentId)
    {
        $sections = Cache::remember(ApiCache::key('sections.by_department', ['department_id' => $departmentId]), ApiCache::LOOKUP_TTL_SECONDS, fn () => Sections::with(['program', 'academicSemester', 'curriculum'])
            ->where('department_id', $departmentId)
            ->get());

        return response()->json($sections);
    }

    private function duplicateNameMessage(string $name): string
    {
        return Sections::normalizeName($name).' already exists in this department for the active semester.';
    }

    /** @param  array<string, list<string>>  $errors */
    private function duplicateNameResponse(array $errors): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => collect($errors)->flatten()->first(),
            'errors' => $errors,
        ], 422);
    }
}
