<?php

namespace App\Http\Requests\Section;

use App\Models\Sections;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(ScheduleAuthorizationService $authorization): Response
    {
        return $authorization->payloadBelongsToDepartment($this, (int) $this->section()->department_id)
            ? Response::allow()
            : Response::deny('You can only manage sections for your department.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $departmentId = $this->input('department_id', $this->section()->department_id);

        return [
            'section_name' => 'sometimes|string|max:255',
            'year_level' => SchedulingPolicy::allowedYearLevelsRule('sometimes'),
            'semester' => SchedulingPolicy::allowedSemestersRule('sometimes'),
            'department_id' => 'sometimes|exists:departments,id',
            'program_id' => ['sometimes', 'integer', Rule::exists('programs', 'id')->where(fn ($q) => $q->where('department_id', $departmentId))],
            'semester_id' => 'sometimes|exists:semesters,id',
            'curriculum_id' => 'sometimes|nullable|integer|exists:curriculum,id',
            'status' => SchedulingPolicy::allowedActiveStatusesRule('sometimes'),
        ];
    }

    private function section(): Sections
    {
        return $this->route('section');
    }
}
