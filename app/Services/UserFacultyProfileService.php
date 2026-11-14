<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\User;
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserFacultyProfileService
{
    public const MODE_CREATE = 'create';

    public const MODE_LINK = 'link';

    public const MODE_NONE = 'none';

    public const MODES = [self::MODE_CREATE, self::MODE_LINK, self::MODE_NONE];

    public function __construct(private readonly FacultyDesignationService $designations) {}

    /**
     * @param  list<int>  $designationIds  validated by the caller
     */
    public static function defaultMaxUnits(): int
    {
        return max(0, (int) config('faculty.default_max_units', 21));
    }

    public function createFor(User $user, array $designationIds = []): Faculty
    {
        [$firstName, $middleName, $lastName, $suffix] = $this->nameParts($user);
        $this->ensureNoDuplicateInstructor((int) $user->department_id, $firstName, $middleName, $lastName, $suffix);

        $faculty = Faculty::create([
            'user_id' => $user->id,
            'administrative_role' => $user->role,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
            'honorific_prefix' => $user->honorific_prefix,
            'credentials' => $user->credentials,
            'employment_type' => 'full-time',
            'max_units' => self::defaultMaxUnits(),
            'overload_units' => 0,
            'deload_units' => 0,
            'department_id' => $user->department_id,
            'program_id' => $user->program_id,
            'status' => 'active',
            'profile_picture' => $user->profile_picture,
        ]);

        if ($designationIds !== []) {
            $this->designations->sync($faculty, $designationIds);
        }

        return $faculty;
    }

    /**
     * @param  list<int>  $designationIds  empty keeps the instructor's current ones
     */
    public function linkTo(User $user, int $facultyId, array $designationIds = []): Faculty
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

        $faculty->update([
            'user_id' => $user->id,
            'administrative_role' => $user->role,
            ...$this->reconcileAffixes($user, $faculty),
        ]);
        if ($designationIds !== []) {
            $this->designations->sync($faculty, $designationIds);
        }

        return $faculty;
    }

    /**
     * @return Collection<int, Faculty>
     */
    public function linkableIn(int $departmentId): Collection
    {
        return Faculty::query()
            ->whereNull('user_id')
            ->where('department_id', $departmentId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', ...Faculty::NAME_COLUMNS, 'employment_type', 'max_units', 'program_id', 'status']);
    }

    public function sync(User $user): ?Faculty
    {
        $faculty = $user->facultyProfile;
        if ($faculty === null) {
            return null;
        }

        [$firstName, $middleName, $lastName, $suffix] = $this->nameParts($user);

        $status = match (true) {
            ! $user->is_active => ['status' => 'inactive'],
            $user->wasChanged('is_active') => ['status' => 'active'],
            default => [],
        };

        // Only a Program Head's account carries a program, and it is authoritative. Other
        // accounts have none, so keep the instructor's own program (set on the instructor
        // profile) unless the department changed, which makes the old program invalid.
        $program = match (true) {
            $user->program_id !== null => ['program_id' => $user->program_id],
            (int) $faculty->department_id !== (int) $user->department_id => ['program_id' => null],
            default => [],
        };

        $faculty->update($status + $program + [
            'administrative_role' => $user->role,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
            ...$this->reconcileAffixes($user, $faculty),
            'department_id' => $user->department_id,
            'profile_picture' => $user->profile_picture,
        ]);

        return $faculty->fresh();
    }

    /**
     * Copies a linked instructor's title and credentials to the account after they were
     * edited on the instructor profile, so the next account sync does not undo the edit.
     */
    public function pushAffixesToAccount(Faculty $faculty): void
    {
        $user = $faculty->user;
        if ($user === null) {
            return;
        }

        $user->fill($faculty->only(PersonName::AFFIX_FIELDS));
        if ($user->isDirty(PersonName::AFFIX_FIELDS)) {
            if (filled($user->first_name) && filled($user->last_name)) {
                $user->name = $user->composedName();
            }
            $user->save();
            app(AuthenticationAuditService::class)->record(request(), 'profile_updated', $user, ['source' => 'faculty_profile']);
        }
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

    /**
     * A new profile for someone already on the department roster would put the same
     * person in front of the generator twice. Middle names compare by initial, since
     * accounts store only the initial while roster records may hold the full name.
     */
    private function ensureNoDuplicateInstructor(int $departmentId, string $firstName, ?string $middleName, string $lastName, ?string $suffix): void
    {
        $normalize = static fn (mixed $value): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($value ?? ''))));
        $initial = static fn (mixed $value): string => mb_substr($normalize($value), 0, 1);

        $match = Faculty::withTrashed()
            ->where('department_id', $departmentId)
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$normalize($lastName)])
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$normalize($firstName)])
            ->get(['id', 'user_id', 'middle_name', 'suffix', 'deleted_at'])
            ->first(fn (Faculty $faculty): bool => $normalize($faculty->suffix) === $normalize($suffix)
                && ($initial($faculty->middle_name) === '' || $initial($middleName) === '' || $initial($faculty->middle_name) === $initial($middleName)));

        if ($match === null) {
            return;
        }

        $message = match (true) {
            $match->trashed() => 'An archived instructor already has this name in this department. Restore them from Archives, then link the account to them.',
            $match->user_id !== null => 'An instructor with this name in this department is already linked to another account.',
            default => 'This person is already on the department\'s instructor roster. Choose "Link existing instructor" instead.',
        };

        throw ValidationException::withMessages(['faculty_mode' => $message]);
    }

    /**
     * The title and credentials an instructor profile should carry for its account.
     *
     * A value on the account wins. A value cleared on the account in this save clears the
     * profile too. Otherwise a value already on the profile (for example, one recorded on a
     * roster instructor before it was linked) is kept and copied onto the account instead of
     * being silently dropped.
     *
     * @return array{honorific_prefix: ?string, credentials: ?string}
     */
    private function reconcileAffixes(User $user, Faculty $faculty): array
    {
        $resolved = [];
        foreach (PersonName::AFFIX_FIELDS as $field) {
            $resolved[$field] = match (true) {
                $user->{$field} !== null => $user->{$field},
                $user->wasChanged($field) => null,
                default => $faculty->{$field},
            };
        }

        $user->fill($resolved);
        if ($user->isDirty(PersonName::AFFIX_FIELDS)) {
            if (filled($user->first_name) && filled($user->last_name)) {
                $user->name = $user->composedName();
            }
            // A direct write keeps the caller's wasChanged() view of this request intact.
            $columns = [...PersonName::AFFIX_FIELDS, 'name'];
            User::query()->whereKey($user->getKey())->update($user->only($columns));
            $user->syncOriginalAttributes($columns);
        }

        return $resolved;
    }

    /**
     * @return array{0: string, 1: ?string, 2: string, 3: ?string}
     */
    private function nameParts(User $user): array
    {
        if (filled($user->first_name) && filled($user->last_name)) {
            return [$user->first_name, $user->middle_initial ?: null, $user->last_name, $user->suffix ?: null];
        }

        return $this->splitName((string) $user->name);
    }

    /**
     * @return array{0: string, 1: ?string, 2: string, 3: ?string}
     */
    private function splitName(string $name): array
    {
        $isSuffix = static fn (string $word): bool => (bool) preg_match('/^(jr|sr|i{2,3}|iv|v)\.?$/i', $word);
        $words = static fn (string $text): array => preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $suffix = null;
        if (str_contains($name, ',')) {
            [$lastPart, $givenPart] = array_map('trim', explode(',', $name, 2));
            $lastWords = $words($lastPart);
            $given = $words(str_replace(',', ' ', $givenPart));
            if (count($lastWords) > 1 && $isSuffix(end($lastWords))) {
                $suffix = array_pop($lastWords);
            }
            $lastName = implode(' ', $lastWords);
        } else {
            $given = $words($name);
            if (count($given) > 2 && $isSuffix(end($given))) {
                $suffix = array_pop($given);
            }
            $lastWords = count($given) > 1 ? [array_pop($given)] : [];
            while (count($given) > 1 && preg_match('/^(de|del|dela|della|delos|de\'|des|di|da|du|la|las|los|san|santa|sta\.?|van|von|der|den|y)$/i', end($given))) {
                array_unshift($lastWords, array_pop($given));
            }
            $lastName = implode(' ', $lastWords);
        }

        if ($suffix === null && count($given) > 1 && $isSuffix(end($given))) {
            $suffix = array_pop($given);
        }

        $middleName = null;
        if (count($given) > 1 && preg_match('/^\p{L}\.?$/u', end($given))) {
            $middleName = rtrim((string) array_pop($given), '.');
        }

        return [implode(' ', $given) ?: 'Unknown', $middleName, $lastName, $suffix];
    }
}
