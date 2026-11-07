<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Semester;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Http\JsonResponse;

trait EnforcesFacultyUnitCeiling
{
    protected function activeSemesterId(): ?int
    {
        $id = Semester::query()->where('is_active', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $projections  FacultyLoadService::projectLoad() results
     */
    protected function unitCeilingRefusal(array $projections): ?JsonResponse
    {
        $over = array_values(array_filter(
            $projections,
            static fn (array $projection): bool => (bool) ($projection['exceeds_ceiling'] ?? false),
        ));

        if ($over === []) {
            return null;
        }

        $describe = static fn (array $projection): string => sprintf(
            '%s (%d of %d units)',
            $projection['faculty_name'] ?? 'Instructor',
            (int) ($projection['projected_units'] ?? 0),
            (int) ($projection['unit_ceiling'] ?? 0),
        );

        return response()->json([
            'message' => count($over) === 1
                ? $this->unitCeilingRefusalMessage($over[0])
                : 'These instructors would go past their unit limit (basic load + overload): '
                    .implode(', ', array_map($describe, $over)).'.',
            'unit_ceiling_exceeded' => ['instructors' => $over],
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function unitCeilingRefusalMessage(array $projection): string
    {
        $name = $projection['faculty_name'] ?? 'This instructor';
        $current = (int) ($projection['current_units'] ?? 0);
        $projected = (int) ($projection['projected_units'] ?? 0);
        $ceiling = (int) ($projection['unit_ceiling'] ?? 0);

        return match ($projection['refusal_reason'] ?? null) {
            SchedulingPolicy::PRO_BONO_REFUSED_ALREADY_USED => sprintf(
                '%s already carries %d of %d units: the one Pro-Bono assignment past the limit has been used. Choose another instructor.',
                $name, $current, $ceiling,
            ),
            SchedulingPolicy::PRO_BONO_REFUSED_LIMIT_REACHED => sprintf(
                '%s has already reached the %d-unit limit (basic load + overload), so Pro-Bono is not available. Choose another instructor.',
                $name, $ceiling,
            ),
            SchedulingPolicy::PRO_BONO_REFUSED_MORE_THAN_ONE_CLASS => sprintf(
                '%s would carry %d of %d units. Pro-Bono allows only one class past the limit. Choose another instructor.',
                $name, $projected, $ceiling,
            ),
            default => sprintf(
                '%s would carry %d units, past the %d-unit limit (basic load + overload). Choose another instructor.',
                $name, $projected, $ceiling,
            ),
        };
    }

    /**
     * The notice to show when an assignment saved as an instructor's Pro-Bono.
     *
     * @param  array<string, mixed>  $projection  FacultyLoadService::projectLoad() result taken before saving
     */
    protected function proBonoNotice(array $projection): ?string
    {
        if (! ($projection['pro_bono'] ?? false)) {
            return null;
        }

        return sprintf(
            'Assigned as Pro-Bono: %s now carries %d of %d units. This is the one assignment allowed past the limit; no more classes can be assigned to this instructor.',
            $projection['faculty_name'] ?? 'The instructor',
            (int) ($projection['projected_units'] ?? 0),
            (int) ($projection['unit_ceiling'] ?? 0),
        );
    }

    /**
     * @return array{section_id: int, course_id: int, units: int}|null
     */
    protected function loadPairForSchedule(Schedule $schedule): ?array
    {
        $course = $this->scheduleCourse($schedule);

        if ($course === null) {
            return null;
        }

        return [
            'section_id' => (int) $schedule->section_id,
            'course_id' => (int) $schedule->course_id,
            'units' => (int) ($course->units ?? 0),
        ];
    }

    /**
     * @param  iterable<Schedule>  $schedules
     * @return array<int, array{section_id: int, course_id: int, units: int}>
     */
    protected function loadPairsForSchedules(iterable $schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : iterator_to_array($schedules);

        $courseIds = [];
        foreach ($schedules as $schedule) {
            if ($schedule->course_id !== null) {
                $courseIds[(int) $schedule->course_id] = true;
            }
        }

        $units = $courseIds === []
            ? []
            : Course::query()
                ->whereIn('id', array_keys($courseIds))
                ->pluck('units', 'id')
                ->all();

        $pairs = [];
        foreach ($schedules as $schedule) {
            $courseId = (int) $schedule->course_id;

            if (! array_key_exists($courseId, $units)) {
                continue;
            }

            $pairs["{$schedule->section_id}:{$courseId}"] = [
                'section_id' => (int) $schedule->section_id,
                'course_id' => $courseId,
                'units' => (int) $units[$courseId],
            ];
        }

        return array_values($pairs);
    }

    protected function assignmentLabelForSchedule(Schedule $schedule): string
    {
        $parts = array_filter([
            $this->scheduleCourse($schedule)?->course_code,
            $schedule->relationLoaded('section')
                ? $schedule->section?->section_name
                : $schedule->section()->first()?->section_name,
        ]);

        return $parts === [] ? 'this class' : implode(' — ', $parts);
    }

    /**
     * @param  array<int, Schedule>  $schedules
     */
    protected function assignmentLabelForClasses(array $schedules, int $classCount): string
    {
        if ($classCount <= 0 || $schedules === []) {
            return 'this class';
        }

        return $classCount === 1
            ? $this->assignmentLabelForSchedule(reset($schedules))
            : "{$classCount} classes";
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    protected function withAssignmentLabel(array $projection, string $label): array
    {
        return array_merge($projection, ['assignment_label' => $label]);
    }

    private function scheduleCourse(Schedule $schedule): ?Course
    {
        return $schedule->relationLoaded('course')
            ? $schedule->course
            : $schedule->course()->first();
    }
}
