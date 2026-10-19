# Multi-provider Weather with Automatic Failover — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a second weather provider (Visual Crossing) behind a `WeatherProvider` interface, with automatic failover when Open-Meteo fails, plus a geocoding fallback.

**Architecture:** `WeatherController` depends on the `WeatherProvider` interface. `OpenMeteoProvider` and `VisualCrossingProvider` normalize their responses into the identical internal shape. `FailoverWeatherService` implements the interface and tries providers in a configured order, skipping ones a `ProviderHealth` circuit breaker marked down. Each provider returns a `provider` key so the contract records the source.

**Tech Stack:** PHP 8.2, Laravel 12, `Illuminate\Support\Facades\Http` + `Cache`, PHPUnit, Pint.

**Spec:** `docs/superpowers/specs/2026-10-19-multi-provider-weather-failover-design.md`

## Global Constraints

- PHP 8.2, Laravel 12. No new Composer/npm dependencies.
- No database. Tests are network-free: every request is faked with `Http::fake`.
- Tests run on `CACHE_STORE=array` and `SESSION_DRIVER=array` (see `phpunit.xml`). Never rely on the file cache in tests.
- The app MUST keep working with **no** Visual Crossing key: the key-less Open-Meteo path alone is a valid state.
- Provider keys are server-side only (config/env); never rendered or sent to the browser.
- Follow existing patterns: constructor injection of config values (see `AppServiceProvider`), `WeatherException` carrying an `errors.*` translation key, `Cache::remember`, `WeatherException::fromStatus`.
- Definition of done for every task: `php artisan test` green and `vendor\bin\pint --dirty` reports `passed`.
- Prefer the `edit`/`write` tools over shell redirection; never corrupt line endings.

## Review Focus

Inputs/conditions the spec implies but a happy-path test would miss. Each gets its own test in the owning task.

1. **Visual Crossing `hours` span midnight** — the "next 24 hours" window must cross into `days[1]` and still yield exactly 24 ordered entries starting at the current hour.
2. **Missing Visual Crossing fields** (`uvindex`, `visibility` at night) must normalize to `null` and render as `—`, never crash.
3. **All providers down with the breaker open** — the request must still attempt the providers and surface `errors.*`, not hang or 500.
4. **Per-record `tzoffset` (DST change) inside the window** — record-level offset must win over the top-level offset so displayed local times stay correct.
5. **Nominatim search fallback shape** — a Nominatim search result (`lat`/`lon`/`display_name`/`address`) must normalize into our location structure; an empty result must not crash.

---

### Task 1: `WeatherProvider` interface and `OpenMeteoProvider`

**Files:**
- Create: `app/Services/Contracts/WeatherProvider.php`
- Move: `app/Services/WeatherService.php` → `app/Services/Providers/OpenMeteoProvider.php`
- Modify: `app/Providers/AppServiceProvider.php:5-43`
- Modify: `app/Http/Controllers/WeatherController.php:6-24`
- Test: `tests/Feature/ProviderBindingTest.php`

**Interfaces:**
- Produces: `App\Services\Contracts\WeatherProvider`
  - `name(): string`
  - `capabilities(): array`
  - `current(array $location, string $units, string $locale): array`
  - `forecast(array $location, string $units, string $locale): array`
- Produces: `App\Services\Providers\OpenMeteoProvider implements WeatherProvider` — same constructor parameters as today's `WeatherService`; `name()` returns `'open_meteo'`; the arrays returned by `current()` and `forecast()` each include `'provider' => 'open_meteo'`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ProviderBindingTest.php`

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ProviderBindingTest`
Expected: FAIL — interface `App\Services\Contracts\WeatherProvider` not found.

- [ ] **Step 3: Create the interface**

Create `app/Services/Contracts/WeatherProvider.php` with the four methods listed in **Interfaces** (with the PHPDoc array shapes copied from the spec §5.1).

- [ ] **Step 4: Move and rename the Open-Meteo service**

Run: `git mv app/Services/WeatherService.php app/Services/Providers/OpenMeteoProvider.php`

In the moved file: change `namespace App\Services;` → `namespace App\Services\Providers;`, rename the class to `OpenMeteoProvider`, add `implements WeatherProvider` (import `App\Services\Contracts\WeatherProvider`), and add:

```php
public function name(): string
{
    return 'open_meteo';
}
```

Add `'provider' => $this->name(),` to the arrays returned by `current()` and `forecast()`. Keep `capabilities()` returning its current array (no `provider` key needed there).

- [ ] **Step 5: Update the container and controller**

In `app/Providers/AppServiceProvider.php`: import `App\Services\Contracts\WeatherProvider` and `App\Services\Providers\OpenMeteoProvider`; delete the `WeatherService` import; replace the `WeatherService` singleton binding with `WeatherProvider` bound to a `new OpenMeteoProvider(...)` using the same arguments as today.

In `app/Http/Controllers/WeatherController.php`: replace the `WeatherService` import/type-hint with `App\Services\Contracts\WeatherProvider` (property name `$weather` may stay).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (27 passing, plus the new binding test). If any existing test fails only because of the added `provider` key, adjust that test.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Extract WeatherProvider interface and OpenMeteoProvider"
```

