<?php

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SkyCast Web Routes
|--------------------------------------------------------------------------
|
| All weather requests are handled on the server; the OpenWeather API key
| never reaches the browser. Search uses GET so results can be bookmarked.
| Geolocation uses POST because the browser posts coordinates and it needs
| CSRF protection (enabled by default for every POST route).
|
*/

Route::get('/', [WeatherController::class, 'index'])->name('weather.index');

Route::get('/weather/search', [WeatherController::class, 'search'])->name('weather.search');

Route::post('/weather/location', [WeatherController::class, 'locate'])->name('weather.location');

Route::get('/language/{locale}', [LanguageController::class, 'switch'])
    ->whereIn('locale', config('skycast.locales', ['en']))
    ->name('language.switch');
