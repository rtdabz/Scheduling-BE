<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Curriculum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchedulingSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $department = $this->resolveDepartment($request);
        $lectureLabAvailable = $this->hasLectureLabCourses($department);

        return response()->json([
            'department_id' => $department->id,
            'lecture_lab_schedule_override_enabled' => (bool) $department->lecture_lab_schedule_override_enabled,
            'split_units_schedule_override_enabled' => (bool) $department->split_units_schedule_override_enabled,
            'custom_lab_duration_override_enabled' => (bool) $department->custom_lab_duration_override_enabled,
            'custom_lab_duration_minutes' => $department->custom_lab_duration_minutes,
            'custom_lab_duration_6_hours_enabled' => (bool) $department->custom_lab_duration_6_hours_enabled,
            'custom_lab_duration_5_hours_enabled' => (bool) $department->custom_lab_duration_5_hours_enabled,
            'custom_lab_duration_other_enabled' => (bool) $department->custom_lab_duration_other_enabled,
            'gec_split_schedule_override_enabled' => (bool) $department->gec_split_schedule_override_enabled,
            'lecture_lab_available' => $lectureLabAvailable,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lecture_lab_schedule_override_enabled' => 'sometimes|required|boolean',
            'split_units_schedule_override_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_override_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_minutes' => 'nullable|integer|min:30|max:720',
            'custom_lab_duration_6_hours_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_5_hours_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_other_enabled' => 'sometimes|required|boolean',
            'gec_split_schedule_override_enabled' => 'sometimes|required|boolean',
        ]);

        $department = $this->resolveDepartment($request);
        if (array_key_exists('lecture_lab_schedule_override_enabled', $validated)) {
            if ((bool) $validated['lecture_lab_schedule_override_enabled'] && !$this->hasLectureLabCourses($department)) {
                return response()->json([
                    'message' => 'Lecture + Laboratory override is only available for departments with courses that have both lecture and laboratory units.',
                ], 422);
            }
            $department->lecture_lab_schedule_override_enabled = (bool) $validated['lecture_lab_schedule_override_enabled'];
        }
        if (array_key_exists('split_units_schedule_override_enabled', $validated)) {
            $department->split_units_schedule_override_enabled = (bool) $validated['split_units_schedule_override_enabled'];
            if ($department->split_units_schedule_override_enabled) {
                $department->gec_split_schedule_override_enabled = false;
            }
        }
        if (array_key_exists('custom_lab_duration_override_enabled', $validated)) {
            $department->custom_lab_duration_override_enabled = (bool) $validated['custom_lab_duration_override_enabled'];
        }
        if (array_key_exists('custom_lab_duration_minutes', $validated)) {
            $department->custom_lab_duration_minutes = $validated['custom_lab_duration_minutes'];
        }
        if (array_key_exists('custom_lab_duration_6_hours_enabled', $validated)) {
            $department->custom_lab_duration_6_hours_enabled = (bool) $validated['custom_lab_duration_6_hours_enabled'];
        }
        if (array_key_exists('custom_lab_duration_5_hours_enabled', $validated)) {
            $department->custom_lab_duration_5_hours_enabled = (bool) $validated['custom_lab_duration_5_hours_enabled'];
        }
        if (array_key_exists('custom_lab_duration_other_enabled', $validated)) {
            $department->custom_lab_duration_other_enabled = (bool) $validated['custom_lab_duration_other_enabled'];
        }
        if (array_key_exists('gec_split_schedule_override_enabled', $validated)) {
            $department->gec_split_schedule_override_enabled = (bool) $validated['gec_split_schedule_override_enabled'];
            if ($department->gec_split_schedule_override_enabled) {
                $department->split_units_schedule_override_enabled = false;
            }
        }
        $department->save();

        return response()->json([
            'department_id' => $department->id,
            'lecture_lab_schedule_override_enabled' => (bool) $department->lecture_lab_schedule_override_enabled,
            'split_units_schedule_override_enabled' => (bool) $department->split_units_schedule_override_enabled,
            'custom_lab_duration_override_enabled' => (bool) $department->custom_lab_duration_override_enabled,
            'custom_lab_duration_minutes' => $department->custom_lab_duration_minutes,
            'custom_lab_duration_6_hours_enabled' => (bool) $department->custom_lab_duration_6_hours_enabled,
            'custom_lab_duration_5_hours_enabled' => (bool) $department->custom_lab_duration_5_hours_enabled,
            'custom_lab_duration_other_enabled' => (bool) $department->custom_lab_duration_other_enabled,
            'gec_split_schedule_override_enabled' => (bool) $department->gec_split_schedule_override_enabled,
            'lecture_lab_available' => $this->hasLectureLabCourses($department),
        ]);
    }

    private function resolveDepartment(Request $request): Departments
    {
        $user = $request->user();

        abort_if(!$user || $user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }

    private function hasLectureLabCourses(Departments $department): bool
    {
        $activeCurriculum = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->first();

        if (!$activeCurriculum) {
            return false;
        }

        return $activeCurriculum->courses()
            ->where('course_category', 'major')
            ->where('lecture_hours', '>', 0)
            ->where('lab_hours', '>', 0)
            ->exists();
    }
}
