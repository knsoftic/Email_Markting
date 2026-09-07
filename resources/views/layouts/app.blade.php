<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
</head>
<body class="font-sans antialiased">
<div x-data="{ open: false }" class="min-h-screen">

    {{-- Mobile drawer --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-40 lg:hidden">
        <div class="absolute inset-0 bg-ink-900/50" @click="open = false"></div>
        <div class="absolute inset-y-0 left-0 w-72 bg-ink-900"
             x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full">
            @include('layouts.partials.sidebar')
        </div>
    </div>

    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 hidden w-64 bg-ink-900 lg:block">
        @include('layouts.partials.sidebar')
    </aside>

    <div class="lg:pl-64">
        @include('layouts.partials.topbar')

        <main class="px-4 py-6 sm:px-6 lg:px-8">
            {{-- Included unconditionally: the partial renders nothing when there
                 is no flash, and an explicit key list here silently swallowed
                 every 'warning' and 'info' message the controllers set. --}}
            @include('layouts.partials.flash')

            {{ $slot }}
        </main>

        <footer class="border-t border-ink-200 px-4 py-5 text-xs text-ink-500 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between">
                <span>{{ $brand['footer_text'] }}</span>
                <span class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    @if ($brand['website'])
                        <a href="{{ $brand['website'] }}" target="_blank" rel="noopener" class="hover:text-ink-700">{{ $brand['website'] }}</a>
                    @endif
                    @if ($brand['support_email'])
                        <a href="mailto:{{ $brand['support_email'] }}" class="hover:text-ink-700">{{ $brand['support_email'] }}</a>
                    @endif
                    @if ($brand['phone'])
                        <span>{{ $brand['phone'] }}</span>
                    @endif
                </span>
            </div>
        </footer>
    </div>
</div>

@include('layouts.partials.toast')
@stack('scripts')
</body>
</html>
