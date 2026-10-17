<?php

namespace App\Services;

use App\Exceptions\WeatherException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches current conditions and forecasts from Open-Meteo and normalizes
 * every response into a stable internal structure, so Blade templates never
 * depend on the raw provider JSON.
 *
 * Open-Meteo is free and key-less, provides true hourly data, a 7-day daily
 * forecast, UV index, precipitation probability, visibility and sun times in
 * a single call. Times are requested as UNIX timestamps (UTC) and every local
 * display is derived from the response's utc_offset_seconds.
 *
 * Nothing here invents data. Any value the provider omits stays null so the
 * view can render an honest "—".
 */
class WeatherService
{
    /**
     * WMO weather codes -> [condition category, translation key suffix].
     * https://open-meteo.com/en/docs
     */
    private const WMO = [
        0 => ['Clear', 'clear'],
        1 => ['Partly', 'mainly_clear'],
        2 => ['Partly', 'partly_cloudy'],
        3 => ['Clouds', 'overcast'],
        45 => ['Fog', 'fog'],
        48 => ['Fog', 'fog'],
        51 => ['Drizzle', 'drizzle'],
        53 => ['Drizzle', 'drizzle'],
        55 => ['Drizzle', 'drizzle'],
        56 => ['Drizzle', 'drizzle'],
        57 => ['Drizzle', 'drizzle'],
        61 => ['Rain', 'rain'],
        63 => ['Rain', 'rain'],
        65 => ['Rain', 'rain'],
        66 => ['Rain', 'rain'],
        67 => ['Rain', 'rain'],
        71 => ['Snow', 'snow'],
        73 => ['Snow', 'snow'],
        75 => ['Snow', 'snow'],
        77 => ['Snow', 'snow'],
        80 => ['Rain', 'rain_showers'],
        81 => ['Rain', 'rain_showers'],
        82 => ['Rain', 'rain_showers'],
        85 => ['Snow', 'snow_showers'],
        86 => ['Snow', 'snow_showers'],
        95 => ['Thunderstorm', 'thunderstorm'],
        96 => ['Thunderstorm', 'thunderstorm'],
        99 => ['Thunderstorm', 'thunderstorm'],
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 8,
        private readonly int $connectTimeout = 5,
        private readonly int $cacheMinutes = 15,
        private readonly int $precision = 2,
    ) {}

    /**
     * Which forecast features are available. Open-Meteo always provides true
     * hourly data and a 7-day forecast, so this is stable regardless of any
     * single response.
     *
     * @return array<string,mixed>
     */
    public function capabilities(): array
    {
        return [
            'plan' => 'open-meteo',
            'current' => true,
            'hourly' => true,
            'hourly_granularity_hours' => 1,
            'daily' => true,
            'daily_days' => 7,
            'nominal_coverage_days' => 7,
            'seven_day_forecast' => true,
        ];
    }

