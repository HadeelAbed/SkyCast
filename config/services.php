<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Open-Meteo
    |--------------------------------------------------------------------------
    |
    | SkyCast talks to the Open-Meteo APIs entirely from the server, so no API
    | key is required and nothing is exposed to the browser.
    |
    |   - forecast_url  : current + hourly + daily forecast (WMO weather codes)
    |   - geocoding_url : forward geocoding (city name -> coordinates)
    |   - reverse_url   : reverse geocoding (coordinates -> place name)
    |
    | The reverse endpoint defaults to Nominatim (OpenStreetMap), which only
    | asks for an identifying User-Agent.
    |
    */

    'open_meteo' => [
        'forecast_url' => env('OPEN_METEO_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'geocoding_url' => env('OPEN_METEO_GEOCODING_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
        'reverse_url' => env('OPEN_METEO_REVERSE_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'user_agent' => env('OPEN_METEO_USER_AGENT', 'SkyCast/1.0'),
        'timeout' => (int) env('OPEN_METEO_TIMEOUT', 8),
        'connect_timeout' => (int) env('OPEN_METEO_CONNECT_TIMEOUT', 5),
        'cache' => [
            // Minutes. Failed responses are never cached.
            'current' => (int) env('OPEN_METEO_CACHE_CURRENT', 10),
            'forecast' => (int) env('OPEN_METEO_CACHE_FORECAST', 15),
            'geocoding' => (int) env('OPEN_METEO_CACHE_GEOCODING', 1440),
        ],
    ],

];
