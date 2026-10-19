<?php

namespace Tests\Unit;

use App\Exceptions\WeatherException;
use App\Services\Contracts\WeatherProvider;
use App\Services\FailoverWeatherService;
use App\Services\ProviderHealth;
use Tests\TestCase;

class FailoverWeatherServiceTest extends TestCase
{
    private function health(int $threshold = 1): ProviderHealth
    {
        return new ProviderHealth(threshold: $threshold, cooldownMinutes: 5);
    }

    /**
     * A provider that blows up with a non-WeatherException error, as a bug in
     * one provider's normalization would.
     */
    private function crashStub(string $name, \Throwable $error): object
    {
        return new class($name, $error) implements WeatherProvider
        {
            public int $calls = 0;

            public function __construct(
                private readonly string $providerName,
                private readonly \Throwable $error,
            ) {}

            public function name(): string
            {
                return $this->providerName;
            }

            public function capabilities(): array
            {
                return ['plan' => $this->providerName];
            }

            public function current(array $location, string $units, string $locale): array
            {
                return $this->respond();
            }

            public function forecast(array $location, string $units, string $locale): array
            {
                return $this->respond();
            }

            private function respond(): array
            {
                $this->calls++;

                throw $this->error;
            }
        };
    }

    /**
     * A provider whose behaviour and call count the test can inspect.
     */
    private function stub(string $name, ?array $payload, ?string $errorKey = null): object
    {
        return new class($name, $payload, $errorKey) implements WeatherProvider
        {
            public int $calls = 0;

            public function __construct(
                private readonly string $providerName,
                private readonly ?array $payload,
                private readonly ?string $errorKey,
            ) {}

            public function name(): string
            {
                return $this->providerName;
            }

            public function capabilities(): array
            {
                return ['plan' => $this->providerName];
            }

            public function current(array $location, string $units, string $locale): array
            {
                return $this->respond();
            }

            public function forecast(array $location, string $units, string $locale): array
            {
                return $this->respond();
            }

            private function respond(): array
            {
                $this->calls++;

                if ($this->errorKey !== null) {
                    throw new WeatherException($this->errorKey);
                }

                return $this->payload ?? [];
            }
        };
    }

    public function test_the_name_is_failover(): void
    {
        $service = new FailoverWeatherService([$this->stub('open_meteo', [])], $this->health());

        $this->assertSame('failover', $service->name());
    }

    public function test_the_first_healthy_provider_wins(): void
    {
        $first = $this->stub('open_meteo', ['provider' => 'open_meteo']);
        $second = $this->stub('visual_crossing', ['provider' => 'visual_crossing']);

        $service = new FailoverWeatherService([$first, $second], $this->health());

        $this->assertSame(['provider' => 'open_meteo'], $service->current([], 'metric', 'en'));
        $this->assertSame(1, $first->calls);
        $this->assertSame(0, $second->calls);
    }

    public function test_failover_uses_the_next_provider(): void
    {
        $first = $this->stub('open_meteo', null, 'errors.rate_limited');
        $second = $this->stub('visual_crossing', ['provider' => 'visual_crossing']);

        $service = new FailoverWeatherService([$first, $second], $this->health());

        $this->assertSame(['provider' => 'visual_crossing'], $service->current([], 'metric', 'en'));
        $this->assertSame(1, $first->calls);
        $this->assertSame(1, $second->calls);
    }

    public function test_an_exception_propagates_when_every_provider_fails(): void
    {
        $service = new FailoverWeatherService([
            $this->stub('open_meteo', null, 'errors.rate_limited'),
            $this->stub('visual_crossing', null, 'errors.provider_unavailable'),
        ], $this->health());

        $this->expectException(WeatherException::class);

        $service->current([], 'metric', 'en');
    }

    public function test_a_provider_marked_down_is_skipped(): void
    {
        $health = $this->health();
        $health->recordFailure('open_meteo');

        $first = $this->stub('open_meteo', ['provider' => 'open_meteo']);
        $second = $this->stub('visual_crossing', ['provider' => 'visual_crossing']);

        $service = new FailoverWeatherService([$first, $second], $health);

        $this->assertSame(['provider' => 'visual_crossing'], $service->current([], 'metric', 'en'));
        $this->assertSame(0, $first->calls);
    }

    public function test_all_providers_down_are_still_attempted(): void
    {
        $health = $this->health();
        $health->recordFailure('open_meteo');
        $health->recordFailure('visual_crossing');

        $first = $this->stub('open_meteo', null, 'errors.provider_unavailable');
        $second = $this->stub('visual_crossing', ['provider' => 'visual_crossing']);

        $service = new FailoverWeatherService([$first, $second], $health);

        $this->assertSame(['provider' => 'visual_crossing'], $service->current([], 'metric', 'en'));
        $this->assertSame(1, $first->calls);
        $this->assertSame(1, $second->calls);
    }

    public function test_all_providers_down_and_failing_throws(): void
    {
        $health = $this->health();
        $health->recordFailure('open_meteo');
        $health->recordFailure('visual_crossing');

        $service = new FailoverWeatherService([
            $this->stub('open_meteo', null, 'errors.provider_unavailable'),
            $this->stub('visual_crossing', null, 'errors.provider_unavailable'),
        ], $health);

        $this->expectException(WeatherException::class);

        $service->current([], 'metric', 'en');
    }

    public function test_capabilities_come_from_the_first_provider(): void
    {
        $service = new FailoverWeatherService([
            $this->stub('open_meteo', []),
            $this->stub('visual_crossing', []),
        ], $this->health());

        $this->assertSame('open_meteo', $service->capabilities()['plan']);
    }

    public function test_a_normalization_crash_fails_over_to_the_next_provider(): void
    {
        $service = new FailoverWeatherService([
            $this->crashStub('open_meteo', new \RuntimeException('boom')),
            $this->stub('visual_crossing', ['provider' => 'visual_crossing']),
        ], $this->health());

        $this->assertSame(['provider' => 'visual_crossing'], $service->current([], 'metric', 'en'));
    }

    public function test_a_normalization_crash_is_rethrown_as_a_weather_exception(): void
    {
        $service = new FailoverWeatherService([
            $this->crashStub('open_meteo', new \RuntimeException('boom')),
        ], $this->health());

        $this->expectException(WeatherException::class);

        $service->current([], 'metric', 'en');
    }

    public function test_a_provider_that_failed_is_not_retried_within_the_same_request(): void
    {
        $first = $this->stub('open_meteo', null, 'errors.provider_unavailable');
        $second = $this->stub('visual_crossing', ['provider' => 'visual_crossing']);

        $service = new FailoverWeatherService([$first, $second], $this->health(threshold: 5));

        $service->current([], 'metric', 'en');
        $service->forecast([], 'metric', 'en');

        $this->assertSame(1, $first->calls);
        $this->assertSame(2, $second->calls);
    }
}
