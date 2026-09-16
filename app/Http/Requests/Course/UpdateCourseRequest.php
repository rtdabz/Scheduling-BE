<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends CourseRequest
{
    public function authorize(ScheduleAuthorizationService $authorization): Response
    {
        return $authorization->payloadBelongsToDepartment($this, (int) $this->course()->department_id)
            ? Response::allow()
            : Response::deny('Forbidden.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $course = $this->course();
        $departmentId = $this->has('department_id') ? $this->input('department_id') : $course->department_id;

        return [
            'course_code' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('courses', 'course_code')
                    ->ignore($course->id)
                    ->where(fn ($query) => $query->where('department_id', $departmentId)),
            ],
            'course_name' => 'sometimes|required|string',
            'lecture_hours' => 'sometimes|required|integer|min:0|max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LECTURE_SLOTS_PER_UNIT),
            'lab_hours' => 'sometimes|required|integer|min:0|max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LABORATORY_SLOTS_PER_UNIT),
            'units' => 'sometimes|required|integer|min:0',
            'course_category' => 'sometimes|required|in:major,minor',
            'room_type_required' => 'sometimes|required|in:lecture,laboratory,field,online',
            'year_level' => 'sometimes|required|in:1,2,3,4',
            'semester' => 'sometimes|required|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => $this->programRule($departmentId),
            'status' => 'nullable|in:active,inactive',
        ];
    }

    private function course(): Course
    {
        return $this->route('course');
    }
}