    /**
     * Current weather for a location.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function current(array $location, string $units, string $locale): array
    {
        $payload = $this->payload($location, $units);

        $current = $payload['current'] ?? null;

        if (! is_array($current) || ! isset($current['time'])) {
            throw WeatherException::malformed('Current weather block was missing.');
        }

        $offset = (int) ($payload['utc_offset_seconds'] ?? 0);
        $hourly = is_array($payload['hourly'] ?? null) ? $payload['hourly'] : [];
        $daily = is_array($payload['daily'] ?? null) ? $payload['daily'] : [];

        $index = $this->currentHourIndex($hourly, (int) $current['time']);
        $isDay = ((int) ($current['is_day'] ?? 1)) === 1;
        [$main, $description] = $this->describe((int) ($current['weather_code'] ?? 3), $isDay, $locale);

        return [
            'temp' => $current['temperature_2m'] ?? null,
            'feels_like' => $current['apparent_temperature'] ?? null,
            'humidity' => $current['relative_humidity_2m'] ?? null,
            'pressure' => $current['pressure_msl'] ?? null,
            'wind_speed' => $current['wind_speed_10m'] ?? null,
            'wind_deg' => $current['wind_direction_10m'] ?? null,
            'visibility' => $index === null ? null : ($hourly['visibility'][$index] ?? null),
            'clouds' => $current['cloud_cover'] ?? null,
            'uv_index' => $index === null ? null : ($hourly['uv_index'][$index] ?? null),
            'is_day' => $isDay,
            'sunrise' => $this->isoOrNull($daily['sunrise'][0] ?? null),
            'sunset' => $this->isoOrNull($daily['sunset'][0] ?? null),
            'timezone_offset' => $offset,
            'condition' => $main,
            'description' => $description,
            'icon' => $isDay ? '01d' : '01n',
            'icon_url' => null,
            'observed_at' => CarbonImmutable::createFromTimestamp((int) $current['time'])->toIso8601String(),
        ];
    }

    /**
     * Hourly (next 24 hours) and daily (7 days) forecast for a location.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array{hourly:array<int,array<string,mixed>>,daily:array<int,array<string,mixed>>,timezone_offset:int,capabilities:array<string,mixed>}
     */
    public function forecast(array $location, string $units, string $locale): array
    {
        $payload = $this->payload($location, $units);

        $hourlyRaw = $payload['hourly'] ?? null;
        $dailyRaw = $payload['daily'] ?? null;

        if (! is_array($hourlyRaw) || ! is_array($dailyRaw)) {
            throw WeatherException::malformed('Forecast response was missing hourly or daily data.');
        }

        $offset = (int) ($payload['utc_offset_seconds'] ?? 0);
        $times = $hourlyRaw['time'] ?? [];
        $start = $this->currentHourIndex($hourlyRaw, $this->currentTime($payload)) ?? 0;

        $hourly = [];

        for ($i = $start; $i < min($start + 24, count($times)); $i++) {
            $isDay = ((int) ($hourlyRaw['is_day'][$i] ?? 1)) === 1;
            [$main, $description] = $this->describe((int) ($hourlyRaw['weather_code'][$i] ?? 3), $isDay, $locale);

            $hourly[] = [
                'timestamp' => (int) $times[$i],
                'time' => CarbonImmutable::createFromTimestamp((int) $times[$i])->toIso8601String(),
                'temp' => $hourlyRaw['temperature_2m'][$i] ?? null,
                'feels_like' => $hourlyRaw['apparent_temperature'][$i] ?? null,
                'humidity' => $hourlyRaw['relative_humidity_2m'][$i] ?? null,
                'pressure' => null,
                'wind_speed' => $hourlyRaw['wind_speed_10m'][$i] ?? null,
                'pop' => $this->probability($hourlyRaw['precipitation_probability'][$i] ?? null),
                'condition' => $main,
                'description' => $description,
                'icon' => $isDay ? '01d' : '01n',
                'icon_url' => null,
            ];
        }

        $daily = [];

        foreach ($dailyRaw['time'] ?? [] as $i => $timestamp) {
            [$main, $description] = $this->describe((int) ($dailyRaw['weather_code'][$i] ?? 3), true, $locale);

            $daily[] = [
                'timestamp' => (int) $timestamp,
                'date' => CarbonImmutable::createFromTimestamp((int) $timestamp)->addSeconds($offset)->toDateString(),
                'temp_min' => $dailyRaw['temperature_2m_min'][$i] ?? null,
                'temp_max' => $dailyRaw['temperature_2m_max'][$i] ?? null,
                'humidity' => null,
                'wind_speed' => null,
                'pop' => $this->probability($dailyRaw['precipitation_probability_max'][$i] ?? null),
                'uv_index' => $dailyRaw['uv_index_max'][$i] ?? null,
                'condition' => $main,
                'description' => $description,
                'icon' => '01d',
                'icon_url' => null,
                'sunrise' => $this->isoOrNull($dailyRaw['sunrise'][$i] ?? null),
                'sunset' => $this->isoOrNull($dailyRaw['sunset'][$i] ?? null),
            ];
        }

        return [
            'hourly' => $hourly,
            'daily' => $daily,
            'timezone_offset' => $offset,
            'capabilities' => [
                'plan' => 'open-meteo',
                'current' => true,
                'hourly' => ! empty($hourly),
                'hourly_granularity_hours' => 1,
                'daily' => ! empty($daily),
                'daily_days' => count($daily),
                'nominal_coverage_days' => 7,
                'seven_day_forecast' => count($daily) >= 7,
            ],
        ];
    }

