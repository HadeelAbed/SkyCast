<?php

namespace App\Services;

use App\Exceptions\WeatherException;
use App\Services\Contracts\WeatherProvider;

/**
 * Tries providers in order and returns the first success.
 *
 * A provider the ProviderHealth breaker marked down is skipped; if every
 * provider is down the service still attempts them in order, so a transient
 * outage in the skip window never leaves the user without weather. When all
 * providers fail, the last WeatherException is rethrown so the controller
 * keeps its normal error handling.
 */
class FailoverWeatherService implements WeatherProvider
{
    /**
     * @var array<int,WeatherProvider>
     */
    private readonly array $providers;

    /**
     * @param  iterable<WeatherProvider>  $providers
     */
    public function __construct(iterable $providers, private readonly ProviderHealth $health)
    {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    public function name(): string
    {
        return 'failover';
    }

    /**
     * @return array<string,mixed>
     */
    public function capabilities(): array
    {
        foreach ($this->providers as $provider) {
            return $provider->capabilities();
        }

        return [];
    }

    /**
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function current(array $location, string $units, string $locale): array
    {
        return $this->attempt(fn (WeatherProvider $provider): array => $provider->current($location, $units, $locale));
    }

    /**
     * @param  array{lat:float,lon:float}  $location
     * @return array<string,mixed>
     */
    public function forecast(array $location, string $units, string $locale): array
    {
        return $this->attempt(fn (WeatherProvider $provider): array => $provider->forecast($location, $units, $locale));
    }

    /**
     * @param  callable(WeatherProvider):array  $call
     * @return array<string,mixed>
     */
    private function attempt(callable $call): array
    {
        $attempted = false;
        $lastError = null;

        foreach ($this->providers as $provider) {
            if ($this->health->isDown($provider->name())) {
                continue;
            }

            $attempted = true;

            try {
                return $this->succeed($provider, $call);
            } catch (WeatherException $e) {
                $this->health->recordFailure($provider->name());
                $lastError = $e;
            }
        }

        // Everything was skipped by the breaker: try them all rather than
        // leave the user with no weather at all.
        if (! $attempted) {
            foreach ($this->providers as $provider) {
                try {
                    return $this->succeed($provider, $call);
                } catch (WeatherException $e) {
                    $this->health->recordFailure($provider->name());
                    $lastError = $e;
                }
            }
        }

        throw $lastError ?? new WeatherException('errors.provider_unavailable');
    }

    /**
     * @param  callable(WeatherProvider):array  $call
     * @return array<string,mixed>
     */
    private function succeed(WeatherProvider $provider, callable $call): array
    {
        $result = $call($provider);

        $this->health->recordSuccess($provider->name());

        return $result;
    }
}
