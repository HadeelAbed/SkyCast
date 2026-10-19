<?php

namespace Tests\Feature;

use App\Services\Contracts\WeatherProvider;
use App\Services\FailoverWeatherService;
use Tests\TestCase;

class ProviderBindingTest extends TestCase
{
    public function test_the_container_resolves_a_weather_provider(): void
    {
        $provider = app(WeatherProvider::class);

        $this->assertInstanceOf(WeatherProvider::class, $provider);
        $this->assertInstanceOf(FailoverWeatherService::class, $provider);
        $this->assertSame('failover', $provider->name());
    }

    public function test_the_primary_provider_capabilities_are_exposed(): void
    {
        $this->assertSame('open-meteo', app(WeatherProvider::class)->capabilities()['plan']);
    }
}
