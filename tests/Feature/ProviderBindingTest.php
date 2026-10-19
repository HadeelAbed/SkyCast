<?php

namespace Tests\Feature;

use App\Services\Contracts\WeatherProvider;
use Tests\TestCase;

class ProviderBindingTest extends TestCase
{
    public function test_the_container_resolves_a_weather_provider(): void
    {
        $provider = app(WeatherProvider::class);

        $this->assertInstanceOf(WeatherProvider::class, $provider);
        $this->assertSame('open_meteo', $provider->name());
    }
}
