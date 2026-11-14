<?php

namespace App\Models;

use App\Models\Concerns\HasNameAffixes;
use App\Notifications\ResetPasswordNotification;
use App\Support\PersonName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasNameAffixes, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'api';

    /**
     * Roles that run a department's office. A department has EITHER one active Secretary OR one
     * active Director; the two share one slot (see AdministrativeRoleSlots) and one workflow.
     *
     * @var list<string>
     */
    public const DEPARTMENT_OFFICE_ROLES = ['secretary', 'director'];

    public const ROLE_REVIEW_MESSAGE = 'This Director account is awaiting VPAA review. Contact the VPAA office to reactivate it.';

    /** @var list<string> */
    public const SLOT_ROLES = ['dean', 'secretary', 'director', 'program_head'];

    /**
     * Expands a recipient/role list so a Director is included wherever a Secretary is.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function withDepartmentOffice(array $roles): array
    {
        return in_array('secretary', $roles, true)
            ? array_values(array_unique([...$roles, ...self::DEPARTMENT_OFFICE_ROLES]))
            : $roles;
    }

    /**
     * The roles that compete for the same slot as $role: Secretary and Director share the
     * department office slot, every other slot role competes only with itself.
     *
     * @return list<string>
     */
    public static function slotRolesFor(string $role): array
    {
        if (in_array($role, self::DEPARTMENT_OFFICE_ROLES, true)) {
            return self::DEPARTMENT_OFFICE_ROLES;
        }

        return in_array($role, self::SLOT_ROLES, true) ? [$role] : [];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_initial',
        'last_name',
        'suffix',
        'honorific_prefix',
        'credentials',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'allow_google_login',
        'google_id',
        'google_email',
        'google_linked_at',
        'last_login_at',
        'department_id',
        'profile_picture',
        'program_id',
    ];

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            // Activation also reconciles the Spatie role: a quarantined legacy Director keeps its
            // users.role value but lost its role assignment, so reactivating it must restore that
            // assignment even though the role column did not change.
            $activated = $user->wasChanged('is_active') && $user->is_active;
            if (! $user->wasRecentlyCreated && ! $user->wasChanged('role') && ! $activated) {
                return;
            }

            $role = (string) $user->role;
            if ($role === '') {
                return;
            }

            Faculty::query()
                ->where('user_id', $user->id)
                ->whereNotNull('administrative_role')
                ->where('administrative_role', '!=', $role)
                ->update(['administrative_role' => $role]);

            if (! Role::query()->where('name', $role)->where('guard_name', 'api')->exists()) {
                return;
            }

            $user->syncRoles([$role]);
        });
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function facultyProfile()
    {
        return $this->hasOne(Faculty::class);
    }

    public static function activeRoleHolder(string $role, ?int $departmentId, ?int $programId, ?int $ignoreUserId = null, bool $lock = false): ?self
    {
        $slotRoles = self::slotRolesFor($role);
        if ($slotRoles === []) {
            return null;
        }

        return self::query()
            ->with(['department', 'program'])
            ->whereIn('role', $slotRoles)
            ->where('is_active', true)
            ->when($ignoreUserId !== null, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->when(
                $role === 'program_head',
                fn ($query) => $query->where('program_id', $programId),
                fn ($query) => $query->where('department_id', $departmentId),
            )
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * The stored display name, e.g. "Dr. Juan P. Dela Cruz Jr., DM".
     *
     * @param  array{first_name: string, middle_initial?: ?string, last_name: string, suffix?: ?string, honorific_prefix?: ?string, credentials?: ?string}  $parts
     */
    public static function composeDisplayName(array $parts): string
    {
        return PersonName::format(
            $parts['honorific_prefix'] ?? null,
            $parts['first_name'],
            $parts['middle_initial'] ?? null,
            $parts['last_name'],
            $parts['suffix'] ?? null,
            $parts['credentials'] ?? null,
        );
    }

    /** Recomposes the display name from the account's own structured fields. */
    public function composedName(): string
    {
        return self::composeDisplayName([
            'first_name' => (string) $this->first_name,
            'middle_initial' => $this->middle_initial,
            'last_name' => (string) $this->last_name,
            'suffix' => $this->suffix,
            'honorific_prefix' => $this->honorific_prefix,
            'credentials' => $this->credentials,
        ]);
    }

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'active_role_slot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'allow_google_login' => 'boolean',
            'google_linked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isVpaa(): bool
    {
        return $this->role === 'vpaa';
    }

    public function isDean(): bool
    {
        return $this->role === 'dean';
    }

    public function isSecretary(): bool
    {
        return $this->role === 'secretary';
    }

    public function isDirector(): bool
    {
        return $this->role === 'director';
    }

    /**
     * A Director account without its Spatie role assignment is a legacy (pre-reintroduction)
     * account that has not been reviewed and reactivated by the VPAA. It must not authenticate,
     * even if the application is deployed before the quarantine migration has run.
     */
    public function awaitingRoleReview(): bool
    {
        return $this->isDirector() && ! $this->roles()->where('name', 'director')->where('guard_name', 'api')->exists();
    }

    /** Secretary or Director: the department office, which shares one workflow and one slot. */
    public function isSecretaryOrDirector(): bool
    {
        return in_array($this->role, self::DEPARTMENT_OFFICE_ROLES, true);
    }

    public function hasCapability(string $capability): bool
    {
        return $this->hasPermissionTo($capability, 'api');
    }

    public function capabilityNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