---

### Task 2: `ProviderHealth` circuit breaker

**Files:**
- Create: `app/Services/ProviderHealth.php`
- Modify: `config/skycast.php`
- Test: `tests/Unit/ProviderHealthTest.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Cache`.
- Produces: `App\Services\ProviderHealth`
  - `__construct(int $threshold, int $cooldownMinutes)`
  - `isDown(string $name): bool`
  - `recordFailure(string $name): void`
  - `recordSuccess(string $name): void`

**Behaviour:** counter key `skycast:provider:fail:{name}`; when a failure count reaches `threshold`, set `skycast:provider:down:{name}` true for `cooldownMinutes`. `recordSuccess` forgets both keys.

- [ ] **Step 1: Write the failing test** — `tests/Unit/ProviderHealthTest.php`

```php
<?php

namespace Tests\Unit;

use App\Services\ProviderHealth;
use Tests\TestCase;

class ProviderHealthTest extends TestCase
{
    public function test_a_provider_is_marked_down_after_the_threshold_and_reset_on_success(): void
    {
        $health = new ProviderHealth(threshold: 3, cooldownMinutes: 5);

        $this->assertFalse($health->isDown('open_meteo'));

        $health->recordFailure('open_meteo');
        $health->recordFailure('open_meteo');
        $this->assertFalse($health->isDown('open_meteo'));

        $health->recordFailure('open_meteo');
        $this->assertTrue($health->isDown('open_meteo'));

        $health->recordSuccess('open_meteo');
        $this->assertFalse($health->isDown('open_meteo'));
    }

    public function test_failures_are_tracked_per_provider(): void
    {
        $health = new ProviderHealth(threshold: 1, cooldownMinutes: 5);

        $health->recordFailure('open_meteo');

        $this->assertTrue($health->isDown('open_meteo'));
        $this->assertFalse($health->isDown('visual_crossing'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ProviderHealthTest`
Expected: FAIL — class `App\Services\ProviderHealth` not found.

- [ ] **Step 3: Implement `ProviderHealth` and config**

Create the class with the keys/behaviour above (use `Cache::get`, `Cache::increment`, `Cache::put`, `Cache::forget`; `Cache::increment` on a missing key starts at 1).

Add to `config/skycast.php`:

```php
'provider_breaker' => [
    'threshold' => (int) env('SKYCAST_PROVIDER_BREAKER_THRESHOLD', 3),
    'cooldown' => (int) env('SKYCAST_PROVIDER_BREAKER_COOLDOWN', 5),
],
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ProviderHealthTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add provider circuit breaker"
```

---

### Task 3: `VisualCrossingProvider`

**Files:**
- Create: `app/Services/Providers/VisualCrossingProvider.php`
- Modify: `config/services.php`
- Modify: `phpunit.xml`
- Modify: `.env.example`
- Test: `tests/Feature/VisualCrossingProviderTest.php`

**Interfaces:**
- Consumes: `App\Services\Contracts\WeatherProvider`, `App\Exceptions\WeatherException`.
- Produces: `App\Services\Providers\VisualCrossingProvider implements WeatherProvider` — `__construct(string $url, string $key, int $timeout = 8, int $connectTimeout = 5, int $cacheMinutes = 15, int $precision = 2)`; `name()` returns `'visual_crossing'`; `current()`/`forecast()` return the identical key shape as `OpenMeteoProvider`, each including `'provider' => 'visual_crossing'`.

