@extends('layouts.skycast')

@section('content')
@php
    use Carbon\CarbonImmutable;

    $locale = $locale ?? app()->getLocale();
    $symbol = $units['temperature_symbol'] ?? '°C';
    $windUnit = $units['wind'] ?? 'km/h';
    $currentUnits = $units['temperature'] ?? 'metric';
    $otherUnits = $currentUnits === 'imperial' ? 'metric' : 'imperial';
    $otherUnitsLabel = $otherUnits === 'imperial' ? '°F' : '°C';
    $otherLocale = $locale === 'ar' ? 'en' : 'ar';
    $otherLocaleLabel = $otherLocale === 'ar' ? 'العربية' : 'English';

    $hasLocation = ! is_null($location);
    $hasWeather = $hasLocation && ! is_null($current);
    $tz = (int) ($timezone_offset ?? 0);

    $fmtTemp = fn ($value) => is_numeric($value) ? round($value).$symbol : '—';

    $toLocal = function ($iso) use ($tz) {
        return $iso ? CarbonImmutable::parse($iso)->utc()->addSeconds($tz) : null;
    };

    $fmtClock = function ($iso) use ($toLocal, $locale) {
        $local = $toLocal($iso);

        return $local ? $local->locale($locale)->translatedFormat('g:i A') : '—';
    };

    $nowLocal = CarbonImmutable::now('UTC')->addSeconds($tz)->locale($locale);

    $unitUrl = ($hasLocation && ! empty($location['city']))
        ? route('weather.search', ['city' => $location['city'], 'units' => $otherUnits])
        : route('weather.index', ['units' => $otherUnits]);

    $searchQuery = $hasLocation ? ($location['city'] ?? '') : '';

    $errors = $errors ?? [];
    $capabilities = $capabilities ?? [];
    $autoLocate = $auto_locate ?? false;

    // ---- current-condition presentation ----
    $main = $hasWeather ? strtolower((string) $current['condition']) : '';
    $code = $hasWeather ? ($current['icon'] ?? null) : null;
    $isNight = is_string($code) && str_ends_with($code, 'n');

    $kind = 'cloudy';

    if (str_contains($main, 'thunder') || str_contains($main, 'rain') || str_contains($main, 'drizzle') || str_contains($main, 'snow')) {
        $kind = 'rainy';
    } elseif (str_contains($main, 'clear')) {
        $kind = 'sunny';
    }

    if ($isNight) {
        $kind = 'night';
    }

    $today = $daily[0] ?? null;
    $high = $today['temp_max'] ?? ($current['temp'] ?? null);
    $low = $today['temp_min'] ?? ($current['temp'] ?? null);

    $windSpeed = $current['wind_speed'] ?? null;
    $windDeg = $current['wind_deg'] ?? null;

    $compass = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    $cardinal = is_numeric($windDeg) ? $compass[((int) round(((float) $windDeg) / 45)) % 8] : null;

    $kmh = is_numeric($windSpeed)
        ? match ($windUnit) {
            'mph' => $windSpeed * 1.60934,
            'm/s' => $windSpeed * 3.6,
            default => (float) $windSpeed,
        }
        : null;

    $beaufortKey = match (true) {
        $kmh === null => null,
        $kmh <= 1 => 'wind.calm',
        $kmh <= 11 => 'wind.light',
        $kmh <= 19 => 'wind.gentle',
        $kmh <= 28 => 'wind.moderate',
        $kmh <= 38 => 'wind.fresh',
        default => 'wind.strong',
    };

    $windContext = trim(implode(' · ', array_filter([$cardinal, $beaufortKey ? __($beaufortKey) : null])));

    $visMeters = $current['visibility'] ?? null;
    $visKm = is_numeric($visMeters) ? round($visMeters / 1000, 1) : null;
    $visContext = $visKm === null ? null : ($visKm >= 10 ? 'highlights.visibility.clear' : 'highlights.visibility.reduced');

    $pressure = $current['pressure'] ?? null;
    $pressureContext = ! is_numeric($pressure) ? null : ($pressure < 1000 ? 'highlights.pressure.low' : ($pressure > 1020 ? 'highlights.pressure.high' : 'highlights.pressure.normal'));

    $uv = $current['uv_index'] ?? null;
    $uvValue = is_numeric($uv) ? (string) (int) round($uv) : '—';
    $uvContext = ! is_numeric($uv) ? null : match (true) {
        $uv < 3 => 'uv.low',
        $uv < 6 => 'uv.moderate',
        $uv < 8 => 'uv.high',
        $uv < 11 => 'uv.very_high',
        default => 'uv.extreme',
    };

    // ---- sun ----
    $sunriseLocal = $hasWeather ? $toLocal($current['sunrise'] ?? null) : null;
    $sunsetLocal = $hasWeather ? $toLocal($current['sunset'] ?? null) : null;
    $sunAvailable = $sunriseLocal && $sunsetLocal;

    $dayProgress = null;
    $isDaytime = false;

    if ($sunAvailable) {
        $nowMin = $nowLocal->hour * 60 + $nowLocal->minute;
        $riseMin = $sunriseLocal->hour * 60 + $sunriseLocal->minute;
        $setMin = $sunsetLocal->hour * 60 + $sunsetLocal->minute;

        if ($setMin > $riseMin) {
            $dayProgress = max(0, min(100, (int) round(($nowMin - $riseMin) / ($setMin - $riseMin) * 100)));
            $isDaytime = $nowMin >= $riseMin && $nowMin <= $setMin;
        }
    }

    // ---- summary ----
    $summary = null;

    if ($hasWeather) {
        $parts = [];
        $parts[] = rtrim((string) $current['description'], '.').'.';

        if (is_numeric($current['feels_like'] ?? null)) {
            $parts[] = __('summary.feels', ['temp' => $fmtTemp($current['feels_like'])]);
        }

        if (is_numeric($high) && is_numeric($low)) {
            $parts[] = __('summary.high_low', ['high' => $fmtTemp($high), 'low' => $fmtTemp($low)]);
        }

        if (is_numeric($windSpeed)) {
            $speed = round($windSpeed).' '.$windUnit;
            $parts[] = $cardinal
                ? __('summary.wind_dir', ['dir' => $cardinal, 'speed' => $speed])
                : __('summary.wind', ['speed' => $speed]);
        }

        $summary = trim(implode(' ', array_filter($parts)));
    }

    // ---- weekly range ----
    $rangeValues = [];
    foreach ($daily as $day) {
        if (is_numeric($day['temp_min'] ?? null)) {
            $rangeValues[] = (float) $day['temp_min'];
        }
        if (is_numeric($day['temp_max'] ?? null)) {
            $rangeValues[] = (float) $day['temp_max'];
        }
    }
    $rangeMin = $rangeValues ? min($rangeValues) : 0;
    $rangeMax = $rangeValues ? max($rangeValues) : 1;
    $rangeSpan = max(1, $rangeMax - $rangeMin);
