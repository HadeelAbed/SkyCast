<?php

namespace App\Http\Controllers;

use App\Exceptions\WeatherException;
use App\Services\Contracts\WeatherProvider;
use App\Services\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The only controller that builds weather pages.
 *
 * Responsibilities: validate user input, delegate to the geocoding and
 * weather services, and hand a normalized data contract to the Blade view.
 * It performs no HTTP requests of its own and contains no provider logic.
 */
class WeatherController extends Controller
{
    public function __construct(
        private readonly GeocodingService $geocoder,
        private readonly WeatherProvider $weather,
    ) {}

    /**
     * Show the main page. No provider request is made until the visitor
     * searches or shares their location, so this works even before an API
     * key is configured.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'units' => ['sometimes', 'string', Rule::in($this->supportedUnits())],
        ]);

        $units = $this->persistUnits($request, $validated['units'] ?? null);
        $locale = app()->getLocale();
        $saved = $request->session()->get('skycast.location');

        // Returning visitors see their last location straight away; first-time
        // visitors get an empty page that asks the browser for a location.
        if (is_array($saved) && isset($saved['lat'], $saved['lon'])) {
            return view('weather', $this->buildContract($saved, $units, $locale));
        }

        return view('weather', $this->emptyContract($request, $units, $locale, [], autoLocate: true));
    }

    /**
     * Resolve a city name and display its weather.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'units' => ['sometimes', 'string', Rule::in($this->supportedUnits())],
        ]);

        $locale = app()->getLocale();
        $units = $this->persistUnits($request, $validated['units'] ?? null);

        try {
            $location = $this->geocoder->resolve($validated['city'], $locale);
        } catch (WeatherException $e) {
            return view('weather', $this->emptyContract($request, $units, $locale, [
                $this->error($e),
            ]));
        }

        $this->persistLocation($request, $location);

        $contract = $this->buildContract($location, $units, $locale);

        if (($location['alternatives'] ?? 0) > 1) {
            $contract['errors'][] = $this->error(new WeatherException('errors.ambiguous_city'));
        }

        return view('weather', $contract);
    }

    /**
     * Display weather for coordinates supplied by the browser.
     */
    public function locate(Request $request): View
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'units' => ['sometimes', 'string', Rule::in($this->supportedUnits())],
        ]);

        $locale = app()->getLocale();
        $units = $this->persistUnits($request, $validated['units'] ?? null);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];

        try {
            $location = $this->geocoder->reverse($lat, $lon, $locale);
        } catch (WeatherException) {
            // Reverse geocoding is best effort: keep the coordinates so the
            // weather request can still be attempted.
            $location = [
                'name' => null,
                'country' => null,
                'state' => null,
                'lat' => $lat,
                'lon' => $lon,
                'alternatives' => 0,
            ];
        }

        $this->persistLocation($request, $location);

        return view('weather', $this->buildContract($location, $units, $locale));
    }

    /**
     * Turn a resolved location into the full Blade data contract.
     *
     * Current and forecast are fetched independently so a forecast failure
     * (for example an unavailable endpoint) never hides the current weather.
     *
     * @param  array{name:?string,country:?string,state:?string,lat:float,lon:float,alternatives:int}  $location
     * @return array<string,mixed>
     */
    private function buildContract(array $location, string $units, string $locale): array
    {
        $errors = [];
        $current = null;

        try {
            $current = $this->weather->current($location, $units, $locale);
        } catch (WeatherException $e) {
            $errors[] = $this->error($e);
        }

        $hourly = [];
        $daily = [];
        $timezoneOffset = null;
        $capabilities = $this->weather->capabilities();

        try {
            $forecast = $this->weather->forecast($location, $units, $locale);
            $hourly = $forecast['hourly'];
            $daily = $forecast['daily'];
            $timezoneOffset = $forecast['timezone_offset'] ?? null;
            $capabilities = $forecast['capabilities'];
        } catch (WeatherException $e) {
            $errors[] = $this->error($e);
        }

        return [
            'location' => [
                'city' => $location['name'],
                'country' => $location['country'],
                'state' => $location['state'] ?? null,
                'lat' => $location['lat'],
                'lon' => $location['lon'],
            ],
            'current' => $current,
            'provider' => $current['provider'] ?? null,
            'hourly' => $hourly,
            'daily' => $daily,
            // The city's UTC offset drives every local-time display in Blade.
            // Prefer the current-weather value, then the forecast's.
            'timezone_offset' => $current['timezone_offset'] ?? $timezoneOffset,
            'units' => $this->unitsMeta($units),
            'locale' => $locale,
            'updated_at' => now()->toIso8601String(),
            'errors' => $errors,
            'capabilities' => $capabilities,
            'auto_locate' => false,
        ];
    }

    /**
     * The contract used for the home page and for early failures. It carries
     * the active preferences and capabilities but no weather data.
     *
     * @param  array<int,array{key:string,message:string}>  $errors
     * @return array<string,mixed>
     */
    private function emptyContract(Request $request, ?string $units = null, ?string $locale = null, array $errors = [], bool $autoLocate = false): array
    {
        $units ??= $this->persistUnits($request, null);
        $locale ??= app()->getLocale();

        return [
            'location' => null,
            'current' => null,
            'provider' => null,
            'hourly' => [],
            'daily' => [],
            'timezone_offset' => null,
            'units' => $this->unitsMeta($units),
            'locale' => $locale,
            'updated_at' => null,
            'errors' => $errors,
            'capabilities' => $this->weather->capabilities(),
            'auto_locate' => $autoLocate,
        ];
    }

    /**
     * Remember the last resolved location so the home page can show it again.
     *
     * @param  array{name:?string,country:?string,state:?string,lat:float,lon:float}  $location
     */
    private function persistLocation(Request $request, array $location): void
    {
        $request->session()->put('skycast.location', [
            'name' => $location['name'] ?? null,
            'country' => $location['country'] ?? null,
            'state' => $location['state'] ?? null,
            'lat' => $location['lat'],
            'lon' => $location['lon'],
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function supportedUnits(): array
    {
        return array_keys(config('skycast.units', []));
    }

    /**
     * Resolve and remember the requested unit, falling back to the configured
     * default. Request-level validation has already rejected unknown values.
     */
    private function persistUnits(Request $request, ?string $units): string
    {
        $units ??= $request->session()->get('units', config('skycast.default_units'));

        if (! in_array($units, $this->supportedUnits(), true)) {
            $units = config('skycast.default_units');
        }

        $request->session()->put('units', $units);

        return $units;
    }

    /**
     * @return array{temperature:string,temperature_symbol:string,wind:string}
     */
    private function unitsMeta(string $units): array
    {
        $definition = config("skycast.units.{$units}", config('skycast.units.metric'));

        return [
            'temperature' => $units,
            'temperature_symbol' => $definition['symbol'],
            'wind' => $definition['wind'],
        ];
    }

    /**
     * @return array{key:string,message:string}
     */
    private function error(WeatherException $e): array
    {
        return [
            'key' => $e->errorKey,
            'message' => __($e->errorKey),
        ];
    }
}
