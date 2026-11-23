<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ChangeUserPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\AdministrativeRoleSlots;
use App\Services\AuthenticationAuditService;
use App\Services\FacultyDesignationService;
use App\Services\UserFacultyProfileService;
use App\Support\AccountVersion;
use App\Support\ApiCache;
use App\Support\PersonName;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private readonly AuthenticationAuditService $audit,
        private readonly UserFacultyProfileService $facultyProfiles,
        private readonly AdministrativeRoleSlots $roleSlots,
    ) {}

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->ensureRoleDepartmentHierarchy($validated['role'], (int) $validated['department_id']);
        $this->ensureRoleSlotAvailable($validated, (bool) ($validated['is_active'] ?? true));
        $facultyMode = $validated['faculty_mode'] ?? UserFacultyProfileService::MODE_CREATE;
        $designationIds = app(FacultyDesignationService::class)->idsFrom($request);
        $maxUnits = match ($facultyMode) {
            UserFacultyProfileService::MODE_LINK => Faculty::query()->whereKey((int) ($validated['faculty_id'] ?? 0))->value('max_units'),
            UserFacultyProfileService::MODE_CREATE => UserFacultyProfileService::defaultMaxUnits(),
            default => null,
        };
        app(FacultyDesignationService::class)->validate($designationIds, maxUnits: $maxUnits === null ? null : (int) $maxUnits);

        $user = $this->withRoleSlotBackstop($validated, fn () => DB::transaction(function () use ($validated, $request, $facultyMode, $designationIds) {
            $this->ensureRoleSlotAvailable($validated, (bool) ($validated['is_active'] ?? true), locked: true);
            $user = User::create([
                'name' => $this->displayName($validated),
                'honorific_prefix' => $validated['honorific_prefix'] ?? null,
                'credentials' => $validated['credentials'] ?? null,
                'first_name' => trim($validated['first_name']),
                'middle_initial' => isset($validated['middle_initial']) ? strtoupper(trim($validated['middle_initial'])) : null,
                'last_name' => trim($validated['last_name']),
                'suffix' => $validated['suffix'] ?? null,
                'username' => $this->availableUsername($validated['username']),
                'email' => strtolower(trim($validated['email'])),
                'password' => Str::random(64),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
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
            $this->audit->record($request, 'user_created', $user, [
                'role' => $user->role,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_mode' => $facultyMode,
                'faculty_profile_id' => $faculty?->id,
            ]);

            return $user;
        }));
        $this->sendInvitation($request, $user);
        $initialDataGroups = $user->role === 'dean' && $user->is_active
            ? ['initial.data']
            : ['initial.data.users', 'initial.data.faculties', 'initial.data.departments'];
        ApiCache::forgetGroups(['departments.index', 'faculty.index', ...$initialDataGroups]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->withAccessState($user->load(['department', 'program', 'facultyProfile'])),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // Omitted title/credentials keep their stored value; explicit null clears them.
        $validated = $request->validated() + $user->only(PersonName::AFFIX_FIELDS);
        $this->ensureRoleDepartmentHierarchy($validated['role'], (int) $validated['department_id']);
        $this->ensureRoleSlotAvailable($validated, (bool) $validated['is_active'], $user->id);

        $newEmail = strtolower(trim($validated['email']));
        $emailChanged = $newEmail !== strtolower((string) $user->email);

        $this->withRoleSlotBackstop($validated, fn () => DB::transaction(function () use ($validated, $request, &$user, $emailChanged) {
            $this->ensureRoleSlotAvailable($validated, (bool) $validated['is_active'], $user->id, locked: true);
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            AccountVersion::check($user, $validated['expected_version']);
            if ($emailChanged) {
                /** @var PasswordBroker $broker */
                $broker = Password::broker('invites');
                $broker->deleteToken($user);
                $user->forceFill(['google_id' => null, 'google_email' => null, 'google_linked_at' => null]);
                $user->tokens()->where('name', 'wicars-google')->delete();
            }
            $user->update([
                'name' => $this->displayName($validated),
                'honorific_prefix' => $validated['honorific_prefix'],
                'credentials' => $validated['credentials'],
                'first_name' => trim($validated['first_name']),
                'middle_initial' => isset($validated['middle_initial']) ? strtoupper(trim($validated['middle_initial'])) : null,
                'last_name' => trim($validated['last_name']),
                'suffix' => $validated['suffix'] ?? null,
                'email' => strtolower(trim($validated['email'])),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
                'allow_google_login' => true,
                'department_id' => $validated['department_id'],
                'profile_picture' => array_key_exists('profile_picture', $validated) ? $validated['profile_picture'] : $user->profile_picture,
                'program_id' => $validated['role'] === 'program_head' ? $validated['program_id'] : null,
            ]);
            $user->syncRoles([$user->role]);
            $syncedProfile = $this->facultyProfiles->sync($user);

            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            $this->audit->record($request, 'user_updated', $user, [
                'active' => $user->is_active,
                'google_login_allowed' => $user->allow_google_login,
                'faculty_profile_synced' => $syncedProfile !== null,
            ]);
        }));
        $user->refresh();
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data', 'initial.data.users']);

        $message = 'User updated successfully.';
        if ($emailChanged && $user->is_active
            && $this->sendInvitation($request, $user) === Password::RESET_LINK_SENT) {
            $message = "User updated. A setup link was sent to {$user->email}.";
        }

        return response()->json([
            'message' => $message,
            'data' => $this->withAccessState($user->fresh()->load(['department', 'program', 'facultyProfile'])),
        ]);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        abort_if($user->isVpaa(), 403, 'The VPAA account cannot be changed here.');
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $scope = $user->only(['role', 'department_id', 'program_id']);
        $this->withRoleSlotBackstop($scope, fn () => DB::transaction(function () use ($request, &$user, $validated, $scope): void {
            $this->roleSlots->lockScope($scope['role'], $scope['department_id'], $scope['program_id']);
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->only(array_keys($scope)) === $scope, 409, 'The account assignment changed. Reload it before changing its status.');
            $this->ensureRoleSlotAvailable($scope, (bool) $validated['is_active'], $user->id, locked: true);
            $user->update(['is_active' => $validated['is_active']]);
            $this->facultyProfiles->sync($user);
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            $this->audit->record($request, 'user_status_changed', $user, ['active' => $user->is_active]);
        }));
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data', 'initial.data.users']);

        return response()->json(['message' => 'Account status updated.', 'data' => $this->withAccessState($user->fresh()->load(['department', 'program', 'facultyProfile']))]);
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
            $user->facultyProfile?->update(['administrative_role' => null]);
            $user->tokens()->delete();
            $user->delete();
        });
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data', 'initial.data.users']);

        return response()->json(['message' => 'User archived successfully.']);
    }

    /**
     * VPAA sets a new password directly. Kept apart from the Forgot/Reset Password flow: pending
     * reset or setup links are left untouched. Existing sessions are revoked so only the new
     * credentials work from this point on.
     */
    public function changePassword(ChangeUserPasswordRequest $request, User $user): JsonResponse
    {
        $password = $request->validated('password');

        DB::transaction(function () use ($request, $user, $password): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->forceFill([
                'password' => $password, // hashed by the model's `hashed` cast
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            $this->audit->record($request, 'user_password_changed', $user, [
                'sessions_revoked' => true,
            ]);
        });

        return response()->json([
            'message' => "Password updated for {$user->name}. They have been signed out of all devices.",
        ]);
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

    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'vpaa') {
            return response()->json(['message' => 'The VPAA account cannot be changed here.'], 403);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Activate this account before sending a setup link.'], 422);
        }

        if ($this->sendInvitation($request, $user) === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'A setup link was just sent. Please wait a minute before sending another.'], 429);
        }

        return response()->json(['message' => "A setup link has been sent to {$user->email}."]);
    }

    public function instructorDefaults(): JsonResponse
    {
        return response()->json(['default_max_units' => UserFacultyProfileService::defaultMaxUnits()]);
    }

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

    private function ensureRoleDepartmentHierarchy(string $role, int $departmentId): void
    {
        if (in_array($role, ['dean', ...User::DEPARTMENT_OFFICE_ROLES], true) || Program::query()->where('department_id', $departmentId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => 'Only a Dean can be assigned to a department without a program.',
        ]);
    }

    /**
     * The unlocked call gives early feedback; the locked call inside the write transaction is
     * authoritative because it holds the department/program scope lock shared with restores.
     */
    private function ensureRoleSlotAvailable(array $validated, bool $isActive, ?int $ignoreUserId = null, bool $locked = false): void
    {
        if (! $isActive) {
            return;
        }

        $role = $validated['role'];
        $departmentId = (int) $validated['department_id'];
        $programId = isset($validated['program_id']) ? (int) $validated['program_id'] : null;
        $holder = $locked
            ? $this->roleSlots->lockedHolder($role, $departmentId, $programId, $ignoreUserId)
            : User::activeRoleHolder($role, $departmentId, $programId, $ignoreUserId);

        if ($holder === null) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => AdministrativeRoleSlots::occupiedMessage($role, $holder),
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withRoleSlotBackstop(array $validated, callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $exception) {
            if (! AdministrativeRoleSlots::isSlotViolation($exception)) {
                throw $exception;
            }
            $label = AdministrativeRoleSlots::slotLabel((string) $validated['role']);

            throw ValidationException::withMessages([
                'role' => "Another active {$label} was saved for this scope at the same time. Refresh and review the current holder.",
            ]);
        }
    }

    private function availableUsername(string $requested): string
    {
        $base = strtolower(trim($requested));
        $taken = User::withTrashed()
            ->whereRaw('LOWER(username) LIKE ?', [$base.'%'])
            ->pluck('username')
            ->map(fn (string $name) => strtolower($name))
            ->flip();

        if (! $taken->has($base)) {
            return $base;
        }

        $suffix = 2;
        while ($taken->has($base.$suffix)) {
            $suffix++;
        }

        return $base.$suffix;
    }

    private function sendInvitation(Request $request, User $user): string
    {
        $status = Password::broker('invites')->sendResetLink(
            ['email' => $user->email],
            fn (User $invitee, string $token) => $invitee->notify(new AccountInvitationNotification($token)),
        );

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit->record($request, 'invitation_sent', $user);
        }

        return $status;
    }

    private function withAccessState(User $user): User
    {
        return $user
            ->setAttribute('account_version', AccountVersion::of($user))
            ->setAttribute('permissions', $user->capabilityNames())
            ->setAttribute('scheduling_ready', $this->schedulingReady($user));
    }

    private function schedulingReady(User $user): bool
    {
        return $user->department_id === null
            || Program::query()->where('department_id', $user->department_id)->exists();
    }

    private function displayName(array $validated): string
    {
        return User::composeDisplayName($validated);
    }
}
