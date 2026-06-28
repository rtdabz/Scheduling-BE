<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'username'      => $validated['username'],
            'password'      => Hash::make($validated['password']),
            'role'          => $validated['role'],
            'department_id' => $validated['department_id'],
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => $user->load('department'),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'role'          => 'required|string|in:dean,program_head,secretary',
            'department_id' => 'required|exists:departments,id',
        ]);

        $user->update([
            'name'          => $validated['name'],
            'role'          => $validated['role'],
            'department_id' => $validated['department_id'],
        ]);

        return response()->json([
            'message' => 'User updated successfully.',
            'data'    => $user->fresh()->load('department'),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully.']);
    }

    public function index(): JsonResponse
    {
        $users = User::with(['department'])
            ->where('role', '!=', 'vpaa')
            ->get();

        return response()->json($users);
    }
}