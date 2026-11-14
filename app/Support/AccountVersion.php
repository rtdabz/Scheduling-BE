<?php

namespace App\Support;

use App\Models\User;

final class AccountVersion
{
    public static function of(User $user): string
    {
        return hash('sha256', json_encode($user->only([
            'name', 'first_name', 'middle_initial', 'last_name', 'suffix', 'honorific_prefix', 'credentials',
            'email', 'role', 'department_id', 'program_id', 'is_active', 'profile_picture', 'deleted_at',
        ]), JSON_THROW_ON_ERROR));
    }

    public static function check(User $user, string $expected): void
    {
        abort_unless(hash_equals(self::of($user), $expected), 409, 'This account changed since you opened it. Reload it before saving.');
    }
}
