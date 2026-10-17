<?php

namespace App\Services;

use App\Exceptions\WeatherException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns place names and coordinates into a normalized location structure.
 *
 * Forward geocoding uses the Open-Meteo geocoding API. Reverse geocoding uses
 * Nominatim (OpenStreetMap), because Open-Meteo does not offer a reverse
 * endpoint. Results are cached for roughly 24 hours because city coordinates
 * do not change.
 */
class GeocodingService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $reverseUrl,
        private readonly string $userAgent = 'SkyCast/1.0',
        private readonly int $timeout = 8,
        private readonly int $connectTimeout = 5,
        private readonly int $cacheMinutes = 1440,
        private readonly int $precision = 2,
    ) {}

    /**
     * Resolve a city name into coordinates.
     *
     * @return array{name:?string,country:?string,state:?string,lat:float,lon:float,alternatives:int}
     *
     * @throws WeatherException when the provider fails or no city matches.
     */
    public function resolve(string $city, string $locale): array
    {
        $normalized = mb_strtolower(trim($city));

        $key = sprintf('om:geo:direct:%s:%s', $locale, md5($normalized));

        $result = Cache::remember($key, now()->addMinutes($this->cacheMinutes), function () use ($city, $locale) {
            $payload = $this->request($this->baseUrl, [
                'name' => $city,
                'count' => 5,
                'language' => $locale,
                'format' => 'json',
            ]);

            $results = $payload['results'] ?? [];

            if (! is_array($results) || $results === []) {
                throw new WeatherException('errors.city_not_found');
            }

            return $this->normalizeResult($results, $locale);
        });

        return $result;
    }

    /**
     * Resolve coordinates back into a human readable place name.
     *
     * Reverse geocoding is best-effort: if the provider returns nothing we
     * still return a usable location so the weather for those coordinates
     * can be shown.
     *
     * @return array{name:?string,country:?string,state:?string,lat:float,lon:float,alternatives:int}
     */
    public function reverse(float $lat, float $lon, string $locale): array
    {
        $rounded = $this->round($lat, $lon);
        $key = sprintf('om:geo:reverse:%s:%s:%s', $locale, $rounded['lat'], $rounded['lon']);

        return Cache::remember($key, now()->addMinutes($this->cacheMinutes), function () use ($lat, $lon, $locale) {
            $payload = $this->request($this->reverseUrl, [
                'format' => 'jsonv2',
                'lat' => $lat,
                'lon' => $lon,
                'zoom' => 10,
                'accept-language' => $locale,
            ]);

            $address = $payload['address'] ?? [];

            if (! is_array($address) || $address === []) {
                return $this->fallback($lat, $lon);
            }

            $name = $this->firstOf($address, ['city', 'town', 'village', 'municipality', 'hamlet', 'county', 'state'])
                ?? ($payload['name'] ?? null);

            return [
                'name' => $name,
                'country' => $address['country'] ?? null,
                'state' => $address['state'] ?? ($address['region'] ?? null),
                'lat' => $lat,
                'lon' => $lon,
                'alternatives' => 0,
            ];
        });
    }

    /**
     * Pick the first usable entry and shape it into our internal structure.
     *
     * @param  array<int,array<string,mixed>>  $results
     * @return array{name:?string,country:?string,state:?string,lat:float,lon:float,alternatives:int}
     */
    private function normalizeResult(array $results, string $locale): array
    {
        $usable = array_values(array_filter($results, static function ($entry) {
            return is_array($entry) && isset($entry['latitude'], $entry['longitude']);
        }));

        if ($usable === []) {
            throw new WeatherException('errors.city_not_found');
        }

        $first = $usable[0];

        return [
            'name' => $first['name'] ?? null,
            'country' => $first['country'] ?? ($first['country_code'] ?? null),
            'state' => $first['admin1'] ?? null,
            'lat' => (float) $first['latitude'],
            'lon' => (float) $first['longitude'],
            'alternatives' => count($usable),
        ];
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<int,string>  $keys
     */
    private function firstOf(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '') {
                return $values[$key];
            }
        }

        return null;
    }

    /**
     * @return array{name:null,country:null,state:null,lat:float,lon:float,alternatives:int}
     */
    private function fallback(float $lat, float $lon): array
    {
        return [
            'name' => null,
            'country' => null,
            'state' => null,
            'lat' => $lat,
            'lon' => $lon,
            'alternatives' => 0,
        ];
    }

    /**
     * Perform a single, guarded HTTP request against a geocoding endpoint.
     */
    private function request(string $url, array $query): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept-Language' => $query['accept-language'] ?? 'en',
            ])
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new WeatherException('errors.provider_unavailable', 'Geocoding connection failed: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new WeatherException('errors.provider_error', 'Geocoding request failed: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw WeatherException::fromStatus($response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw WeatherException::malformed('Geocoding response was not valid JSON.');
        }

        return $data;
    }

    /**
     * @return array{lat:float,lon:float}
     */
    private function round(float $lat, float $lon): array
    {
        return [
            'lat' => round($lat, $this->precision),
            'lon' => round($lon, $this->precision),
        ];
    }
}
