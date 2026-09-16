<?php

namespace App\Http\Requests\Semester;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the academic year and the enabled flag are editable -- changing the
 * semester would collide with the sibling rows.
 */
class UpdateSemesterRequest extends FormRequest
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
            'academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
