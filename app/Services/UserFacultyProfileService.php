<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\User;

class UserFacultyProfileService
{
    public function createFor(User $user): Faculty
    {
        [$firstName, $middleName, $lastName] = $this->splitName($user->name);

        return Faculty::create([
            'user_id' => $user->id,
            'administrative_role' => $user->role,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'employment_type' => 'full-time',
            'max_units' => 21,
            'overload_units' => 0,
            'deload_units' => 0,
            'probono_units' => 0,
            'department_id' => $user->department_id,
            'status' => 'active',
            'profile_picture' => $user->profile_picture,
        ]);
    }

    public function sync(User $user): Faculty
    {
        $faculty = $user->facultyProfile ?: $this->createFor($user);
        [$firstName, $middleName, $lastName] = $this->splitName($user->name);

        $faculty->update([
            'administrative_role' => $user->role,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'department_id' => $user->department_id,
            'profile_picture' => $user->profile_picture,
        ]);

        return $faculty->fresh();
    }

    public function detachAsRegularFaculty(User $user): void
    {
        $user->facultyProfile?->update([
            'user_id' => null,
            'administrative_role' => null,
        ]);
    }

    public function deleteFor(User $user): void
    {
        $user->facultyProfile?->delete();
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $firstName = array_shift($parts) ?: 'Unknown';
        $lastName = count($parts) > 0 ? array_pop($parts) : '';
        $middleName = count($parts) > 0 ? implode(' ', $parts) : null;

        return [$firstName, $middleName, $lastName];
    }
}
