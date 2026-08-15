<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'username'      => 'required|string|max:255|unique:users',
            'password'      => 'required|string|min:6',
            'role'          => 'required|string|in:dean,program_head,secretary',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id'    => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'username'      => $validated['username'],
            'password'      => Hash::make($validated['password']),
            'role'          => $validated['role'],
            'department_id' => $validated['department_id'],
            'profile_picture' => $validated['profile_picture'] ?? null,
            'program_id'    => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => $user->load(['department', 'program']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'role'          => 'required|string|in:dean,program_head,secretary',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id'    => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
        ]);

        $user->update([
            'name'          => $validated['name'],
            'role'          => $validated['role'],
            'department_id' => $validated['department_id'],
            'profile_picture' => array_key_exists('profile_picture', $validated) ? $validated['profile_picture'] : $user->profile_picture,
            'program_id'    => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
        ]);

        return response()->json([
            'message' => 'User updated successfully.',
            'data'    => $user->fresh()->load(['department', 'program']),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully.']);
    }

    public function index(): JsonResponse
    {
        $users = User::with(['department', 'program'])
            ->where('role', '!=', 'vpaa')
            ->get();

        return response()->json($users);
    }
}
