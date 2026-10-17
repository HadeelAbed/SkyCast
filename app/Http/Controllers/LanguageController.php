<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Switches the interface language between the supported locales.
 */
class LanguageController extends Controller
{
    /**
     * Store the chosen locale in the session and send the visitor back.
     * Only locales listed in config/skycast.php are accepted.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('skycast.locales', ['en']), true), 404);

        $request->session()->put('locale', $locale);
        App::setLocale($locale);

        return $this->safeRedirect($request);
    }

    /**
     * Redirect back to the previous page only when it belongs to this
     * application. Anything else (missing or foreign referer) goes home,
     * which avoids open redirects.
     */
    private function safeRedirect(Request $request): RedirectResponse
    {
        $fallback = route('weather.index');
        $referer = $request->headers->get('referer');

        if (! $referer) {
            return redirect()->to($fallback);
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if ($refererHost && $appHost && strcasecmp($refererHost, $appHost) === 0) {
            return redirect()->to($referer);
        }

        return redirect()->to($fallback);
    }
}
