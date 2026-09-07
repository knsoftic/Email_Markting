<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <div class="space-y-6">

        {{-- Greeting + plan summary --}}
        <div class="kn-card">
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-ink-900">
                        Welcome back, {{ auth()->user()->name }}
                    </h2>
                    <p class="mt-1 text-sm text-ink-500">
                        {{ $account?->name ?? 'Your account' }}
                        @if ($plan)
                            · <span class="font-medium text-ink-700">{{ $plan->name }} plan</span>
                            @if ($subscription?->ends_at)
                                · renews {{ $subscription->ends_at->format('d M Y') }}
                            @endif
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if (Route::has('campaigns.create'))
                        <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">New campaign</a>
                    @endif
                    @if (Route::has('subscribers.create'))
                        <a href="{{ route('subscribers.create') }}" class="kn-btn-secondary">Add contact</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Primary stats --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Total contacts', number_format($stats['contacts_total']), number_format($stats['contacts_active']).' active'],
                ['Campaigns', number_format($stats['campaigns_total']), $stats['campaigns_scheduled'].' scheduled'],
                ['Emails sent', number_format($stats['emails_sent']), number_format($usage->emails_sent).' this month'],
                ['Emails received', number_format($stats['emails_received']), number_format($stats['emails_unread']).' unread'],
            ] as [$label, $value, $meta])
                <div class="kn-stat">
                    <p class="kn-stat-label">{{ $label }}</p>
                    <p class="kn-stat-value">{{ $value }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ $meta }}</p>
                </div>
            @endforeach
        </div>

        {{-- Engagement + usage --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="kn-card lg:col-span-2">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Engagement</h3>
                    <span class="text-xs text-ink-500">Across all campaigns</span>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <div>
                        <p class="kn-stat-label">Open rate</p>
                        <p class="kn-stat-value">{{ $stats['open_rate'] }}%</p>
                        {{-- The same caveat the analytics screens print. An open
                             is a pixel fetch: it misses readers whose client
                             blocked the image, and counts machines that fetched
                             it for them. Describing the same number two
                             different ways on two screens is how a team ends up
                             arguing about which one is right. --}}
                        <p class="mt-1 text-xs text-ink-500">
                            Unique opens ÷ delivered sends. Approximate both ways — image blocking hides
                            readers, mail-privacy proxies add machines.
                        </p>
                    </div>
                    <div>
                        <p class="kn-stat-label">Click rate</p>
                        <p class="kn-stat-value">{{ $stats['click_rate'] }}%</p>
                        <p class="mt-1 text-xs text-ink-500">Unique clicks ÷ delivered sends.</p>
                    </div>
                    <div>
                        <p class="kn-stat-label">Campaign replies</p>
                        <p class="kn-stat-value">{{ number_format($stats['campaign_replies']) }}</p>
                        <p class="mt-1 text-xs text-ink-500">Replies matched back to a campaign.</p>
                    </div>
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Monthly usage</h3>
                    <span class="text-xs text-ink-500">{{ $usage->period }}</span>
                </div>
                <div class="space-y-4 p-5">
                    @php
                        $rows = [
                            ['Contacts', $stats['contacts_total'], $subscription?->limit('max_contacts')],
                            ['Emails sent', $usage->emails_sent, $subscription?->limit('max_emails_per_month')],
                            ['Emails received', $usage->emails_received, $subscription?->limit('max_emails_received_per_month')],
                        ];
                    @endphp

                    @foreach ($rows as [$label, $used, $limit])
                        @php
                            $percent = $limit ? min(100, round(($used / max(1, $limit)) * 100)) : 0;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium text-ink-700">{{ $label }}</span>
                                <span class="text-ink-500">
                                    {{ number_format($used) }} / {{ $limit === null ? 'Unlimited' : number_format($limit) }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-ink-200">
                                <div class="h-full rounded-full {{ $percent >= 90 ? 'bg-red-500' : 'bg-brand-600' }}"
                                     style="width: {{ $limit === null ? 2 : $percent }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Recent campaigns</h3>
                    @if (Route::has('campaigns.index'))
                        <a href="{{ route('campaigns.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">View all</a>
                    @endif
                </div>

                @forelse ($recentCampaigns as $campaign)
                    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-3 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink-900">{{ $campaign->name }}</p>
                            <p class="truncate text-xs text-ink-500">{{ $campaign->subject }}</p>
                        </div>
                        <span class="kn-badge-gray shrink-0">{{ ucfirst($campaign->status) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-ink-500">
                        No campaigns yet. Your first campaign will appear here.
                    </p>
                @endforelse
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Recent inbox</h3>
                    @if (Route::has('inbox.index'))
                        <a href="{{ route('inbox.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Open inbox</a>
                    @endif
                </div>

                @forelse ($recentEmails as $email)
                    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-3 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink-900">{{ $email->fromDisplay() }}</p>
                            <p class="truncate text-xs text-ink-500">{{ $email->subject }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-ink-400">
                            {{ $email->received_at?->diffForHumans() }}
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-ink-500">
                        No mailbox connected yet. Received email will appear here.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
