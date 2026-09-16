<?php

namespace App\Http\Requests\Course;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends CourseRequest
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
            'course_code' => [
                'required',
                'string',
                Rule::unique('courses', 'course_code')->where(
                    fn ($query) => $query->where('department_id', $this->input('department_id')),
                ),
            ],
            'course_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0|max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LECTURE_SLOTS_PER_UNIT),
            'lab_hours' => 'required|integer|min:0|max:'.SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LABORATORY_SLOTS_PER_UNIT),
            'units' => 'required|integer|min:0',
            'course_category' => 'required|in:major,minor',
            'room_type_required' => 'required|in:lecture,laboratory,field,online',
            'year_level' => 'nullable|in:1,2,3,4',
            'semester' => 'nullable|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => $this->programRule($this->input('department_id')),
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
