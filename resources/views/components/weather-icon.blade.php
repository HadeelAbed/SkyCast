@props(['condition' => null, 'code' => null])

@php
    $main = is_string($condition) ? strtolower($condition) : '';

    $icon = match (true) {
        str_contains($main, 'thunder') => 'zap',
        str_contains($main, 'drizzle'), str_contains($main, 'rain') => 'cloud-rain',
        str_contains($main, 'snow') => 'snowflake',
        str_contains($main, 'partly') => 'cloud-sun',
        str_contains($main, 'clear') => 'sun',
        str_contains($main, 'cloud') => 'cloud',
        default => 'cloud',
    };

    $isNight = is_string($code) && str_ends_with($code, 'n');

    if ($isNight && in_array($icon, ['sun', 'cloud', 'cloud-sun'], true)) {
        $icon = 'cloud-moon';
    }
@endphp

<x-icon :name="$icon" {{ $attributes }} />
