<?php

namespace App\Services\Providers;

use App\Exceptions\WeatherException;
use App\Services\Contracts\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches current conditions and forecasts from the Visual Crossing timeline
 * API and normalizes them into the identical structure OpenMeteoProvider
 * produces, so nothing downstream learns which provider answered.
 *
 * Visual Crossing returns local datetimes plus a per-record timezone offset
 * (in hours); every timestamp here is converted to the underlying UTC instant
 * so local display stays correct even across a DST change inside the window.
 *
 * Nothing here invents data: any value the provider omits stays null.
 */
class VisualCrossingProvider implements WeatherProvider
{
    /**
     * Visual Crossing icon -> [condition category, translation key suffix].
     * https://www.visualcrossing.com/resources/documentation/weather-api/weather-icon-list/
     */
    private const ICONS = [
        'clear-day' => ['Clear', 'clear'],
        'clear-night' => ['Clear', 'clear'],
        'partly-cloudy-day' => ['Partly', 'partly_cloudy'],
        'partly-cloudy-night' => ['Partly', 'partly_cloudy'],
        'cloudy' => ['Clouds', 'overcast'],
        'fog' => ['Fog', 'fog'],
        'wind' => ['Clouds', 'overcast'],
        'rain' => ['Rain', 'rain'],
        'showers-day' => ['Rain', 'rain_showers'],
        'showers-night' => ['Rain', 'rain_showers'],
        'snow' => ['Snow', 'snow'],
        'snow-showers-day' => ['Snow', 'snow_showers'],
        'snow-showers-night' => ['Snow', 'snow_showers'],
        'sleet' => ['Snow', 'snow'],
        'rain-snow' => ['Snow', 'snow'],
        'rain-snow-showers-day' => ['Snow', 'snow_showers'],
        'rain-snow-showers-night' => ['Snow', 'snow_showers'],
        'hail' => ['Snow', 'snow'],
        'thunder-rain' => ['Thunderstorm', 'thunderstorm'],
        'thunder-showers-day' => ['Thunderstorm', 'thunderstorm'],
        'thunder-showers-night' => ['Thunderstorm', 'thunderstorm'],
    ];

    public function __construct(
        private readonly string $url,
        private readonly string $key,
        private readonly int $timeout = 8,
        private readonly int $connectTimeout = 5,
        private readonly int $cacheMinutes = 15,
        private readonly int $precision = 2,
    ) {}

    public function name(): string
    {
        return 'visual_crossing';
    }

