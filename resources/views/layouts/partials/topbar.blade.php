@php $user = auth()->user(); @endphp

{{-- Impersonation is always visible and always reversible from here. --}}
@if (session()->has('impersonator_id'))
    <div class="flex flex-wrap items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm text-white sm:px-6">
        <span>
            You are signed in as <strong>{{ $user->name }}</strong>
            @if ($user->account) in {{ $user->account->name }} @endif.
        </span>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="rounded-md bg-white/20 px-3 py-1 text-xs font-semibold hover:bg-white/30">
                Stop impersonating
            </button>
        </form>
    </div>
@endif

<header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-ink-200 bg-white/95 px-4 backdrop-blur sm:px-6">
    {{-- Mobile sidebar toggle; `open` lives on the layout's root x-data. --}}
    <button type="button" @click="open = true"
            class="kn-btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
        </svg>
    </button>

    <div class="min-w-0 flex-1">
        @isset($header)
            <div class="truncate text-base font-semibold text-ink-900">{{ $header }}</div>
        @endisset
    </div>

    {{-- Global search. Guarded on the route existing AND on the user having an
         account: this same top bar is included by the admin layout, where a
         super admin has no tenant to search. A GET form, so a search is a URL
         somebody can bookmark, share or reload. --}}
    @if (isset($user) && $user->account_id && \Illuminate\Support\Facades\Route::has('search.index'))
        <form method="GET" action="{{ route('search.index') }}" class="hidden sm:block">
            <label for="kn-topbar-search" class="sr-only">Search</label>
            <div class="relative">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400"
                     fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
                </svg>
                <input id="kn-topbar-search" type="search" name="q"
                       value="{{ request()->routeIs('search.index') ? request()->filter('q') : '' }}"
                       placeholder="Search"
                       class="w-40 rounded-lg border-ink-200 bg-white py-1.5 pl-8 pr-3 text-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 md:w-56">
            </div>
        </form>
    @endif

    {{-- The bell, its unread badge and its dropdown. The partial does its own
         guarding: it renders nothing, and runs no query at all, for a user
         with no account — this same top bar is included by the admin layout,
         where a super admin has no tenant notifications to show. --}}
    @include('layouts.partials.notification-bell')

    <div class="relative" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <button type="button" @click="menu = !menu"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-ink-100">
            <img src="{{ $user->avatarUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
            <span class="hidden text-left sm:block">
                <span class="block text-sm font-medium leading-tight text-ink-900">{{ $user->name }}</span>
                <span class="block text-xs leading-tight text-ink-500">
                    {{ $user->isSuperAdmin() ? 'Super Admin' : ($user->account?->name ?? 'Account') }}
                </span>
            </span>
            <svg class="h-4 w-4 text-ink-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
            </svg>
        </button>

        <div x-show="menu" x-cloak @click.outside="menu = false" x-transition
             class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl border border-ink-200 bg-white py-1.5 shadow-panel">
            <div class="border-b border-ink-100 px-4 py-2.5">
                <p class="truncate text-sm font-medium text-ink-900">{{ $user->name }}</p>
                <p class="truncate text-xs text-ink-500">{{ $user->email }}</p>
            </div>

            <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-ink-700 hover:bg-ink-50">
                Profile settings
            </a>

            @if (\Illuminate\Support\Facades\Route::has('billing.index'))
                <a href="{{ route('billing.index') }}" class="block px-4 py-2 text-sm text-ink-700 hover:bg-ink-50">
                    Plan &amp; billing
                </a>
            @endif

            {{-- Opens in a new tab: somebody reaching for the guide is usually
                 stuck half-way through something, and losing that page to read
                 about it would be the wrong trade. --}}
            @if (\Illuminate\Support\Facades\Route::has('guide'))
                <a href="{{ route('guide') }}" target="_blank" rel="noopener"
                   class="block px-4 py-2 text-sm text-ink-700 hover:bg-ink-50">
                    Guide &amp; help
                </a>
            @endif

            <form method="POST" action="{{ route('logout') }}" class="border-t border-ink-100">
                @csrf
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                    Log out
                </button>
            </form>
        </div>
    </div>
</header>
