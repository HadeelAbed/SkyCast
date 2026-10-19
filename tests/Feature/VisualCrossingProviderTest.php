<?php

namespace Tests\Feature;

use App\Services\Providers\VisualCrossingProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisualCrossingProviderTest extends TestCase
{
    private const URL = 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline';

    private function provider(): VisualCrossingProvider
    {
        return new VisualCrossingProvider(url: self::URL, key: 'test-key');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function hours(string $date, int $from, int $to): array
    {
        $hours = [];

        for ($hour = $from; $hour <= $to; $hour++) {
            $hours[] = [
                'datetime' => sprintf('%sT%02d:00:00', $date, $hour),
                'temp' => 20,
                'feelslike' => 19,
                'humidity' => 40,
                'windspeed' => 12,
                'precipprob' => 20,
                'icon' => $hour >= 6 && $hour < 18 ? 'partly-cloudy-day' : 'partly-cloudy-night',
            ];
        }

        return $hours;
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(): array
    {
        return [
            'timezone' => 'Asia/Amman',
            'tzoffset' => 3,
            'currentConditions' => [
                'datetime' => '2026-10-19T12:00:00',
                'temp' => 21.0,
                'feelslike' => 20.0,
                'humidity' => 40,
                'pressure' => 1010,
                'windspeed' => 12,
                'winddir' => 270,
                'visibility' => 10,
                'cloudcover' => 25,
                'uvindex' => 5,
                'icon' => 'partly-cloudy-day',
            ],
            'days' => [[
                'datetime' => '2026-10-19',
                'tempmin' => 14,
                'tempmax' => 24,
                'precipprob' => 20,
                'uvindex' => 6,
                'sunrise' => '2026-10-19T06:30:00',
                'sunset' => '2026-10-19T18:10:00',
                'icon' => 'partly-cloudy-day',
                'hours' => [
                    ['datetime' => '2026-10-19T12:00:00', 'temp' => 21, 'feelslike' => 20, 'humidity' => 40, 'windspeed' => 12, 'precipprob' => 20, 'icon' => 'partly-cloudy-day'],
                    ['datetime' => '2026-10-19T13:00:00', 'temp' => 22, 'feelslike' => 21, 'humidity' => 38, 'windspeed' => 13, 'precipprob' => 10, 'icon' => 'clear-day'],
                ],
            ], [
                'datetime' => '2026-10-20',
                'tempmin' => 13,
                'tempmax' => 23,
                'precipprob' => 0,
                'uvindex' => 6,
                'sunrise' => '2026-10-20T06:31:00',
                'sunset' => '2026-10-20T18:09:00',
                'icon' => 'clear-day',
                'hours' => [
                    ['datetime' => '2026-10-20T00:00:00', 'temp' => 16, 'feelslike' => 15, 'humidity' => 50, 'windspeed' => 8, 'precipprob' => 0, 'icon' => 'clear-night'],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $fixture
     */
    private function fake(?array $fixture = null): void
    {
        Http::fake([
            '*visualcrossing.com/*' => Http::response($fixture ?? $this->fixture(), 200),
        ]);
    }

    public function test_current_conditions_are_normalized(): void
    {
        $this->fake();

        $current = $this->provider()->current(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en');

        $this->assertSame('visual_crossing', $current['provider']);
        $this->assertEquals(21.0, $current['temp']);
        $this->assertEquals(5, $current['uv_index']);
        $this->assertEquals(10, $current['visibility']);
        $this->assertSame(10800, $current['timezone_offset']);
        $this->assertSame('01d', $current['icon']);
        $this->assertTrue($current['is_day']);
        $this->assertSame(__('weather.partly_cloudy', [], 'en'), $current['description']);
    }

    public function test_forecast_is_normalized_in_ascending_order(): void
    {
        $this->fake();

        $forecast = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en');

        $this->assertSame('visual_crossing', $forecast['provider']);
        $this->assertSame(10800, $forecast['timezone_offset']);
        $this->assertNotEmpty($forecast['hourly']);
        $this->assertSame('2026-10-19', $forecast['daily'][0]['date']);
        $this->assertEquals(14, $forecast['daily'][0]['temp_min']);
        $this->assertEquals(24, $forecast['daily'][0]['temp_max']);
        $this->assertEquals(6, $forecast['daily'][0]['uv_index']);

        $timestamps = array_column($forecast['hourly'], 'timestamp');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    public function test_hourly_window_crosses_midnight_to_cover_twenty_four_hours(): void
    {
        $fixture = $this->fixture();
        $fixture['days'][0]['hours'] = $this->hours('2026-10-19', 12, 23);
        $fixture['days'][1]['hours'] = $this->hours('2026-10-20', 0, 23);
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertCount(24, $hourly);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T12:00:00', 'UTC')->subHours(3)->timestamp,
            $hourly[0]['timestamp'],
        );
    }

    public function test_a_record_level_timezone_offset_wins(): void
    {
        $fixture = $this->fixture();
        $fixture['days'][0]['hours'][1]['tzoffset'] = 2;
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T13:00:00', 'UTC')->subHours(2)->timestamp,
            $hourly[1]['timestamp'],
        );
    }

    public function test_missing_optional_fields_normalize_to_null(): void
    {
        $fixture = $this->fixture();
        unset($fixture['currentConditions']['uvindex'], $fixture['currentConditions']['visibility']);
        $this->fake($fixture);

        $current = $this->provider()->current(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en');

        $this->assertNull($current['uv_index']);
        $this->assertNull($current['visibility']);
    }
}
