<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the locale chosen by the user (stored in the session) to every web
 * request. Only locales listed in config/skycast.php are accepted, so a
 * tampered session value can never reach Blade or OpenWeather's "lang" param.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('skycast.locales', ['en']);
        $locale = $request->session()->get('locale', config('app.locale'));

        if (! in_array($locale, $supported, true)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
