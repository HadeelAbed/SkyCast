# SkyCast

A Laravel weather app with live conditions, a 24-hour hourly forecast, a 7-day
forecast, and an Arabic (RTL) / English (LTR) interface — backed by
[Open-Meteo](https://open-meteo.com/) (key-less) with automatic failover to
[Visual Crossing](https://www.visualcrossing.com/weather-api) and Nominatim for
geocoding.

## Features

- Current conditions, 7 "weather highlights" (incl. UV index), hourly (24 h) and
  daily (7-day) forecasts, sunrise/sunset.
- City search, browser geolocation, and remembering the last viewed location.
- Metric / imperial units and a full English ⇄ Arabic (RTL) UI.
- Automatic provider failover with a per-provider circuit breaker, so a single
  provider's outage (rate limit, 5xx, timeout) degrades gracefully.
- Server-side caching; failures degrade gracefully with localized messages.
- No API key required. An optional, free Visual Crossing key enables the
  fallback provider.

## Requirements

- PHP 8.2+, Composer, Node.js 20+.

## Setup

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
npm run build
php artisan serve
```

Then open <http://127.0.0.1:8000>.

## Tests

```bash
php artisan test
```

## Documentation

See [`docs/backend-implementation-guide.md`](docs/backend-implementation-guide.md)
for a file-by-file explanation of the backend.
