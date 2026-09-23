<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Spatie roles and permissions are API-authenticated resources. */
    protected string $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_initial',
        'last_name',
        'suffix',
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

    /**
     * Keeps the Spatie role in step with the `role` column.
     *
     * Authorization reads Spatie's tables (CapabilityMiddleware ->
     * hasCapability -> hasPermissionTo), but the column is what the rest of the
     * application writes and reads. Only UserController synced the two, so a
     * user created by any other path carried the column with no Spatie role
     * and was denied every capability-guarded route. Syncing here makes the
     * column the single source of truth and removes the drift for good.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if (! $user->wasRecentlyCreated && ! $user->wasChanged('role')) {
                return;
            }

            $role = (string) $user->role;
            if ($role === '') {
                return;
            }

            // users.role is the one place a role is written. The linked faculty
            // profile's administrative_role and the Spatie assignment below are
            // copies, kept in step here so no save path can leave them behind.
            Faculty::query()
                ->where('user_id', $user->id)
                ->whereNotNull('administrative_role')
                ->where('administrative_role', '!=', $role)
                ->update(['administrative_role' => $role]);

            // A role row is missing only when the seeder has not run (or the
            // column holds a value that is not a real role). Syncing would
            // throw; leaving the roles untouched keeps the save itself intact.
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

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
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