    /**
     * Fetch (and cache) the single Open-Meteo payload that both current() and
     * forecast() read from, so a page render costs one provider request.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    private function payload(array $location, string $units): array
    {
        [$lat, $lon] = $this->cacheCoordinates($location);
        $key = "om:forecast:{$lat}:{$lon}:{$units}";

        return Cache::remember($key, now()->addMinutes($this->cacheMinutes), function () use ($lat, $lon, $units) {
            return $this->request($lat, $lon, $units);
        });
    }

    private function request(float $lat, float $lon, string $units): array
    {
        $temperatureUnit = $units === 'imperial' ? 'fahrenheit' : 'celsius';
        $windSpeedUnit = $units === 'imperial' ? 'mph' : 'kmh';

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->get($this->baseUrl, [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,weather_code,cloud_cover,pressure_msl,wind_speed_10m,wind_direction_10m',
                    'hourly' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation_probability,weather_code,wind_speed_10m,visibility,uv_index,is_day',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset,uv_index_max,precipitation_probability_max',
                    'timezone' => 'auto',
                    'timeformat' => 'unixtime',
                    'forecast_days' => 7,
                    'temperature_unit' => $temperatureUnit,
                    'wind_speed_unit' => $windSpeedUnit,
                    'precipitation_unit' => 'mm',
                ]);
        } catch (ConnectionException $e) {
            throw new WeatherException('errors.provider_unavailable', 'Open-Meteo connection failed: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new WeatherException('errors.provider_error', 'Open-Meteo request failed: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw WeatherException::fromStatus($response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw WeatherException::malformed('Open-Meteo response was not valid JSON.');
        }

        return $data;
    }

    /**
     * @return array{0:string,1:string} [condition category, localized description]
     */
    private function describe(int $code, bool $isDay, string $locale): array
    {
        [$main, $key] = self::WMO[$code] ?? ['Clouds', 'overcast'];

        return [$main, __('weather.'.$key, [], $locale)];
    }

    /**
     * Locate the hourly index that matches the current (or first future) hour.
     *
     * @param  array<string,mixed>  $hourly
     */
    private function currentHourIndex(array $hourly, int $time): ?int
    {
        $times = $hourly['time'] ?? [];

        if (! is_array($times) || $times === []) {
            return null;
        }

        foreach ($times as $index => $value) {
            if ((int) $value >= $time) {
                return (int) $index;
            }
        }

        return count($times) - 1;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function currentTime(array $payload): int
    {
        $current = $payload['current'] ?? [];

        if (is_array($current) && isset($current['time'])) {
            return (int) $current['time'];
        }

        $times = $payload['hourly']['time'] ?? [];

        return is_array($times) && $times !== [] ? (int) $times[0] : 0;
    }

    private function probability(mixed $value): ?float
    {
        return is_numeric($value) ? ((float) $value) / 100 : null;
    }

    private function isoOrNull(mixed $timestamp): ?string
    {
        return is_numeric($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp)->toIso8601String()
            : null;
    }

    /**
     * @param  array{lat:float,lon:float}  $location
     * @return array{0:float,1:float}
     */
    private function cacheCoordinates(array $location): array
    {
        return [
            round((float) $location['lat'], $this->precision),
            round((float) $location['lon'], $this->precision),
        ];
    }
}
