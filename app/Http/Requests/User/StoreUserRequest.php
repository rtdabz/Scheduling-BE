<?php

namespace App\Http\Requests\User;

use App\Models\Faculty;
use App\Services\UserFacultyProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Not unique here: the controller numbers a taken name, since the
            // form proposes the same department+role name for every holder.
            'username' => 'required|string|max:250',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|string|in:dean,program_head,secretary',
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // Archived accounts keep their email, so a returning user is
            // restored rather than recreated.
            'email.unique' => 'This email belongs to an existing or archived account. If it was archived, restore it from Archives instead.',
        ];
    }
}
