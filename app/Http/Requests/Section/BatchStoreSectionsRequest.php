<?php

namespace App\Http\Requests\Section;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;

class BatchStoreSectionsRequest extends FormRequest
{
    /**
     * The department check needs every validated department_id, so it stays in
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
            'sections' => 'required|array|min:1|max:50',
            'sections.*.section_name' => 'required|string|max:255',
            'sections.*.year_level' => SchedulingPolicy::allowedYearLevelsRule('required'),
            'sections.*.department_id' => 'required|exists:departments,id',
            'sections.*.program_id' => 'required|integer|exists:programs,id',
            'sections.*.curriculum_id' => 'nullable|integer|exists:curriculum,id',
        ];
    }
}
