<?php

namespace App\Services;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;

/**
 * Serialises writes that can put an account into a dean, secretary, or program-head slot.
 *
 * Every writer (account creation, update/activation, archive restore) locks the row that owns
 * the slot — the department for deans and secretaries, the program for program heads — and then
 * re-reads the holder under that lock. The users table unique key is the final backstop.
 */
final class AdministrativeRoleSlots
{
    public const ROLES = ['dean', 'secretary', 'program_head'];

    /** Call inside a database transaction; the scope lock is held until it commits. */
    public function lockedHolder(string $role, ?int $departmentId, ?int $programId, ?int $ignoreUserId = null): ?User
    {
        if (! in_array($role, self::ROLES, true)) {
            return null;
        }
        $this->lockScope($role, $departmentId, $programId);

        return User::activeRoleHolder($role, $departmentId, $programId, $ignoreUserId, lock: true);
    }

    /**
     * Take the scope lock before locking any account row. Writers that lock their own account
     * first and the scope second deadlock against each other on MySQL/MariaDB.
     */
    public function lockScope(string $role, ?int $departmentId, ?int $programId): void
    {
        if ($role === 'program_head') {
            if ($programId !== null) {
                Program::withTrashed()->whereKey($programId)->lockForUpdate()->first(['id']);
            }
        } elseif (in_array($role, self::ROLES, true) && $departmentId !== null) {
            Departments::withTrashed()->whereKey($departmentId)->lockForUpdate()->first(['id']);
        }
    }

    public static function isSlotViolation(QueryException $exception): bool
    {
        return DatabaseErrors::isUniqueViolation($exception) && DatabaseErrors::mentions(
            $exception,
            'users_active_role_slot_unique',
            'users.role, users.department_id',
            'users.program_id',
        );
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'dean' => 'Dean',
            'program_head' => 'Program Head',
            'secretary' => 'Secretary',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }
}
