<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Schedule\SectionCurriculumResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

/**
 * The one place a generation request's course selection is resolved.
 * Extracted from ScheduleRecommendationController, where five endpoints each
 * repeated this logic and had drifted apart.
 */
final class GenerationCourseSelection
{
    public function __construct(private readonly SectionCurriculumResolver $curriculumResolver) {}

    /**
     * Turns one section's generation request into the course selection the
     * solver receives. Every generation endpoint -- section preview, queued
     * preview, select, and both year-level paths -- goes through here, so a
     * section is configured the same way whichever screen generated it.
     *
     * Minor (balanced) splits and Hybrid Splits are both fixed two-meeting
     * shapes, so a preferred pattern set on either is kept; any other course's
     * pattern is dropped. (The section-level paths used to drop a Hybrid
     * Split's pattern while year-level kept it, so the same course generated
     * differently depending on the screen.)
     *
     * @param  array<string, mixed>  $config  course_ids, selected_split_session_course_ids,
     *                                        selected_gec_course_ids, hybrid_split_course_ids,
     *                                        preferred_patterns, is_hybrid
     * @return array{course_ids: list<int>, selected_split_session_course_ids: list<int>, is_hybrid: bool, balanced_split_course_ids: list<int>, hybrid_split_course_ids: list<int>, preferred_patterns: array<int, mixed>}
     */
    public function resolve(Sections $section, array $config): array
    {
        $courseIds = $this->resolveCourseIds($section, $config['course_ids'] ?? null);
        $splitIds = $this->resolveLectureLabSplitCourseIds($section, $config['selected_split_session_course_ids'] ?? [], $courseIds);
        $hybridSplitIds = $this->resolveHybridSplitCourseIds($config['hybrid_split_course_ids'] ?? [], $courseIds);
        $balancedSplitIds = array_values(array_unique([
            ...$this->resolveMinorSplitCourseIds($section, $config['selected_gec_course_ids'] ?? [], $courseIds),
            ...$hybridSplitIds,
        ]));

        return [
            'course_ids' => $courseIds,
            'selected_split_session_course_ids' => $splitIds,
            'is_hybrid' => $this->resolvedHybridMode($splitIds, $config['is_hybrid'] ?? false),
            'balanced_split_course_ids' => $balancedSplitIds,
            'hybrid_split_course_ids' => $hybridSplitIds,
            'preferred_patterns' => $this->mergeSelectedSplitPatterns($config['preferred_patterns'] ?? [], $balancedSplitIds, $courseIds),
        ];
    }
    public function resolveCourseIds(Sections $section, ?array $providedCourseIds): array
    {
        // The curriculum is a property of the cohort, not of the department: a
        // department mid-transition runs the old and the new one at once.
        $curriculum = $this->curriculumResolver->forSection($section);

        $period = $this->mapSemesterToInt($section->semester);
        $courseQuery = $curriculum->courses()
            ->wherePivot('year_level', (int) $section->year_level)
            ->wherePivot('semester', $period)
            ->where('courses.status', 'active');

        if (! empty($providedCourseIds)) {
            $courseQuery->whereIn('courses.id', array_map('intval', $providedCourseIds));
        }

        $courseIds = $courseQuery->pluck('courses.id')->toArray();

        if (! empty($courseIds)) {
            return $courseIds;
        }

        if (! empty($providedCourseIds)) {
            throw new InvalidArgumentException(sprintf(
                'The selected courses are not active entries in "%s" for year %s, %s semester.',
                (string) $curriculum->name,
                (string) $section->year_level,
                (string) $section->semester,
            ));
        }

        throw new InvalidArgumentException(sprintf(
            'Curriculum "%s" has no courses for year %s, %s semester. Add courses to that year level, or point the section at a different curriculum.',
            (string) $curriculum->name,
            (string) $section->year_level,
            (string) $section->semester,
        ));
    }

    private function mapSemesterToInt(string $period): int
    {
        return match ($period) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => throw new InvalidArgumentException("Unrecognized semester '{$period}'."),
        };
    }

    private function resolveLectureLabSplitCourseIds(Sections $section, array $requestedCourseIds, array $validCourseIds): array
    {
        $candidateIds = array_values(array_intersect(
            array_map('intval', $requestedCourseIds),
            array_map('intval', $validCourseIds),
        ));

        if ($candidateIds === []) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $candidateIds)
            ->get()
            ->filter(static fn (Course $course): bool => SchedulingPolicy::isMajorCourse($course)
                && ! SchedulingPolicy::isFieldCourse($course, (int) $section->department_id)
                && (int) $course->lecture_hours > 0
                && (int) $course->lab_hours > 0)
            ->pluck('id')
            ->map(static fn ($courseId): int => (int) $courseId)
            ->values()
            ->all();
    }

    private function resolveMinorSplitCourseIds(Sections $section, array $requestedCourseIds, array $validCourseIds): array
    {
        $splitSettings = SchedulingPolicy::balancedSplitSettings($section->department);

        $candidateIds = array_values(array_intersect(
            array_map('intval', $requestedCourseIds),
            array_map('intval', $validCourseIds),
        ));

        if ($candidateIds === []) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $candidateIds)
            ->get()
            ->filter(static fn (Course $course): bool => SchedulingPolicy::balancedSplitEligible($course, $splitSettings))
            ->pluck('id')
            ->map(static fn ($courseId): int => (int) $courseId)
            ->values()
            ->all();
    }

    /** @param list<int|string> $requestedCourseIds @param list<int|string> $validCourseIds */
    private function resolveHybridSplitCourseIds(array $requestedCourseIds, array $validCourseIds): array
    {
        $candidateIds = array_values(array_intersect(
            array_map('intval', $requestedCourseIds),
            array_map('intval', $validCourseIds),
        ));

        if ($candidateIds === []) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $candidateIds)
            ->get()
            ->filter(static fn (Course $course): bool => SchedulingPolicy::hybridSplitEligible($course))
            ->pluck('id')
            ->map(static fn ($courseId): int => (int) $courseId)
            ->values()
            ->all();
    }

    private function mergeSelectedSplitPatterns(array $preferredPatterns, array $selectedCourseIds, array $validCourseIds): array
    {
        $validCourseIds = array_flip(array_map('intval', $validCourseIds));
        $selectedCourseIds = array_flip(array_map('intval', $selectedCourseIds));
        $merged = [];

        foreach ($preferredPatterns as $courseId => $preferredPattern) {
            $courseId = (int) $courseId;
            if ($courseId <= 0 || ! isset($validCourseIds[$courseId]) || ! isset($selectedCourseIds[$courseId])) {
                continue;
            }

            $merged[$courseId] = $preferredPattern;
        }

        return $merged;
    }

    /** @param array<int, int|string> $selectedLectureLabCourseIds */
    private function resolvedHybridMode(array $selectedLectureLabCourseIds, mixed $requested): bool
    {
        return (bool) filter_var($requested, FILTER_VALIDATE_BOOLEAN)
            || array_values(array_filter(array_map('intval', $selectedLectureLabCourseIds), static fn (int $id): bool => $id > 0)) !== [];
    }
}
