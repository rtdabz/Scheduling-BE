<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;

trait ConfirmsFacultyOverload
{
    public const OVERLOAD_CONFIRMATION_MESSAGE = 'This instructor will have a pro bono load. Do you want to proceed?';

    public const OVERLOAD_CONFIRMATION_MESSAGE_PLURAL = 'These instructors will have a pro bono load. Do you want to proceed?';

    protected function activeSemesterId(): ?int
    {
        $id = Semester::query()->where('is_active', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $projections  FacultyLoadService::projectLoad() results
     */
    protected function overloadConfirmationResponse(array $projections): ?JsonResponse
    {
        $needed = array_values(array_filter(
            $projections,
            static fn (array $projection): bool => (bool) ($projection['requires_confirmation'] ?? false),
        ));

        if ($needed === []) {
            return null;
        }

        return response()->json([
            'message' => count($needed) === 1
                ? self::OVERLOAD_CONFIRMATION_MESSAGE
                : self::OVERLOAD_CONFIRMATION_MESSAGE_PLURAL,
            'overload_confirmation' => ['instructors' => $needed],
        ], 409);
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
