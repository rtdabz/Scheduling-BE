<?php

namespace App\Services\Scheduling\Generation;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

class ScheduleGenerationPreflightService
{
    public function __construct(
        private readonly SectionCurriculumResolver $curricula,
    ) {}

    /**
     * @param  list<int>  $courseIds
     * @param  array<string, mixed>  $options
     *
     * @throws ScheduleGenerationPreflightException
     */
    public function validate(Sections $section, array $courseIds, array $options = []): void
    {
        $courses = Course::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $courseIds))))
            ->orderBy('course_code')
            ->get();

        // Resource feasibility and fallbacks belong to configuration validation and
        // the solver. Course eligibility is independent of department classification.
        $issues = $this->commonIssues($section, $courses, $courseIds);
        if ($issues !== []) {
            throw new ScheduleGenerationPreflightException($issues);
        }
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

        if ($section->academicSemester === null || (string) $section->academicSemester->semester !== (string) $section->semester) {
            $issues[] = $this->issue(
                'invalid_curriculum_assignment',
                "Section {$section->section_name} does not match the academic semester.",
                $section,
                ['semester_id' => (int) $section->semester_id],
                'Correct the section semester or semester before generating.',
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
        if ($courses->isEmpty()) {
            return collect();
        }

        return $this->curricula->periods(
            (int) $this->curricula->forSection($section)->id,
            $courses->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
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
