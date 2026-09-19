<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Models\Rooms;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How a department shares its physical rooms between its sections: per-section
 * targets for regular and laboratory physical meetings and for online ones,
 * scaled by how scarce rooms are, and the soft penalty for a solution that
 * drifts from them. Soft only -- it ranks solutions, it never refuses one.
 *
 * The solver owns the per-section delivery counts of already-placed meetings
 * (it builds them while indexing existing schedules) and passes them in.
 * prepare() reads department-wide demand live; that data is not in the
 * scheduling snapshot (see docs/architecture.md).
 *
 * Extracted from CspSolver unchanged apart from names.
 */
final class DepartmentRoomFairness
{
    /** @var array{active_sections: int, physical_rooms: int, target_physical_ratio: float, scarcity_multiplier: float, section_regular_physical_targets?: array<int, int>, section_lab_physical_targets?: array<int, int>, section_online_targets?: array<int, int>} */
    private array $targets = self::NEUTRAL;

    private const NEUTRAL = [
        'active_sections' => 1,
        'physical_rooms' => 0,
        'target_physical_ratio' => 1.0,
        'scarcity_multiplier' => 0.0,
        'section_regular_physical_targets' => [],
        'section_lab_physical_targets' => [],
        'section_online_targets' => [],
    ];

    /** Back to no targets, as before any prepare(). */
    public function reset(): void
    {
        $this->targets = self::NEUTRAL;
    }

    /** The current targets, for year-level diagnostics. */
    public function toArray(): array
    {
        return $this->targets;
    }

