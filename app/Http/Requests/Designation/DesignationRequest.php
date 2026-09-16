<?php

namespace App\Http\Requests\Designation;

use App\Models\Designation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and editing a designation. The update request is
 * the same set, with the route's designation as the existing record.
 */
abstract class DesignationRequest extends FormRequest
{
    /** Access is enforced by the route's capability middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** The designation being edited, or null when creating one. */
    abstract protected function existing(): ?Designation;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $existing = $this->existing();

        // Names are unique among siblings: "Coordinator" may exist under two
        // different parents.
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
            // No upper bound is imposed here: a full deload is a real
            // arrangement, and the ceiling is max_units, which varies per
            // instructor. FacultyBasicLoad already floors the result at zero.
            'deload_units' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
