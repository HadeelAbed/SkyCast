<?php

namespace App\Services\Contracts;

/**
 * A weather data source that normalizes its own provider payloads into the
 * app's internal structure.
 *
 * Every implementation must return the identical key shape so the controller
 * and Blade templates never learn which provider answered. Missing values are
 * always null (never invented).
 */
interface WeatherProvider
{
    /**
     * Stable machine name used in configuration, logs and the data contract.
     */
    public function name(): string;

    /**
     * Which forecast features this provider offers.
     *
     * @return array<string,mixed>
     */
    public function capabilities(): array;

    /**
     * Current weather for a location.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function current(array $location, string $units, string $locale): array;

    /**
     * Hourly (next 24 hours) and daily (7 days) forecast for a location.
     *
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function forecast(array $location, string $units, string $locale): array;
}
