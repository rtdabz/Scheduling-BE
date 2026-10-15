<?php

namespace App\Http\Requests\Course;

class BatchStoreCoursesRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $courses = $this->input('courses');
        if (! is_array($courses)) {
            return;
        }

        foreach ($courses as &$course) {
            if (is_array($course) && array_key_exists('course_code', $course)) {
                $course['course_code'] = $this->normalizeCode($course['course_code']);
            }
        }
        unset($course);
        $this->merge(['courses' => $courses]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'courses' => 'required|array|min:1',
            'courses.*.row_id' => 'required|string',
            'courses.*.year_level' => 'required|integer|between:1,4',
            'courses.*.semester' => 'required|integer|between:1,3',
        ];
        foreach ($this->courseRules() as $field => $rule) {
            $rules['courses.*.'.$field] = $rule;
        }

        return $rules;
    }
}
