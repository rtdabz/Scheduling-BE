<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Optional browser Geolocation API fix sent with a sign-in as `device_location`
 * ({latitude, longitude, accuracy, captured_at}). Anything malformed is dropped silently:
 * location is an enrichment and must never turn a sign-in into a validation error.
 */
final class DeviceLocation
{
    /** ~1 m; browsers rarely report better, and more digits only add false precision. */
    private const PRECISION = 5;

    private const MAX_ACCURACY_M = 100_000;

    /** A fix older than this was not taken for this sign-in. */
    private const MAX_AGE_MINUTES = 10;

    /** @return array{device_latitude: float, device_longitude: float, device_accuracy_m: int, device_captured_at: string}|null */
    public static function fromRequest(Request $request): ?array
    {
        $input = $request->input('device_location');
        if (! is_array($input)) {
            return null;
        }

        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;
        $accuracy = $input['accuracy'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_numeric($accuracy)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $accuracy = (float) $accuracy;
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180
            || ($latitude === 0.0 && $longitude === 0.0)
            || $accuracy < 0 || $accuracy > self::MAX_ACCURACY_M) {
            return null;
        }

        try {
            $capturedAt = isset($input['captured_at']) && is_string($input['captured_at']) ? Carbon::parse($input['captured_at']) : now();
        } catch (Throwable) {
            return null;
        }
        // Small allowance for client clock drift into the future.
        if ($capturedAt->gt(now()->addMinutes(2)) || $capturedAt->lt(now()->subMinutes(self::MAX_AGE_MINUTES))) {
            return null;
        }

        return [
            'device_latitude' => round($latitude, self::PRECISION),
            'device_longitude' => round($longitude, self::PRECISION),
            'device_accuracy_m' => (int) max(1, round($accuracy)),
            'device_captured_at' => $capturedAt->utc()->toDateTimeString(),
        ];
    }
}
