<?php

namespace App\Http\Requests\Course;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CourseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('course_code')) {
            $this->merge([
                'course_code' => $this->normalizeCode($this->input('course_code')),
            ]);
        }
    }

    protected function normalizeCode(mixed $code): mixed
    {
        return is_string($code) ? trim(preg_replace('/\s+/', ' ', strtoupper($code))) : $code;
    }

    /** @return array<string, array<int, string>> */
    protected function courseRules(bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'course_code' => [...$required, 'string', 'max:255'],
            'course_name' => [...$required, 'string', 'max:255'],
            'lecture_hours' => [...$required, 'integer', 'min:0', 'max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LECTURE_SLOTS_PER_UNIT)],
            'lab_hours' => [...$required, 'integer', 'min:0', 'max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LABORATORY_SLOTS_PER_UNIT)],
            'units' => [...$required, 'integer', 'min:0'],
            'course_category' => [...$required, 'string', 'in:major,minor'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'course_code.unique' => 'This course code belongs to an existing or archived course in your department. If it was archived, ask the VPAA to restore it from Archives.',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function programRule(mixed $departmentId): array
    {
        if ($departmentId === null || $departmentId === '') {
            return ['nullable', 'integer', Rule::exists('programs', 'id')->whereNull('deleted_at')];
        }

        return [
            'nullable',
            'integer',
            Rule::exists('programs', 'id')->whereNull('deleted_at')->where(
                fn ($query) => $query->where('department_id', (int) $departmentId),
            ),
        ];
    }
}
