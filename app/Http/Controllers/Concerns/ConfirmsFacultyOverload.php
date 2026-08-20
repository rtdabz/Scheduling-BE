<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Terms;
use Illuminate\Http\JsonResponse;

/**
 * Shared confirmation gate for assignments that push an instructor past their
 * Basic Load.
 *
 * The gate lives on the server rather than in the pickers because the pages
 * cache their faculty payload: a client-side check reads a stale load and then
 * silently skips the prompt when someone else has just assigned. Every endpoint
 * that writes `faculty_id` therefore projects the load itself and answers 409
 * until the request carries `confirm_overload`.
 *
 * 409 rather than 422 because these endpoints already answer 422 for assignments
 * they *refuse*, which the UI renders as an error. This one is not a refusal —
 * it is a question.
 *
 * The helpers here deliberately read relations *without* attaching them to the
 * model. Every calling endpoint feeds `$schedule->toArray()` into the rule
 * engine, and a loaded relation would smuggle nested `course` / `section` arrays
 * into that payload.
 */
trait ConfirmsFacultyOverload
{
    public const OVERLOAD_CONFIRMATION_MESSAGE = 'This instructor will have an overload. Do you want to proceed?';

    public const OVERLOAD_CONFIRMATION_MESSAGE_PLURAL = 'These instructors will have an overload. Do you want to proceed?';

    /**
     * The term the load is measured in. Load only counts the active term, so a
     * null here means every projection reports a zero current load.
     */
    protected function activeTermId(): ?int
    {
        $id = Terms::query()->where('is_active', true)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * A 409 asking the user to confirm, or null when nothing needs confirming.
     *
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
     * The (section, course) pair a schedule row contributes to an instructor's
     * load, with the units the projection needs. Returns null when the row has
     * no course to draw units from.
     *
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
     * The same pairs for a whole set of rows, resolving their courses in one
     * query instead of one per row.
     *
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

            // Keyed so a course split across several meeting blocks is one pair,
            // matching how FacultyLoadService dedupes an instructor's rows.
            $pairs["{$schedule->section_id}:{$courseId}"] = [
                'section_id' => (int) $schedule->section_id,
                'course_id' => $courseId,
                'units' => (int) $units[$courseId],
            ];
        }

        return array_values($pairs);
    }

    /**
     * What the confirmation names as the thing being assigned, e.g. "IT 301 —
     * BSIT 3A". Falls back to whichever half is known.
     */
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
     * The label for a bulk assignment. A single class is named outright; several
     * are counted, since listing a dozen class codes in the prompt reads worse
     * than the number does.
     *
     * $classCount is the number of *distinct* classes, which is not the number of
     * rows: one class split across meeting blocks is several rows.
     *
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

    /**
     * The row's course, read without attaching the relation to the model.
     */
    private function scheduleCourse(Schedule $schedule): ?Course
    {
        return $schedule->relationLoaded('course')
            ? $schedule->course
            : $schedule->course()->first();
    }
}
