<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The weekly windows an instructor is available to teach in.
 *
 * `faculty_availabilities` was already read by the rule engine and by the
 * timetable conflict checks, but nothing could write to it: every instructor had
 * an empty window set, so both fell back to a hardcoded guess about when a
 * part-timer could teach. These endpoints give the table its write path.
 */
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
            'availabilities' => $this->windows($faculty),
        ]);
    }

    /**
     * Replaces the whole weekly set in one call. The editor is a weekly grid, so
     * a whole-week replace keeps the stored windows exactly what the user sees
     * and avoids per-row add/remove races between two open editors.
     */
    public function replace(Request $request, Faculty $faculty): JsonResponse
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

        $validator->after(function ($validator) use ($request) {
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
                'day_index' => (int) $window['day_index'],
                'start_time' => SchedulingPolicy::normalizeTime((string) $window['start_time']),
                'end_time' => SchedulingPolicy::normalizeTime((string) $window['end_time']),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        DB::transaction(function () use ($faculty, $windows): void {
            $faculty->availabilities()->delete();
            if ($windows !== []) {
                DB::table('faculty_availabilities')->insert($windows);
            }
        });

        return response()->json([
            'faculty_id' => $faculty->id,
            'availabilities' => $this->windows($faculty->refresh()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function windows(Faculty $faculty): array
    {
        return $faculty->availabilities()
            ->orderBy('day_index')
            ->orderBy('start_time')
            ->get()
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
            return response()->json(['message' => 'Faculty member not found in your department.'], 404);
        }

        return null;
    }
}
