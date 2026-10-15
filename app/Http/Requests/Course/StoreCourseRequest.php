<?php

namespace App\Http\Requests\Course;

use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends CourseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->filled('department_id')) {
            $scope = app(ScheduleAuthorizationService::class)->departmentScope($this);
            if ($scope !== null) {
                $this->merge(['department_id' => $scope]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->courseRules();

        return [
            ...$rules,
            'course_code' => [
                ...$rules['course_code'],
                Rule::unique('courses', 'course_code')->where(
                    fn ($query) => $query->where('department_id', $this->input('department_id')),
                ),
            ],
            'room_type_required' => 'required|in:lecture,laboratory,field,online',
            'year_level' => 'required|in:1,2,3,4',
            'semester' => 'required|in:1st,2nd,summer',
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'program_id' => $this->programRule($this->input('department_id')),
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