**Request:** `GET {url}/{lat},{lon}?key={key}&unitGroup={metric|us}&include=current,hours,days&contentType=json&date1={today}&date2={today+6}`. `imperial` → `us`, otherwise `metric`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/VisualCrossingProviderTest.php`

Fake the endpoint `*visualcrossing.com/*` with this fixture (metrics fixed by the test), then assert the normalized shape:

```php
$fixture = [
    'timezone' => 'Asia/Amman',
    'tzoffset' => 3,
    'currentConditions' => [
        'datetime' => '2026-10-19T12:00:00', 'temp' => 21.0, 'feelslike' => 20.0,
        'humidity' => 40, 'pressure' => 1010, 'windspeed' => 12, 'winddir' => 270,
        'visibility' => 10, 'cloudcover' => 25, 'uvindex' => 5,
        'icon' => 'partly-cloudy-day',
    ],
    'days' => [[
        'datetime' => '2026-10-19', 'tempmin' => 14, 'tempmax' => 24,
        'precipprob' => 20, 'uvindex' => 6, 'sunrise' => '2026-10-19T06:30:00',
        'sunset' => '2026-10-19T18:10:00', 'icon' => 'partly-cloudy-day',
        'hours' => [
            ['datetime' => '2026-10-19T12:00:00', 'temp' => 21, 'feelslike' => 20, 'humidity' => 40, 'windspeed' => 12, 'precipprob' => 20, 'icon' => 'partly-cloudy-day'],
            ['datetime' => '2026-10-19T13:00:00', 'temp' => 22, 'feelslike' => 21, 'humidity' => 38, 'windspeed' => 13, 'precipprob' => 10, 'icon' => 'clear-day'],
        ],
    ], [
        'datetime' => '2026-10-20', 'tempmin' => 13, 'tempmax' => 23,
        'precipprob' => 0, 'uvindex' => 6, 'sunrise' => '2026-10-20T06:31:00',
        'sunset' => '2026-10-20T18:09:00', 'icon' => 'clear-day',
        'hours' => [['datetime' => '2026-10-20T00:00:00', 'temp' => 16, 'feelslike' => 15, 'humidity' => 50, 'windspeed' => 8, 'precipprob' => 0, 'icon' => 'clear-night']],
    ]],
];
```

Assertions:
- `current()['temp'] === 21.0`, `['uv_index'] === 5`, `['visibility'] === 10`, `['timezone_offset'] === 10800`, `['provider'] === 'visual_crossing'`, `['icon'] === '01d'` (day).
- `forecast()['timezone_offset'] === 10800`; `hourly` is non-empty and ordered ascending by `timestamp`; `daily` `temp_min`/`temp_max`/`uv_index` map from `tempmin`/`tempmax`/`uvindex` and `date === '2026-10-19'`.
- A second test reuses the same fake but drops `uvindex`/`visibility` from `currentConditions` and asserts those keys are `null` (Review Focus #2).
- A third test uses a fixture whose `days[0].hours` and `days[1].hours` together hold at least 24 entries and asserts `forecast()['hourly']` has exactly 24 entries, ascending by `timestamp`, with the first entry at the current hour — proving the window crosses midnight correctly (Review Focus #1).
- A fourth test gives one hour record its own `tzoffset` differing from the top-level value and asserts that record's `timestamp`/`time` use the record-level offset, not the top-level one (Review Focus #4).

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=VisualCrossingProviderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the provider**

Implement `payload()` (cached 15 min under key `vc:forecast:{lat}:{lon}:{units}`, never caching failures), `current()` and `forecast()` mirroring `OpenMeteoProvider`'s output keys. Key mapping decisions (from spec §7):
- Time: parse the local `datetime` as UTC then subtract the record `tzoffset` (hours) when present else the top-level `tzoffset` (Review Focus #4); `timezone_offset = tzoffset * 3600`.
- `pop` = `precipprob / 100`. `is_day` from the icon suffix (`-night` → false). `icon` output `'01d'`/`'01n'`.
- Icon → `weather.*` key via a private `const ICONS` map; unknown icons fall back to `overcast`.

- [ ] **Step 4: Add config, test env, and `.env.example`**

`config/services.php` (after `open_meteo`):

```php
'visual_crossing' => [
    'url' => env('VISUAL_CROSSING_URL', 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline'),
    'key' => env('VISUAL_CROSSING_API_KEY'),
    'timeout' => (int) env('VISUAL_CROSSING_TIMEOUT', 8),
    'connect_timeout' => (int) env('VISUAL_CROSSING_CONNECT_TIMEOUT', 5),
    'cache' => ['forecast' => (int) env('VISUAL_CROSSING_CACHE_FORECAST', 15)],
],
```

`phpunit.xml`: add `<env name="VISUAL_CROSSING_API_KEY" value=""/>` inside `<php>` so tests are deterministic even if a real key exists in `.env`.

`.env.example`: add a commented block with `VISUAL_CROSSING_API_KEY=`, `VISUAL_CROSSING_TIMEOUT=8`, and a note that the app works without it.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=VisualCrossingProviderTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add VisualCrossingProvider with normalization"
```

---

### Task 4: `FailoverWeatherService`

**Files:**
- Create: `app/Services/FailoverWeatherService.php`
- Modify: `config/skycast.php`
- Test: `tests/Unit/FailoverWeatherServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\Contracts\WeatherProvider`, `App\Services\ProviderHealth`, `App\Exceptions\WeatherException`.
- Produces: `App\Services\FailoverWeatherService implements WeatherProvider` — `__construct(iterable $providers, ProviderHealth $health)`; `name()` returns `'failover'`; `capabilities()` returns the first provider's capabilities.

**Algorithm (`attempt(callable $call): array`):**
1. First pass: for each provider whose `ProviderHealth::isDown($name)` is false, call `$call($provider)`; on success `recordSuccess` and return; on `WeatherException` `recordFailure` and remember the exception.
2. If no provider was attempted (all skipped by the breaker), second pass: try each provider in order the same way.
3. If none succeeded, throw the last `WeatherException` (or `new WeatherException('errors.provider_unavailable')` if there was none).

- [ ] **Step 1: Write the failing test** — `tests/Unit/FailoverWeatherServiceTest.php`

Use a small inline stub provider and a `ProviderHealth` built with `threshold: 1`. Tests:
- first provider returns a payload → returned as-is; second never called (use a flag).
- first throws, second returns → second's payload returned.
- both throw → `WeatherException` propagates.
- with the first marked `recordFailure` (so `isDown` true at threshold 1), only the second is called.
- both providers marked down via `recordFailure` → the service still attempts them (second pass) and returns the first success (Review Focus #3).
- both marked down and both throw → the `WeatherException` still propagates (never hangs, never 500s uncaught).
- `capabilities()` equals the first provider's capabilities.

```php
$ok = new class implements WeatherProvider {
    public function name(): string { return 'open_meteo'; }
    public function capabilities(): array { return ['daily_days' => 7]; }
    public function current(array $l, string $u, string $s): array { return ['provider' => 'open_meteo']; }
    public function forecast(array $l, string $u, string $s): array { return ['hourly' => [], 'daily' => [], 'timezone_offset' => 0, 'capabilities' => []]; }
};
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FailoverWeatherServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the class and config**

Implement per the algorithm. Add to `config/skycast.php`:

```php
'provider_order' => array_values(array_filter(explode(',', env('SKYCAST_PROVIDER_ORDER', 'open_meteo,visual_crossing')))),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=FailoverWeatherServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add failover weather service and provider order config"
```

---

### Task 5: Wire failover, add `provider` to the contract, end-to-end tests

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Http/Controllers/WeatherController.php`
- Modify: `.env.example`
- Test: `tests/Feature/FailoverWeatherTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: contract `provider` (string|null), and a container binding where `WeatherProvider` resolves to a `FailoverWeatherService` built from `skycast.provider_order`, skipping any provider whose key is empty.

- [ ] **Step 1: Write the failing test** — `tests/Feature/FailoverWeatherTest.php`

Fake Open-Meteo as 429 and Visual Crossing as success:

```php
config(['services.visual_crossing.key' => 'test-key']);

Http::fake([
    '*/geocoding-api.open-meteo.com/v1/search*' => Http::response([
        'results' => [['name' => 'Cairo', 'latitude' => 30.0444, 'longitude' => 31.2357, 'country' => 'Egypt']],
    ], 200),
    '*/api.open-meteo.com/v1/forecast*' => Http::response(['error' => 'rate limited'], 429),
    '*visualcrossing.com/*' => Http::response($vcFixture, 200),
]);

