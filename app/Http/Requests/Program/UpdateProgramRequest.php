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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Program $program */
        $program = $this->route('program');

        return [
            'cluster' => ['nullable', 'string', 'max:255'],
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('programs', 'code')
                    ->ignore($program->id)
                    ->where(fn ($query) => $query->where('department_id', $program->department_id)),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
