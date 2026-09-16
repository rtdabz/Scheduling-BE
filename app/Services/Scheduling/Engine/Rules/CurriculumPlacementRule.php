<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * semester_enabled, section_semester_alignment, section_semester_period_alignment,
 * subject_section_semester_alignment, subject_section_year_alignment.
 *
 * Whether the course belongs in this section's semester and year level, read
 * from the curriculum the section follows.
 */
final class CurriculumPlacementRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /** @return list<array<string, mixed>> */
    public function check(AttemptRecords $records): array
    {
        $violations = [];
        $semester = $records->semester;
        $section = $records->section;
        [$courseYearLevel, $courseSemester] = $this->placement($section, $records->course);

        if (! (bool) ($semester->is_enabled ?? true)) {
            $violations[] = [
                'rule' => 'semester_enabled',
                'message' => 'Selected academic semester is disabled for scheduling.',
            ];
        }

        if ((int) $section->semester_id !== (int) $semester->id) {
            $violations[] = [
                'rule' => 'section_semester_alignment',
                'message' => 'Selected section does not belong to the selected academic semester.',
            ];
        }

        if ((string) $section->semester !== (string) $semester->semester) {
            $violations[] = [
                'rule' => 'section_semester_period_alignment',
                'message' => 'Section semester does not match the academic semester.',
            ];
        }

        if ($courseSemester !== (string) $section->semester) {
            $violations[] = [
                'rule' => 'subject_section_semester_alignment',
                'message' => 'Course semester does not match the selected section semester.',
            ];
        }

        if ($courseYearLevel !== (string) $section->year_level) {
            $violations[] = [
                'rule' => 'subject_section_year_alignment',
                'message' => 'Course year level does not match the selected section year level.',
            ];
        }

        return $violations;
    }

    /**
     * The year level and semester a course holds for this section's cohort.
     *
     * The curriculum comes from the section (SectionCurriculumResolver), never
     * from "the department's active curriculum": a department mid-transition
     * runs several. When the section has no usable curriculum, or the curriculum
     * does not place the course, the course row's catalogue default applies, as
     * it always did. The cached Course model is never written to, because the
     * same instance is reused for sections that follow a different curriculum.
     *
     * @return array{0: string, 1: string}
     */
    private function placement(Sections $section, Course $course): array
    {
        $curriculumId = $this->lookups->remember('section-curriculum:'.$section->id, function () use ($section): ?int {
            try {
                return (int) app(SectionCurriculumResolver::class)->forSection($section)->id;
            } catch (InvalidArgumentException) {
                return null;
            }
        });

        $placement = $curriculumId === null ? null : $this->lookups->remember(
            'curriculum_course:'.$curriculumId.':'.$course->id,
            fn () => DB::table('curriculum_course')
                ->where('curriculum_id', $curriculumId)
                ->where('course_id', $course->id)
                ->first(),
        );

        if ($placement === null) {
            return [(string) $course->year_level, (string) $course->semester];
        }

        return [
            (string) $placement->year_level,
            match ((string) $placement->semester) {
                '1' => '1st',
                '2' => '2nd',
                default => 'summer',
            },
        ];
    }
}
