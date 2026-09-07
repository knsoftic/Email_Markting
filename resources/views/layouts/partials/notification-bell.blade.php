@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    /**
     * The top-bar bell.
     *
     * ── It must cost almost nothing ─────────────────────────────────────────
     * This renders on every authenticated page in the product, so it is two
     * small queries and no more: one count over (notifiable_type,
     * notifiable_id) with read_at NULL, and one read of the five most recent
     * rows over the same index. The presenter then resolves every link target
     * in at most three batched `whereIn` lookups, rather than one query per
     * row — see NotificationPresenter.
     *
     * ── And nothing at all for a user with no account ───────────────────────
     * A super admin has no account and no tenant world; the admin layout
     * includes this same top bar. Without the account_id guard every admin
     * page would run two queries to render a bell that is meaningless there.
     */
    $bellUser = auth()->user();
    $bellReady = $bellUser
        && $bellUser->account_id !== null
        && RouteFacade::has('notifications.index');

    $bellUnread = 0;
    $bellRows = collect();

    if ($bellReady) {
        $bellUnread = $bellUser->unreadNotifications()->count();

        $bellRows = app(\App\Notifications\NotificationPresenter::class)->present(
            $bellUser->notifications()->limit(5)->get(),
            $bellUser
        );
    }
@endphp

@if ($bellReady)
    <div class="relative" x-data="{ bell: false }" @keydown.escape.window="bell = false">
        <button type="button" @click="bell = ! bell"
                class="kn-btn-ghost relative p-2"
                :aria-expanded="bell ? 'true' : 'false'"
                aria-label="{{ $bellUnread > 0 ? $bellUnread.' unread notifications' : 'Notifications' }}">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
            </svg>

            @if ($bellUnread > 0)
                <span class="absolute right-1 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
                    {{ $bellUnread > 99 ? '99+' : $bellUnread }}
                </span>
            @endif
        </button>

        <div x-show="bell" x-cloak @click.outside="bell = false" x-transition
             class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-xl border border-ink-200 bg-white shadow-panel sm:w-96">

            <div class="flex items-center justify-between gap-2 border-b border-ink-100 px-4 py-2.5">
                <p class="text-sm font-medium text-ink-900">
                    Notifications
                    @if ($bellUnread > 0)
                        <span class="ml-1 text-xs font-normal text-ink-500">{{ number_format($bellUnread) }} unread</span>
                    @endif
                </p>

                @if ($bellUnread > 0)
                    {{-- back=1: clearing the badge should not throw the reader
                         off whatever page they were on. --}}
                    <form method="POST" action="{{ route('notifications.readAll') }}">
                        @csrf
                        <button type="submit" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                            Mark all read
                        </button>
                    </form>
                @endif
            </div>

            <div class="max-h-96 overflow-y-auto">
                @forelse ($bellRows as $row)
                    <div class="flex items-start gap-3 border-b border-ink-50 px-4 py-3 {{ $row['read'] ? 'bg-white' : 'bg-brand-50/40' }}">
                        @include('notifications.partials.icon', ['icon' => $row['icon'], 'level' => $row['level']])

                        <div class="min-w-0 flex-1">
                            @if ($row['url'] && ! $row['read'])
                                {{-- Opening it is also reading it, so this is one
                                     POST that marks read and then lands on the
                                     thing itself. --}}
                                <form method="POST" action="{{ route('notifications.read', $row['id']) }}">
                                    @csrf
                                    <button type="submit" class="block w-full text-left text-sm font-medium text-ink-900 hover:text-brand-600">
                                        {{ $row['title'] }}
                                    </button>
                                </form>
                            @elseif ($row['url'])
                                <a href="{{ $row['url'] }}" class="block text-sm font-medium text-ink-700 hover:text-brand-600">
                                    {{ $row['title'] }}
                                </a>
                            @else
                                <p class="text-sm font-medium {{ $row['read'] ? 'text-ink-700' : 'text-ink-900' }}">{{ $row['title'] }}</p>
                            @endif

                            <p class="mt-0.5 line-clamp-2 text-xs text-ink-500">{{ $row['body'] }}</p>

                            @if ($row['note'])
                                <p class="mt-1 text-xs italic text-ink-400">{{ $row['note'] }}</p>
                            @endif

                            <div class="mt-1 flex items-center gap-3">
                                <span class="text-[11px] text-ink-400">
                                    {{ $row['at']?->diffForHumans() ?? 'Just now' }}
                                </span>

                                @unless ($row['read'])
                                    <form method="POST" action="{{ route('notifications.read', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="back" value="1">
                                        <button type="submit" class="text-[11px] font-medium text-brand-600 hover:text-brand-700">
                                            Mark read
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-ink-500">
                        Nothing yet. Finished sends, failed sends, split-test results and
                        connection problems all show up here.
                    </p>
                @endforelse
            </div>

            <a href="{{ route('notifications.index') }}"
               class="block border-t border-ink-100 px-4 py-2.5 text-center text-xs font-medium text-brand-600 hover:bg-ink-50">
                See all notifications
            </a>
        </div>
    </div>
@endif
