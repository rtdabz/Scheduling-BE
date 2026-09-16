<?php

namespace App\Http\Requests\Program;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
{
    /** Access is enforced by the route's middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'cluster' => ['nullable', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('programs', 'code')->where(
                    fn ($query) => $query->where('department_id', $this->input('department_id'))
                ),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
