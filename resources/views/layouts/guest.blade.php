<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
</head>
<body class="font-sans text-ink-800 antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-2">

        {{-- Brand panel: hidden on small screens so the form gets the space. --}}
        <aside class="relative hidden lg:flex flex-col justify-between bg-brand-700 p-12 text-white overflow-hidden">
            <div class="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-white/5"></div>
            <div class="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-black/10"></div>

            <a href="{{ url('/') }}" class="relative z-10 inline-flex">
                <x-application-logo :inverted="true" />
            </a>

            <div class="relative z-10 max-w-md">
                <h1 class="text-3xl font-semibold leading-tight tracking-tight">
                    {{ $brand['tagline'] }}
                </h1>
                <p class="mt-4 text-sm leading-relaxed text-white/70">
                    Run permission-based campaigns, connect your mailboxes over IMAP, and keep every
                    customer reply in one threaded conversation.
                </p>

                <ul class="mt-8 space-y-3 text-sm text-white/80">
                    @foreach ([
                        'Campaigns, scheduling and queue-based bulk sending',
                        'Open, click, reply and unsubscribe tracking',
                        'Connected inbox with threaded campaign replies',
                    ] as $point)
                        <li class="flex items-start gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-white/60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="relative z-10 text-xs text-white/50">{{ $brand['footer_text'] }}</p>
        </aside>

        {{-- Form panel --}}
        <main class="flex items-center justify-center px-5 py-12 sm:px-8 bg-white">
            <div class="w-full max-w-md">
                <a href="{{ url('/') }}" class="mb-8 inline-flex lg:hidden">
                    <x-application-logo />
                </a>

                {{ $slot }}

                <p class="mt-10 text-center text-xs text-ink-400 lg:hidden">
                    {{ $brand['footer_text'] }}
                </p>
            </div>
        </main>
    </div>
</body>
</html>
