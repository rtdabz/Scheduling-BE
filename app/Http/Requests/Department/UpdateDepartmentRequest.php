<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    /** Access is enforced by the route's middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('department')->id;

        return [
            'department_name' => 'sometimes|required|string|max:255|unique:departments,department_name,'.$id,
            'department_code' => 'sometimes|required|string|max:20|unique:departments,department_code,'.$id,
            'scheduling_profile' => 'sometimes|in:standard,laboratory_enabled',
            'logo' => 'nullable|string',
        ];
    }
}
