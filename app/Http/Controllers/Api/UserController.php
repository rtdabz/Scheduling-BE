<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\User;
use App\Notifications\WicarsAccountCreatedNotification;
use App\Services\AuthenticationAuditService;
use App\Services\FacultyDesignationService;
use App\Services\UserFacultyProfileService;
use App\Support\ApiCache;
use App\Support\CapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private readonly AuthenticationAuditService $audit,
        private readonly UserFacultyProfileService $facultyProfiles,
        private readonly CapabilityRegistry $capabilities,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_initial' => ['nullable', 'string', 'size:1', 'alpha'],
            'last_name' => 'required|string|max:100',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', Password::min(10)->letters()->mixedCase()->numbers()],
            'role' => 'required|string|in:dean,program_head,secretary,director',
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in($this->capabilities->names())],
            'is_active' => 'sometimes|boolean',
            'allow_google_login' => 'sometimes|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
            // Defaults to `create` so existing API clients keep their behaviour.
            'faculty_mode' => ['sometimes', 'string', Rule::in(UserFacultyProfileService::MODES)],
            'faculty_id' => [
                Rule::requiredIf(fn () => $request->input('faculty_mode') === UserFacultyProfileService::MODE_LINK),
                'nullable',
                'integer',
            ],
            'designation_id' => ['nullable', 'integer'],
            'designation_ids' => ['nullable', 'array'],
        ]);
        $this->ensureRoleDepartmentHierarchy($validated['role'], (int) $validated['department_id']);
        $this->validatePermissionAssignments($validated['permissions'] ?? [], $validated['role']);
        $facultyMode = $validated['faculty_mode'] ?? UserFacultyProfileService::MODE_CREATE;
        $designationIds = app(FacultyDesignationService::class)->idsFrom($request);
        app(FacultyDesignationService::class)->validate($designationIds);

        $user = DB::transaction(function () use ($validated, $request, $facultyMode, $designationIds) {
            $user = User::create([
                'name' => $this->displayName($validated),
                'first_name' => trim($validated['first_name']),
                'middle_initial' => isset($validated['middle_initial']) ? strtoupper(trim($validated['middle_initial'])) : null,
                'last_name' => trim($validated['last_name']),
                'username' => strtolower(trim($validated['username'])),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
                // Google login is enabled automatically for every account.
                'allow_google_login' => true,
                'department_id' => $validated['department_id'],
                'profile_picture' => $validated['profile_picture'] ?? null,
                'program_id' => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
            ]);
            $faculty = match ($facultyMode) {
                UserFacultyProfileService::MODE_LINK => $this->facultyProfiles->linkTo($user, (int) $validated['faculty_id'], $designationIds),
                UserFacultyProfileService::MODE_CREATE => $this->facultyProfiles->createFor($user, $designationIds),
                default => null,
            };
            $user->syncRoles([$user->role]);
            $user->syncPermissions($this->capabilities->expandForRole($validated['permissions'] ?? [], $user->role));
            $this->audit->record($request, 'user_created', $user, [
                'role' => $user->role,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_mode' => $facultyMode,
                'faculty_profile_id' => $faculty?->id,
            ]);

            return $user;
        });
        $user->notify(new WicarsAccountCreatedNotification);
        // A brand-new account cannot appear on an existing timetable, so the
        // `schedules`/`courses`/`sections` portions of the initial-data payload
        // are untouched and only the sections below need rebuilding.
        //
        // `has_dean` is the exception: it sits outside OPTIONAL_SECTIONS and so
        // ships in *every* initial-data response, including the ones that ask
        // for no user data at all. An active dean therefore still has to
        // invalidate the whole group.
        $initialDataGroups = $user->role === 'dean' && $user->is_active
            ? ['initial.data']
            : ['initial.data.users', 'initial.data.faculties', 'initial.data.departments'];
        ApiCache::forgetGroups(['departments.index', 'faculty.index', ...$initialDataGroups]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->withAccessState($user->load(['department', 'program', 'facultyProfile'])),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account cannot be changed here.'], 403);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_initial' => ['nullable', 'string', 'size:1', 'alpha'],
            'last_name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => 'required|string|in:dean,program_head,secretary,director',
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in($this->capabilities->names())],
            'is_active' => 'required|boolean',
            'allow_google_login' => 'sometimes|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id'))),
            ],
        ]);
        $this->ensureRoleDepartmentHierarchy($validated['role'], (int) $validated['department_id']);
        $permissions = array_key_exists('permissions', $validated)
            ? $validated['permissions']
            : $user->getDirectPermissions()->pluck('name')->all();
        $this->validatePermissionAssignments($permissions, $validated['role']);

        DB::transaction(function () use ($validated, $request, $user, $permissions) {
            $user->update([
                'name' => $this->displayName($validated),
                'first_name' => trim($validated['first_name']),
                'middle_initial' => isset($validated['middle_initial']) ? strtoupper(trim($validated['middle_initial'])) : null,
                'last_name' => trim($validated['last_name']),
                'email' => strtolower(trim($validated['email'])),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
                // Google login is enabled automatically for every account.
                'allow_google_login' => true,
                'department_id' => $validated['department_id'],
                'profile_picture' => array_key_exists('profile_picture', $validated) ? $validated['profile_picture'] : $user->profile_picture,
                'program_id' => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
            ]);
            $user->syncRoles([$user->role]);
            $syncedProfile = $this->facultyProfiles->sync($user);
            // Re-expanded even when no permissions were sent: a prerequisite the
            // previous role supplied has to be stored directly once the role
            // no longer does, or its dependents stop working.
            $user->syncPermissions($this->capabilities->expandForRole($permissions, $user->role));

            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            $this->audit->record($request, 'user_updated', $user, [
                'active' => $user->is_active,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_synced' => $syncedProfile !== null,
            ]);
        });
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->withAccessState($user->fresh()->load(['department', 'program', 'facultyProfile'])),
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
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

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

    /**
     * Unlinked instructors in a department, so the Create User form can attach
     * an account to an existing roster entry instead of duplicating it.
     */
    public function linkableFaculty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
        ]);

        return response()->json($this->facultyProfiles->linkableIn((int) $validated['department_id']));
    }

    public function index(): JsonResponse
    {
        $users = User::with(['department', 'program', 'facultyProfile'])
            ->where('role', '!=', 'vpaa')
            ->get()
            ->each(function (User $user) {
                $this->withAccessState($user);
            });

        return response()->json($users);
    }

    public function permissions(): JsonResponse
    {
        return response()->json($this->capabilities->names());
    }

    public function userPermissions(User $user): JsonResponse
    {
        $inherited = $user->getPermissionsViaRoles()->pluck('name')->sort()->values()->all();
        $direct = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $effective = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        $catalog = $this->capabilities->names();

        return response()->json([
            'user_id' => $user->id,
            'inherited' => $inherited,
            'direct' => $direct,
            'effective' => $effective,
            'catalog' => $catalog,
            'catalog_metadata' => $this->capabilities->catalogFor($user),
            'modules' => $this->capabilities->modulesFor($user),
            'presets' => $this->capabilities->presets(),
            // Program-bound capabilities are refused by CapabilityMiddleware
            // until the department owns a program; the matrix warns about it.
            'scheduling_ready' => $this->schedulingReady($user),
        ]);
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account permissions cannot be changed.'], 403);
        }

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->capabilities->names())],
        ]);

        $requested = array_values(array_unique($validated['permissions']));
        $assignmentErrors = [];
        // Validated against what was asked for, so the error index still points
        // at the offending entry in the request.
        foreach ($requested as $index => $permission) {
            if (! $this->capabilities->isAssignableTo($user, $permission)) {
                $assignmentErrors["permissions.$index"] = [
                    "The {$permission} capability is not assignable to the {$user->role} role.",
                ];
            }
        }
        if ($assignmentErrors !== []) {
            throw ValidationException::withMessages($assignmentErrors);
        }
        // Expanded so a capability is never saved without the reads it depends
        // on: an account granted instructor assignment but not `schedule.view`
        // reached a page it was allowed to open and 403'd fetching its data.
        $newDirect = $this->capabilities->expandForRole($requested, (string) $user->role);
        $previousDirect = $user->getDirectPermissions()->pluck('name')->values()->all();

        $added = array_values(array_diff($newDirect, $previousDirect));
        $removed = array_values(array_diff($previousDirect, $newDirect));

        DB::transaction(function () use ($request, $user, $newDirect, $added, $removed) {
            $user->syncPermissions($newDirect);

            $this->audit->record($request, 'user_updated', $user, [
                'action' => 'permissions_updated',
                'permission_changes' => [
                    'added' => $added,
                    'removed' => $removed,
                ],
                'direct_permissions' => $newDirect,
            ]);
        });

        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        $freshUser = $user->fresh();
        $inherited = $freshUser->getPermissionsViaRoles()->pluck('name')->sort()->values()->all();
        $direct = $freshUser->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $effective = $freshUser->getAllPermissions()->pluck('name')->sort()->values()->all();

        return response()->json([
            'message' => 'User permissions updated successfully.',
            'data' => [
                'user_id' => $freshUser->id,
                'inherited' => $inherited,
                'direct' => $direct,
                'effective' => $effective,
            ],
        ]);
    }

    private function ensureRoleDepartmentHierarchy(string $role, int $departmentId): void
    {
        if (in_array($role, ['dean', 'secretary', 'director'], true) || Program::query()->where('department_id', $departmentId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => 'Only a Dean can be assigned to a department without a program.',
        ]);
    }

    /** @param list<string> $permissions */
    private function validatePermissionAssignments(array $permissions, string $role): void
    {
        $errors = [];
        foreach (array_values(array_unique($permissions)) as $index => $permission) {
            if (! $this->capabilities->isAssignableToRole($role, $permission)) {
                $errors["permissions.$index"] = [
                    "The {$permission} capability is not assignable to the {$role} role.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function withAccessState(User $user): User
    {
        $direct = $user->getDirectPermissions()->pluck('name')->values()->all();
        $inherited = $user->getPermissionsViaRoles()->pluck('name')->values()->all();
        $effective = $user->capabilityNames();

        return $user
            ->setAttribute('direct_permissions', $direct)
            ->setAttribute('inherited_permissions', $inherited)
            ->setAttribute('permissions', $effective)
            ->setAttribute('scheduling_ready', $this->schedulingReady($user));
    }

    private function schedulingReady(User $user): bool
    {
        return $user->department_id === null
            || Program::query()->where('department_id', $user->department_id)->exists();
    }

    private function displayName(array $validated): string
    {
        return trim(implode(' ', array_filter([
            trim($validated['first_name']),
            isset($validated['middle_initial']) && trim($validated['middle_initial']) !== ''
                ? strtoupper(trim($validated['middle_initial'])).'.'
                : null,
            trim($validated['last_name']),
        ])));
    }
}
