<?php

namespace App\Services;

use App\Models\LoginGeolocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves an IP address to an approximate city-level location.
 *
 * Never throws: private/reserved addresses, a disabled feature, provider errors and
 * implausible coordinates all degrade to a non-resolved status without coordinates.
 */
class IpGeolocationService
{
    /** Two decimals is roughly 1 km — enough for a city pin, never a street address. */
    private const COORDINATE_PRECISION = 2;

    /** @return array{lookup_status: string, city: ?string, region: ?string, country: ?string, country_code: ?string, latitude: ?float, longitude: ?float, is_proxy: ?bool, provider: ?string} */
    public function locate(?string $ip): array
    {
        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->result(LoginGeolocation::UNAVAILABLE);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $this->result(LoginGeolocation::PRIVATE);
        }
        if (! config('services.ip_geolocation.enabled')) {
            return $this->result(LoginGeolocation::UNAVAILABLE);
        }

        $provider = (string) config('services.ip_geolocation.provider', 'ipapi');
        $cacheKey = 'ip-geolocation:'.$provider.':'.hash('sha256', $ip);
        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $result = $provider === 'ip-api' ? $this->ipApi($ip) : $this->ipapiCo($ip);
        } catch (Throwable $exception) {
            report($exception);

            return $this->result(LoginGeolocation::UNAVAILABLE, ['provider' => $provider]);
        }

        if ($result['lookup_status'] === LoginGeolocation::RESOLVED) {
            Cache::put($cacheKey, $result, now()->addHours(12));
        }

        return $result;
    }

    private function ipapiCo(string $ip): array
    {
        $response = Http::timeout($this->timeout())->acceptJson()->get('https://ipapi.co/'.rawurlencode($ip).'/json/');
        $body = $response->json() ?? [];
        if (! $response->successful() || ! empty($body['error'])) {
            return $this->result(LoginGeolocation::UNAVAILABLE, ['provider' => 'ipapi']);
        }

        return $this->fromCoordinates('ipapi', $body['latitude'] ?? null, $body['longitude'] ?? null, [
            'city' => $body['city'] ?? null,
            'region' => $body['region'] ?? null,
            'country' => $body['country_name'] ?? null,
            'country_code' => $body['country_code'] ?? null,
        ]);
    }

    private function ipApi(string $ip): array
    {
        $response = Http::timeout($this->timeout())->acceptJson()->get('http://ip-api.com/json/'.rawurlencode($ip), [
            'fields' => 'status,message,country,countryCode,regionName,city,lat,lon,proxy,hosting',
        ]);
        $body = $response->json() ?? [];
        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            return $this->result(LoginGeolocation::UNAVAILABLE, ['provider' => 'ip-api']);
        }

        return $this->fromCoordinates('ip-api', $body['lat'] ?? null, $body['lon'] ?? null, [
            'city' => $body['city'] ?? null,
            'region' => $body['regionName'] ?? null,
            'country' => $body['country'] ?? null,
            'country_code' => $body['countryCode'] ?? null,
            'is_proxy' => isset($body['proxy']) || isset($body['hosting']) ? (bool) (($body['proxy'] ?? false) || ($body['hosting'] ?? false)) : null,
        ]);
    }

    private function fromCoordinates(string $provider, mixed $latitude, mixed $longitude, array $place): array
    {
        $place = array_map(fn ($value) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 120) : (is_bool($value) ? $value : null), $place);
        $place['country_code'] = isset($place['country_code']) && preg_match('/^[A-Za-z]{2}$/', $place['country_code']) ? strtoupper($place['country_code']) : null;

        $valid = is_numeric($latitude) && is_numeric($longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180
            // Null Island (0,0) is what several providers return when they know nothing.
            && ! ((float) $latitude === 0.0 && (float) $longitude === 0.0);

        if (! $valid) {
            return $this->result(LoginGeolocation::UNAVAILABLE, ['provider' => $provider] + $place);
        }

        return $this->result(LoginGeolocation::RESOLVED, [
            'provider' => $provider,
            'latitude' => round((float) $latitude, self::COORDINATE_PRECISION),
            'longitude' => round((float) $longitude, self::COORDINATE_PRECISION),
        ] + $place);
    }

    private function result(string $status, array $values = []): array
    {
        return array_merge([
            'lookup_status' => $status,
            'city' => null,
            'region' => null,
            'country' => null,
            'country_code' => null,
            'latitude' => null,
            'longitude' => null,
            'is_proxy' => null,
            'provider' => null,
        ], array_intersect_key($values, array_flip(['city', 'region', 'country', 'country_code', 'latitude', 'longitude', 'is_proxy', 'provider'])));
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.ip_geolocation.timeout', 3));
    }
}