$response = $this->get(route('weather.search', ['city' => 'Cairo']));

$response->assertOk();
$response->assertViewHas('provider', 'visual_crossing');
$response->assertSee(__('weather.clear'), false);
```

Reuse the Task 3 fixture (move it to a shared helper or duplicate it). Add a second test: with `config(['services.visual_crossing.key' => null])`, a 429 from Open-Meteo surfaces `__('errors.rate_limited')` and Visual Crossing is never requested.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FailoverWeatherTest`
Expected: FAIL — `provider` view data is `null` (or the failover service is not bound yet).

- [ ] **Step 3: Bind the failover service**

In `AppServiceProvider::register()`, bind `WeatherProvider` to a closure that:
- builds `ProviderHealth` from `config('skycast.provider_breaker.threshold')` / `.cooldown`;
- builds the provider list in `config('skycast.provider_order')` order: `open_meteo` → `OpenMeteoProvider` (always), `visual_crossing` → `VisualCrossingProvider` **only if** `config('services.visual_crossing.key')` is non-empty;
- returns `new FailoverWeatherService($providers, $health)`.

- [ ] **Step 4: Add `provider` to the contract**

In `WeatherController::buildContract()` add `'provider' => $current['provider'] ?? null` (the current block is the source of truth). In `emptyContract()` add `'provider' => null`.

