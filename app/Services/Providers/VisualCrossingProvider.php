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
 * (in hours, sometimes fractional); every timestamp here is converted to the
 * underlying UTC instant so local display stays correct even across a DST
 * change inside the window. Day-level fields (hourly `datetime`, `sunrise`,
 * `sunset`) arrive as time-only strings and are anchored to their day.
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

    /**
     * The daily view is a 7-day forecast; Visual Crossing defaults to 15 days,
     * so the extra rows are trimmed before they reach the interface.
     */
    private const FORECAST_DAYS = 7;

    private const HOURS_PER_FORECAST = 24;

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
            'daily_days' => self::FORECAST_DAYS,
            'nominal_coverage_days' => self::FORECAST_DAYS,
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

        $day = is_array($payload['days'][0] ?? null) ? $payload['days'][0] : [];
        $dayDate = is_string($day['datetime'] ?? null) ? $day['datetime'] : null;
        $offset = $this->offsetSeconds($payload, $current, $day);

        $isDay = $this->isDay((string) ($current['icon'] ?? ''));
        [$main, $key] = self::ICONS[$current['icon'] ?? ''] ?? ['Clouds', 'overcast'];

        return [
            'provider' => $this->name(),
            'temp' => $current['temp'] ?? null,
            'feels_like' => $current['feelslike'] ?? null,
            'humidity' => $current['humidity'] ?? null,
            'pressure' => $current['pressure'] ?? null,
            'wind_speed' => $current['windspeed'] ?? null,
            'wind_deg' => $current['winddir'] ?? null,
            'visibility' => $this->visibilityMeters($current['visibility'] ?? null, $units),
            'clouds' => $current['cloudcover'] ?? null,
            'uv_index' => $current['uvindex'] ?? null,
            'is_day' => $isDay,
            'sunrise' => $this->eventIso($day, 'sunrise', $dayDate, $offset),
            'sunset' => $this->eventIso($day, 'sunset', $dayDate, $offset),
            'timezone_offset' => $this->locationOffsetSeconds($payload),
            'condition' => $main,
            'description' => __('weather.'.$key, [], $locale),
            'icon' => $isDay ? '01d' : '01n',
            'icon_url' => null,
            'observed_at' => CarbonImmutable::createFromTimestamp(
                $this->instant($current, $dayDate, $offset)
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

        $days = array_slice(array_values($days), 0, self::FORECAST_DAYS);
        $defaultOffset = $this->locationOffsetSeconds($payload);

        $allHours = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $dayDate = is_string($day['datetime'] ?? null) ? $day['datetime'] : null;

            foreach ($day['hours'] ?? [] as $hour) {
                if (! is_array($hour) || ! isset($hour['datetime'])) {
                    continue;
                }

                $allHours[] = $this->normalizeHour($hour, $this->offsetSeconds($payload, $hour, $day), $dayDate, $locale);
            }
        }

        usort($allHours, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $firstDay = is_array($days[0] ?? null) ? $days[0] : [];
        $current = is_array($payload['currentConditions'] ?? null) ? $payload['currentConditions'] : [];
        $currentOffset = $this->offsetSeconds($payload, $current, $firstDay);
        $start = $this->currentHourIndex(
            $allHours,
            $this->floorToLocalHour($this->instant($current, $firstDay['datetime'] ?? null, $currentOffset), $currentOffset),
        );
        $hourly = array_slice($allHours, $start, self::HOURS_PER_FORECAST);

        $daily = [];

        foreach ($days as $day) {
            if (! is_array($day) || ! isset($day['datetime'])) {
                continue;
            }

            $dayDate = (string) $day['datetime'];
            $offset = $this->offsetSeconds($payload, $day);
            [$main, $key] = self::ICONS[$day['icon'] ?? ''] ?? ['Clouds', 'overcast'];

            $daily[] = [
                'timestamp' => $this->instant($day, $dayDate, $offset),
                'date' => $dayDate,
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
                'sunrise' => $this->eventIso($day, 'sunrise', $dayDate, $offset),
                'sunset' => $this->eventIso($day, 'sunset', $dayDate, $offset),
            ];
        }

        return [
            'provider' => $this->name(),
            'hourly' => $hourly,
            'daily' => $daily,
            'timezone_offset' => $defaultOffset,
            'capabilities' => [
                'plan' => 'visual-crossing',
                'current' => true,
                'hourly' => ! empty($hourly),
                'hourly_granularity_hours' => 1,
                'daily' => ! empty($daily),
                'daily_days' => count($daily),
                'nominal_coverage_days' => self::FORECAST_DAYS,
                'seven_day_forecast' => count($daily) >= self::FORECAST_DAYS,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $hour
     * @return array<string,mixed>
     */
    private function normalizeHour(array $hour, int $offsetSeconds, ?string $dayDate, string $locale): array
    {
        $isDay = $this->isDay((string) ($hour['icon'] ?? ''));
        [$main, $key] = self::ICONS[$hour['icon'] ?? ''] ?? ['Clouds', 'overcast'];
        $timestamp = $this->instant($hour, $dayDate, $offsetSeconds);

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
     * UTC instant for a record: the provider's own epoch value wins when
     * present, otherwise the record's local datetime is combined with its
     * offset.
     *
     * @param  array<string,mixed>  $record
     */
    private function instant(array $record, ?string $dayDate, int $offsetSeconds): int
    {
        if (isset($record['datetimeEpoch']) && is_numeric($record['datetimeEpoch'])) {
            return (int) $record['datetimeEpoch'];
        }

        return $this->localEpoch($this->localDateTime($record['datetime'] ?? null, $dayDate), $offsetSeconds);
    }

    /**
     * UTC instant for a day-level astronomical event (sunrise/sunset), which
     * Visual Crossing returns as a time-only string or an epoch.
     *
     * @param  array<string,mixed>  $day
     */
    private function eventEpoch(array $day, string $key, ?string $dayDate, int $offsetSeconds): ?int
    {
        $epochKey = $key.'Epoch';

        if (isset($day[$epochKey]) && is_numeric($day[$epochKey])) {
            return (int) $day[$epochKey];
        }

        $value = $day[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->localEpoch($this->localDateTime($value, $dayDate), $offsetSeconds);
    }

    /**
     * @param  array<string,mixed>  $day
     */
    private function eventIso(array $day, string $key, ?string $dayDate, int $offsetSeconds): ?string
    {
        $epoch = $this->eventEpoch($day, $key, $dayDate, $offsetSeconds);

        return $epoch === null ? null : CarbonImmutable::createFromTimestamp($epoch)->toIso8601String();
    }

    /**
     * Visual Crossing returns hourly `datetime` and sunrise/sunset as
     * time-only strings ("12:00:00"); only full datetimes are used as-is.
     * Time-only values are anchored to their parent day (or the epoch).
     */
    private function localDateTime(mixed $value, ?string $dayDate): string
    {
        $value = is_string($value) ? $value : '';

        if ($value === '') {
            return $dayDate ?? '1970-01-01';
        }

        if (str_contains($value, 'T') || str_contains($value, '-')) {
            return $value;
        }

        return ($dayDate ?? '1970-01-01').'T'.$value;
    }

    /**
     * Convert a provider-local datetime to the underlying UTC UNIX timestamp.
     */
    private function localEpoch(string $localDateTime, int $offsetSeconds): int
    {
        return CarbonImmutable::parse($localDateTime, 'UTC')->getTimestamp() - $offsetSeconds;
    }

    /**
     * The location-wide offset (seconds) reported at the top level.
     *
     * @param  array<string,mixed>  $payload
     */
    private function locationOffsetSeconds(array $payload): int
    {
        return (int) round(((float) ($payload['tzoffset'] ?? 0)) * 3600);
    }

    /**
     * The offset (seconds) that applies to a specific record, preferring the
     * provider's `offsetseconds` and falling back to the record -> day ->
     * location `tzoffset` (which is reported in hours and may be fractional).
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $parent
     */
    private function offsetSeconds(array $payload, array $record, array $parent = []): int
    {
        if (isset($record['offsetseconds']) && is_numeric($record['offsetseconds'])) {
            return (int) $record['offsetseconds'];
        }

        $hours = $record['tzoffset'] ?? $parent['tzoffset'] ?? $payload['tzoffset'] ?? 0;

        return (int) round(((float) $hours) * 3600);
    }

    /**
     * The start of the local hour containing the given instant, expressed as
     * a UTC instant, so a current time of 12:30 selects the 12:00 bucket.
     */
    private function floorToLocalHour(int $epoch, int $offsetSeconds): int
    {
        return (int) (floor(($epoch + $offsetSeconds) / 3600) * 3600) - $offsetSeconds;
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
     * Visual Crossing reports visibility in kilometres (metric) or miles (us);
     * the contract, like Open-Meteo, uses metres.
     */
    private function visibilityMeters(mixed $value, string $units): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $perUnit = $units === 'imperial' ? 1609.344 : 1000.0;

        return round(((float) $value) * $perUnit, 1);
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
