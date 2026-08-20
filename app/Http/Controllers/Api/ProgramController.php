<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $programs = Program::query()
            ->with('department:id,department_name,department_code')
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('cluster')
            ->orderBy('code')
            ->get();

        return response()->json($programs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'cluster' => ['nullable', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('programs', 'code')->where(
                    fn ($query) => $query->where('department_id', $request->input('department_id'))
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $program = Program::create([
            'department_id' => $validated['department_id'],
            'cluster' => $validated['cluster'] ?? null,
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Program created successfully.',
            'data' => $program->load('department:id,department_name,department_code'),
        ], 201);
    }
}
