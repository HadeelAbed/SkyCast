# SkyCast — Multi-provider weather with automatic failover (Design)

- **Date:** 2026-10-19
- **Status:** Approved (conversational); awaiting written-spec review
- **Author:** SkyCast team (design brainstorm)
- **Related:** `docs/backend-implementation-guide.md`, `cloudflare/open-meteo-proxy.js`

## 1. Context and problem

SkyCast fetches all weather from **Open-Meteo** through `App\Services\WeatherService`.
Open-Meteo applies free-tier limits **per IP address**, and hosting providers that
share an outbound IP (Render, Vercel, Netlify) are throttled early and return
**HTTP 429**. The current workaround is a Cloudflare Worker proxy (see
`cloudflare/open-meteo-proxy.js`), which makes Open-Meteo count the limit per
Cloudflare user.

If Open-Meteo is unavailable for any reason — 429, 5xx, timeout, or a rejected
request — the page currently shows an error instead of weather. There is a single
point of failure.

## 2. Goals

1. **Reliability:** if the primary weather provider fails, automatically use a
   second provider so the forecast keeps working.
2. **Clean architecture:** introduce a provider abstraction (`WeatherProvider`
   interface) so providers are swappable, testable, and easy to extend.
3. **No regression:** a missing second-provider key must not break the app; the
   app keeps working with Open-Meteo alone.
4. **Geocoding resilience:** the same shared-IP risk affects forward geocoding,
   so add a geocoding fallback too.

## 3. Non-goals

- No UI redesign. The Blade contract shape stays the same (plus one new field).
- No database, queue, or background jobs.
- No provider admin UI or runtime dashboard.
- No third provider; two is enough for this milestone.

## 4. Decision

**Approach A — provider interface + failover orchestrator.**

- `WeatherProvider` interface with `capabilities()`, `current()`, `forecast()`,
  and `name()`.
- `OpenMeteoProvider` — today's `WeatherService` logic (requests go through the
  configured Open-Meteo base URL, i.e. the Cloudflare Worker).
- `VisualCrossingProvider` — new; normalizes Visual Crossing Timeline responses
  into the identical internal structure.
- `FailoverWeatherService` — implements `WeatherProvider`; tries providers in a
  configured order and moves to the next on a provider failure.
- `GeocodingService` gains an internal fallback (Open-Meteo geocoding →
  Nominatim search), keeping its current public API.

Rejected alternatives: a single service with internal `try/catch` (tangled,
hard to test, does not showcase design) and a Laravel `Manager` pattern
(needless complexity for two providers).

## 5. Architecture

### 5.1 Interface

```php
namespace App\Services\Contracts;

interface WeatherProvider
{
    /** @return array<string,mixed> */
    public function capabilities(): array;

    /** @param array{lat:float,lon:float} $location
     *  @return array<string,mixed> */
    public function current(array $location, string $units, string $locale): array;

    /** @param array{lat:float,lon:float} $location
     *  @return array{hourly:array<int,array<string,mixed>>,daily:array<int,array<string,mixed>>,timezone_offset:int|null,capabilities:array<string,mixed>} */
    public function forecast(array $location, string $units, string $locale): array;

    /** Stable identifier, e.g. "open_meteo". */
    public function name(): string;
}
```

The exact return shape is the one the controller already consumes, so the Blade
view and the controller stay provider-agnostic.

### 5.2 Components

| Component | Responsibility | Depends on |
|-----------|----------------|------------|
| `WeatherProvider` (interface) | Contract every provider implements | — |
| `OpenMeteoProvider` | Fetch + normalize Open-Meteo; HTTP, caching | `Http`, `Cache` |
| `VisualCrossingProvider` | Fetch + normalize Visual Crossing; HTTP, caching | `Http`, `Cache` |
| `FailoverWeatherService` | Ordered attempts, circuit breaker, `provider` attribution | providers, `ProviderHealth` |
| `ProviderHealth` | Track consecutive failures; open/close a breaker via cache | `Cache` |
| `GeocodingService` | Forward/reverse geocoding with internal forward fallback | `Http`, `Cache` |
| `WeatherController` | Unchanged, except type-hint `WeatherProvider` | `WeatherProvider`, `GeocodingService` |

### 5.3 Wiring (AppServiceProvider)

- Bind each concrete provider with its own config block
  (`services.open_meteo`, `services.visual_crossing`).
- Bind `WeatherProvider` to a `FailoverWeatherService` built from the providers
  named in `skycast.provider_order`.

```
WeatherController
   └── WeatherProvider (FailoverWeatherService)
          ├── OpenMeteoProvider        (always on)
          └── VisualCrossingProvider   (only if VISUAL_CROSSING_API_KEY is set)
```

## 6. Failover policy

