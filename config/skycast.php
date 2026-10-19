<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The first entry is used as the default when nothing is stored in the
    | session. Only these locale codes are accepted from the user or from
    | the /language/{locale} route, which prevents arbitrary input from
    | reaching OpenWeather's "lang" parameter.
    |
    */

    'locales' => ['en', 'ar'],

    /*
    |--------------------------------------------------------------------------
    | Supported Temperature Units
    |--------------------------------------------------------------------------
    |
    | The keys are the values accepted from the user and forwarded to the
    | OpenWeather "units" parameter. "symbol" is what Blade renders, and
    | "wind" describes the wind-speed unit returned by that mode.
    |
    */

    'units' => [
        'metric' => [
            'symbol' => '°C',
            'wind' => 'km/h',
        ],
        'imperial' => [
            'symbol' => '°F',
            'wind' => 'mph',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Temperature Unit
    |--------------------------------------------------------------------------
    */

    'default_units' => 'metric',

    /*
    |--------------------------------------------------------------------------
    | Coordinates Precision For Cache Keys
    |--------------------------------------------------------------------------
    |
    | Coordinates are rounded to this many decimals before building cache
    | keys. Two decimals is roughly 1.1 km, which keeps the cache small
    | without moving the reported weather to a different place.
    |
    */

    'coordinate_precision' => 2,

    /*
    |--------------------------------------------------------------------------
    | Provider Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | After "threshold" consecutive failures a weather provider is marked down
    | for "cooldown" minutes so failover skips it. A success resets the state.
    |
    */

    'provider_breaker' => [
        'threshold' => (int) env('SKYCAST_PROVIDER_BREAKER_THRESHOLD', 3),
        'cooldown' => (int) env('SKYCAST_PROVIDER_BREAKER_COOLDOWN', 5),
    ],

];
