<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Program\StoreProgramRequest;
use App\Http\Requests\Program\UpdateProgramRequest;
use App\Models\Program;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Program membership decides who may teach a major, so the faculty and
        // course forms need this list too. A non-VPAA user only ever assigns
        // programs of their own department, so that is all they are shown.
        $user = $request->user();
        $departmentId = $user && ! $user->isVpaa()
            ? $user->department_id
            : $request->query('department_id');
        $programId = $user?->role === 'program_head' ? (int) ($user->program_id ?? 0) : null;

        $programs = Program::query()
            ->with('department:id,department_name,department_code')
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->when($programId !== null, fn ($query) => $query->whereKey($programId))
            ->orderBy('code')->orderBy('major')
            ->orderBy('code')
            ->get();

        return response()->json($programs);
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $program = Program::create([
            'department_id' => $validated['department_id'],
            'major' => trim((string) ($validated['major'] ?? '')),
            'code' => strtoupper(trim($validated['code'])),
            'name' => isset($validated['name']) && trim($validated['name']) !== '' ? trim($validated['name']) : null,
        ]);
        ApiCache::forgetGroups(['departments.index', 'courses.index', 'initial.data']);

        return response()->json([
            'message' => 'Program created successfully.',
            'data' => $program->load('department:id,department_name,department_code'),
        ], 201);
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $validated = $request->validated();

        $program->update([
            ...$validated,
            'major' => array_key_exists('major', $validated)
                ? trim((string) ($validated['major'] ?? ''))
                : $program->major,
            'code' => array_key_exists('code', $validated) ? strtoupper(trim($validated['code'])) : $program->code,
            'name' => array_key_exists('name', $validated)
                ? (isset($validated['name']) && trim($validated['name']) !== '' ? trim($validated['name']) : null)
                : $program->name,
        ]);
        ApiCache::forgetGroups(['departments.index', 'courses.index', 'initial.data']);

        return response()->json([
            'message' => 'Program updated successfully.',
            'data' => $program->fresh()->load('department:id,department_name,department_code'),
        ]);
    }

    public function destroy(Program $program): JsonResponse
    {
        if ($program->users()->exists() || $program->faculties()->exists() || $program->courses()->exists()) {
            return response()->json([
                'message' => 'This program cannot be archived while users, faculty, or courses are assigned to it.',
            ], 422);
        }

        $program->delete();
        ApiCache::forgetGroups(['departments.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => 'Program archived successfully.']);
    }
}
