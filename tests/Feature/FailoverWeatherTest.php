<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\VisualCrossingFixture;
use Tests\TestCase;

class FailoverWeatherTest extends TestCase
{
    private function fakeGeocoding(): array
    {
        return [
            'results' => [['name' => 'Cairo', 'latitude' => 30.0444, 'longitude' => 31.2357, 'country' => 'Egypt']],
        ];
    }

    public function test_the_page_falls_back_to_the_second_provider(): void
    {
        config(['services.visual_crossing.key' => 'test-key']);

        Http::fake([
            '*/geocoding-api.open-meteo.com/v1/search*' => Http::response($this->fakeGeocoding(), 200),
            '*/api.open-meteo.com/v1/forecast*' => Http::response(['error' => 'rate limited'], 429),
            '*visualcrossing.com/*' => Http::response(VisualCrossingFixture::payload(), 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertViewHas('provider', 'visual_crossing');
        $response->assertSee(__('weather.partly_cloudy'));
    }

    public function test_without_a_key_the_primary_provider_error_is_surfaced(): void
    {
        config(['services.visual_crossing.key' => null]);

        Http::fake([
            '*/geocoding-api.open-meteo.com/v1/search*' => Http::response($this->fakeGeocoding(), 200),
            '*/api.open-meteo.com/v1/forecast*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertSee(__('errors.rate_limited'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'visualcrossing.com'));
    }
}
