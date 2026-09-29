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
            // Stored inline as a data URL; the client resizes to 300px JPEG, so a
            // real logo is far below this cap.
            'logo' => ['nullable', 'string', 'max:200000', 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/]+={0,2}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'department_name.unique' => 'This name belongs to an existing or archived department. If it was archived, restore it from Archives instead.',
            'department_code.unique' => 'This code belongs to an existing or archived department. If it was archived, restore it from Archives instead.',
        ];
    }
}
