<?php

namespace App\Services;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;

/**
 * Serialises writes that can put an account into a dean, secretary/director, or program-head slot.
 *
 * Secretary and Director share one department slot: a department has either one active Secretary
 * or one active Director, never both (User::slotRolesFor).
 *
 * Every writer (account creation, update/activation, archive restore) locks the row that owns
 * the slot — the department for deans and secretaries, the program for program heads — and then
 * re-reads the holder under that lock. The users table unique key is the final backstop.
 */
final class AdministrativeRoleSlots
{
    public const ROLES = User::SLOT_ROLES;

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
            // SQLite partial index shared by Secretary and Director (department_id only).
            'users_active_department_office_unique',
            'failed: users.department_id',
        );
    }

    /** "Secretary" or "Secretary/Director" — the name of the slot an account competes for. */
    public static function slotLabel(string $role): string
    {
        return implode('/', array_map(self::roleLabel(...), User::slotRolesFor($role) ?: [$role]));
    }

    /**
     * The validation message for an occupied slot, naming the holder's actual role and scope.
     */
    public static function occupiedMessage(string $requestedRole, User $holder): string
    {
        $holderLabel = self::roleLabel((string) $holder->role);
        $scope = $holder->role === 'program_head'
            ? ($holder->program?->code ?? $holder->program?->name ?? 'This program')
            : ($holder->department?->department_code ?? $holder->department?->department_name ?? 'This department');
        $shared = in_array($requestedRole, User::DEPARTMENT_OFFICE_ROLES, true)
            ? ' A department has one Secretary or one Director, not both.'
            : '';

        return "{$scope} already has an active {$holderLabel} ({$holder->name}).{$shared} Deactivate them first.";
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'dean' => 'Dean',
            'program_head' => 'Program Head',
            'secretary' => 'Secretary',
            'director' => 'Director',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }
}
