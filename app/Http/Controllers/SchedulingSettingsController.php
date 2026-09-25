<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
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
            'forced_day_rules' => 'sometimes|array',
            'forced_day_rules.*.course_id' => 'required|integer|exists:courses,id',
            'forced_day_rules.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'consecutive_day_rules' => 'sometimes|array',
            'consecutive_day_rules.*.course_id' => 'required|integer|exists:courses,id',
            'consecutive_day_rules.*.section_id' => 'nullable|integer|exists:sections,id',
            'consecutive_day_rules.*.day_count' => 'required|integer|min:'.SchedulingPolicy::MIN_CONSECUTIVE_DAYS.'|max:'.count(SchedulingPolicy::DAYS),
            'consecutive_day_rules.*.preferred_start_day' => SchedulingPolicy::allowedDaysRule('nullable'),
            'field_course_codes' => 'sometimes|array',
            'field_course_codes.*' => 'required|string|max:255',
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
        if (! $sundayClassesEnabled
            && collect($validated['forced_day_rules'] ?? [])->contains(static fn (array $rule): bool => $rule['day'] === 'Sunday')) {
            return response()->json([
                'message' => 'Sunday classes are not enabled for this department, so no course can have Sunday as its Required Day.',
            ], 422);
        }

        $consecutiveError = $this->consecutiveDayRulesError($department, $section, $validated, $sundayClassesEnabled);
        if ($consecutiveError !== null) {
            return response()->json(['message' => $consecutiveError], 422);
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
        // Turning Sunday off leaves classes already on Sunday in place; the
        // sunday_classes rule only refuses new Sunday placements.
        $department->sunday_classes_enabled = $sundayClassesEnabled;
        $department->save();

        if (array_key_exists('forced_day_rules', $validated)) {
            $this->syncForcedDayRules($department, $validated['forced_day_rules'], $section);
        }
        if (array_key_exists('consecutive_day_rules', $validated)) {
            $this->syncConsecutiveDayRules($department, $validated['consecutive_day_rules'], $section);
        }
        if (array_key_exists('field_course_codes', $validated)) {
            $this->syncFieldCourseCodes($department, $validated['field_course_codes'], $section);
        }

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
        $query = DB::table('field_course_settings')
            ->whereNotNull('course_code')
            ->where(fn ($scope) => $scope
                ->whereNull('department_id')
                ->orWhere('department_id', $department->id))
            ->orderBy('course_code');

        if ($scopedOptions !== null) {
            $allowedCodes = collect($scopedOptions)
                ->pluck('code')
                ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
                ->all();
            $query->whereIn('course_code', $allowedCodes);
        }

        return $query
            ->pluck('course_code')
            ->map(static fn ($courseCode): string => (string) $courseCode)
            ->values()
            ->all();
    }

    private function syncFieldCourseCodes(Departments $department, array $courseCodes, ?Sections $section = null): void
    {
        $allowedCodes = collect($this->fieldCourseOptions($department, $section))
            ->pluck('code')
            ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
            ->all();
        $allowedCodeMap = array_fill_keys($allowedCodes, true);

        DB::transaction(function () use ($department, $courseCodes, $allowedCodeMap): void {
            // Scoped to this department: the delete used to clear every
            // department's row for the same code (audit finding #34).
            DB::table('field_course_settings')
                ->where('department_id', $department->id)
                ->whereNotNull('course_code')
                ->whereIn('course_code', array_keys($allowedCodeMap))
                ->delete();

            $rows = [];
            foreach ($courseCodes as $courseCode) {
                $courseCode = SchedulingPolicy::normalizeCourseCode((string) $courseCode);
                if ($courseCode === '' || ! isset($allowedCodeMap[$courseCode])) {
                    continue;
                }

                $rows[$courseCode] = [
                    'department_id' => $department->id,
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

    private function forcedDayRules(Departments $department, ?Sections $section = null): array
    {
        $query = DB::table('department_forced_course_days')
            ->where('department_id', $department->id);

        if ($section !== null) {
            $query->whereIn(
                'course_id',
                collect($this->forcedDayCourses($department, $section))->pluck('id')->all(),
            );
        }

        return $query
            ->orderBy('course_id')
            ->get(['course_id', 'day'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'day' => (string) $rule->day,
            ])
            ->values()
            ->all();
    }

    private function syncForcedDayRules(Departments $department, array $rules, ?Sections $section = null): void
    {
        $allowedCourseIds = collect($this->forcedDayCourses($department, $section))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $allowedCourseIdMap = array_fill_keys($allowedCourseIds, true);

        DB::transaction(function () use ($department, $rules, $allowedCourseIdMap): void {
            DB::table('department_forced_course_days')
                ->where('department_id', $department->id)
                ->whereIn('course_id', array_keys($allowedCourseIdMap))
                ->delete();

            $rows = [];
            foreach ($rules as $rule) {
                $courseId = (int) $rule['course_id'];
                if (! isset($allowedCourseIdMap[$courseId])) {
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

    /**
     * Every Consecutive Days rule for the courses in scope: course-wide
     * (section_id null) and per section.
     *
     * @return list<array{course_id: int, section_id: int|null, day_count: int, preferred_start_day: string|null}>
     */
    private function consecutiveDayRules(Departments $department, ?Sections $section = null): array
    {
        return DB::table('course_consecutive_day_rules')
            ->where('department_id', $department->id)
            ->when($section !== null, fn ($query) => $query->whereIn(
                'course_id',
                collect($this->forcedDayCourses($department, $section))->pluck('id')->all(),
            ))
            ->orderBy('course_id')
            ->orderBy('section_id')
            ->get(['course_id', 'section_id', 'day_count', 'preferred_start_day'])
            ->map(static fn ($rule): array => [
                'course_id' => (int) $rule->course_id,
                'section_id' => $rule->section_id === null ? null : (int) $rule->section_id,
                'day_count' => (int) $rule->day_count,
                'preferred_start_day' => $rule->preferred_start_day === null ? null : (string) $rule->preferred_start_day,
            ])
            ->values()
            ->all();
    }

    /**
     * Why the requested Consecutive Days rules cannot be saved, or null.
     *
     * A run must fit the department's week (Sunday only when it is open), and
     * a course cannot have both a Required Day and Consecutive Days: the
     * Required Day holds every meeting to that one day.
     *
     * @param  array<string, mixed>  $validated
     */
    private function consecutiveDayRulesError(Departments $department, ?Sections $section, array $validated, bool $sundayClassesEnabled): ?string
    {
        $touchesRules = array_key_exists('consecutive_day_rules', $validated);
        $touchesRequiredDays = array_key_exists('forced_day_rules', $validated);
        if (! $touchesRules && ! $touchesRequiredDays) {
            return null;
        }

        $rules = $validated['consecutive_day_rules'] ?? [];
        $touchedCourseIds = array_values(array_unique(array_map(
            static fn (array $rule): int => (int) $rule['course_id'],
            [...$rules, ...($validated['forced_day_rules'] ?? [])],
        )));
        $codes = Course::query()->whereIn('id', $touchedCourseIds)->pluck('course_code', 'id');
        $code = static fn (int $courseId): string => (string) ($codes[$courseId] ?? "Course {$courseId}");

        $sectionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $rule): int => (int) ($rule['section_id'] ?? 0),
            $rules,
        ))));
        if ($sectionIds !== []
            && Sections::query()->where('department_id', $department->id)->whereIn('id', $sectionIds)->count() !== count($sectionIds)) {
            return 'Consecutive Days can only be set for this department\'s own sections.';
        }

        $teachingDays = SchedulingPolicy::teachingDays($sundayClassesEnabled);
        $week = $sundayClassesEnabled ? 'Monday-Sunday' : 'Monday-Saturday';
        foreach ($rules as $rule) {
            $dayCount = (int) $rule['day_count'];
            $startDay = $rule['preferred_start_day'] ?? null;
            if ($dayCount > count($teachingDays)) {
                return sprintf(
                    '%s: %d consecutive days do not fit the %s teaching week.%s',
                    $code((int) $rule['course_id']),
                    $dayCount,
                    $week,
                    $sundayClassesEnabled ? '' : ' Choose fewer days, or ask the department secretary to enable Sunday classes.',
                );
            }
            $startIndex = $startDay === null ? false : array_search($startDay, $teachingDays, true);
            if ($startDay !== null && ($startIndex === false || $startIndex + $dayCount > count($teachingDays))) {
                return sprintf(
                    '%s: %d consecutive days starting %s run past the end of the %s teaching week. Choose an earlier starting day or fewer days.',
                    $code((int) $rule['course_id']),
                    $dayCount,
                    $startDay,
                    $week,
                );
            }
        }

        // The Required Days and Consecutive Days rules as they will stand after
        // this save, the same way the two syncs replace the courses in scope.
        $scopeCourseIds = $this->ruleScopeCourseIds($department, $section);
        $inScope = static fn (int $courseId): bool => isset($scopeCourseIds[$courseId]);

        $requiredDays = SchedulingPolicy::forcedCourseDayMap((int) $department->id);
        if ($touchesRequiredDays) {
            $requiredDays = array_filter($requiredDays, static fn (string $day, int $courseId): bool => ! $inScope($courseId), ARRAY_FILTER_USE_BOTH);
            foreach ($validated['forced_day_rules'] as $rule) {
                $requiredDays[(int) $rule['course_id']] = (string) $rule['day'];
            }
        }

        $consecutiveCourseIds = DB::table('course_consecutive_day_rules')
            ->where('department_id', $department->id)
            ->pluck('course_id')
            ->map(static fn ($courseId): int => (int) $courseId)
            ->all();
        if ($touchesRules) {
            $consecutiveCourseIds = [
                ...array_filter($consecutiveCourseIds, static fn (int $courseId): bool => ! $inScope($courseId)),
                ...array_map(static fn (array $rule): int => (int) $rule['course_id'], $rules),
            ];
        }

        // Only a course this request touches is checked, so an unrelated save
        // is never refused over rules already stored.
        foreach (array_unique($consecutiveCourseIds) as $courseId) {
            if (isset($requiredDays[$courseId]) && $inScope($courseId) && in_array($courseId, $touchedCourseIds, true)) {
                return sprintf(
                    '%s has a Required Day of %s, so it cannot also meet on consecutive days. Clear its Required Day, or tick its meeting days instead.',
                    $code($courseId),
                    $requiredDays[$courseId],
                );
            }
        }

        return null;
    }

    /**
     * The courses a settings save may set rules for: the active curriculum's,
     * narrowed to the section's year and semester when one is given. The same
     * scope Required Day rules sync within.
     *
     * @return array<int, true>
     */
    private function ruleScopeCourseIds(Departments $department, ?Sections $section): array
    {
        return array_fill_keys(
            array_map('intval', collect($this->forcedDayCourses($department, $section))->pluck('id')->all()),
            true,
        );
    }

    /**
     * Replaces the rules of every course in scope with the ones sent, the way
     * Required Day rules are synced. One rule per course and section: a later
     * duplicate wins.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    private function syncConsecutiveDayRules(Departments $department, array $rules, ?Sections $section = null): void
    {
        $scopeCourseIds = $this->ruleScopeCourseIds($department, $section);

        DB::transaction(function () use ($department, $rules, $scopeCourseIds): void {
            DB::table('course_consecutive_day_rules')
                ->where('department_id', $department->id)
                ->whereIn('course_id', array_keys($scopeCourseIds))
                ->delete();

            $rows = [];
            foreach ($rules as $rule) {
                $courseId = (int) $rule['course_id'];
                if (! isset($scopeCourseIds[$courseId])) {
                    continue;
                }

                $sectionId = isset($rule['section_id']) ? (int) $rule['section_id'] : null;
                $rows[$courseId.':'.($sectionId ?? 'all')] = [
                    'department_id' => $department->id,
                    'course_id' => $courseId,
                    'section_id' => $sectionId,
                    'day_count' => (int) $rule['day_count'],
                    'preferred_start_day' => $rule['preferred_start_day'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('course_consecutive_day_rules')->insert(array_values($rows));
            }
        });
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
