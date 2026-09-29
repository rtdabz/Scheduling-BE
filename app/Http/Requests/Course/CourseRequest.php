<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CourseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('course_code')) {
            $this->merge([
                'course_code' => trim(preg_replace('/\s+/', ' ', strtoupper((string) $this->input('course_code')))),
            ]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'course_code.unique' => 'This course code belongs to an existing or archived course in your department. If it was archived, ask the VPAA to restore it from Archives.',
        ];
    }

    /**
     * A major's program decides which instructors may teach it, so the program has
     * to belong to the department that offers the course.
     *
     * @return array<int, mixed>
     */
    protected function programRule(mixed $departmentId): array
    {
        if ($departmentId === null || $departmentId === '') {
            return ['nullable', 'integer', 'exists:programs,id'];
        }

        return [
            'nullable',
            'integer',
            Rule::exists('programs', 'id')->where(
                fn ($query) => $query->where('department_id', (int) $departmentId),
            ),
        ];
    }
}