@endphp

<header class="topbar">
    <div class="wrap header-inner">
        <a class="brand" href="{{ route('weather.index') }}" aria-label="SkyCast">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32"><path d="M7 23h17a5 5 0 0 0 .1-10A8 8 0 0 0 9 14a4.5 4.5 0 0 0-2 9Z"></path></svg>
            </span>
            <span class="brand-name">SkyCast</span>
        </a>
        <nav class="nav-links" aria-label="{{ __('app.title') }}">
            <a href="#today">{{ __('nav.today') }}</a>
            <a href="#forecast">{{ __('nav.forecast') }}</a>
            <a href="#about">{{ __('nav.about') }}</a>
        </nav>
        <div class="header-tools">
            <a class="pill" href="{{ route('language.switch', $otherLocale) }}" aria-label="{{ __('language.label') }}">{{ $otherLocaleLabel }}</a>
            <a class="pill" href="{{ $unitUrl }}" aria-label="{{ __('units.label') }}">{{ $symbol }} / {{ $otherUnitsLabel }}</a>
        </div>
    </div>
    <div class="search-band">
        <div class="wrap">
            <div class="search-row">
                <form class="search-wrap" method="GET" action="{{ route('weather.search') }}" role="search">
                    <x-icon name="search" />
                    <label class="sr-only" for="city">{{ __('search.placeholder') }}</label>
                    <input class="search-input" id="city" name="city" value="{{ $searchQuery }}" placeholder="{{ __('search.placeholder') }}" autocomplete="off" required>
                    <button id="clear-search" type="button" class="clear-btn {{ empty($searchQuery) ? 'hidden' : '' }}" aria-label="{{ __('search.clear') }}">
                        <x-icon name="x" />
                    </button>
                    <input type="hidden" name="units" value="{{ $currentUnits }}">
                </form>
                <form id="geo-form" method="POST" action="{{ route('weather.location') }}">
                    @csrf
                    <input type="hidden" name="units" value="{{ $currentUnits }}">
                    <input type="hidden" name="lat" id="geo-lat">
                    <input type="hidden" name="lon" id="geo-lon">
                    <button type="button" class="pill location-trigger" id="geo-button" data-unsupported="{{ __('geo.unsupported') }}" data-failed="{{ __('geo.failed') }}" @if ($autoLocate) data-auto-locate="1" @endif>
                        <x-icon name="map-pin" /><span>{{ __('geo.button') }}</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<main class="wrap" id="today">
    <div class="intro">
        <div>
            <p class="eyebrow">{{ __('hero.eyebrow') }}</p>
            <h1>{{ __('hero.title') }}</h1>
            <p class="intro-sub">{{ __('hero.tagline') }}</p>
        </div>
        @if ($hasWeather)
            <span class="live-badge">
                <x-icon name="map-pin" />
                {{ __('hero.updated', ['time' => $nowLocal->translatedFormat('g:i A')]) }}
            </span>
        @endif
    </div>

    @if (! empty($errors))
        <div class="status" role="status" aria-live="polite">
            <div>
                <strong>{{ __('app.title') }}</strong>
                <ul>
                    @foreach ($errors as $error)
                        <li>{{ $error['message'] ?? '' }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($hasWeather)
        <div id="weather-view">
            <section class="hero {{ $kind }}" aria-labelledby="weather-heading">
                <img class="hero-photo" loading="lazy" src="https://images.pexels.com/photos/38220309/pexels-photo-38220309.jpeg" alt="">
                <div class="hero-inner">
                    <div>
                        <div class="hero-location">
                            <x-icon name="map-pin" />
                            <span>{{ collect([$location['state'] ?? null, $location['city'] ?? null, $location['country'] ?? null])->filter()->implode(', ') ?: __('location.unknown') }}</span>
                        </div>
                        <div class="hero-date">{{ $nowLocal->translatedFormat('l, j F · g:i A') }}</div>
                        <div class="hero-temp">{{ $fmtTemp($current['temp']) }}</div>
                        <h2 class="condition" id="weather-heading">{{ $current['description'] }}</h2>
                        <div class="hero-metrics">
                            <span>{{ __('current.feels_like') }} {{ $fmtTemp($current['feels_like']) }}</span>
                            <span>{{ __('hero.high') }} {{ $fmtTemp($high) }} · {{ __('hero.low') }} {{ $fmtTemp($low) }}</span>
                        </div>
                        @if ($summary)
                            <p class="hero-summary">{{ $summary }}</p>
                        @endif
                    </div>
                    <div class="weather-art" aria-hidden="true">
                        <x-weather-icon :condition="$current['condition']" :code="$current['icon']" />
                    </div>
                </div>
            </section>

            <section aria-labelledby="highlights-heading">
                <div class="section-head">
                    <h2 id="highlights-heading">{{ __('highlights.heading') }}</h2>
                    <p>{{ __('highlights.caption') }}</p>
                </div>
                <div class="highlights">
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="droplets" /></div>
                        <div class="highlight-label">{{ __('current.humidity') }}</div>
                        <div class="highlight-value">{{ is_numeric($current['humidity']) ? round($current['humidity']).'%' : '—' }}</div>
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="wind" /></div>
                        <div class="highlight-label">{{ __('current.wind') }}</div>
                        <div class="highlight-value">{{ is_numeric($windSpeed) ? round($windSpeed).' '.$windUnit : '—' }}</div>
                        @if ($windContext)
                            <div class="highlight-context">{{ $windContext }}</div>
                        @endif
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="eye" /></div>
                        <div class="highlight-label">{{ __('highlights.visibility') }}</div>
                        <div class="highlight-value">{{ $visKm === null ? '—' : $visKm.' '.__('units.km') }}</div>
                        @if ($visContext)
                            <div class="highlight-context">{{ __($visContext) }}</div>
                        @endif
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="gauge" /></div>
                        <div class="highlight-label">{{ __('current.pressure') }}</div>
                        <div class="highlight-value">{{ is_numeric($pressure) ? round($pressure).' hPa' : '—' }}</div>
                        @if ($pressureContext)
                            <div class="highlight-context">{{ __($pressureContext) }}</div>
                        @endif
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="sun" /></div>
                        <div class="highlight-label">{{ __('uv.heading') }}</div>
                        <div class="highlight-value">{{ $uvValue }}</div>
                        @if ($uvContext)
                            <div class="highlight-context">{{ __($uvContext) }}</div>
                        @endif
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="sunrise" /></div>
                        <div class="highlight-label">{{ __('sun.sunrise') }}</div>
                        <div class="highlight-value">{{ $sunAvailable ? $sunriseLocal->locale($locale)->translatedFormat('g:i A') : '—' }}</div>
                    </article>
                    <article class="card highlight">
                        <div class="highlight-icon"><x-icon name="sunset" /></div>
                        <div class="highlight-label">{{ __('sun.sunset') }}</div>
                        <div class="highlight-value">{{ $sunAvailable ? $sunsetLocal->locale($locale)->translatedFormat('g:i A') : '—' }}</div>
                    </article>
                </div>
            </section>

            <div id="forecast">
                <section aria-labelledby="hourly-heading">
                    <div class="section-head">
                        <h2 id="hourly-heading">{{ __('hourly.heading') }}</h2>
                        <p>{{ __('hourly.caption') }}</p>
                    </div>
                    @if (! empty($hourly))
                        <div class="card forecast-card">
                            <div class="hour-scroll" aria-label="{{ __('hourly.heading') }}">
                                @foreach ($hourly as $index => $hour)
                                    @php
                                        $hourLocal = CarbonImmutable::createFromTimestampUTC((int) $hour['timestamp'])->addSeconds($tz)->locale($locale);
                                        $pop = is_numeric($hour['pop'] ?? null) ? (int) round($hour['pop'] * 100) : null;
                                    @endphp
                                    <div class="hour-item {{ $index === 0 ? 'is-now' : '' }}">
                                        <strong>{{ $index === 0 ? __('hero.now') : $hourLocal->translatedFormat('g A') }}</strong>
                                        <div class="hour-icon"><x-weather-icon :condition="$hour['condition']" :code="$hour['icon']" /></div>
                                        <div class="hour-temp">{{ $fmtTemp($hour['temp']) }}</div>
                                        @if ($pop !== null)
                                            <div class="hour-rain"><x-icon name="droplets" /> {{ $pop }}%</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>

                <section aria-labelledby="weekly-heading">
                    <div class="section-head">
                        <h2 id="weekly-heading">{{ __('weekly.heading') }}</h2>
                        <p>{{ __('weekly.caption') }}</p>
                    </div>
                    @if (! empty($daily))
                        <div class="card weekly-card">
                            @foreach ($daily as $index => $day)
                                @php
                                    $dayDate = CarbonImmutable::createFromTimestampUTC((int) ($day['timestamp'] ?? 0))->addSeconds($tz)->locale($locale);
                                    $isToday = $dayDate->toDateString() === $nowLocal->toDateString();
                                    $dayMin = is_numeric($day['temp_min'] ?? null) ? (float) $day['temp_min'] : null;
                                    $dayMax = is_numeric($day['temp_max'] ?? null) ? (float) $day['temp_max'] : null;
                                    $left = $dayMin === null ? 0 : max(0, min(100, ($dayMin - $rangeMin) / $rangeSpan * 100));
                                    $width = ($dayMin === null || $dayMax === null) ? 0 : max(8, min(100 - $left, ($dayMax - $dayMin) / $rangeSpan * 100));
                                @endphp
                                <div class="week-row {{ $isToday ? 'today' : '' }}">
                                    <div class="day-name">
                                        {{ $isToday ? __('weekly.today') : $dayDate->translatedFormat('D') }}
                                        <small>{{ $dayDate->translatedFormat('j M') }}</small>
                                    </div>
                                    <div class="day-condition">
                                        <x-weather-icon :condition="$day['condition']" :code="$day['icon']" />
                                        <span>{{ $day['description'] }}</span>
                                    </div>
                                    <div class="week-low">{{ $fmtTemp($dayMin) }}</div>
                                    <div class="range"><span style="left: {{ round($left, 1) }}%; width: {{ round($width, 1) }}%"></span></div>
                                    <div class="week-high">{{ $fmtTemp($dayMax) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <div class="bottom-grid">
                <section class="card detail-card" aria-labelledby="sun-heading">
                    <h2 id="sun-heading">{{ __('sun.heading') }}</h2>
                    @if ($sunAvailable)
                        <div class="sun-times">
                            <div>
                                <span>{{ __('sun.sunrise') }}</span>
                                <strong>{{ $sunriseLocal->locale($locale)->translatedFormat('g:i A') }}</strong>
                            </div>
                            <div class="align-end">
                                <span>{{ __('sun.sunset') }}</span>
                                <strong>{{ $sunsetLocal->locale($locale)->translatedFormat('g:i A') }}</strong>
                            </div>
                        </div>
                        <div class="sun-path" aria-hidden="true">
                            <div class="sun-arc"></div>
                            <div class="sun-dot {{ $isDaytime ? '' : 'hidden' }}" style="left: {{ $dayProgress ?? 0 }}%"></div>
                        </div>
                        <div class="progress-track" aria-hidden="true">
                            <span class="progress-fill" style="width: {{ $isDaytime ? ($dayProgress ?? 0) : 0 }}%"></span>
                        </div>
                        <p class="detail-note">{{ $isDaytime ? __('sun.progress').': '.($dayProgress ?? 0).'%' : __('sun.night') }}</p>
                    @else
                        <p class="detail-note">{{ __('sun.unavailable') }}</p>
                    @endif
                </section>
                <section class="card detail-card" aria-labelledby="summary-heading">
                    <h2 id="summary-heading">{{ __('summary.heading') }}</h2>
                    <p class="summary-copy">{{ $summary }}</p>
                </section>
            </div>
        </div>
    @elseif (! $hasLocation)
        <div class="status" role="status" aria-live="polite">
            <div>
                <strong>{{ __('empty.title') }}</strong>
                <p>{{ __('empty.hint') }}</p>
            </div>
        </div>
    @endif

    <section class="about" id="about" aria-labelledby="about-heading">
        <h2 id="about-heading">{{ __('about.heading') }}</h2>
        <p>{{ __('about.copy') }}</p>
    </section>
</main>

<footer class="footer">
    <div class="wrap">
        <div class="footer-inner">
            <div class="footer-brand">
                <a class="brand" href="{{ route('weather.index') }}">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32"><path d="M7 23h17a5 5 0 0 0 .1-10A8 8 0 0 0 9 14a4.5 4.5 0 0 0-2 9Z"></path></svg>
                    </span>
                    <span class="brand-name">SkyCast</span>
                </a>
                <p>{{ __('footer.description') }}</p>
            </div>
            <nav class="footer-links" aria-label="{{ __('app.title') }}">
                <a href="{{ route('language.switch', $otherLocale) }}">{{ $otherLocaleLabel }}</a>
                <a href="#about">{{ __('nav.about') }}</a>
                <a href="#privacy">{{ __('footer.privacy') }}</a>
            </nav>
        </div>
        <p class="attribution">
            {{ __('footer.attribution') }}
            <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">open-meteo.com</a>
        </p>
        <p class="attribution" id="privacy">{{ __('footer.privacy_note') }}</p>
    </div>
</footer>
@endsection
