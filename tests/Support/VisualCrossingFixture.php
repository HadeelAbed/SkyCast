<?php

namespace Tests\Support;

/**
 * A Visual Crossing timeline payload shaped like the real API returns it:
 * `hours[].datetime` and `days[].sunrise/sunset` are time-only strings,
 * `days[].datetime` is a date, and `tzoffset` is a float. Shared by the
 * provider unit test and the end-to-end failover test.
 */
class VisualCrossingFixture
{
    /**
     * @return array<string,mixed>
     */
    public static function payload(): array
    {
        return [
            'timezone' => 'Asia/Amman',
            'tzoffset' => 3.0,
            'currentConditions' => [
                'datetime' => '2026-10-19T12:30:00',
                'temp' => 21.0,
                'feelslike' => 20.0,
                'humidity' => 40,
                'pressure' => 1010,
                'windspeed' => 12,
                'winddir' => 270,
                'visibility' => 10,
                'cloudcover' => 25,
                'uvindex' => 5,
                'icon' => 'partly-cloudy-day',
            ],
            'days' => [
                [
                    'datetime' => '2026-10-19',
                    'tempmin' => 14,
                    'tempmax' => 24,
                    'precipprob' => 20,
                    'uvindex' => 6,
                    'sunrise' => '06:30:00',
                    'sunset' => '18:10:00',
                    'icon' => 'partly-cloudy-day',
                    'hours' => [
                        ['datetime' => '12:00:00', 'temp' => 21, 'feelslike' => 20, 'humidity' => 40, 'windspeed' => 12, 'precipprob' => 20, 'icon' => 'partly-cloudy-day'],
                        ['datetime' => '13:00:00', 'temp' => 22, 'feelslike' => 21, 'humidity' => 38, 'windspeed' => 13, 'precipprob' => 10, 'icon' => 'clear-day'],
                    ],
                ],
                [
                    'datetime' => '2026-10-20',
                    'tempmin' => 13,
                    'tempmax' => 23,
                    'precipprob' => 0,
                    'uvindex' => 6,
                    'sunrise' => '06:31:00',
                    'sunset' => '18:09:00',
                    'icon' => 'clear-day',
                    'hours' => [
                        ['datetime' => '00:00:00', 'temp' => 16, 'feelslike' => 15, 'humidity' => 50, 'windspeed' => 8, 'precipprob' => 0, 'icon' => 'clear-night'],
                    ],
                ],
            ],
        ];
    }
}
