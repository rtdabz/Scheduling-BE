<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchedulingSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $department = $this->resolveDepartment($request);
        $section = $this->resolveSection($request, $department);
        $lectureLabAvailable = $this->hasLectureLabCourses($department);

        return response()->json($this->settingsPayload(
            $department,
            $section,
            $lectureLabAvailable,
            $this->canManageSundayClasses($request),
        ));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lecture_lab_schedule_override_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_override_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_minutes' => 'nullable|integer|min:'.SchedulingPolicy::SLOT_MINUTES
                .'|max:'.(SchedulingPolicy::totalSlots() * SchedulingPolicy::SLOT_MINUTES)
                .'|multiple_of:'.SchedulingPolicy::SLOT_MINUTES,
            'custom_lab_duration_6_hours_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_5_hours_enabled' => 'sometimes|required|boolean',
            'custom_lab_duration_other_enabled' => 'sometimes|required|boolean',
            'gec_split_schedule_override_enabled' => 'sometimes|required|boolean',
            'major_lecture_split_schedule_override_enabled' => 'sometimes|required|boolean',
            'sunday_classes_enabled' => 'sometimes|required|boolean',
            // Default LAB Room Requirement: where every course's laboratory meetings may meet.
            'lab_room_type' => 'sometimes|required|string|in:'.implode(',', SchedulingPolicy::LAB_ROOM_TYPES),
        ]);

        $department = $this->resolveDepartment($request);
        $section = $this->resolveSection($request, $department);

        // Sunday is an overflow day the dean agrees to verbally; the department
        // secretary is the one who records it here. Resending the current value
        // is harmless, so only an actual change is refused to other roles.
        $sundayClassesEnabled = array_key_exists('sunday_classes_enabled', $validated)
            ? (bool) $validated['sunday_classes_enabled']
            : (bool) $department->sunday_classes_enabled;
        if ($sundayClassesEnabled !== (bool) $department->sunday_classes_enabled
            && ! $this->canManageSundayClasses($request)) {
            return response()->json([
                'message' => 'Only the department secretary can enable or disable Sunday classes.',
            ], 403);
        }

        $laboratorySettingKeys = [
            'lecture_lab_schedule_override_enabled',
            'custom_lab_duration_override_enabled',
            'custom_lab_duration_6_hours_enabled',
            'custom_lab_duration_5_hours_enabled',
            'custom_lab_duration_other_enabled',
        ];
        $enablingLaboratorySetting = collect($laboratorySettingKeys)
            ->contains(fn (string $key): bool => array_key_exists($key, $validated) && (bool) $validated[$key]);
        if (($department->scheduling_profile ?? 'standard') === 'standard' && $enablingLaboratorySetting) {
            return response()->json([
                'error_code' => 'invalid_department_setting',
                'department_profile' => 'standard',
                'message' => 'Laboratory scheduling settings cannot be enabled for a standard department.',
            ], 422);
        }
        if (array_key_exists('lecture_lab_schedule_override_enabled', $validated)) {
            if ((bool) $validated['lecture_lab_schedule_override_enabled'] && ! $this->hasLectureLabCourses($department)) {
                return response()->json([
                    'message' => 'Lecture + Laboratory override is only available for departments with courses that have both lecture and laboratory units.',
                ], 422);
            }
            $department->lecture_lab_schedule_override_enabled = (bool) $validated['lecture_lab_schedule_override_enabled'];
            if (! $department->lecture_lab_schedule_override_enabled) {
                // Nothing left for a laboratory length to describe.
                $department->custom_lab_duration_override_enabled = false;
            }
        }
        if (array_key_exists('custom_lab_duration_override_enabled', $validated)) {
            // Custom Lab Duration only ever changes the laboratory half of a
            // lecture/laboratory split, so without that override there is no
            // component for it to resize. Refusing here keeps the setting from
            // being stored in a state where it silently does nothing.
            $wantsCustomLab = (bool) $validated['custom_lab_duration_override_enabled'];
            $splitEnabled = array_key_exists('lecture_lab_schedule_override_enabled', $validated)
                ? (bool) $validated['lecture_lab_schedule_override_enabled']
                : (bool) $department->lecture_lab_schedule_override_enabled;
            if ($wantsCustomLab && ! $splitEnabled) {
                return response()->json([
                    'message' => 'Custom Lab Duration applies to Lecture + Laboratory splits. Enable Apply Hybrid first.',
                ], 422);
            }
            $department->custom_lab_duration_override_enabled = $wantsCustomLab;
        }
        if (array_key_exists('custom_lab_duration_minutes', $validated)) {
            $department->custom_lab_duration_minutes = $validated['custom_lab_duration_minutes'];
        }
        // The three presets are stored separately but describe a single
        // choice of laboratory length, and SchedulingPolicy resolves them in a
        // fixed order. Enabling one therefore clears the other two, so what the
        // generator uses is always the option the secretary just picked.
        $durationChoiceKeys = [
            'custom_lab_duration_6_hours_enabled',
            'custom_lab_duration_5_hours_enabled',
            'custom_lab_duration_other_enabled',
        ];
        $chosenDuration = collect($durationChoiceKeys)
            ->first(fn (string $key): bool => array_key_exists($key, $validated) && (bool) $validated[$key]);
        foreach ($durationChoiceKeys as $key) {
            if ($chosenDuration !== null) {
                $department->{$key} = $key === $chosenDuration;

                continue;
            }
            if (array_key_exists($key, $validated)) {
                $department->{$key} = (bool) $validated[$key];
            }
        }

        // Whatever turned the override off -- this request, or the split
        // being switched off above -- no preset survives it. The audit and the
        // standard-profile guard both read these flags, so a preset left true
        // under a disabled override reads as a laboratory setting still in use.
        if (! (bool) $department->custom_lab_duration_override_enabled) {
            foreach ($durationChoiceKeys as $key) {
                $department->{$key} = false;
            }
        }

        if ((bool) $department->custom_lab_duration_override_enabled
            && (bool) $department->custom_lab_duration_other_enabled
            && SchedulingPolicy::customLaboratoryDurationSlots($department) === null) {
            return response()->json([
                'message' => 'Enter a custom laboratory duration in whole half-hours that fits inside the teaching day.',
            ], 422);
        }
        if (array_key_exists('gec_split_schedule_override_enabled', $validated)) {
            $department->gec_split_schedule_override_enabled = (bool) $validated['gec_split_schedule_override_enabled'];
        }
        if (array_key_exists('major_lecture_split_schedule_override_enabled', $validated)) {
            // Refused rather than stored when the department runs no lecture-only
            // major: the setting would be on with nothing for it to apply to, and
            // the audit would report a split policy the generator never uses.
            if ((bool) $validated['major_lecture_split_schedule_override_enabled']
                && ! $this->hasMajorLectureOnlyCourses($department)) {
                return response()->json([
                    'message' => 'Major Lecture Split Sessions is only available for departments with major courses that have lecture units and no laboratory units.',
                ], 422);
            }
            $department->major_lecture_split_schedule_override_enabled = (bool) $validated['major_lecture_split_schedule_override_enabled'];
        }
        if (array_key_exists('lab_room_type', $validated)) {
            $department->lab_room_type = (string) $validated['lab_room_type'];
        }
        // Turning Sunday off leaves classes already on Sunday in place; the
        // sunday_classes rule only refuses new Sunday placements.
        $department->sunday_classes_enabled = $sundayClassesEnabled;
        $department->save();
        SchedulingPolicy::clearFieldCourseCache();

        // Required Day, Consecutive Days and Field Course are not saved: each
        // applies to one generation run, which passes its own.

        ApiCache::forgetGroup('initial.data');

        return response()->json($this->settingsPayload(
            $department,
            $section,
            $this->hasLectureLabCourses($department),
            $this->canManageSundayClasses($request),
        ));
    }

    private function canManageSundayClasses(Request $request): bool
    {
        return $request->user()?->role === 'secretary';
    }

    /**
     * Classes this department already holds on Sunday in the active semester,
     * so turning Sunday off can say how many stay behind.
     */
    private function sundayClassCount(Departments $department): int
    {
        $semesterId = Semester::query()->where('is_active', true)->value('id');
        if ($semesterId === null) {
            return 0;
        }

        return DB::table('schedules')
            ->where('department_id', $department->id)
            ->where('semester_id', $semesterId)
            ->where('day', 'Sunday')
            ->count();
    }

    private function settingsPayload(Departments $department, ?Sections $section, bool $lectureLabAvailable, bool $canManageSundayClasses): array
    {
        $fieldCourseOptions = $this->fieldCourseOptions($department, $section);
        $courseOptions = $this->forcedDayCourses($department, $section);

        return [
            'department_id' => $department->id,
            'scheduling_profile' => (string) ($department->scheduling_profile ?? 'standard'),
            'lecture_lab_schedule_override_enabled' => (bool) $department->lecture_lab_schedule_override_enabled,
            'custom_lab_duration_override_enabled' => (bool) $department->custom_lab_duration_override_enabled,
            'custom_lab_duration_minutes' => $department->custom_lab_duration_minutes,
            'custom_lab_duration_6_hours_enabled' => (bool) $department->custom_lab_duration_6_hours_enabled,
            'custom_lab_duration_5_hours_enabled' => (bool) $department->custom_lab_duration_5_hours_enabled,
            'custom_lab_duration_other_enabled' => (bool) $department->custom_lab_duration_other_enabled,
            'gec_split_schedule_override_enabled' => (bool) $department->gec_split_schedule_override_enabled,
            'major_lecture_split_schedule_override_enabled' => (bool) $department->major_lecture_split_schedule_override_enabled,
            'lecture_lab_available' => $lectureLabAvailable,
            'major_lecture_split_available' => $this->hasMajorLectureOnlyCourses($department),
            'sunday_classes_enabled' => (bool) $department->sunday_classes_enabled,
            'lab_room_type' => SchedulingPolicy::labRoomType((int) $department->id),
            'can_manage_sunday_classes' => $canManageSundayClasses,
            'sunday_class_count' => $this->sundayClassCount($department),
            'generation_period' => $section ? [
                'section_id' => (int) $section->id,
                'semester' => (string) $section->semester,
                'year_level' => (int) $section->year_level,
                'semester_id' => (int) $section->semester_id,
            ] : null,
            'forced_day_courses' => $courseOptions,
            'forced_day_rules' => $this->forcedDayRules($department, $section),
            'consecutive_day_rules' => $this->consecutiveDayRules($department, $section),
            'field_course_assignment_enabled' => $this->fieldCourseAssignmentEnabled($department),
            'field_course_options' => $fieldCourseOptions,
            'field_course_codes' => $this->fieldCourseCodes($department, $section ? $fieldCourseOptions : null),
            'preferred_room_options' => $this->preferredRoomOptions($department, $section),
        ];
    }

    /**
     * The physical rooms a Setup Courses "Preferred Room" may name: the ones
     * this department can reach in the section's semester, per
     * RoomAccessPolicy. A borrowed room is still only usable inside its grant
     * windows; the generator applies those, the preference only ranks.
     *
     * @return list<array{id: int, room_code: string, room_type: string, building: ?string, allow_lecture_usage: bool}>
     */
    private function preferredRoomOptions(Departments $department, ?Sections $section): array
    {
        return Rooms::query()
            ->select(['id', 'room_code', 'room_type', 'building', 'allow_lecture_usage'])
            ->where('status', 'available')
            ->whereIn('room_type', ['lecture', 'laboratory', 'field'])
            ->tap(fn ($query) => app(RoomAccessPolicy::class)->scopeReachableRooms(
                $query,
                (int) $department->id,
                $section ? (int) $section->semester_id : null,
            ))
            ->orderBy('room_code')
            ->get()
            ->map(static fn (Rooms $room): array => [
                'id' => (int) $room->id,
                'room_code' => (string) $room->room_code,
                'room_type' => (string) $room->room_type,
                'building' => $room->building === null ? null : (string) $room->building,
                'allow_lecture_usage' => (bool) $room->allow_lecture_usage,
            ])
            ->values()
            ->all();
    }

    private function resolveDepartment(Request $request): Departments
    {
        $user = $request->user();

        abort_if(! $user || $user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }

    private function resolveSection(Request $request, Departments $department): ?Sections
    {
        $sectionId = $request->integer('section_id');
        if ($sectionId <= 0) {
            return null;
        }

        return Sections::query()
            ->where('department_id', $department->id)
            ->findOrFail($sectionId);
    }

    private function hasLectureLabCourses(Departments $department): bool
    {
        // A department-wide capability question: if *any* curriculum it runs
        // has a lecture+lab major, the setting is relevant. Checking only the
        // first active curriculum hid the setting from departments whose
        // lecture+lab majors live in the curriculum that happened to sort second.
        $activeCurriculumIds = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id');

        if ($activeCurriculumIds->isEmpty()) {
            return false;
        }

        return Course::query()
            ->whereHas('curriculum', fn ($scope) => $scope->whereIn('curriculum.id', $activeCurriculumIds))
            ->where('course_category', 'major')
            ->where('lecture_hours', '>', 0)
            ->where('lab_hours', '>', 0)
            ->exists();
    }

    /**
     * Whether any active curriculum this department runs has a major course that
     * is pure lecture. Those are the only majors a balanced split can apply to:
     * once laboratory units are folded into the unit count, the split's
     * total-duration rule no longer describes the lecture load.
     */
    private function hasMajorLectureOnlyCourses(Departments $department): bool
    {
        $activeCurriculumIds = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id');

        if ($activeCurriculumIds->isEmpty()) {
            return false;
        }

        return Course::query()
            ->whereHas('curriculum', fn ($scope) => $scope->whereIn('curriculum.id', $activeCurriculumIds))
            ->where('course_category', 'major')
            ->where('lecture_hours', '>', 0)
            ->where(fn ($scope) => $scope->where('lab_hours', 0)->orWhereNull('lab_hours'))
            ->exists();
    }

    /**
     * When a section is in hand, its own curriculum is the answer — that is the
     * course list the user is configuring against. Only the department-wide
     * question (no section) falls back to scanning the department's active
     * curricula, and then any of them will do because the caller is asking
     * whether such a course exists at all, not where it sits.
     */
    private function activeCurriculum(Departments $department, ?Sections $section = null): ?Curriculum
    {
        if ($section?->curriculum_id !== null) {
            return Curriculum::query()->find((int) $section->curriculum_id);
        }

        return Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->orderByDesc('effective_school_year')
            ->first();
    }

    private function forcedDayCourses(Departments $department, ?Sections $section = null): array
    {
        $activeCurriculum = $this->activeCurriculum($department, $section);

        if (! $activeCurriculum) {
            return [];
        }

        $query = $activeCurriculum->courses()
            ->where('status', 'active')
            ->when($section, fn ($query) => $query
                ->where('curriculum_course.semester', $this->mapSemesterToPivotValue((string) $section->semester))
                ->where('curriculum_course.year_level', (int) $section->year_level))
            ->orderBy('course_code');

        return $query
            ->get(['courses.id', 'course_code', 'course_name'])
            ->map(static fn ($course): array => [
                'id' => (int) $course->id,
                'code' => (string) $course->course_code,
                'name' => (string) $course->course_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Derived from whether the department has any field courses configured, so
     * removing the last one turns the behaviour off again. The stored flag it
     * replaced could only ever be set to true (audit finding #35).
     */
    private function fieldCourseAssignmentEnabled(Departments $department): bool
    {
        return SchedulingPolicy::fieldCourseSettingEnabled((int) $department->id);
    }

    private function fieldCourseOptions(Departments $department, ?Sections $section = null): array
    {
        $activeCurriculum = $this->activeCurriculum($department, $section);

        if (! $activeCurriculum) {
            return [];
        }

        $query = $activeCurriculum->courses()
            ->where('courses.status', 'active')
            ->when($section, fn ($query) => $query
                ->where('curriculum_course.semester', $this->mapSemesterToPivotValue((string) $section->semester))
                ->where('curriculum_course.year_level', (int) $section->year_level))
            ->orderBy('course_code');

        return $query
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

    private function fieldCourseCodes(Departments $department, ?array $scopedOptions = null): array
    {
        $codes = DepartmentCourseRules::fieldCourseCodes((int) $department->id);

        if ($scopedOptions !== null) {
            $allowedCodes = collect($scopedOptions)
                ->pluck('code')
                ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
                ->all();
            $codes = array_values(array_intersect($codes, $allowedCodes));
        }

        return $codes;
    }

    private function forcedDayRules(Departments $department, ?Sections $section = null): array
    {
        $query = DepartmentCourseRules::query((int) $department->id)
            ->whereNotNull('forced_day');

        if ($section !== null) {
            $query->whereIn(
                'course_id',
                collect($this->forcedDayCourses($department, $section))->pluck('id')->all(),
            );
        }

        return $query
            ->orderBy('course_id')
            ->get(['course_id', 'forced_day'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'day' => (string) $rule->forced_day,
            ])
            ->values()
            ->all();
    }

    /**
     * Every Consecutive Days rule for the courses in scope: course-wide
     * (section_id null) and per section.
     *
     * @return list<array{course_id: int, section_id: int|null, day_count: int, preferred_start_day: string|null, meeting_days: list<string>|null}>
     */
    private function consecutiveDayRules(Departments $department, ?Sections $section = null): array
    {
        return DepartmentCourseRules::query((int) $department->id)
            ->whereNotNull('consecutive_day_count')
            ->when($section !== null, fn ($query) => $query->whereIn(
                'course_id',
                collect($this->forcedDayCourses($department, $section))->pluck('id')->all(),
            ))
            ->orderBy('course_id')
            ->orderBy('section_id')
            ->get(['course_id', 'section_id', 'consecutive_day_count', 'preferred_start_day', 'meeting_days'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'section_id' => $rule->section_id === null ? null : (int) $rule->section_id,
                'day_count' => (int) $rule->consecutive_day_count,
                'preferred_start_day' => $rule->preferred_start_day === null ? null : (string) $rule->preferred_start_day,
                'meeting_days' => SchedulingPolicy::parseMeetingDays($rule->meeting_days),
            ])
            ->values()
            ->all();
    }

    private function mapSemesterToPivotValue(string $semester): int
    {
        return match ($semester) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => abort(422, "Unsupported semester '{$semester}' for generation constraints."),
        };
    }
}
