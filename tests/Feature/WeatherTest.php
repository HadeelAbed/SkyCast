<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * These tests never touch the network. Every Open-Meteo response is faked, so
 * they run without an internet connection.
 */
class WeatherTest extends TestCase
{
    private const FORECAST_URL = '*/api.open-meteo.com/v1/forecast*';

    private const GEOCODE_URL = '*/geocoding-api.open-meteo.com/v1/search*';

    private const REVERSE_URL = '*/nominatim.openstreetmap.org/reverse*';

    /**
     * Fake every endpoint SkyCast uses with sane, complete payloads.
     */
    private function fakeHappyPath(): void
    {
        Http::fake([
            self::GEOCODE_URL => Http::response([
                'results' => [[
                    'name' => 'Cairo',
                    'latitude' => 30.0444,
                    'longitude' => 31.2357,
                    'country' => 'Egypt',
                    'country_code' => 'EG',
                    'admin1' => 'Cairo',
                ]],
            ], 200),

            self::REVERSE_URL => Http::response([
                'address' => ['city' => 'Cairo', 'state' => 'Cairo', 'country' => 'Egypt'],
            ], 200),

            self::FORECAST_URL => Http::response($this->forecastPayload(), 200),
        ]);
    }

    /**
     * A complete Open-Meteo forecast payload.
     */
    private function forecastPayload(int $start = 1700000000, int $hourlyCount = 48, int $code = 0): array
    {
        $hourly = [
            'time' => [],
            'temperature_2m' => [],
            'apparent_temperature' => [],
            'relative_humidity_2m' => [],
            'precipitation_probability' => [],
            'weather_code' => [],
            'wind_speed_10m' => [],
            'visibility' => [],
            'uv_index' => [],
            'is_day' => [],
        ];

        for ($i = 0; $i < $hourlyCount; $i++) {
            $hourly['time'][] = $start + ($i * 3600);
            $hourly['temperature_2m'][] = 28 + ($i % 5);
            $hourly['apparent_temperature'][] = 27 + ($i % 5);
            $hourly['relative_humidity_2m'][] = 40;
            $hourly['precipitation_probability'][] = 35;
            $hourly['weather_code'][] = $code;
            $hourly['wind_speed_10m'][] = 2.5;
            $hourly['visibility'][] = 10000;
            $hourly['uv_index'][] = 5;
            $hourly['is_day'][] = 1;
        }

        $daily = [
            'time' => [],
            'weather_code' => [],
            'temperature_2m_max' => [],
            'temperature_2m_min' => [],
            'sunrise' => [],
            'sunset' => [],
            'uv_index_max' => [],
            'precipitation_probability_max' => [],
        ];

        for ($d = 0; $d < 7; $d++) {
            $daily['time'][] = $start + ($d * 86400);
            $daily['weather_code'][] = $code;
            $daily['temperature_2m_max'][] = 30 + $d;
            $daily['temperature_2m_min'][] = 20 + $d;
            $daily['sunrise'][] = $start + ($d * 86400) + 21600;
            $daily['sunset'][] = $start + ($d * 86400) + 64800;
            $daily['uv_index_max'][] = 5;
            $daily['precipitation_probability_max'][] = 20;
        }

        return [
            'latitude' => 30.0444,
            'longitude' => 31.2357,
            'utc_offset_seconds' => 7200,
            'timezone' => 'Africa/Cairo',
            'current' => [
                'time' => $start,
                'temperature_2m' => 31.5,
                'relative_humidity_2m' => 40,
                'apparent_temperature' => 34.0,
                'is_day' => 1,
                'weather_code' => $code,
                'cloud_cover' => 5,
                'pressure_msl' => 1010,
                'wind_speed_10m' => 12.5,
                'wind_direction_10m' => 45,
            ],
            'hourly' => $hourly,
            'daily' => $daily,
        ];
    }

