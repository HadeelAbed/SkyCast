<?php

namespace App\Providers;

use App\Services\Contracts\WeatherProvider;
use App\Services\FailoverWeatherService;
use App\Services\GeocodingService;
use App\Services\ProviderHealth;
use App\Services\Providers\OpenMeteoProvider;
use App\Services\Providers\VisualCrossingProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The services receive configuration values, not the env() helper, so
        // endpoints stay in one place and are trivial to swap in tests.
        $this->app->singleton(GeocodingService::class, function () {
            $config = config('services.open_meteo');

            return new GeocodingService(
                baseUrl: $config['geocoding_url'],
                reverseUrl: $config['reverse_url'],
                searchUrl: $config['nominatim_search_url'],
                userAgent: $config['user_agent'],
                timeout: $config['timeout'],
                connectTimeout: $config['connect_timeout'],
                cacheMinutes: $config['cache']['geocoding'],
                precision: config('skycast.coordinate_precision'),
            );
        });

        $this->app->singleton(WeatherProvider::class, function () {
            $providers = [];

            foreach (config('skycast.provider_order', []) as $name) {
                $provider = match ($name) {
                    'open_meteo' => $this->openMeteoProvider(),
                    'visual_crossing' => $this->visualCrossingProvider(),
                    default => null,
                };

                if ($provider !== null) {
                    $providers[] = $provider;
                }
            }

            return new FailoverWeatherService(
                providers: $providers,
                health: new ProviderHealth(
                    threshold: (int) config('skycast.provider_breaker.threshold'),
                    cooldownMinutes: (int) config('skycast.provider_breaker.cooldown'),
                ),
            );
        });
    }

    private function openMeteoProvider(): OpenMeteoProvider
    {
        $config = config('services.open_meteo');

        return new OpenMeteoProvider(
            baseUrl: $config['forecast_url'],
            timeout: $config['timeout'],
            connectTimeout: $config['connect_timeout'],
            cacheMinutes: $config['cache']['forecast'],
            precision: config('skycast.coordinate_precision'),
        );
    }

    /**
     * Visual Crossing is optional: without an API key it is skipped entirely
     * so the app keeps working Open-Meteo-only.
     */
    private function visualCrossingProvider(): ?VisualCrossingProvider
    {
        $config = config('services.visual_crossing');

        if (blank($config['key'] ?? null)) {
            return null;
        }

        return new VisualCrossingProvider(
            url: $config['url'],
            key: $config['key'],
            timeout: $config['timeout'],
            connectTimeout: $config['connect_timeout'],
            cacheMinutes: $config['cache']['forecast'],
            precision: config('skycast.coordinate_precision'),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating proxy (e.g. Render), generate https URLs.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
