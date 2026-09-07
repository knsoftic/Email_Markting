<x-app-layout>
    <x-slot name="header">Notifications</x-slot>

    @php
        /**
         * Whole literal class strings only — never assembled from fragments,
         * so Tailwind's scanner sees every class this page can render.
         */
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        $subtitle = $totalCount === 0
            ? 'Nothing has been recorded for you yet'
            : ($unreadCount === 0
                ? number_format($totalCount).' '.\Illuminate\Support\Str::plural('notification', $totalCount).', all read'
                : number_format($unreadCount).' unread of '.number_format($totalCount));
    @endphp

    <x-page-header title="Notifications" :subtitle="$subtitle">
        <x-slot name="actions">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.readAll') }}">
                    @csrf
                    <x-secondary-button type="submit">Mark all read</x-secondary-button>
                </form>
            @endif
        </x-slot>
    </x-page-header>

    {{-- --------------------------------------------------------- filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('notifications.index') }}"
           class="{{ $chipBase }} {{ $show === 'all' ? $chipOn : $chipOff }}">
            All
            <span class="{{ $show === 'all' ? $chipCountOn : $chipCountOff }}">{{ number_format($totalCount) }}</span>
        </a>

        <a href="{{ route('notifications.index', ['show' => 'unread']) }}"
           class="{{ $chipBase }} {{ $show === 'unread' ? $chipOn : $chipOff }}">
            Unread
            <span class="{{ $show === 'unread' ? $chipCountOn : $chipCountOff }}">{{ number_format($unreadCount) }}</span>
        </a>
    </div>

    {{-- ------------------------------------------------------------ list --}}
    <div class="kn-card overflow-hidden">
        @forelse ($rows as $row)
            <div class="flex items-start gap-3 border-b border-ink-100 px-4 py-4 last:border-b-0 sm:px-5
                        {{ $row['read'] ? 'bg-white' : 'bg-brand-50/40' }}">

                @include('notifications.partials.icon', ['icon' => $row['icon'], 'level' => $row['level']])

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($row['url'] && ! $row['read'])
                            {{-- Opening it is also reading it: one POST that marks
                                 it read and then lands on the thing itself. --}}
                            <form method="POST" action="{{ route('notifications.read', $row['id']) }}">
                                @csrf
                                <button type="submit" class="text-left text-sm font-semibold text-ink-900 hover:text-brand-600">
                                    {{ $row['title'] }}
                                </button>
                            </form>
                        @elseif ($row['url'])
                            <a href="{{ $row['url'] }}" class="text-sm font-medium text-ink-700 hover:text-brand-600">
                                {{ $row['title'] }}
                            </a>
                        @else
                            <span class="text-sm {{ $row['read'] ? 'font-medium text-ink-700' : 'font-semibold text-ink-900' }}">
                                {{ $row['title'] }}
                            </span>
                        @endif

                        @unless ($row['read'])
                            <span class="kn-badge-blue">New</span>
                        @endunless
                    </div>

                    <p class="mt-1 text-sm text-ink-600">{{ $row['body'] }}</p>

                    @if ($row['note'])
                        {{-- A notification outlives the thing it is about. Rather
                             than a link that 404s, or a row that quietly
                             disappears, the screen says what became of it. --}}
                        <p class="mt-1.5 text-xs italic text-ink-500">{{ $row['note'] }}</p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <span class="text-xs text-ink-400"
                              title="{{ $row['at']?->format('D, d M Y H:i') }}">
                            {{ $row['at']?->diffForHumans() ?? 'Time not recorded' }}
                        </span>

                        @unless ($row['read'])
                            <form method="POST" action="{{ route('notifications.read', $row['id']) }}">
                                @csrf
                                <input type="hidden" name="back" value="1">
                                <button type="submit" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                                    Mark read
                                </button>
                            </form>
                        @endunless

                        <x-confirm-form :action="route('notifications.destroy', $row['id'])"
                                        label="Delete"
                                        button-class="text-xs font-medium text-red-600 hover:text-red-700"
                                        message="Delete this notification? It is only removed from your own list." />
                    </div>
                </div>
            </div>
        @empty
            @if ($show === 'unread')
                <x-empty-state title="Nothing unread"
                               message="Every notification on your list has been read.">
                    <x-slot name="action">
                        <a href="{{ route('notifications.index') }}" class="kn-btn-secondary">See all notifications</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-empty-state title="No notifications yet"
                               message="This is where the product tells you things it noticed on its own: a campaign that finished or failed, a split test that picked a winner, an SMTP account or mailbox that stopped working, and how much of the monthly email allowance is left." />
            @endif
        @endforelse

        @if ($notifications->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $notifications->links() }}</div>
        @endif
    </div>
</x-app-layout>
