<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Timeslot\UpdateTimeslotSettingsRequest;
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
     * @return array<int, string>
     */
    public function getAvailableSlots(int $duration): array
    {
        return $this->timeslotService->generateStartTimes($duration);
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
