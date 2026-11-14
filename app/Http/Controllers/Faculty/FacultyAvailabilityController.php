<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Semester;
use App\Services\Scheduling\FacultyAvailabilityImpact;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Support\ConflictMessage;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FacultyAvailabilityController extends Controller
{
    private const DAY_LABELS = [
        0 => 'Monday',
        1 => 'Tuesday',
        2 => 'Wednesday',
        3 => 'Thursday',
        4 => 'Friday',
        5 => 'Saturday',
        6 => 'Sunday',
    ];

    public function index(Request $request, Faculty $faculty): JsonResponse
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        return response()->json([
            'faculty_id' => $faculty->id,
            'employment_type' => $faculty->employment_type,
            'opening_time' => SchedulingPolicy::openingTime(),
            'closing_time' => SchedulingPolicy::closingTime(),
            ...$this->modeFields($faculty),
        ]);
    }

    public function replace(Request $request, Faculty $faculty, FacultyAvailabilityImpact $impact, SchedulingScopeLock $lock): JsonResponse
    {
        if ($response = $this->guardDepartment($request, $faculty)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'availabilities' => 'present|array|max:35',
            'availabilities.*.day_index' => 'required|integer|between:0,6',
            'availabilities.*.start_time' => 'required|date_format:H:i,H:i:s',
            'availabilities.*.end_time' => 'required|date_format:H:i,H:i:s',
        ]);

        $validator->after(function ($validator) use ($request, $faculty) {
            $windows = $request->input('availabilities', []);
            if (! is_array($windows)) {
                return;
            }

            $byDay = [];
            foreach ($windows as $index => $window) {
                if (! is_array($window) || ! isset($window['start_time'], $window['end_time'])) {
                    continue;
                }

                $start = SchedulingPolicy::normalizeTime((string) $window['start_time']);
                $end = SchedulingPolicy::normalizeTime((string) $window['end_time']);

                if ($start >= $end) {
                    $validator->errors()->add(
                        "availabilities.{$index}.end_time",
                        'The window must end after it starts.'
                    );

                    continue;
                }

                if (! SchedulingPolicy::isWithinOperatingHours($start, $end)) {
                    $validator->errors()->add(
                        "availabilities.{$index}.start_time",
                        'The window must fall inside operating hours ('
                            .SchedulingPolicy::openingTime().'-'.SchedulingPolicy::closingTime().').'
                    );

                    continue;
                }

                $day = (int) ($window['day_index'] ?? -1);
                // Added windows extend the default hours; overlapping them adds nothing.
                foreach (SchedulingPolicy::standardFacultyHours($faculty->employment_type) as $default) {
                    if ($default['day_index'] === $day && $start < $default['end_time'] && $end > $default['start_time']) {
                        $validator->errors()->add(
                            "availabilities.{$index}.start_time",
                            'This window overlaps the default '.$faculty->employment_type.' hours for '
                                .(self::DAY_LABELS[$day] ?? "day {$day}").' ('
                                .ConflictMessage::time($default['start_time']).' - '.ConflictMessage::time($default['end_time'])
                                .'). Add only hours outside the default.'
                        );

                        continue 2;
                    }
                }

                foreach ($byDay[$day] ?? [] as $existing) {
                    if ($start < $existing['end'] && $end > $existing['start']) {
                        $label = self::DAY_LABELS[$day] ?? "day {$day}";
                        $validator->errors()->add(
                            "availabilities.{$index}.start_time",
                            "This window overlaps another {$label} window."
                        );

                        break;
                    }
                }

                $byDay[$day][] = ['start' => $start, 'end' => $end];
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $windows = collect($validator->validated()['availabilities'] ?? [])
            ->map(fn (array $window) => [
                'faculty_id' => $faculty->id,
                'day' => SchedulingPolicy::DAYS[(int) $window['day_index']],
                'start_time' => SchedulingPolicy::normalizeTime((string) $window['start_time']),
                'end_time' => SchedulingPolicy::normalizeTime((string) $window['end_time']),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        // Same per-semester lock as assignment saves, so an assignment cannot
        // slip in between this impact check and the write.
        $activeSemesterId = (int) Semester::query()->where('is_active', true)->value('id');
        $lock->execute([$activeSemesterId], fn () => DB::transaction(function () use ($faculty, $windows, $impact): void {
            $current = Faculty::query()->whereKey($faculty->id)->lockForUpdate()->firstOrFail();
            $impact->assertNoneOutside(
                $current,
                $current->employment_type,
                array_map(static fn (array $window): array => [
                    'day_index' => SchedulingPolicy::dayIndex($window['day']),
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                ], $windows),
                'availabilities',
            );

            $current->availabilities()->delete();
            if ($windows !== []) {
                DB::table('faculty_availabilities')->insert($windows);
            }
        }));

        ApiCache::forgetGroups(['faculty.index', 'initial.data', 'instructor_assignments.index']);

        return response()->json([
            'faculty_id' => $faculty->id,
            ...$this->modeFields($faculty->refresh()),
        ]);
    }

    /**
     * The default week of the employment type and the windows added on top of
     * it. Availability is their union; "custom" means windows were added.
     *
     * @return array<string, mixed>
     */
    private function modeFields(Faculty $faculty): array
    {
        $windows = $this->windows($faculty);

        return [
            'availability_mode' => $windows === [] ? 'standard' : 'custom',
            'availabilities' => $windows,
            'standard_availabilities' => SchedulingPolicy::standardFacultyHours($faculty->employment_type),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function windows(Faculty $faculty): array
    {
        return $faculty->availabilities()
            ->orderBy('start_time')
            ->get()
            ->sortBy(fn ($window) => $window->day_index, SORT_NUMERIC)
            ->values()
            ->map(fn ($window) => [
                'id' => (int) $window->id,
                'day_index' => (int) $window->day_index,
                'day_label' => self::DAY_LABELS[(int) $window->day_index] ?? null,
                'start_time' => SchedulingPolicy::normalizeTime((string) $window->start_time),
                'end_time' => SchedulingPolicy::normalizeTime((string) $window->end_time),
            ])
            ->all();
    }

    private function guardDepartment(Request $request, Faculty $faculty): ?JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->isVpaa()) {
            return null;
        }

        if ($user->department_id !== null && (int) $faculty->department_id !== (int) $user->department_id) {
            return response()->json(['message' => 'Instructor not found in your department.'], 404);
        }

        if ($user->role === 'program_head' && (int) $faculty->program_id !== (int) ($user->program_id ?? 0)) {
            return response()->json(['message' => 'Instructor not found in your program.'], 404);
        }

        return null;
    }
}
