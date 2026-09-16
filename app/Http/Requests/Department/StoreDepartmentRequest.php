<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
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
            'department_name' => 'required|string|max:255|unique:departments,department_name',
            'department_code' => 'required|string|max:20|unique:departments,department_code',
            'scheduling_profile' => 'sometimes|in:standard,laboratory_enabled',
            'logo' => 'nullable|string',
        ];
    }
}
