<?php

namespace App\Http\Requests\Course;

use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
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

    /**
     * Every course belongs to exactly one department, so the same code in two
     * departments is two separate records. A department user's own department
     * is filled in when the payload leaves it out; the controller still rejects
     * one naming another department.
     */
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
            // Required: the columns are not nullable, so a missing value
            // must be a validation error rather than a failed insert.
            'year_level' => 'required|in:1,2,3,4',
            'semester' => 'required|in:1st,2nd,summer',
            'department_id' => 'required|integer|exists:departments,id',
            'program_id' => $this->programRule($this->input('department_id')),
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
