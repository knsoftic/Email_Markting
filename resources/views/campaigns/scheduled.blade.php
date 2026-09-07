<x-app-layout>
    <x-slot name="header">Scheduled campaigns</x-slot>

    @php
        /**
         * Only the paginator reaches this view — no audience summary is
         * calculated for the queue, because resolving every scheduled
         * campaign's recipients on a list page would cost one heavy query per
         * row for a number that is deliberately not final until send time.
         */
        $total = $campaigns->total();

        $subtitle = $total === 1
            ? '1 campaign waiting to go out'
            : number_format($total).' campaigns waiting to go out';

        // The stored timezone is validated on save, but a row written before a
        // rename would throw inside setTimezone(), so it is checked here once.
        $knownZones = array_flip(timezone_identifiers_list());
    @endphp

    <x-page-header title="Scheduled campaigns" :subtitle="$subtitle" :back="route('campaigns.index')">
        <x-slot name="actions">
            @permission('campaigns.create')
                <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">New campaign</a>
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ---------------------------------------------------------- explainer --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body">
            <p class="text-sm text-ink-700">
                The audience is resolved <span class="font-semibold text-ink-900">at send time, not now</span> — a
                contact who joins one of the selected lists, tags or segments before the send starts is included,
                and anyone who unsubscribes before then is not.
            </p>
        </div>
    </div>

    @if ($campaigns->isEmpty())
        <div class="kn-card">
            <x-empty-state title="Nothing is scheduled"
                           message="Campaigns you schedule appear here until they start sending. Build a campaign, then pick a send time on the confirmation screen instead of sending it straight away.">
                <x-slot name="action">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        @permission('campaigns.create')
                            <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">Create campaign</a>
                        @endpermission
                        <a href="{{ route('campaigns.index') }}" class="kn-btn-secondary">All campaigns</a>
                    </div>
                </x-slot>
            </x-empty-state>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($campaigns as $campaign)
                @php
                    $zone = isset($knownZones[$campaign->timezone])
                        ? $campaign->timezone
                        : config('app.timezone');

                    $at = $campaign->scheduled_at;
                    $local = $at?->copy()->setTimezone($zone);
                    $overdue = $at !== null && $at->isPast();

                    // Carbon's own future wording is "3 hours from now"; the
                    // absolute form plus a prefix reads the way a queue should.
                    $relative = $at === null
                        ? null
                        : ($overdue
                            ? $at->diffForHumans()
                            : 'in '.$at->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]));

                    // The audience column reads the campaign's own JSON column,
                    // which is already loaded with the row — naming the lists
                    // would cost three queries per card for a set of names that
                    // is not what decides the send anyway.
                    $audience = (array) ($campaign->audience ?? []);
                    $audienceParts = [];

                    foreach (['lists' => 'list', 'tags' => 'tag', 'segments' => 'segment'] as $key => $noun) {
                        $picked = count((array) ($audience[$key] ?? []));

                        if ($picked > 0) {
                            $audienceParts[] = $picked.' '.\Illuminate\Support\Str::plural($noun, $picked);
                        }
                    }
                @endphp

                <div class="kn-card">
                    <div class="kn-card-header flex-wrap">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('campaigns.show', $campaign) }}"
                                   class="truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                                    {{ $campaign->name }}
                                </a>
                                <x-status-badge :status="$campaign->status" />
                                @if ($at === null)
                                    <span class="kn-badge-red">No send time set</span>
                                @elseif ($overdue)
                                    <span class="kn-badge-amber">Due now — starting shortly</span>
                                @endif
                            </div>
                            <p class="mt-1 truncate text-xs text-ink-500" title="{{ $campaign->subject }}">
                                {{ $campaign->subject }}
                            </p>
                        </div>

                        <div class="text-right">
                            @if ($local)
                                <p class="text-sm font-semibold text-ink-900">{{ $local->format('D, d M Y H:i') }}</p>
                                <p class="mt-0.5 text-xs {{ $overdue ? 'font-medium text-amber-600' : 'text-ink-500' }}">
                                    {{ $zone }} · {{ $relative }}
                                </p>
                            @else
                                <p class="text-sm font-semibold text-red-600">Send time missing</p>
                                <p class="mt-0.5 text-xs text-ink-500">Nothing will go out until it is scheduled again.</p>
                            @endif
                        </div>
                    </div>

                    <div class="kn-card-body">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="min-w-0">
                                <p class="kn-stat-label">Audience</p>
                                @if ($audienceParts === [])
                                    <p class="mt-0.5 text-sm font-medium text-red-600">Nothing selected</p>
                                    <p class="text-xs text-ink-500">
                                        This will not send until a list, tag or segment is chosen.
                                    </p>
                                @else
                                    <p class="mt-0.5 text-sm text-ink-800">{{ implode(' · ', $audienceParts) }}</p>
                                    <p class="text-xs text-ink-500">
                                        @if ($campaign->total_recipients > 0)
                                            {{ number_format($campaign->total_recipients) }} at the last count
                                        @else
                                            Counted when it sends
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <p class="kn-stat-label">From</p>
                                <p class="mt-0.5 truncate text-sm text-ink-800" title="{{ $campaign->from_email }}">
                                    {{ $campaign->from_name }}
                                </p>
                                <p class="truncate text-xs text-ink-500">{{ $campaign->from_email }}</p>
                            </div>

                            <div class="min-w-0">
                                <p class="kn-stat-label">Replies go to</p>
                                <p class="mt-0.5 truncate text-sm text-ink-800">
                                    {{ $campaign->reply_to ?: $campaign->from_email }}
                                </p>
                                <p class="text-xs text-ink-500">
                                    {{ $campaign->reply_to ? 'Custom reply-to address' : 'Same as the from address' }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="kn-stat-label">Tracking</p>
                                <p class="mt-0.5 text-sm text-ink-800">
                                    {{ $campaign->track_opens ? 'Opens on' : 'Opens off' }} ·
                                    {{ $campaign->track_clicks ? 'clicks on' : 'clicks off' }}
                                </p>
                                <p class="text-xs text-ink-500">
                                    Last edited {{ $campaign->updated_at?->diffForHumans() ?? 'recently' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-1.5 border-t border-ink-100 px-5 py-3">
                        <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-ghost kn-btn-sm">View</a>

                        @permission('campaigns.update')
                            {{-- A scheduled campaign is still editable, so content can be
                                 fixed without unscheduling it first. --}}
                            <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-ghost kn-btn-sm">Edit</a>
                        @endpermission

                        @permission('campaigns.send')
                            <form method="POST" action="{{ route('campaigns.unschedule', $campaign) }}" class="inline">
                                @csrf
                                <button type="submit" class="kn-btn-secondary kn-btn-sm">Unschedule</button>
                            </form>

                            <x-confirm-form :action="route('campaigns.cancel', $campaign)"
                                            method="POST"
                                            label="Cancel"
                                            button-class="kn-btn-danger kn-btn-sm"
                                            :message="'Cancel '.$campaign->name.'? The schedule is dropped and the campaign is marked cancelled — unscheduling instead puts it back to a draft you can send later.'" />

                            {{-- Goes through the confirmation screen like every
                                 other send in the app: posting straight to
                                 campaigns.send would 422 on an error page if a
                                 blocker appeared after scheduling (the SMTP
                                 account removed, the audience emptied), and it
                                 would skip the reach figure entirely. --}}
                            <a href="{{ route('campaigns.confirm', $campaign) }}" class="kn-btn-primary kn-btn-sm">
                                Review &amp; send now
                            </a>
                        @endpermission
                    </div>
                </div>
            @endforeach
        </div>

        @if ($campaigns->hasPages())
            <div class="mt-6 border-t border-ink-100 px-5 py-3">{{ $campaigns->links() }}</div>
        @endif
    @endif
</x-app-layout>
