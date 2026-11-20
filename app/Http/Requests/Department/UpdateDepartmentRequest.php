<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The API returns `logo` as a URL to the stored image (see Departments::logo()). Clients that send that
     * URL back unchanged mean "keep the current logo", so drop it instead of failing the base64 rule.
     */
    protected function prepareForValidation(): void
    {
        $logo = $this->input('logo');
        $id = $this->route('department')?->id;
        if (is_string($logo) && $id !== null
            && preg_match('#^(https?://[^/]+)?/api/departments/'.preg_quote((string) $id, '#').'/logo(\?.*)?$#', $logo)) {
            $this->request->remove('logo');
            $this->json()->remove('logo');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('department')->id;

        return [
            'department_name' => 'sometimes|required|string|max:255|unique:departments,department_name,'.$id,
            'department_code' => 'sometimes|required|string|max:20|unique:departments,department_code,'.$id,
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
