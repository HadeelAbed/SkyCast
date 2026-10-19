<?php

namespace Tests\Feature;

use App\Services\Providers\VisualCrossingProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\VisualCrossingFixture;
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
    private function hours(int $from, int $to): array
    {
        $hours = [];

        for ($hour = $from; $hour <= $to; $hour++) {
            $hours[] = [
                'datetime' => sprintf('%02d:00:00', $hour),
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
        return VisualCrossingFixture::payload();
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
        $this->assertSame(10000.0, $current['visibility']);
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
        $fixture['days'][0]['hours'] = $this->hours(12, 23);
        $fixture['days'][1]['hours'] = $this->hours(0, 23);
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertCount(24, $hourly);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T12:00:00', 'UTC')->subHours(3)->timestamp,
            $hourly[0]['timestamp'],
        );
    }

    public function test_the_current_hour_bucket_is_used_when_minutes_are_present(): void
    {
        $fixture = $this->fixture();
        $fixture['currentConditions']['datetime'] = '2026-10-19T12:59:00';
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T12:00:00', 'UTC')->subHours(3)->timestamp,
            $hourly[0]['timestamp'],
        );
    }

    public function test_a_record_level_timezone_offset_wins(): void
    {
        $fixture = $this->fixture();
        $fixture['days'][0]['hours'][1]['tzoffset'] = 2.0;
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T13:00:00', 'UTC')->subHours(2)->timestamp,
            $hourly[1]['timestamp'],
        );
    }

    public function test_fractional_timezone_offsets_are_preserved(): void
    {
        $fixture = $this->fixture();
        $fixture['tzoffset'] = 5.5;
        $this->fake($fixture);

        $provider = $this->provider();
        $location = ['lat' => 30.0444, 'lon' => 31.2357];
        $current = $provider->current($location, 'metric', 'en');
        $hourly = $provider->forecast($location, 'metric', 'en')['hourly'];

        $this->assertSame(19800, $current['timezone_offset']);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-19T12:00:00', 'UTC')->subMinutes(330)->timestamp,
            $hourly[0]['timestamp'],
        );
    }

    public function test_the_daily_forecast_is_bounded_to_seven_days(): void
    {
        $fixture = $this->fixture();
        $fixture['days'] = [];

        for ($index = 0; $index < 15; $index++) {
            $fixture['days'][] = [
                'datetime' => CarbonImmutable::parse('2026-10-19', 'UTC')->addDays($index)->toDateString(),
                'tempmin' => 10,
                'tempmax' => 20,
                'icon' => 'clear-day',
                'hours' => [],
            ];
        }

        $this->fake($fixture);

        $daily = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['daily'];

        $this->assertCount(7, $daily);
    }

    public function test_a_provider_epoch_is_preferred_over_local_datetime(): void
    {
        $fixture = $this->fixture();
        $epoch = CarbonImmutable::parse('2026-10-19T09:30:00', 'UTC')->timestamp;
        $fixture['days'][0]['hours'][0]['datetimeEpoch'] = $epoch;
        $this->fake($fixture);

        $hourly = $this->provider()->forecast(['lat' => 30.0444, 'lon' => 31.2357], 'metric', 'en')['hourly'];

        $this->assertSame($epoch, $hourly[0]['timestamp']);
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
