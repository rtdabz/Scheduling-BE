<?php

namespace App\Http\Requests\Designation;

use App\Models\Designation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class DesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    abstract protected function existing(): ?Designation;

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $existing = $this->existing();

        $parentId = $this->has('parent_id')
            ? ($this->input('parent_id') === null || $this->input('parent_id') === '' ? null : (int) $this->input('parent_id'))
            : $existing?->parent_id;

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('designations', 'id')->whereNull('deleted_at')],
            'name' => [
                $existing === null ? 'required' : 'sometimes',
                'required', 'string', 'max:255',
                Rule::unique('designations', 'name')
                    ->ignore($existing?->id)
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId)),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'deload_units' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'This designation is already added.',
        ];
    }
}
