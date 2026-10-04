<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\AuthenticationAuditService;
use App\Services\FacultyDesignationService;
use App\Services\UserFacultyProfileService;
use App\Support\ApiCache;
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
            UserFacultyProfileService::MODE_CREATE => UserFacultyProfileService::DEFAULT_MAX_UNITS,
            default => null,
        };
        app(FacultyDesignationService::class)->validate($designationIds, maxUnits: $maxUnits === null ? null : (int) $maxUnits);

        $user = DB::transaction(function () use ($validated, $request, $facultyMode, $designationIds) {
            $user = User::create([
                'name' => $this->displayName($validated),
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
        });
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
        $validated = $request->validated();
        $this->ensureRoleDepartmentHierarchy($validated['role'], (int) $validated['department_id']);
        $this->ensureRoleSlotAvailable($validated, (bool) $validated['is_active'], $user->id);

        $newEmail = strtolower(trim($validated['email']));
        $emailChanged = $newEmail !== strtolower((string) $user->email);

        DB::transaction(function () use ($validated, $request, $user, $emailChanged) {
            if ($emailChanged) {
                /** @var \Illuminate\Auth\Passwords\PasswordBroker $broker */
                $broker = Password::broker('invites');
                $broker->deleteToken($user);
                $user->forceFill(['google_id' => null, 'google_email' => null, 'google_linked_at' => null]);
                $user->tokens()->where('name', 'wicars-google')->delete();
            }
            $user->update([
                'name' => $this->displayName($validated),
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
        });
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

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
        if (in_array($role, ['dean', 'secretary'], true) || Program::query()->where('department_id', $departmentId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => 'Only a Dean can be assigned to a department without a program.',
        ]);
    }

    private function ensureRoleSlotAvailable(array $validated, bool $isActive, ?int $ignoreUserId = null): void
    {
        if (! $isActive) {
            return;
        }

        $role = $validated['role'];
        $holder = User::activeRoleHolder(
            $role,
            (int) $validated['department_id'],
            isset($validated['program_id']) ? (int) $validated['program_id'] : null,
            $ignoreUserId,
        );

        if ($holder === null) {
            return;
        }

        $label = match ($role) {
            'dean' => 'Dean',
            'program_head' => 'Program Head',
            default => 'Secretary',
        };
        $scope = $role === 'program_head'
            ? ($holder->program?->code ?? $holder->program?->name ?? 'This program')
            : ($holder->department?->department_code ?? $holder->department?->department_name ?? 'This department');

        throw ValidationException::withMessages([
            'role' => "{$scope} already has an active {$label} ({$holder->name}). Deactivate them first.",
        ]);
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
