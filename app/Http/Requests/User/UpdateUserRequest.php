<?php

namespace App\Http\Requests\User;

use App\Models\Faculty;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->targetUser()->role === 'vpaa'
            ? Response::deny('The VPAA account cannot be changed here.')
            : Response::allow();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'middle_initial' => ['nullable', 'string', 'size:1', 'alpha'],
            'last_name' => 'required|string|max:100',
            'suffix' => ['nullable', Rule::in(Faculty::NAME_SUFFIXES)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->targetUser()->id)],
            'role' => 'required|string|in:dean,program_head,secretary,director',
            'is_active' => 'required|boolean',
            'allow_google_login' => 'sometimes|boolean',
            'department_id' => 'required|exists:departments,id',
            'profile_picture' => 'nullable|string',
            'program_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('role') === 'program_head'),
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $this->input('department_id'))),
            ],
        ];
    }

    /** The account being edited (not the signed-in user, which is `user()`). */
    private function targetUser(): User
    {
        return $this->route('user');
    }
}
