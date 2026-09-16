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
