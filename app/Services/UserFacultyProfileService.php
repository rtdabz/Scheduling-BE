<?php

namespace App\Services;

use App\Models\Designation;
use App\Models\Faculty;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserFacultyProfileService
{
    /** A new account gets a fresh instructor profile. */
    public const MODE_CREATE = 'create';

    /** A new account takes over an instructor already on the roster. */
    public const MODE_LINK = 'link';

    /** A new account does not teach, so it gets no instructor profile. */
    public const MODE_NONE = 'none';

    public const MODES = [self::MODE_CREATE, self::MODE_LINK, self::MODE_NONE];

    public function createFor(User $user, ?int $designationId = null): Faculty
    {
        [$firstName, $middleName, $lastName] = $this->splitName($user->name);

        return Faculty::create([
            'user_id' => $user->id,
            'administrative_role' => $user->role,
            'designation_id' => $designationId,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'employment_type' => 'full-time',
            'max_units' => 21,
            'overload_units' => 0,
            // Copied rather than joined: SchedulingPolicy::facultyBasicLoad()
            // reads this column, so an unset deload would schedule a dean at a
            // full load.
            'deload_units' => $this->deloadFor($designationId),
            'probono_units' => 0,
            'department_id' => $user->department_id,
            'program_id' => $user->program_id,
            'status' => 'active',
            'profile_picture' => $user->profile_picture,
        ]);
    }

    /**
     * Attaches the account to an instructor already on the roster instead of
     * creating a second record for the same person, which the generator would
     * otherwise treat as two people and double-book.
     *
     * The instructor keeps their own name, load and history. Only the account
     * link, the mirrored role and (when one is chosen) the designation change.
     * Must run inside the caller's transaction so the row lock holds.
     */
    public function linkTo(User $user, int $facultyId, ?int $designationId = null): Faculty
    {
        $faculty = Faculty::query()
            ->whereKey($facultyId)
            ->whereNull('user_id')
            ->where('department_id', $user->department_id)
            ->lockForUpdate()
            ->first();

        if ($faculty === null) {
            throw ValidationException::withMessages([
                'faculty_id' => 'That instructor is not in the selected department or is already linked to another account.',
            ]);
        }

        $attributes = [
            'user_id' => $user->id,
            'administrative_role' => $user->role,
        ];
        if ($designationId !== null) {
            $attributes['designation_id'] = $designationId;
            $attributes['deload_units'] = $this->deloadFor($designationId);
        }

        $faculty->update($attributes);

        return $faculty;
    }

    /**
     * Instructors in a department that no account has claimed yet: the
     * candidates the Create User form offers to link.
     *
     * @return Collection<int, Faculty>
     */
    public function linkableIn(int $departmentId): Collection
    {
        return Faculty::query()
            ->whereNull('user_id')
            ->where('department_id', $departmentId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'employment_type', 'program_id', 'designation_id', 'status']);
    }

    /**
     * Mirrors account details onto an existing profile. It deliberately does
     * not create one: an account saved as non-teaching must stay that way
     * through later edits.
     */
    public function sync(User $user): ?Faculty
    {
        $faculty = $user->facultyProfile;
        if ($faculty === null) {
            return null;
        }

        [$firstName, $middleName, $lastName] = $this->splitName($user->name);

        $faculty->update([
            'administrative_role' => $user->role,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'department_id' => $user->department_id,
            'program_id' => $user->program_id,
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

    private function deloadFor(?int $designationId): int
    {
        if ($designationId === null) {
            return 0;
        }

        return (int) (Designation::query()->whereKey($designationId)->value('deload_units') ?? 0);
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