- [ ] **Step 5: Add env docs and run the suite**

Append to `.env.example`:

```
# Provider order for automatic failover (comma separated). Key-less providers
# are skipped automatically. Visual Crossing needs a free API key.
SKYCAST_PROVIDER_ORDER=open_meteo,visual_crossing
```

Run: `php artisan test`
Expected: PASS (all tasks' tests plus the existing suite).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Wire provider failover into the container and contract"
```

---

### Task 6: Geocoding fallback

**Files:**
- Modify: `app/Services/GeocodingService.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Test: `tests/Feature/GeocodingFallbackTest.php`
- Test: modify `tests/Feature/WeatherTest.php`

**Interfaces:**
- Consumes: `App\Exceptions\WeatherException`.
- Produces: `GeocodingService` retries forward geocoding against a Nominatim search endpoint when the Open-Meteo geocoding call fails with a provider/transport error or 429. An empty result is **not** a failure (no fallback).

- [ ] **Step 1: Write the failing test** — `tests/Feature/GeocodingFallbackTest.php`

```php
Http::fake([
    '*/geocoding-api.open-meteo.com/v1/search*' => Http::response(['error' => 'rate limited'], 429),
    '*/nominatim.openstreetmap.org/search*' => Http::response([[
        'lat' => '30.0444', 'lon' => '31.2357',
        'display_name' => 'Cairo, Egypt',
        'address' => ['city' => 'Cairo', 'state' => 'Cairo', 'country' => 'Egypt'],
    ]], 200),
    '*/nominatim.openstreetmap.org/reverse*' => Http::response(['address' => ['city' => 'Cairo', 'country' => 'Egypt']], 200),
]);

$response = $this->get(route('weather.search', ['city' => 'Cairo']));

$response->assertOk();
$response->assertSee('Cairo');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=GeocodingFallbackTest`
Expected: FAIL — the fallback endpoint is not called (page shows a rate-limit error).

Add a second test: fake the Open-Meteo geocoding endpoint as 429 and the Nominatim search endpoint as `[]` (empty). Assert the page responds `Ok` and shows `__('errors.city_not_found')` — an empty fallback result must not crash (Review Focus #5).

- [ ] **Step 3: Implement the fallback**

Add a `searchUrl` constructor argument (Nominatim search). In `resolve()`, wrap the Open-Meteo `request()` so that on `WeatherException` with key `errors.provider_unavailable`, `errors.provider_error`, `errors.malformed_response`, or `errors.rate_limited` it retries `searchUrl` with `format=jsonv2&q={city}&limit=5&accept-language={locale}`. Add a `normalizeNominatim()` that maps `lat`/`lon` → floats, `address.city|town|village|municipality|state` → name, `address.country` → country, `address.state|region` → state, `alternatives` → `count($results)`. Cache the fallback result under a distinct key so it is not confused with Open-Meteo results.

Config: add `'nominatim_search_url' => env('OPEN_METEO_NOMINATIM_SEARCH_URL', 'https://nominatim.openstreetmap.org/search')` to `services.open_meteo`, pass it in `AppServiceProvider`'s `GeocodingService` binding, and add the env line to `.env.example`.

- [ ] **Step 4: Update the affected existing test**

`tests/Feature/WeatherTest.php::test_provider_server_error_is_handled_gracefully` currently fakes only the Open-Meteo geocoding endpoint as 500. Because geocoding now falls back, fake the Nominatim search endpoint as 500 as well so both providers fail and the assertion `__('errors.provider_unavailable')` still holds.

- [ ] **Step 5: Run the suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add Nominatim fallback for forward geocoding"
```

---

### Task 7: Documentation and final verification

**Files:**
- Modify: `docs/backend-implementation-guide.md`
- Modify: `README.md` (only if it lists the architecture)

- [ ] **Step 1: Update the backend guide**

Add: the `WeatherProvider` interface, both providers, `FailoverWeatherService` + `ProviderHealth`, the failover policy (order, triggers, breaker), the Visual Crossing mapping, the geocoding fallback, and the new env vars (`VISUAL_CROSSING_API_KEY`, `SKYCAST_PROVIDER_ORDER`, breaker settings). Note the manual step: create a free Visual Crossing account and set the key on Render.

- [ ] **Step 2: Full verification**

Run: `php artisan test` → Expected: all green.
Run: `vendor\bin\pint --dirty` → Expected: `passed`.
Run: `npm run build` → Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "Document multi-provider failover"
```
