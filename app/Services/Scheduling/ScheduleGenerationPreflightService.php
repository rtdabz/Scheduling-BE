<?php

namespace App\Services\Scheduling;

use App\Enums\DepartmentSchedulingProfile;
use App\Exceptions\ScheduleGenerationPreflightException;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleGenerationPreflightService
{
    public function __construct(
        private readonly DepartmentSchedulingProfileResolver $profiles,
    ) {}

    /**
     * @param  list<int>  $courseIds
     * @param  array<string, mixed>  $options
     *
     * @throws ScheduleGenerationPreflightException
     */
    public function validate(Sections $section, array $courseIds, array $options = []): DepartmentSchedulingProfile
    {
        $department = $section->department ?: Departments::query()->findOrFail((int) $section->department_id);
        $profile = $this->profiles->resolve($department);
        $courses = Course::query()
            ->with('categories')
            ->whereIn('id', array_values(array_unique(array_map('intval', $courseIds))))
            ->orderBy('course_code')
            ->get();

        $issues = [];
        $issues = [...$issues, ...$this->commonIssues($section, $courses, $courseIds)];

        if ($profile === DepartmentSchedulingProfile::STANDARD) {
            $issues = [...$issues, ...$this->standardIssues($section, $department, $courses, $options)];
        } else {
            $issues = [...$issues, ...$this->laboratoryIssues($section, $department, $courses, $options)];
        }

        if ($issues !== []) {
            throw new ScheduleGenerationPreflightException($issues, $profile);
        }

        return $profile;
    }

    /** @return list<array<string, mixed>> */
    private function commonIssues(Sections $section, Collection $courses, array $courseIds): array
    {
        $issues = [];
        $curriculumPeriods = $this->curriculumPeriods($section, $courses);
        $sectionSemester = $this->semesterPivotValue((string) $section->semester);

        if ((string) $section->status !== 'active') {
            $issues[] = $this->issue(
                'invalid_section_status',
                "Section {$section->section_name} is not active.",
                $section,
                ['status' => (string) $section->status],
                'Activate the section before generating its schedule.',
            );
        }

        if ($section->term === null || (string) $section->term->semester !== (string) $section->semester) {
            $issues[] = $this->issue(
                'invalid_curriculum_assignment',
                "Section {$section->section_name} does not match its academic term semester.",
                $section,
                ['term_id' => (int) $section->term_id],
                'Correct the section term or semester before generating.',
            );
        }

        $requested = array_values(array_unique(array_map('intval', $courseIds)));
        $loaded = $courses->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $missing = array_values(array_diff($requested, $loaded));

        foreach ($missing as $courseId) {
            $issues[] = $this->issue(
                code: 'invalid_curriculum_assignment',
                message: "Course {$courseId} could not be loaded for the selected section.",
                section: $section,
                context: ['course_id' => $courseId],
                action: 'Remove the course from the request or add it to the active curriculum period.',
            );
        }

        foreach ($courses as $course) {
            if ((string) $course->status !== 'active') {
                $issues[] = $this->courseIssue('invalid_course_status', "Course {$course->course_code} is not active.", $section, $course, 'Activate the course or remove it from the generation request.');
            }

            $curriculumPeriod = $curriculumPeriods->get((int) $course->id);
            if (
                $curriculumPeriod === null
                || (int) $curriculumPeriod->year_level !== (int) $section->year_level
                || (int) $curriculumPeriod->semester !== $sectionSemester
            ) {
                $issues[] = $this->courseIssue('invalid_curriculum_assignment', "Course {$course->course_code} does not match the section year level and semester.", $section, $course, 'Attach the course to the correct curriculum period.');
            }

            if (
                (string) $course->course_category === 'major'
                && $course->department_id !== null
                && (int) $course->department_id !== (int) $section->department_id
            ) {
                $issues[] = $this->courseIssue('invalid_curriculum_assignment', "Major course {$course->course_code} belongs to another department.", $section, $course, 'Assign the course to the correct department curriculum.');
            }

            $rawSlots = (float) ($course->units ?? 0) * 2;
            if ($rawSlots <= 0 || abs($rawSlots - round($rawSlots)) > 0.00001 || $rawSlots > SchedulingPolicy::totalSlots()) {
                $issues[] = $this->courseIssue('invalid_course_duration', "Course {$course->course_code} has a duration that cannot fit the scheduling grid.", $section, $course, 'Correct the course units and duration configuration.');
            }
        }

        return $issues;
    }

    private function curriculumPeriods(Sections $section, Collection $courses): Collection
    {
        $curriculumId = Curriculum::query()
            ->where('department_id', (int) $section->department_id)
            ->where('status', 'active')
            ->value('id');

        if ($curriculumId === null || $courses->isEmpty()) {
            return collect();
        }

        return DB::table('curriculum_course')
            ->where('curriculum_id', (int) $curriculumId)
            ->whereIn('course_id', $courses->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->get(['course_id', 'year_level', 'semester'])
            ->keyBy(static fn (object $period): int => (int) $period->course_id);
    }

    private function semesterPivotValue(string $semester): int
    {
        return match ($semester) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => 0,
        };
    }

    /** @return list<array<string, mixed>> */
    private function standardIssues(Sections $section, Departments $department, Collection $courses, array $options): array
    {
        $issues = [];
        $laboratoryCourses = $courses->filter(fn (Course $course): bool => SchedulingPolicy::isLaboratoryCourse($course));

        foreach ($laboratoryCourses as $course) {
            $issues[] = $this->courseIssue(
                'department_profile_mismatch',
                "Standard department {$department->department_code} contains laboratory course {$course->course_code}.",
                $section,
                $course,
                'Correct the course data or change the department profile to laboratory-enabled.',
            );
        }

        $labSettings = [
            'lecture_lab_schedule_override_enabled',
            'custom_lab_duration_override_enabled',
            'custom_lab_duration_6_hours_enabled',
            'custom_lab_duration_5_hours_enabled',
            'custom_lab_duration_other_enabled',
        ];
        foreach ($labSettings as $setting) {
            if ((bool) $department->{$setting}) {
                $issues[] = $this->issue(
                    'invalid_department_setting',
                    "Standard department {$department->department_code} has laboratory setting {$setting} enabled.",
                    $section,
                    ['setting' => $setting],
                    'Disable the laboratory setting before generating a standard schedule.',
                );
            }
        }

        if ($this->requiresPhysicalLectureRoom($courses, $options) && ! $this->hasRoom($section, 'lecture')) {
            $issues[] = $this->issue(
                'missing_lecture_room',
                "No eligible lecture room is available for standard department {$department->department_code}.",
                $section,
                ['room_type' => 'lecture'],
                'Assign an available lecture room to the department or mark a shared lecture room.',
            );
        }

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function laboratoryIssues(Sections $section, Departments $department, Collection $courses, array $options): array
    {
        $issues = [];
        $hasLaboratoryCourse = $courses->contains(fn (Course $course): bool => SchedulingPolicy::isLaboratoryCourse($course));

        // Missing laboratory rooms are handled by the solver's Room TBA
        // fallback and must not block generation at preflight.

        return $issues;
    }

    private function requiresPhysicalLectureRoom(Collection $courses, array $options): bool
    {
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = array_map('strval', $options['delivery_modes_by_course_id'] ?? []);

        return $courses->contains(function (Course $course) use ($defaultMode, $deliveryModes): bool {
            $mode = $deliveryModes[(string) $course->id] ?? $deliveryModes[(int) $course->id] ?? $defaultMode;

            return $mode === 'on-site' && ! SchedulingPolicy::isFieldCourse($course) && ! SchedulingPolicy::isLaboratoryCourse($course);
        });
    }

    private function requiresPhysicalLaboratoryRoom(Collection $courses, array $options): bool
    {
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = array_map('strval', $options['delivery_modes_by_course_id'] ?? []);

        return $courses->contains(function (Course $course) use ($defaultMode, $deliveryModes): bool {
            $mode = $deliveryModes[(string) $course->id] ?? $deliveryModes[(int) $course->id] ?? $defaultMode;

            return $mode === 'on-site' && SchedulingPolicy::isLaboratoryCourse($course);
        });
    }

    private function hasRoom(Sections $section, string $roomType): bool
    {
        return Rooms::query()
            ->where('status', 'available')
            ->where('room_type', $roomType)
            ->where(fn ($query) => $query
                ->whereNull('department_id')
                ->orWhere('department_id', (int) $section->department_id))
            ->exists();
    }

    /** @param array<string, mixed> $context */
    private function issue(string $code, string $message, Sections $section, array $context, string $action): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->section_name,
            'context' => $context,
            'suggested_action' => $action,
        ];
    }

    private function courseIssue(string $code, string $message, Sections $section, Course $course, string $action): array
    {
        return $this->issue($code, $message, $section, [
            'course_id' => (int) $course->id,
            'course_code' => (string) $course->course_code,
        ], $action);
    }
}
