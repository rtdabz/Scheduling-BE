<?php

namespace App\Http\Requests\Section;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    /**
     * The department check needs the validated department_id, so it stays in
     * the controller, after validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section_name' => 'required|string|max:255',
            'year_level' => SchedulingPolicy::allowedYearLevelsRule('required'),
            'department_id' => 'required|exists:departments,id',
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where(fn ($q) => $q->where('department_id', $this->input('department_id')))],
            'curriculum_id' => 'nullable|integer|exists:curriculum,id',
        ];
    }
}
