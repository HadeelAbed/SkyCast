<?php

namespace App\Providers;

use App\Services\GeocodingService;
use App\Services\WeatherService;
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

        $this->app->singleton(WeatherService::class, function () {
            $config = config('services.open_meteo');

            return new WeatherService(
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
        //
    }
}