1. **Trigger:** catch `WeatherException` from a weather provider and try the
   next one. Weather providers only throw provider/transport errors
   (`provider_unavailable`, `provider_error`, `malformed_response`,
   `rate_limited`, `invalid_api_key`), so every thrown error is failover-eligible.
2. **Order:** `skycast.provider_order` (default `open_meteo,visual_crossing`).
   Unknown or key-less providers are skipped.
3. **Circuit breaker:** `ProviderHealth` keeps a failure counter per provider
   (cache key `skycast:provider:fail:{name}`). After `threshold` (3) consecutive
   failures it marks the provider down for `cooldown` minutes (default 5,
   cache key `skycast:provider:down:{name}`). A down provider is skipped to
   avoid paying its timeout on every request. If **all** providers are down,
   the order is tried anyway (never fail just because of the breaker).
4. **Success:** on success the counter is cleared and the successful provider
   name is recorded for attribution.
5. **Caching:** each provider caches its own normalized payload (reusing the
   existing 10/15-minute TTLs). Failures are never cached.
6. **Latency budget:** worst case is the sum of provider timeouts. The breaker
   prevents repeated cost once a provider is known bad.
7. **Contract change:** the returned contract gains `provider` (string) with the
   serving provider's `name()`. `capabilities()` on the failover service returns
   the primary provider's capabilities; each provider still returns its own
   `capabilities` inside the `forecast()` result, which the controller already
   reads.

## 7. Visual Crossing provider

### 7.1 Request

```
GET {visual_crossing.url}/{lat},{lon}
    ?key={key}
    &unitGroup=metric|us          # maps from "metric"|"imperial"
    &include=current,hours,days
    &contentType=json
    (no lang: conditions are localized by our own weather.* keys)
```

Coordinates are accepted directly (`lat,lon`), so no geocoding is needed for the
fallback path. `unitGroup`: `imperial` → `us`, otherwise `metric`.

A 7-day window is requested via `date1`/`date2` to avoid returning the default
15 days and inflating response cost.

### 7.2 Normalization (identical keys to `OpenMeteoProvider`)

**current** ← `currentConditions`:
`temp`, `feelslike`→`feels_like`, `humidity`, `pressure`, `windspeed`→`wind_speed`,
`winddir`→`wind_deg`, `visibility`, `cloudcover`→`clouds`, `uvindex`→`uv_index`,
`icon`→`is_day`, `datetime`→`observed_at`, plus `sunrise`/`sunset` from
`days[0]`, and `timezone_offset` from `tzoffset`.

**hourly** (next 24) ← flatten `days[0].hours` + `days[1].hours`, starting at the
hour matching `currentConditions.datetime`:
`datetime`→`timestamp`,`time`; `temp`; `feelslike`→`feels_like`; `humidity`;
`windspeed`→`wind_speed`; `precipprob`→`pop` (÷100); `icon`→condition;
`pressure` and `icon_url` set to `null` for shape parity.

**daily** (7) ← `days[0..6]`:
`tempmin`→`temp_min`; `tempmax`→`temp_max`; `precipprob`→`pop` (÷100);
`uvindex`→`uv_index`; `sunrise`; `sunset`; `icon`→condition; `datetime`→`date`;
`humidity`/`wind_speed`/`icon_url` set to `null`.

### 7.3 Time handling

Visual Crossing returns local datetimes (`yyyy-MM-ddTHH:mm:ss`) unless
`timezone=Z`. Convert to epoch: parse the local string as UTC, then subtract the
offset. Use the record-level `tzoffset` when present, otherwise the top-level
`tzoffset` (hours), because DST can change it inside the window.
`timezone_offset = tzoffset * 3600` (seconds), matching Open-Meteo's
`utc_offset_seconds`.

### 7.4 Icon → condition mapping

Map Visual Crossing icon names to our existing condition categories and
`weather.*` translation keys (same keys the WMO table uses), for example:

| VC icon (day/night variants) | `weather.*` key | category |
|---|---|---|
| `clear-*` | `clear` | Clear |
| `partly-cloudy-*` | `partly_cloudy` | Partly |
| `cloudy` | `overcast` | Clouds |
| `fog` | `fog` | Fog |
| `rain`, `showers-*` | `rain` / `rain_showers` | Rain |
| `snow`, `snow-showers-*`, `sleet`, `hail` | `snow` / `snow_showers` | Snow |
| `thunderstorm`, `thunder-rain` | `thunderstorm` | Thunderstorm |
| `wind` | `partly_cloudy` | Partly |

`is_day` is derived from the icon suffix (`-day`/`-night`); if absent, from the
current time versus `sunrise`/`sunset`.

## 8. Geocoding fallback