    /**
     * @return array<string,mixed>
     */
    public function capabilities(): array
    {
        return [
            'plan' => 'visual-crossing',
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
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function current(array $location, string $units, string $locale): array
    {
        $payload = $this->payload($location, $units);
        $current = $payload['currentConditions'] ?? null;

        if (! is_array($current) || ! isset($current['datetime'])) {
            throw WeatherException::malformed('Visual Crossing current block was missing.');
        }

        $offset = $this->offsetHours($payload, $current);
        $isDay = $this->isDay((string) ($current['icon'] ?? ''));
        [$main, $key] = self::ICONS[$current['icon'] ?? ''] ?? ['Clouds', 'overcast'];

        $day = is_array($payload['days'][0] ?? null) ? $payload['days'][0] : [];
        $dayOffset = $this->offsetHours($payload, $day);

        return [
            'provider' => $this->name(),
            'temp' => $current['temp'] ?? null,
            'feels_like' => $current['feelslike'] ?? null,
            'humidity' => $current['humidity'] ?? null,
            'pressure' => $current['pressure'] ?? null,
            'wind_speed' => $current['windspeed'] ?? null,
            'wind_deg' => $current['winddir'] ?? null,
            'visibility' => $current['visibility'] ?? null,
            'clouds' => $current['cloudcover'] ?? null,
            'uv_index' => $current['uvindex'] ?? null,
            'is_day' => $isDay,
            'sunrise' => $this->isoFromLocal($day['sunrise'] ?? null, $dayOffset),
            'sunset' => $this->isoFromLocal($day['sunset'] ?? null, $dayOffset),
            'timezone_offset' => $this->locationOffset($payload) * 3600,
            'condition' => $main,
            'description' => __('weather.'.$key, [], $locale),
            'icon' => $isDay ? '01d' : '01n',
            'icon_url' => null,
            'observed_at' => CarbonImmutable::createFromTimestamp(
                $this->timestamp((string) $current['datetime'], $offset)
            )->toIso8601String(),
        ];
    }

    /**
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function forecast(array $location, string $units, string $locale): array
    {
        $payload = $this->payload($location, $units);
        $days = $payload['days'] ?? null;

        if (! is_array($days) || $days === []) {
            throw WeatherException::malformed('Visual Crossing response was missing daily data.');
        }

        $defaultOffset = $this->locationOffset($payload);

        $allHours = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            foreach ($day['hours'] ?? [] as $hour) {
                if (! is_array($hour) || ! isset($hour['datetime'])) {
                    continue;
                }

                $allHours[] = $this->normalizeHour($hour, $this->offsetHours($payload, $hour, $day), $locale);
            }
        }

        usort($allHours, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $start = $this->currentHourIndex($allHours, $this->currentTimestamp($payload, $defaultOffset));
        $hourly = array_slice($allHours, $start, 24);

        $daily = [];

        foreach ($days as $day) {
            if (! is_array($day) || ! isset($day['datetime'])) {
                continue;
            }

            $offset = $this->offsetHours($payload, $day);
            [$main, $key] = self::ICONS[$day['icon'] ?? ''] ?? ['Clouds', 'overcast'];

            $daily[] = [
                'timestamp' => $this->timestamp($day['datetime'].'T00:00:00', $offset),
                'date' => $day['datetime'],
                'temp_min' => $day['tempmin'] ?? null,
                'temp_max' => $day['tempmax'] ?? null,
                'humidity' => null,
                'wind_speed' => null,
                'pop' => $this->probability($day['precipprob'] ?? null),
                'uv_index' => $day['uvindex'] ?? null,
                'condition' => $main,
                'description' => __('weather.'.$key, [], $locale),
                'icon' => '01d',
                'icon_url' => null,
                'sunrise' => $this->isoFromLocal($day['sunrise'] ?? null, $offset),
                'sunset' => $this->isoFromLocal($day['sunset'] ?? null, $offset),
            ];
        }

        return [
            'provider' => $this->name(),
            'hourly' => $hourly,
            'daily' => $daily,
            'timezone_offset' => $defaultOffset * 3600,
            'capabilities' => [
                'plan' => 'visual-crossing',
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
     * @param  array<string,mixed>  $hour
     * @return array<string,mixed>
     */
    private function normalizeHour(array $hour, int $offset, string $locale): array
    {
        $isDay = $this->isDay((string) ($hour['icon'] ?? ''));
        [$main, $key] = self::ICONS[$hour['icon'] ?? ''] ?? ['Clouds', 'overcast'];
        $timestamp = $this->timestamp((string) $hour['datetime'], $offset);

        return [
            'timestamp' => $timestamp,
            'time' => CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String(),
            'temp' => $hour['temp'] ?? null,
            'feels_like' => $hour['feelslike'] ?? null,
            'humidity' => $hour['humidity'] ?? null,
            'pressure' => null,
            'wind_speed' => $hour['windspeed'] ?? null,
            'pop' => $this->probability($hour['precipprob'] ?? null),
            'condition' => $main,
            'description' => __('weather.'.$key, [], $locale),
            'icon' => $isDay ? '01d' : '01n',
            'icon_url' => null,
        ];
    }

    /**
     * Fetch (and cache) the single Visual Crossing payload both current() and
     * forecast() read from, so a page render costs one provider request.
     * Failures throw, so nothing is cached.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    private function payload(array $location, string $units): array
    {
        $lat = round((float) $location['lat'], $this->precision);
        $lon = round((float) $location['lon'], $this->precision);
        $key = "vc:forecast:{$lat}:{$lon}:{$units}";

        return Cache::remember($key, now()->addMinutes($this->cacheMinutes), function () use ($lat, $lon, $units) {
            return $this->request($lat, $lon, $units);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function request(float $lat, float $lon, string $units): array
    {
        $unitGroup = $units === 'imperial' ? 'us' : 'metric';

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->get("{$this->url}/{$lat},{$lon}", [
                    'key' => $this->key,
                    'unitGroup' => $unitGroup,
                    'include' => 'current,hours,days',
                    'contentType' => 'json',
                ]);
        } catch (ConnectionException $e) {
            throw new WeatherException('errors.provider_unavailable', 'Visual Crossing connection failed: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new WeatherException('errors.provider_error', 'Visual Crossing request failed: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw WeatherException::fromStatus($response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['days'])) {
            throw WeatherException::malformed('Visual Crossing response was not valid.');
        }

        return $data;
    }

    /**
     * The location-wide UTC offset (seconds) reported at the top level.
     *
     * @param  array<string,mixed>  $payload
     */
    private function locationOffset(array $payload): int
    {
        return (int) ($payload['tzoffset'] ?? 0);
    }

    /**
     * The offset (in hours) that applies to a specific record, falling back to
     * its parent day and then the location.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $parent
     */
    private function offsetHours(array $payload, array $record, array $parent = []): int
    {
        return (int) ($record['tzoffset'] ?? $parent['tzoffset'] ?? $payload['tzoffset'] ?? 0);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function currentTimestamp(array $payload, int $defaultOffset): int
    {
        $current = $payload['currentConditions'] ?? [];

        if (is_array($current) && isset($current['datetime'])) {
            return $this->timestamp((string) $current['datetime'], $this->offsetHours($payload, $current));
        }

        return 0;
    }

    /**
     * @param  array<int,array<string,mixed>>  $hours
     */
    private function currentHourIndex(array $hours, int $time): int
    {
        foreach ($hours as $index => $hour) {
            if ($hour['timestamp'] >= $time) {
                return $index;
            }
        }

        return max(0, count($hours) - 1);
    }

    /**
     * Convert a provider-local datetime to the underlying UTC UNIX timestamp.
     */
    private function timestamp(string $localDateTime, int $offsetHours): int
    {
        return CarbonImmutable::parse($localDateTime, 'UTC')->subHours($offsetHours)->getTimestamp();
    }

    private function isoFromLocal(mixed $localDateTime, int $offsetHours): ?string
    {
        if (! is_string($localDateTime) || $localDateTime === '') {
            return null;
        }

        return CarbonImmutable::createFromTimestamp(
            $this->timestamp($localDateTime, $offsetHours)
        )->toIso8601String();
    }

    private function isDay(string $icon): bool
    {
        return ! str_ends_with($icon, '-night');
    }

    private function probability(mixed $value): ?float
    {
        return is_numeric($value) ? ((float) $value) / 100 : null;
    }
}
