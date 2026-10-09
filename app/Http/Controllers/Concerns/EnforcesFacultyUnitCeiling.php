<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Semester;
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
                ? sprintf(
                    '%s would carry %d units, past the %d-unit limit (basic load + overload). Choose another instructor.',
                    $over[0]['faculty_name'] ?? 'This instructor',
                    (int) ($over[0]['projected_units'] ?? 0),
                    (int) ($over[0]['unit_ceiling'] ?? 0),
                )
                : 'These instructors would go past their unit limit (basic load + overload): '
                    .implode(', ', array_map($describe, $over)).'.',
            'unit_ceiling_exceeded' => ['instructors' => $over],
        ], 422);
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