Inside `GeocodingService::resolve()`, the forward lookup tries Open-Meteo
geocoding first, then **Nominatim search** (`{reverseUrl}` host with `/search`)
on provider/transport errors or 429. An empty result (`city_not_found`) is a
legitimate "not found" and does **not** trigger a fallback. `reverse()` is
unchanged. The public API and return shape do not change.

## 9. Configuration and environment

`config/services.php`:

```php
'visual_crossing' => [
    'url' => env('VISUAL_CROSSING_URL', 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline'),
    'key' => env('VISUAL_CROSSING_API_KEY'),
    'timeout' => (int) env('VISUAL_CROSSING_TIMEOUT', 8),
    'connect_timeout' => (int) env('VISUAL_CROSSING_CONNECT_TIMEOUT', 5),
    'cache' => ['forecast' => (int) env('VISUAL_CROSSING_CACHE_FORECAST', 15)],
],
```

`config/skycast.php`:

```php
'provider_order' => array_values(array_filter(explode(',', env('SKYCAST_PROVIDER_ORDER', 'open_meteo,visual_crossing')))),
'provider_breaker' => [
    'threshold' => (int) env('SKYCAST_PROVIDER_BREAKER_THRESHOLD', 3),
    'cooldown' => (int) env('SKYCAST_PROVIDER_BREAKER_COOLDOWN', 5), // minutes
],
```

`.env.example` gains `VISUAL_CROSSING_API_KEY=`, `SKYCAST_PROVIDER_ORDER`,
and the breaker settings, with comments. Deployment: add
`VISUAL_CROSSING_API_KEY` on Render (and locally in `.env`). If the key is
absent, `VisualCrossingProvider` is not registered and the app runs on
Open-Meteo only.

## 10. Error handling and user-facing behavior

- If the primary succeeds: identical behavior to today (no visible change),
  plus `provider=open_meteo` in the contract.
- If the primary fails and the fallback succeeds: weather renders, sourced from
  `visual_crossing`; no error is shown to the user.
- If **both** fail: current behavior — `errors` messages render in the status
  area, and the branded error pages cover hard failures.
- The `provider` field is available for a subtle "source" note and for support
  diagnostics; surfacing it in the UI is optional and out of scope.

## 11. Testing strategy (network-free, `Http::fake`)

Add to `tests/Feature` (extend `WeatherTest` or a new `FailoverWeatherTest`):

1. Primary success → Visual Crossing is **not** requested.
2. Primary returns 429 → fallback serves the page; `provider = visual_crossing`.
3. Both fail → error UI renders (existing behavior preserved).
4. `VisualCrossingProvider` normalization unit test against a VC fixture →
   shape matches the Open-Meteo contract (current/hourly/daily/timezone).
5. `ProviderHealth` breaker: after `threshold` failures the provider is skipped
   on the next request; a success resets it.
6. No `VISUAL_CROSSING_API_KEY` → only Open-Meteo is tried; app still works.
7. Geocoding fallback: Open-Meteo geocoding 429 → Nominatim search resolves.

## 12. Files

**New**
- `app/Services/Contracts/WeatherProvider.php`
- `app/Services/Providers/OpenMeteoProvider.php`
- `app/Services/Providers/VisualCrossingProvider.php`
- `app/Services/FailoverWeatherService.php`
- `app/Services/ProviderHealth.php`
- `tests/Unit/VisualCrossingProviderTest.php` (or within the feature test)
- `tests/Feature/FailoverWeatherTest.php`

**Modified**
- `app/Services/WeatherService.php` → **renamed** to
  `app/Services/Providers/OpenMeteoProvider.php` (git history preserved via move)
- `app/Services/GeocodingService.php` → forward fallback
- `app/Providers/AppServiceProvider.php` → bind interface + providers
- `app/Http/Controllers/WeatherController.php` → type-hint `WeatherProvider`;
  add `provider` to the contract
- `config/services.php`, `config/skycast.php`
- `.env.example`
- `docs/backend-implementation-guide.md`
- `resources/views/**` — only if the optional `provider` note is surfaced

## 13. Risks and open questions

- **Visual Crossing free tier counts records, not calls.** `include=current,hours,days`
  over 7 days is roughly 176 records, so a long Open-Meteo outage could exhaust
  the 1000 records/day free tier. Mitigation if needed: request a 2-day hourly
  window in a second scoped call, or shorten the hourly window. Documented, not
  implemented in this milestone.
- **Two timezone conversions** (Open-Meteo `utc_offset_seconds` vs Visual
  Crossing `tzoffset` hours) must both produce seconds consistently; covered by
  the fixture test.
- **Key exposure:** the Visual Crossing key stays server-side only (config/env),
  never sent to the browser, matching the current design.
- **Naming decision:** the existing `WeatherService` is **renamed** to
  `OpenMeteoProvider` so that "WeatherService" no longer implies a single
  provider. References in the container bindings and tests are updated.
