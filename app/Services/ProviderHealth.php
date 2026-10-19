<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A tiny circuit breaker per weather provider.
 *
 * After `threshold` consecutive failures a provider is marked "down" for
 * `cooldownMinutes`, so failover skips it and the healthy provider answers.
 * A single success resets the counter and lifts the mark.
 */
class ProviderHealth
{
    public function __construct(
        private readonly int $threshold,
        private readonly int $cooldownMinutes,
    ) {}

    public function isDown(string $name): bool
    {
        return (bool) Cache::get($this->downKey($name), false);
    }

    public function recordFailure(string $name): void
    {
        $failKey = $this->failKey($name);
        $count = ((int) Cache::get($failKey, 0)) + 1;

        Cache::put($failKey, $count, now()->addMinutes($this->cooldownMinutes));

        if ($count >= $this->threshold) {
            Cache::put($this->downKey($name), true, now()->addMinutes($this->cooldownMinutes));
        }
    }

    public function recordSuccess(string $name): void
    {
        Cache::forget($this->failKey($name));
        Cache::forget($this->downKey($name));
    }

    private function failKey(string $name): string
    {
        return "skycast:provider:fail:{$name}";
    }

    private function downKey(string $name): string
    {
        return "skycast:provider:down:{$name}";
    }
}
