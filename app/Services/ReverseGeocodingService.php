<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Names the place (city, region, country) at a device's coordinates via OpenStreetMap Nominatim.
 *
 * Never throws: a disabled feature, provider errors or an empty result return null.
 * Nominatim's usage policy requires an identifying User-Agent and at most one request per second;
 * sign-in volume is far below that, and results are cached per ~100 m cell.
 */
class ReverseGeocodingService
{
    /** @return array{device_city: ?string, device_region: ?string, device_country: ?string}|null */
    public function placeAt(float $latitude, float $longitude): ?array
    {
        if (! config('services.reverse_geocoding.enabled')) {
            return null;
        }

        $cacheKey = sprintf('reverse-geocode:%.3f,%.3f', $latitude, $longitude);
        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = Http::timeout(max(1, (int) config('services.reverse_geocoding.timeout', 4)))
                ->withUserAgent(config('app.name').' sign-in audit ('.config('app.url').')')
                ->acceptJson()
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'jsonv2',
                    'zoom' => 10, // city level; street addresses are never requested
                    'addressdetails' => 1,
                    'accept-language' => 'en',
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $address = $response->successful() ? ($response->json('address') ?? null) : null;
        if (! is_array($address)) {
            return null;
        }

        $place = [
            'device_city' => $this->first($address, ['city', 'town', 'municipality', 'village', 'county']),
            'device_region' => $this->first($address, ['state', 'region', 'province']),
            'device_country' => $this->first($address, ['country']),
        ];
        if (! array_filter($place)) {
            return null;
        }

        Cache::put($cacheKey, $place, now()->addDays(7));

        return $place;
    }

    private function first(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($address[$key] ?? null) && trim($address[$key]) !== '') {
                return mb_substr(trim($address[$key]), 0, 120);
            }
        }

        return null;
    }
}