    /** 1. Home page returns a successful response. */
    public function test_home_page_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk()->assertSee('SkyCast');
    }

    /** 1b. An empty home page asks the browser to locate the visitor. */
    public function test_empty_home_page_requests_automatic_location(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewHas('auto_locate', true);
    }

    /** 1c. A previously saved location is shown on the home page. */
    public function test_home_page_shows_a_saved_location(): void
    {
        $this->fakeHappyPath();

        $response = $this->withSession([
            'skycast.location' => [
                'name' => 'Cairo',
                'country' => 'Egypt',
                'state' => 'Cairo',
                'lat' => 30.0444,
                'lon' => 31.2357,
            ],
        ])->get('/');

        $response->assertOk();
        $response->assertSee('Cairo');
        $response->assertSee('Clear sky');
        $response->assertViewHas('auto_locate', false);
    }

    /** 1d. Searching for a city remembers it for the next home-page visit. */
    public function test_search_remembers_the_location(): void
    {
        $this->fakeHappyPath();

        $this->get(route('weather.search', ['city' => 'Cairo']))->assertOk();

        $this->get('/')->assertOk()->assertSee('Cairo');
    }

    /** 2. Valid city search resolves the location and retrieves weather. */
    public function test_valid_city_search_resolves_location_and_retrieves_weather(): void
    {
        $this->fakeHappyPath();

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee('Cairo');
        $response->assertSee('Clear sky');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'geocoding-api.open-meteo.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.open-meteo.com/v1/forecast'));
    }

    /** 3. Unknown city is handled correctly. */
    public function test_unknown_city_is_handled_correctly(): void
    {
        Http::fake([
            self::GEOCODE_URL => Http::response(['generationtime_ms' => 0.1], 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Nowhereville']));

        $response->assertOk();
        $response->assertSee(__('errors.city_not_found'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.open-meteo.com/v1/forecast'));
    }

    /** 4. Valid latitude and longitude retrieve weather. */
    public function test_valid_coordinates_retrieve_weather(): void
    {
        $this->fakeHappyPath();

        $response = $this->post(route('weather.location'), [
            'lat' => 30.0444,
            'lon' => 31.2357,
        ]);

        $response->assertOk();
        $response->assertSee('Cairo');
        $response->assertSee('Clear sky');
    }

    /** 5. Invalid coordinates are rejected. */
    public function test_invalid_coordinates_are_rejected(): void
    {
        Http::fake();

        $response = $this->post(route('weather.location'), [
            'lat' => 200,
            'lon' => 400,
        ]);

        $response->assertSessionHasErrors(['lat', 'lon']);
        Http::assertNothingSent();
    }

    /** 6. Invalid temperature units are rejected. */
    public function test_invalid_units_are_rejected(): void
    {
        Http::fake();

        $response = $this->get(route('weather.search', [
            'city' => 'Cairo',
            'units' => 'kelvin-ish',
        ]));

        $response->assertSessionHasErrors('units');
        Http::assertNothingSent();
    }

    /** 7. A malformed forecast response is handled gracefully. */
    public function test_malformed_forecast_is_handled_gracefully(): void
    {
        Http::fake([
            self::GEOCODE_URL => Http::response([
                'results' => [['name' => 'Cairo', 'latitude' => 30.0444, 'longitude' => 31.2357, 'country' => 'Egypt']],
            ], 200),
            self::FORECAST_URL => Http::response([], 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee(__('errors.malformed_response'));
    }

    /** 8a. Provider timeout is handled gracefully. */
    public function test_provider_timeout_is_handled_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee(__('errors.provider_unavailable'));
    }

    /** 8b. Provider server error is handled gracefully. */
    public function test_provider_server_error_is_handled_gracefully(): void
    {
        Http::fake([
            self::GEOCODE_URL => Http::response(['error' => 'boom'], 500),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee(__('errors.provider_unavailable'));
    }

    /** 9. API responses are cached as expected. */
    public function test_api_responses_are_cached(): void
    {
        $this->fakeHappyPath();

        $this->get(route('weather.search', ['city' => 'Cairo']))->assertOk();
        $this->get(route('weather.search', ['city' => 'Cairo']))->assertOk();

        // Geocoding + one shared forecast payload = 2 calls, regardless of the
        // second identical request, because every response is cached.
        Http::assertSentCount(2);
    }

    /** 10. Responses missing optional fields do not crash the app. */
    public function test_missing_optional_fields_do_not_crash(): void
    {
        Http::fake([
            self::GEOCODE_URL => Http::response([
                'results' => [['name' => 'Cairo', 'latitude' => 30.0444, 'longitude' => 31.2357, 'country' => 'Egypt']],
            ], 200),

            // No humidity, no pressure, no wind, no sun times.
            self::FORECAST_URL => Http::response([
                'utc_offset_seconds' => 0,
                'current' => [
                    'time' => 1700000000,
                    'temperature_2m' => 20.2,
                    'weather_code' => 3,
                ],
                'hourly' => [
                    'time' => [1700000000],
                    'temperature_2m' => [20],
                ],
                'daily' => [
                    'time' => [1700000000],
                    'temperature_2m_max' => [22],
                    'temperature_2m_min' => [15],
                ],
            ], 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee(__('weather.overcast'));
    }

    /** 11. A true 7-day / hourly forecast is reported as available. */
    public function test_seven_day_forecast_is_reported_as_available(): void
    {
        $this->fakeHappyPath();

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();

        $capabilities = $response->viewData('capabilities');

        $this->assertTrue($capabilities['seven_day_forecast']);
        $this->assertSame(1, $capabilities['hourly_granularity_hours']);
        $this->assertSame(7, $capabilities['daily_days']);
    }

    /** 12. The hourly forecast covers a full 24 hours. */
    public function test_hourly_forecast_covers_twenty_four_hours(): void
    {
        $this->fakeHappyPath();

        $hourly = $this->get(route('weather.search', ['city' => 'Cairo']))
            ->assertOk()
            ->viewData('hourly');

        $this->assertCount(24, $hourly);
    }

    /** 13. Optional current-weather detail fields are surfaced in the view. */
    public function test_optional_detail_fields_are_rendered(): void
    {
        $this->fakeHappyPath();

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee('40%');
        $response->assertSee('km/h');
        $response->assertSee('10 km');
        $response->assertSee('1010 hPa');
        $response->assertSee('35%');
    }
}
