<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingFallbackTest extends TestCase
{
    public function test_geocoding_falls_back_to_nominatim_when_open_meteo_fails(): void
    {
        Http::fake([
            '*/geocoding-api.open-meteo.com/v1/search*' => Http::response(['error' => 'rate limited'], 429),
            '*/nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '30.0444',
                'lon' => '31.2357',
                'display_name' => 'Cairo, Egypt',
                'address' => ['city' => 'Cairo', 'state' => 'Cairo', 'country' => 'Egypt'],
            ]], 200),
            '*/nominatim.openstreetmap.org/reverse*' => Http::response(['address' => ['city' => 'Cairo', 'country' => 'Egypt']], 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Cairo']));

        $response->assertOk();
        $response->assertViewHas('location', fn ($location) => $location['city'] === 'Cairo');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'nominatim.openstreetmap.org/search'));
    }

    public function test_an_empty_nominatim_result_does_not_crash(): void
    {
        Http::fake([
            '*/geocoding-api.open-meteo.com/v1/search*' => Http::response(['error' => 'rate limited'], 429),
            '*/nominatim.openstreetmap.org/search*' => Http::response([], 200),
        ]);

        $response = $this->get(route('weather.search', ['city' => 'Nowhereville']));

        $response->assertOk();
        $response->assertSee(__('errors.city_not_found'));
    }
}
