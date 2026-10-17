@php
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SkyCast — Your World, In Every Forecast.')</title>
    <meta name="description" content="{{ __('app.tagline') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/skycast.css', 'resources/js/skycast.js'])
</head>
<body>
    <div class="shell">
        @yield('content')
    </div>
</body>
</html>
