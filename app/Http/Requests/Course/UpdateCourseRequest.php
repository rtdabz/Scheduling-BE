<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends CourseRequest
{
    public function authorize(ScheduleAuthorizationService $authorization): Response
    {
        return $authorization->payloadBelongsToDepartment($this, (int) $this->course()->department_id)
            && ! $authorization->rejectsRequestedDepartment($this, $this->input('department_id'))
            ? Response::allow()
            : Response::deny('You can only manage courses for your department.');
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $data = parent::validationData();
        $course = $this->course();

        if (! array_key_exists('department_id', $data) && $course->department_id !== null) {
            $data['department_id'] = $course->department_id;
        }
        // Validate the retained program against the resulting department. Minors
        // have their program cleared by the controller instead.
        if (! array_key_exists('program_id', $data) && ($data['course_category'] ?? $course->course_category) === 'major') {
            $data['program_id'] = $course->program_id;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $course = $this->course();
        $departmentId = $this->has('department_id') ? $this->input('department_id') : $course->department_id;

        $rules = $this->courseRules(true);

        return [
            ...$rules,
            'course_code' => [
                ...$rules['course_code'],
                Rule::unique('courses', 'course_code')
                    ->ignore($course->id)
                    ->where(fn ($query) => $query->where('department_id', $departmentId)),
            ],
            'room_type_required' => 'sometimes|required|in:lecture,laboratory,field,online',
            'year_level' => 'sometimes|required|in:1,2,3,4',
            'semester' => 'sometimes|required|in:1st,2nd,summer',
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'program_id' => $this->programRule($departmentId),
            'status' => 'nullable|in:active,inactive',
        ];
    }

    private function course(): Course
    {
        return $this->route('course');
    }
}
