<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthenticationAuditService;
use App\Services\UserFacultyProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(
        private readonly AuthenticationAuditService $audit,
        private readonly UserFacultyProfileService $facultyProfiles,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', Password::min(10)->letters()->mixedCase()->numbers()],
            'role' => 'required|string|in:dean,program_head,secretary',
            'is_active' => 'sometimes|boolean',
            'allow_google_login' => 'sometimes|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
        ]);

        $user = DB::transaction(function () use ($validated, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => strtolower(trim($validated['username'])),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
                'allow_google_login' => $validated['allow_google_login'] ?? false,
                'department_id' => $validated['department_id'],
                'profile_picture' => $validated['profile_picture'] ?? null,
                'program_id' => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
            ]);
            $this->facultyProfiles->createFor($user);
            $this->audit->record($request, 'user_created', $user, [
                'role' => $user->role,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_created' => true,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user->load(['department', 'program', 'facultyProfile']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account cannot be changed here.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => 'required|string|in:dean,program_head,secretary',
            'is_active' => 'required|boolean',
            'allow_google_login' => 'required|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
        ]);

        DB::transaction(function () use ($validated, $request, $user) {
            $user->update([
                'name' => $validated['name'],
                'email' => strtolower(trim($validated['email'])),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
                'allow_google_login' => $validated['allow_google_login'],
                'department_id' => $validated['department_id'],
                'profile_picture' => array_key_exists('profile_picture', $validated) ? $validated['profile_picture'] : $user->profile_picture,
                'program_id' => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
            ]);
            $this->facultyProfiles->sync($user);

            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            $this->audit->record($request, 'user_updated', $user, [
                'active' => $user->is_active,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_synced' => true,
            ]);
        });

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh()->load(['department', 'program', 'facultyProfile']),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account cannot be archived here.'], 403);
        }

        $request->validate([
            'remove_faculty_profile' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($request, $user) {
            $this->audit->record($request, 'user_archived', $user, [
                'faculty_profile_preserved' => $user->facultyProfile()->exists(),
            ]);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'User archived successfully.']);
    }

    public function unlinkGoogle(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account cannot be changed here.'], 403);
        }

        $user->forceFill([
            'google_id' => null,
            'google_email' => null,
            'google_linked_at' => null,
        ])->save();

        $user->tokens()->where('name', 'wicars-google')->delete();
        $this->audit->record($request, 'google_unlinked', $user);

        return response()->json([
            'message' => 'Google account unlinked successfully.',
            'data' => $user->fresh()->load(['department', 'program']),
        ]);
    }

    public function index(): JsonResponse
    {
        $users = User::with(['department', 'program'])
            ->where('role', '!=', 'vpaa')
            ->with('facultyProfile')
            ->get();

        return response()->json($users);
    }
}
