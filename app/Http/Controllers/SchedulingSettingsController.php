<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Curriculum;
use App\Models\Course;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
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
            'force_schedule_reuse_enabled' => (bool) $department->force_schedule_reuse_enabled,
            'lecture_lab_available' => $lectureLabAvailable,
            'forced_day_courses' => $this->forcedDayCourses($department),
            'forced_day_rules' => $this->forcedDayRules($department),
            'forced_schedule_course_options' => $this->forcedScheduleCourseOptions($department),
            'forced_schedule_course_codes' => $this->forcedScheduleCourseCodes($department),
            'field_course_assignment_enabled' => $this->fieldCourseAssignmentEnabled(),
            'field_course_options' => $this->fieldCourseOptions($department),
            'field_course_codes' => $this->fieldCourseCodes(),
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
            'force_schedule_reuse_enabled' => 'sometimes|required|boolean',
            'forced_day_rules' => 'sometimes|array',
            'forced_day_rules.*.course_id' => 'required|integer|exists:courses,id',
            'forced_day_rules.*.day' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'forced_schedule_course_codes' => 'sometimes|array',
            'forced_schedule_course_codes.*' => 'required|string|max:255',
            'field_course_assignment_enabled' => 'sometimes|required|boolean',
            'field_course_codes' => 'sometimes|array',
            'field_course_codes.*' => 'required|string|max:255',
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
        if (array_key_exists('force_schedule_reuse_enabled', $validated)) {
            $department->force_schedule_reuse_enabled = (bool) $validated['force_schedule_reuse_enabled'];
        }
        $department->save();

        if (array_key_exists('forced_day_rules', $validated)) {
            $this->syncForcedDayRules($department, $validated['forced_day_rules']);
        }
        if (array_key_exists('forced_schedule_course_codes', $validated)) {
            $this->syncForcedScheduleCourseCodes($department, $validated['forced_schedule_course_codes']);
        }
        if (array_key_exists('field_course_assignment_enabled', $validated)) {
            $this->syncFieldCourseAssignmentEnabled((bool) $validated['field_course_assignment_enabled']);
        }
        if (array_key_exists('field_course_codes', $validated)) {
            $this->syncFieldCourseCodes($department, $validated['field_course_codes']);
        }

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
            'force_schedule_reuse_enabled' => (bool) $department->force_schedule_reuse_enabled,
            'lecture_lab_available' => $this->hasLectureLabCourses($department),
            'forced_day_courses' => $this->forcedDayCourses($department),
            'forced_day_rules' => $this->forcedDayRules($department),
            'forced_schedule_course_options' => $this->forcedScheduleCourseOptions($department),
            'forced_schedule_course_codes' => $this->forcedScheduleCourseCodes($department),
            'field_course_assignment_enabled' => $this->fieldCourseAssignmentEnabled(),
            'field_course_options' => $this->fieldCourseOptions($department),
            'field_course_codes' => $this->fieldCourseCodes(),
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

    private function activeCurriculum(Departments $department): ?Curriculum
    {
        return Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->first();
    }

    private function forcedDayCourses(Departments $department): array
    {
        $activeCurriculum = $this->activeCurriculum($department);

        if (!$activeCurriculum) {
            return [];
        }

        return $activeCurriculum->courses()
            ->where('status', 'active')
            ->orderBy('course_code')
            ->get(['courses.id', 'course_code', 'course_name'])
            ->map(static fn ($course): array => [
                'id' => (int) $course->id,
                'code' => (string) $course->course_code,
                'name' => (string) $course->course_name,
            ])
            ->values()
            ->all();
    }

    private function forcedScheduleCourseOptions(Departments $department): array
    {
        return $this->forcedDayCourses($department);
    }

    private function forcedScheduleCourseCodes(Departments $department): array
    {
        return DB::table('department_forced_schedule_course_codes')
            ->where('department_id', $department->id)
            ->orderBy('course_code')
            ->pluck('course_code')
            ->map(static fn ($courseCode): string => (string) $courseCode)
            ->values()
            ->all();
    }

    private function syncForcedScheduleCourseCodes(Departments $department, array $courseCodes): void
    {
        $allowedCodes = collect($this->forcedScheduleCourseOptions($department))
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
        $allowedCodeMap = array_fill_keys($allowedCodes, true);

        DB::transaction(function () use ($department, $courseCodes, $allowedCodeMap): void {
            DB::table('department_forced_schedule_course_codes')
                ->where('department_id', $department->id)
                ->delete();

            $rows = [];
            foreach ($courseCodes as $courseCode) {
                $courseCode = trim((string) $courseCode);
                if ($courseCode === '' || !isset($allowedCodeMap[$courseCode])) {
                    continue;
                }

                $rows[$courseCode] = [
                    'department_id' => $department->id,
                    'course_code' => $courseCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('department_forced_schedule_course_codes')->insert(array_values($rows));
            }
        });
    }

    private function fieldCourseAssignmentEnabled(): bool
    {
        return (bool) DB::table('field_course_settings')
            ->whereNull('course_code')
            ->value('enabled');
    }

    private function syncFieldCourseAssignmentEnabled(bool $enabled): void
    {
        DB::table('field_course_settings')->updateOrInsert(
            ['course_code' => null],
            ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()],
        );
        SchedulingPolicy::clearFieldCourseCache();
    }

    private function fieldCourseOptions(Departments $department): array
    {
        $activeCurriculum = $this->activeCurriculum($department);

        if (!$activeCurriculum) {
            return [];
        }

        return $activeCurriculum->courses()
            ->where('courses.status', 'active')
            ->orderBy('course_code')
            ->get(['courses.id', 'course_code', 'course_name'])
            ->map(static fn (Course $course): array => [
                'id' => (int) $course->id,
                'code' => (string) $course->course_code,
                'name' => (string) $course->course_name,
            ])
            ->unique('code')
            ->values()
            ->all();
    }

    private function fieldCourseCodes(): array
    {
        return DB::table('field_course_settings')
            ->whereNotNull('course_code')
            ->orderBy('course_code')
            ->pluck('course_code')
            ->map(static fn ($courseCode): string => (string) $courseCode)
            ->values()
            ->all();
    }

    private function syncFieldCourseCodes(Departments $department, array $courseCodes): void
    {
        $allowedCodes = collect($this->fieldCourseOptions($department))
            ->pluck('code')
            ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
            ->all();
        $allowedCodeMap = array_fill_keys($allowedCodes, true);

        DB::transaction(function () use ($courseCodes, $allowedCodeMap): void {
            DB::table('field_course_settings')
                ->whereNotNull('course_code')
                ->delete();

            $rows = [];
            foreach ($courseCodes as $courseCode) {
                $courseCode = SchedulingPolicy::normalizeCourseCode((string) $courseCode);
                if ($courseCode === '' || !isset($allowedCodeMap[$courseCode])) {
                    continue;
                }

                $rows[$courseCode] = [
                    'enabled' => true,
                    'course_code' => $courseCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('field_course_settings')->insert(array_values($rows));
            }
        });

        SchedulingPolicy::clearFieldCourseCache();
    }

    private function forcedDayRules(Departments $department): array
    {
        return DB::table('department_forced_course_days')
            ->where('department_id', $department->id)
            ->orderBy('course_id')
            ->get(['course_id', 'day'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'day' => (string) $rule->day,
            ])
            ->values()
            ->all();
    }

    private function syncForcedDayRules(Departments $department, array $rules): void
    {
        $allowedCourseIds = collect($this->forcedDayCourses($department))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowedCourseIdMap = array_fill_keys($allowedCourseIds, true);

        DB::transaction(function () use ($department, $rules, $allowedCourseIdMap): void {
            DB::table('department_forced_course_days')
                ->where('department_id', $department->id)
                ->delete();

            $rows = [];
            foreach ($rules as $rule) {
                $courseId = (int) $rule['course_id'];
                if (!isset($allowedCourseIdMap[$courseId])) {
                    continue;
                }

                $rows[$courseId] = [
                    'department_id' => $department->id,
                    'course_id' => $courseId,
                    'day' => $rule['day'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('department_forced_course_days')->insert(array_values($rows));
            }
        });
    }
}
