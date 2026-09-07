<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
</head>
<body class="bg-white font-sans antialiased">

<header class="border-b border-ink-200">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4">
        <x-application-logo />

        <nav class="flex items-center gap-2">
            @auth
                <a href="{{ route('dashboard') }}" class="kn-btn-primary kn-btn-sm">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="kn-btn-ghost kn-btn-sm">Sign in</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="kn-btn-primary kn-btn-sm">Get started</a>
                @endif
            @endauth
        </nav>
    </div>
</header>

<main>
    <section class="mx-auto max-w-6xl px-5 py-20 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-brand-600">
            {{ $brand['company_name'] }}
        </p>
        <h1 class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-ink-900 sm:text-5xl">
            {{ $brand['tagline'] }}
        </h1>
        <p class="mx-auto mt-5 max-w-2xl text-base leading-relaxed text-ink-600">
            Send permission-based campaigns through your own SMTP, connect your mailboxes over IMAP,
            and keep every customer reply threaded against the campaign that started it.
        </p>

        @if (Route::has('register'))
            <div class="mt-8 flex justify-center gap-3">
                <a href="{{ route('register') }}" class="kn-btn-primary">Create your account</a>
                <a href="{{ route('login') }}" class="kn-btn-secondary">Sign in</a>
            </div>
        @endif
    </section>

    <section class="border-t border-ink-200 bg-ink-50">
        <div class="mx-auto grid max-w-6xl gap-6 px-5 py-16 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Campaigns that scale', 'Queue-based bulk sending with batching, rate limits, retries and live progress.'],
                ['Your own SMTP', 'Gmail, Microsoft, Zoho, SES, SendGrid, Mailgun, Brevo or any custom host — with rotation and limits.'],
                ['Connected inbox', 'IMAP sync with incremental fetch, folders, attachments and threaded conversations.'],
                ['Real tracking', 'Opens, clicks, replies, bounces and unsubscribes, with honest reporting on what pixels can and cannot see.'],
                ['Contacts done properly', 'Lists, tags, segments, CSV import with column mapping, and a suppression list that is always respected.'],
                ['Built for teams', 'Separate accounts, staff roles, granular permissions and a full activity log.'],
            ] as [$title, $copy])
                <div class="kn-card p-5">
                    <h3 class="text-sm font-semibold text-ink-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-600">{{ $copy }}</p>
                </div>
            @endforeach
        </div>
    </section>
</main>

<footer class="border-t border-ink-200">
    <div class="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-8 text-xs text-ink-500 sm:flex-row sm:items-center sm:justify-between">
        <span>{{ $brand['footer_text'] }}</span>
        <span class="flex flex-wrap gap-x-4 gap-y-1">
            @if ($brand['website'])
                <a href="{{ $brand['website'] }}" class="hover:text-ink-700">{{ $brand['website'] }}</a>
            @endif
            @if ($brand['support_email'])
                <a href="mailto:{{ $brand['support_email'] }}" class="hover:text-ink-700">{{ $brand['support_email'] }}</a>
            @endif
        </span>
    </div>
</footer>

</body>
</html>
