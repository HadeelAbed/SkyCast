<?php

namespace App\Providers;

use App\Services\Contracts\WeatherProvider;
use App\Services\GeocodingService;
use App\Services\Providers\OpenMeteoProvider;
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
                userAgent: $config['user_agent'],
                timeout: $config['timeout'],
                connectTimeout: $config['connect_timeout'],
                cacheMinutes: $config['cache']['geocoding'],
                precision: config('skycast.coordinate_precision'),
            );
        });

        $this->app->singleton(WeatherProvider::class, function () {
            $config = config('services.open_meteo');

            return new OpenMeteoProvider(
                baseUrl: $config['forecast_url'],
                timeout: $config['timeout'],
                connectTimeout: $config['connect_timeout'],
                cacheMinutes: $config['cache']['forecast'],
                precision: config('skycast.coordinate_precision'),
            );
        });
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
