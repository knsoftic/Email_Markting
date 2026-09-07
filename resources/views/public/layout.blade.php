<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Email preferences')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body class="bg-ink-100 font-sans antialiased">
    <div class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-5 py-12">

        <div class="kn-card overflow-hidden">
            <div class="border-b border-ink-200/70 bg-white px-6 py-5">
                {{-- The sending account is named, not KN Softic: the recipient
                     signed up with the customer, not with the platform. --}}
                <p class="text-sm font-semibold text-ink-900">{{ $heading ?? 'Email preferences' }}</p>
                @isset($subheading)
                    <p class="mt-1 text-sm text-ink-500">{{ $subheading }}</p>
                @endisset
            </div>

            <div class="px-6 py-6">
                @yield('content')
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-ink-400">
            Sent with {{ $brand['company_name'] ?? 'KN Softic' }}
        </p>
    </div>
</body>
</html>
