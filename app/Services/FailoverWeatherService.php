<?php

namespace App\Services;

use App\Exceptions\WeatherException;
use App\Services\Contracts\WeatherProvider;
use Throwable;

/**
 * Tries providers in order and returns the first success.
 *
 * A provider the ProviderHealth breaker marked down is skipped; if every
 * provider is down the service still attempts them in order, so a transient
 * outage in the skip window never leaves the user without weather. A provider
 * that already failed earlier in the same request is also skipped, so a
 * current()+forecast() render does not repeat a doomed call.
 *
 * Only WeatherException is treated as a handled provider failure; any other
 * Throwable is a provider bug (e.g. malformed data crashing normalization) and
 * is converted to a provider error so it fails over instead of surfacing a 500.
 *
 * When all providers fail, the last WeatherException is rethrown so the
 * controller keeps its normal error handling.
 */
class FailoverWeatherService implements WeatherProvider
{
    /**
     * @var array<int,WeatherProvider>
     */
    private readonly array $providers;

    /**
     * Providers that already failed in this request, keyed by name.
     *
     * @var array<string,bool>
     */
    private array $failed = [];

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
        $skippedByBreaker = false;
        $lastError = null;

        foreach ($this->providers as $provider) {
            $name = $provider->name();

            if ($this->failed[$name] ?? false) {
                continue;
            }

            if ($this->health->isDown($name)) {
                $skippedByBreaker = true;

                continue;
            }

            $attempted = true;
            [$ok, $result, $error] = $this->try($provider, $call);

            if ($ok) {
                return $result;
            }

            $lastError = $error;
        }

        // Everything was skipped by the breaker: try them all rather than
        // leave the user with no weather at all.
        if (! $attempted && $skippedByBreaker) {
            foreach ($this->providers as $provider) {
                if ($this->failed[$provider->name()] ?? false) {
                    continue;
                }

                [$ok, $result, $error] = $this->try($provider, $call);

                if ($ok) {
                    return $result;
                }

                $lastError = $error;
            }
        }

        throw $lastError ?? new WeatherException('errors.provider_unavailable');
    }

    /**
     * Run one provider call, recording health and returning a uniform
     * [success, result, error] tuple.
     *
     * @param  callable(WeatherProvider):array  $call
     * @return array{0:bool,1:mixed,2:WeatherException|null}
     */
    private function try(WeatherProvider $provider, callable $call): array
    {
        $name = $provider->name();

        try {
            $result = $call($provider);

            $this->health->recordSuccess($name);
            unset($this->failed[$name]);

            return [true, $result, null];
        } catch (WeatherException $e) {
            $error = $e;
        } catch (Throwable $e) {
            $error = WeatherException::malformed('Provider '.$name.' failed: '.$e->getMessage());
        }

        $this->health->recordFailure($name);
        $this->failed[$name] = true;

        return [false, null, $error];
    }
}
