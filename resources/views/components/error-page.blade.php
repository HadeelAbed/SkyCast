@props(['code' => '500'])

@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $title = __('errors.http.'.$code.'.title');
    $message = __('errors.http.'.$code.'.message');
    $home = url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} — SkyCast</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #101d35;
            --deep: #1b3152;
            --sky: #3286c8;
            --soft: #eaf4fc;
            --cloud: #f7f9fc;
            --ink: #17263d;
            --muted: #64748b;
            --line: #e4eaf1;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "DM Sans", system-ui, sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, var(--soft) 0, var(--cloud) 440px, var(--cloud) 100%);
        }

        html[lang="ar"] body { font-family: "Noto Sans Arabic", system-ui, sans-serif; }

        .error-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(16, 29, 53, 0.12);
        }

        .error-brand {
            display: inline-block;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: var(--navy);
            text-decoration: none;
        }

        .error-code {
            margin: 24px 0 8px;
            font-size: 72px;
            line-height: 1;
            font-weight: 700;
            color: var(--sky);
        }

        .error-title {
            margin: 0 0 12px;
            font-size: 24px;
            color: var(--navy);
        }

        .error-msg {
            margin: 0 auto 28px;
            max-width: 42ch;
            color: var(--muted);
            line-height: 1.6;
        }

        .error-btn {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 999px;
            background: var(--navy);
            color: #fff;
            font-weight: 500;
            text-decoration: none;
        }

        .error-btn:hover { background: var(--deep); }

        @media (prefers-reduced-motion: no-preference) {
            .error-card { animation: rise 0.4s ease-out both; }
            @keyframes rise {
                from { opacity: 0; transform: translateY(8px); }
                to { opacity: 1; transform: none; }
            }
        }
    </style>
</head>
<body>
    <main class="error-card">
        <a class="error-brand" href="{{ $home }}">SkyCast</a>
        <p class="error-code">{{ $code }}</p>
        <h1 class="error-title">{{ $title }}</h1>
        <p class="error-msg">{{ $message }}</p>
        <a class="error-btn" href="{{ $home }}">{{ __('errors.http.back_home') }}</a>
    </main>
</body>
</html>
