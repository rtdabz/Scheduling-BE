<?php

namespace App\Http\Requests\Program;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }

        $this->merge(['major' => trim((string) $this->input('major'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'major' => ['nullable', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('programs', 'code')->where(
                    fn ($query) => $query
                        ->where('department_id', $this->input('department_id'))
                        ->where('major', $this->input('major'))
                ),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This department already has an existing or archived program with that code and major. If it was archived, restore it from Archives instead.',
        ];
    }
}
