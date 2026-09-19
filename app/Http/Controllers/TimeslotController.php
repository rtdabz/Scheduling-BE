<?php

namespace App\Http\Controllers;

use App\Http\Requests\Timeslot\StoreTimeslotOverrideRequest;
use App\Http\Requests\Timeslot\UpdateTimeslotOverrideRequest;
use App\Http\Requests\Timeslot\UpdateTimeslotSettingsRequest;
use App\Models\TimeslotOverride;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\TimeslotService;
use App\Support\ApiCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeslotController extends Controller
{
    public function __construct(private readonly TimeslotService $timeslotService)
    {
    }

    public function index(): JsonResponse
    {
        $settings = $this->timeslotService->settings();
        $durations = [60, 90, 120, 180, 240];

        return response()->json([
            'settings' => [
                'opening_time' => $this->formatTime($settings->opening_time),
                'closing_time' => $this->formatTime($settings->closing_time),
                'field_end_time' => $this->formatTime(SchedulingPolicy::fieldDayEndTime()),
                'slot_interval' => (int) $settings->slot_interval,
            ],
            'overrides' => TimeslotOverride::query()
                ->orderBy('duration_minutes')
                ->orderBy('start_time')
                ->get()
                ->map(fn (TimeslotOverride $override): array => $this->serializeOverride($override))
                ->values(),
            'generated_slots' => collect($durations)
                ->mapWithKeys(fn (int $duration): array => [
                    $duration => $this->timeslotService->generateStartTimes($duration),
                ]),
        ]);
    }

    public function updateSettings(UpdateTimeslotSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->validateClosingTime($validated);
        if (isset($validated['field_end_time'])) {
            $this->validateFieldEndTime($validated);
        }

        $settings = $this->timeslotService->settings();
        $settings->update([
            'opening_time' => $this->toDatabaseTime($validated['opening_time']),
            'closing_time' => $this->toDatabaseTime($validated['closing_time']),
            'slot_interval' => (int) $validated['slot_interval'],
            ...(isset($validated['field_end_time'])
                ? ['field_end_time' => $this->toDatabaseTime($validated['field_end_time'])]
                : []),
        ]);
        SchedulingPolicy::clearTimeCache();
        ApiCache::forgetGroup('initial.data');

        return response()->json([
            'message' => 'Timeslot settings updated successfully.',
            'settings' => [
                'opening_time' => $this->formatTime($settings->opening_time),
                'closing_time' => $this->formatTime($settings->closing_time),
                'field_end_time' => $this->formatTime(SchedulingPolicy::fieldDayEndTime()),
                'slot_interval' => (int) $settings->slot_interval,
            ],
        ]);
    }

    public function storeOverride(StoreTimeslotOverrideRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $override = TimeslotOverride::query()->create([
            'duration_minutes' => (int) $validated['duration_minutes'],
            'start_time' => $this->toDatabaseTime($validated['start_time']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);
        SchedulingPolicy::clearTimeCache();
        ApiCache::forgetGroup('initial.data');

        return response()->json([
            'message' => 'Timeslot override created successfully.',
            'override' => $this->serializeOverride($override),
        ], 201);
    }

    public function updateOverride(UpdateTimeslotOverrideRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $override = TimeslotOverride::query()->findOrFail($id);

        $override->fill(collect($validated)
            ->mapWithKeys(function (mixed $value, string $key): array {
                if ($key === 'start_time') {
                    return [$key => $this->toDatabaseTime($value)];
                }

                return [$key => $value];
            })
            ->all());

        $override->save();
        SchedulingPolicy::clearTimeCache();
        ApiCache::forgetGroup('initial.data');

        return response()->json([
            'message' => 'Timeslot override updated successfully.',
            'override' => $this->serializeOverride($override),
        ]);
    }

    public function destroyOverride(int $id): JsonResponse
    {
        $override = TimeslotOverride::query()->findOrFail($id);
        $override->delete();
        SchedulingPolicy::clearTimeCache();
        ApiCache::forgetGroup('initial.data');

        return response()->json([
            'message' => 'Timeslot override archived successfully.',
        ]);
    }

    public function generateSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        return response()->json([
            'duration_minutes' => (int) $validated['duration_minutes'],
            'slots' => $this->getAvailableSlots((int) $validated['duration_minutes']),
        ]);
    }

    /**
     * Reusable method for schedule generation flows.
     *
     * @return array<int, string>
     */
    public function getAvailableSlots(int $duration): array
    {
        return $this->timeslotService->generateStartTimes($duration);
    }

    private function serializeOverride(TimeslotOverride $override): array
    {
        return [
            'id' => $override->id,
            'duration_minutes' => (int) $override->duration_minutes,
            'start_time' => $this->formatTime($override->start_time),
            'is_active' => (bool) $override->is_active,
        ];
    }

    private function toDatabaseTime(string $time): string
    {
        return $this->parseUserTime($time)->format('H:i:s');
    }

    private function formatTime(string $time): string
    {
        return Carbon::parse($time)->format('g:i A');
    }

    private function parseUserTime(string $time): Carbon
    {
        $normalized = preg_replace('/\s*(AM|PM)$/i', ' $1', trim($time));

        return Carbon::createFromFormat('g:i A', strtoupper($normalized));
    }

    /**
     * A field class must be able to end at the field end time, so it sits after
     * opening, no later than closing, and on the 30-minute scheduling grid.
     */
    private function validateFieldEndTime(array $validated): void
    {
        $opening = $this->parseUserTime($validated['opening_time']);
        $closing = $this->parseUserTime($validated['closing_time']);
        $fieldEnd = $this->parseUserTime($validated['field_end_time']);

        $message = match (true) {
            $fieldEnd->lessThanOrEqualTo($opening) => 'The field end time must be after the opening time.',
            $fieldEnd->greaterThan($closing) => 'The field end time cannot be later than the closing time.',
            (($fieldEnd->hour * 60 + $fieldEnd->minute) - ($opening->hour * 60 + $opening->minute)) % SchedulingPolicy::SLOT_MINUTES !== 0 => 'The field end time must fall on a 30-minute slot.',
            default => null,
        };

        if ($message !== null) {
            abort(response()->json([
                'message' => $message,
                'errors' => ['field_end_time' => [$message]],
            ], 422));
        }
    }

    private function validateClosingTime(array $validated): void
    {
        $opening = $this->parseUserTime($validated['opening_time']);
        $closing = $this->parseUserTime($validated['closing_time']);

        if ($closing->lessThanOrEqualTo($opening)) {
            abort(response()->json([
                'message' => 'The closing time must be after the opening time.',
                'errors' => [
                    'closing_time' => ['The closing time must be after the opening time.'],
                ],
            ], 422));
        }
    }
}
