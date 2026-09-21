<?php

namespace App\Http\Requests\Program;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
{
    /** Access is enforced by the route's middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }

        if ($this->has('major')) {
            $this->merge(['major' => trim((string) $this->input('major'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Program $program */
        $program = $this->route('program');

        // Code and major identify the program together, so the uniqueness check
        // has to use whichever major this request will leave in place.
        $major = $this->has('major') ? (string) $this->input('major') : (string) $program->major;
        $code = $this->has('code') ? (string) $this->input('code') : (string) $program->code;

        return [
            'major' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique('programs', 'major')
                    ->ignore($program->id)
                    ->where(fn ($query) => $query
                        ->where('department_id', $program->department_id)
                        ->where('code', $code)),
            ],
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('programs', 'code')
                    ->ignore($program->id)
                    ->where(fn ($query) => $query
                        ->where('department_id', $program->department_id)
                        ->where('major', $major)),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'This department already offers a program with that code and major.',
            'major.unique' => 'This department already offers a program with that code and major.',
        ];
    }
}