    public function prepare(Sections $section, Collection $rooms): void
    {
        $activeSections = Sections::query()
            ->where('department_id', (int) $section->department_id)
            ->where('semester_id', (int) $section->semester_id)
            ->where('status', 'active')
            ->get(['id', 'year_level', 'semester']);

        $activeSectionCount = $activeSections->count();

        $physicalRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => ! in_array((string) $room->room_type, ['online', 'field'], true))
            ->count();
        $lectureRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => (string) $room->room_type === 'lecture')
            ->count();
        $laboratoryRoomCount = $rooms
            ->filter(static fn (Rooms $room): bool => (string) $room->room_type === 'laboratory')
            ->count();

        $demandBySection = $this->departmentDemandBySection(
            departmentId: (int) $section->department_id,
            sections: $activeSections,
        );

        $activeSectionCount = max(1, $activeSectionCount);
        $physicalRoomCount = max(0, $physicalRoomCount);
        $totalRegularDemand = array_sum(array_column($demandBySection, 'regular'));
        $totalLabDemand = array_sum(array_column($demandBySection, 'lab'));

        $regularPhysicalRatio = $this->physicalDemandRatio(
            roomCount: $lectureRoomCount,
            sectionCount: $activeSectionCount,
            totalDemand: $totalRegularDemand,
        );
        $labPhysicalRatio = $laboratoryRoomCount > 0 ? 1.0 : 0.0;
        $targetPhysicalRatio = $totalRegularDemand + $totalLabDemand > 0
            ? (($regularPhysicalRatio * $totalRegularDemand) + ($labPhysicalRatio * $totalLabDemand))
                / max(1, $totalRegularDemand + $totalLabDemand)
            : 1.0;
        $scarcityMultiplier = max(0.0, 1.0 - $targetPhysicalRatio);

        $this->targets = [
            'active_sections' => $activeSectionCount,
            'physical_rooms' => $physicalRoomCount,
            'target_physical_ratio' => $targetPhysicalRatio,
            'scarcity_multiplier' => $scarcityMultiplier,
            'section_regular_physical_targets' => $this->physicalTargetsBySection($demandBySection, 'regular', $regularPhysicalRatio),
            'section_lab_physical_targets' => $this->physicalTargetsBySection($demandBySection, 'lab', $labPhysicalRatio),
            'section_online_targets' => $this->onlineTargetsBySection(
                demandBySection: $demandBySection,
                regularPhysicalTargets: $this->physicalTargetsBySection($demandBySection, 'regular', $regularPhysicalRatio),
            ),
        ];
    }

    /**
     * @param  Collection<int, Sections>  $sections
     * @return array<int, array{regular: int, lab: int}>
     */
    private function departmentDemandBySection(int $departmentId, Collection $sections): array
    {
        $demand = [];
        foreach ($sections as $section) {
            $demand[(int) $section->id] = ['regular' => 0, 'lab' => 0];
        }

        if ($sections->isEmpty()) {
            return $demand;
        }

        // Room demand is per cohort, so it has to be counted against the
        // curriculum each section actually follows. Counting the whole
        // department against one curriculum understates lab demand for every
        // section still on the old one, and the solver then over-commits rooms.
        $curriculumIds = $sections
            ->pluck('curriculum_id')
            ->filter()
            ->map('intval')
            ->unique()
            ->values()
            ->all();

        if ($curriculumIds === []) {
            return $demand;
        }

        $semesterMap = [
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
        ];
        $sectionsByPeriod = $sections->groupBy(
            static fn (Sections $section): string => (string) ($section->curriculum_id ?? 0)
                .'|'.(string) $section->year_level
                .'|'.(string) ($semesterMap[(string) $section->semester] ?? $section->semester),
        );

        $courses = DB::table('curriculum_course')
            ->join('courses', 'courses.id', '=', 'curriculum_course.course_id')
            ->whereIn('curriculum_course.curriculum_id', $curriculumIds)
            ->where('courses.status', 'active')
            ->get([
                'curriculum_course.curriculum_id',
                'curriculum_course.year_level',
                'curriculum_course.semester',
                'courses.lecture_hours',
                'courses.lab_hours',
                'courses.room_type_required',
                'courses.course_code',
            ]);

        foreach ($courses as $course) {
            $periodKey = (string) $course->curriculum_id.'|'.(string) $course->year_level.'|'.(string) $course->semester;
            $matchingSections = $sectionsByPeriod->get($periodKey);
            if ($matchingSections === null) {
                continue;
            }

            $lectureHours = (int) ($course->lecture_hours ?? 0);
            $labHours = (int) ($course->lab_hours ?? 0);
            if ($lectureHours <= 0 && $labHours <= 0) {
                continue;
            }

            $roomType = (string) ($course->room_type_required ?? 'lecture');
            $courseCode = (string) ($course->course_code ?? '');
            if ($roomType === 'field' || preg_match('/\b(?:NSTP|ROTC|CWTS|LTS)\b/i', $courseCode) === 1) {
                continue;
            }

            foreach ($matchingSections as $matchingSection) {
                $matchingSectionId = (int) $matchingSection->id;

                if ($labHours > 0 || $roomType === 'laboratory') {
                    $demand[$matchingSectionId]['lab']++;

                    if ($lectureHours > 0) {
                        $demand[$matchingSectionId]['regular']++;
                    }

                    continue;
                }

                $demand[$matchingSectionId]['regular']++;
            }
        }

        return $demand;
    }

    private function physicalDemandRatio(int $roomCount, int $sectionCount, int $totalDemand): float
    {
        if ($totalDemand <= 0) {
            return 1.0;
        }

        $roomShare = $roomCount / max(1, $sectionCount);
        // Estimate meeting capacity from the actual operating window and the
        // shortest standard schedulable block. This is a fairness heuristic,
        // not a per-section daily course limit.
        $teachingDays = count(SchedulingPolicy::WEEKDAYS_AND_SATURDAY);
        $minimumBlockSlots = min(self::CLASSROOM_SCHEDULABLE_BLOCK_SLOTS);
        $meetingsPerRoomDay = max(1, intdiv(SchedulingPolicy::totalSlots(), $minimumBlockSlots));
        $demandShare = ($roomCount * $teachingDays * $meetingsPerRoomDay) / max(1, $totalDemand);

        return max(0.35, min(1.0, max($roomShare, $demandShare)));
    }

    /**
     * @param  array<int, array{regular: int, lab: int}>  $demandBySection
     * @return array<int, int>
     */
    private function physicalTargetsBySection(array $demandBySection, string $bucket, float $ratio): array
    {
        $targets = [];

        foreach ($demandBySection as $sectionId => $demand) {
            $sectionDemand = max(0, (int) ($demand[$bucket] ?? 0));
            $targets[(int) $sectionId] = $sectionDemand > 0
                ? ($ratio <= 0.0 ? 0 : max(1, (int) round($sectionDemand * $ratio)))
                : 0;
        }

        return $targets;
    }

    /**
     * @param  array<int, array{regular: int, lab: int}>  $demandBySection
     * @param  array<int, int>  $regularPhysicalTargets
     * @return array<int, int>
     */
    private function onlineTargetsBySection(array $demandBySection, array $regularPhysicalTargets): array
    {
        $targets = [];

        foreach ($demandBySection as $sectionId => $demand) {
            $regularDemand = max(0, (int) ($demand['regular'] ?? 0));
            $regularPhysicalTarget = max(0, (int) ($regularPhysicalTargets[$sectionId] ?? 0));
            $targets[(int) $sectionId] = max(0, $regularDemand - $regularPhysicalTarget);
        }

        return $targets;
    }

    public function minimumOnlineTarget(int $sectionId, array $existingSectionDeliveryCounts): int
    {
        $onlineTargets = $this->targets['section_online_targets'] ?? [];
        $target = max(0, (int) ($onlineTargets[$sectionId] ?? 0));
        $existingOnline = max(0, (int) ($existingSectionDeliveryCounts[$sectionId]['online'] ?? 0));

        return max(0, $target - $existingOnline);
    }

    /**
     * @param  array<int, array{physical: int, online: int, protected_physical: int}>  $generatedDeliveryCountsBySection
     */
    public function penalty(array $generatedDeliveryCountsBySection, array $existingSectionDeliveryCounts): int
    {
        $targetPhysicalRatio = (float) $this->targets['target_physical_ratio'];
        $scarcityMultiplier = (float) $this->targets['scarcity_multiplier'];
        $regularPhysicalTargets = $this->targets['section_regular_physical_targets'] ?? [];
        $labPhysicalTargets = $this->targets['section_lab_physical_targets'] ?? [];
        $onlineTargets = $this->targets['section_online_targets'] ?? [];

        if ($targetPhysicalRatio >= 1.0 || $scarcityMultiplier <= 0.0) {
            return 0;
        }

        $penalty = 0;

        foreach ($generatedDeliveryCountsBySection as $sectionId => $generatedCounts) {
            $existingCounts = $existingSectionDeliveryCounts[$sectionId] ?? [
                'physical' => 0,
                'online' => 0,
            ];

            $generatedPhysical = max(0, (int) ($generatedCounts['physical'] ?? 0));
            $generatedOnline = max(0, (int) ($generatedCounts['online'] ?? 0));
            $protectedPhysical = max(0, (int) ($generatedCounts['protected_physical'] ?? 0));
            $regularPhysical = max(0, (int) ($generatedCounts['regular_physical'] ?? ($generatedPhysical - $protectedPhysical)));
            $regularTotal = max(0, $regularPhysical + $generatedOnline);

            if ($regularTotal > 0) {
                $allowedRegularPhysical = (int) ceil($regularTotal * $targetPhysicalRatio);
                $excessPhysicalBlocks = max(0, $regularPhysical - $allowedRegularPhysical);
                $penalty += (int) round($excessPhysicalBlocks * 18 * $scarcityMultiplier);
            }

            $regularTarget = max(0, (int) ($regularPhysicalTargets[$sectionId] ?? PHP_INT_MAX));
            if ($regularTarget !== PHP_INT_MAX && $regularPhysical > $regularTarget) {
                $penalty += (int) round(($regularPhysical - $regularTarget) * 240 * max(0.25, $scarcityMultiplier));
            }

            $labTarget = max(0, (int) ($labPhysicalTargets[$sectionId] ?? PHP_INT_MAX));
            if ($labTarget !== PHP_INT_MAX && $protectedPhysical > $labTarget) {
                $penalty += (int) round(($protectedPhysical - $labTarget) * 320 * max(0.25, $scarcityMultiplier));
            }

            if ($generatedPhysical === 0 && (($regularTarget + $labTarget) > 0)) {
                $penalty += 500;
            }

            $projectedSectionOnline = (int) ($existingCounts['online'] ?? 0) + $generatedOnline;
            $onlineTarget = max(0, (int) ($onlineTargets[$sectionId] ?? PHP_INT_MAX));
            if ($onlineTarget !== PHP_INT_MAX && $projectedSectionOnline > $onlineTarget) {
                $penalty += (int) round(($projectedSectionOnline - $onlineTarget) * 520 * max(0.25, $scarcityMultiplier));
            }

            if ($onlineTarget > 0 && $generatedOnline === 0 && $regularPhysical > $regularTarget) {
                $penalty += 300;
            }

            $projectedSectionPhysical = (int) $existingCounts['physical'] + $generatedPhysical;
            $allSectionPhysicalCounts = array_map(
                static fn (array $counts): int => (int) ($counts['physical'] ?? 0),
                $existingSectionDeliveryCounts,
            );
            $allSectionPhysicalCounts[$sectionId] = $projectedSectionPhysical;

            if (count($allSectionPhysicalCounts) > 1) {
                $averagePhysical = array_sum($allSectionPhysicalCounts) / count($allSectionPhysicalCounts);
                $excessOverAverage = max(0.0, $projectedSectionPhysical - $averagePhysical - 1.0);
                $penalty += (int) round($excessOverAverage * 4 * $scarcityMultiplier);
            }

            $allSectionOnlineCounts = array_map(
                static fn (array $counts): int => (int) ($counts['online'] ?? 0),
                $existingSectionDeliveryCounts,
            );
            $allSectionOnlineCounts[$sectionId] = $projectedSectionOnline;

            if (count($allSectionOnlineCounts) > 1) {
                $averageOnline = array_sum($allSectionOnlineCounts) / count($allSectionOnlineCounts);
                $excessOnlineOverAverage = max(0.0, $projectedSectionOnline - $averageOnline - 1.0);
                $penalty += (int) round($excessOnlineOverAverage * 180 * max(0.25, $scarcityMultiplier));
            }
        }

        return $penalty;
    }
}
