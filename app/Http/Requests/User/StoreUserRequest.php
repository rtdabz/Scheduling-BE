<?php

namespace App\Http\Requests\User;

use App\Models\Faculty;
use App\Services\UserFacultyProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /** Access is enforced by the route's capability middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'middle_initial' => ['nullable', 'string', 'size:1', 'alpha'],
            'last_name' => 'required|string|max:100',
            'suffix' => ['nullable', Rule::in(Faculty::NAME_SUFFIXES)],
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', Password::min(10)->letters()->mixedCase()->numbers()],
            'role' => 'required|string|in:dean,program_head,secretary,director',
            'is_active' => 'sometimes|boolean',
            'allow_google_login' => 'sometimes|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $this->input('department_id'))),
            ],
            // Defaults to `create` so existing API clients keep their behaviour.
            'faculty_mode' => ['sometimes', 'string', Rule::in(UserFacultyProfileService::MODES)],
            'faculty_id' => [
                Rule::requiredIf(fn () => $this->input('faculty_mode') === UserFacultyProfileService::MODE_LINK),
                'nullable',
                'integer',
            ],
            'designation_id' => ['nullable', 'integer'],
            'designation_ids' => ['nullable', 'array'],
        ];
    }
}
