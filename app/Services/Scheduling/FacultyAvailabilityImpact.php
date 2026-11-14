<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Faculty;
use App\Models\Schedule;
use App\Services\Scheduling\Engine\Rules\InstructorAvailabilityRule;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Refuses an availability or employment-type change that would leave classes
 * already assigned to the instructor (active semester) outside their hours.
 * Existing schedules are never moved or unassigned automatically.
 */
final class FacultyAvailabilityImpact
{
    /**
     * @param  iterable<array<string, mixed>|object>  $addedWindows
     * @return list<array<string, mixed>>
     */
    public function outside(Faculty $faculty, ?string $employmentType, iterable $addedWindows): array
    {
        $windows = collect($addedWindows)->values()->all();

        return Schedule::query()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->where('faculty_id', $faculty->id)
            ->whereHas('academicSemester', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->filter(static fn (Schedule $schedule): bool => InstructorAvailabilityRule::outsideAvailability(
                $employmentType,
                $windows,
                (string) $schedule->day,
                (string) $schedule->start_time,
                (string) $schedule->end_time,
            ))
            ->map(static fn (Schedule $schedule): array => [
                'schedule_id' => (int) $schedule->id,
                'course_code' => $schedule->course?->course_code,
                'section_name' => $schedule->section?->section_name,
                'day' => (string) $schedule->day,
                'start_time' => substr((string) $schedule->start_time, 0, 5),
                'end_time' => substr((string) $schedule->end_time, 0, 5),
            ])
            ->values()
            ->all();
    }

    /**
     * Throws a 422 (rolling back the surrounding transaction) when the change
     * would strand assigned classes.
     *
     * @param  iterable<array<string, mixed>|object>  $addedWindows
     */
    public function assertNoneOutside(Faculty $faculty, ?string $employmentType, iterable $addedWindows, string $field): void
    {
        $affected = $this->outside($faculty, $employmentType, $addedWindows);
        if ($affected === []) {
            return;
        }

        $labels = implode(', ', array_map(
            static fn (array $row): string => sprintf(
                '%s %s (%s %s-%s)',
                $row['course_code'] ?? 'Class',
                $row['section_name'] ?? '',
                $row['day'],
                $row['start_time'],
                $row['end_time'],
            ),
            array_slice($affected, 0, 5),
        ));
        $more = count($affected) > 5 ? ' and '.(count($affected) - 5).' more' : '';
        $message = 'This change would leave '.count($affected).' assigned class'.(count($affected) === 1 ? '' : 'es')
            .' outside the instructor\'s availability: '.$labels.$more
            .'. Reassign or move them first, or keep a window that covers them.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
            'affected_schedules' => $affected,
        ], 422));
    }
}
