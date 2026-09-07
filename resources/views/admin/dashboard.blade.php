<x-admin-layout>
    <x-slot name="header">Platform overview</x-slot>

    <x-page-header title="Platform overview"
                   subtitle="Live figures across every account on this installation." />

    <div class="space-y-6">

        {{-- Headline counters --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Total users" :value="number_format($stats['users_total'])"
                         :meta="number_format($stats['users_active']).' active · '.number_format($stats['users_suspended']).' suspended'"
                         :href="route('admin.users.index')" />
            <x-stat-card label="Accounts" :value="number_format($stats['accounts_total'])"
                         :meta="number_format($stats['accounts_active']).' active'"
                         :href="route('admin.accounts.index')" />
            <x-stat-card label="Subscribers" :value="number_format($stats['subscribers_total'])"
                         meta="Across all accounts" />
            <x-stat-card label="Campaigns" :value="number_format($stats['campaigns_total'])"
                         meta="All statuses" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Emails sent" :value="number_format($stats['emails_sent'])" meta="Campaign sends" />
            <x-stat-card label="Failed emails" :value="number_format($stats['emails_failed'])"
                         :tone="$stats['emails_failed'] > 0 ? 'danger' : 'default'" meta="Delivery failures" />
            <x-stat-card label="Emails received" :value="number_format($stats['emails_received'])" meta="Via IMAP sync" />
            <x-stat-card label="SMTP / Mailboxes"
                         :value="number_format($stats['smtp_accounts']).' / '.number_format($stats['mailboxes'])"
                         meta="Sending and receiving endpoints" />
        </div>

        {{-- Charts --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">New registrations</h3>
                    <span class="text-xs text-ink-500">Last 14 days</span>
                </div>
                <div class="p-5">
                    <div class="h-56">
                        @php $registrationsChart = ['type' => 'line', 'labels' => $charts['registrations']['labels'], 'datasets' => [['label' => 'Registrations', 'data' => $charts['registrations']['data']]]]; @endphp
                        <canvas data-chart='@json($registrationsChart)'></canvas>
                    </div>
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Emails sent</h3>
                    <span class="text-xs text-ink-500">Last 14 days</span>
                </div>
                <div class="p-5">
                    <div class="h-56">
                        @php $sendingChart = ['type' => 'bar', 'labels' => $charts['sending']['labels'], 'datasets' => [['label' => 'Sent', 'data' => $charts['sending']['data']]]]; @endphp
                        <canvas data-chart='@json($sendingChart)'></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Engagement + queue + health --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Engagement</h3>
                </div>
                <div class="grid grid-cols-2 gap-4 p-5">
                    <div>
                        <p class="kn-stat-label">Open rate</p>
                        <p class="kn-stat-value">{{ $stats['open_rate'] }}%</p>
                        <p class="mt-1 text-xs text-ink-500">Approximate — pixels can be blocked.</p>
                    </div>
                    <div>
                        <p class="kn-stat-label">Click rate</p>
                        <p class="kn-stat-value">{{ $stats['click_rate'] }}%</p>
                        <p class="mt-1 text-xs text-ink-500">Unique clicks ÷ sends.</p>
                    </div>
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Queue</h3>
                    <a href="{{ route('admin.system.queue') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Details</a>
                </div>
                <div class="space-y-2.5 p-5 text-sm">
                    @foreach ([
                        ['Pending jobs', $queue['pending'], $queue['pending'] > 500],
                        ['In progress', $queue['reserved'], false],
                        ['Failed jobs', $queue['failed'], $queue['failed'] > 0],
                        ['Open batches', $queue['batches'], false],
                    ] as [$label, $value, $alert])
                        <div class="flex items-center justify-between">
                            <span class="text-ink-600">{{ $label }}</span>
                            <span class="font-semibold {{ $alert ? 'text-red-600' : 'text-ink-900' }}">
                                {{ number_format($value) }}
                            </span>
                        </div>
                    @endforeach

                    @if ($queue['oldest_wait'])
                        <p class="pt-1 text-xs text-ink-500">Oldest pending job queued {{ $queue['oldest_wait'] }}.</p>
                    @endif
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">System health</h3>
                    <a href="{{ route('admin.system.health') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Full report</a>
                </div>
                <div class="space-y-2.5 p-5 text-sm">
                    @foreach ($health as $check)
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-ink-600">{{ $check['label'] }}</span>
                            <span class="{{ $check['ok'] ? 'kn-badge-green' : 'kn-badge-red' }} shrink-0">
                                {{ $check['ok'] ? 'OK' : 'Check' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Recent lists --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Recent registrations</h3>
                    <a href="{{ route('admin.users.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">All users</a>
                </div>

                @forelse ($recentUsers as $user)
                    <a href="{{ route('admin.users.show', $user) }}"
                       class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-3 last:border-0 hover:bg-ink-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink-900">{{ $user->name }}</p>
                            <p class="truncate text-xs text-ink-500">
                                {{ $user->email }} · {{ $user->account?->name ?? 'Platform' }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-ink-400">{{ $user->created_at->diffForHumans() }}</span>
                    </a>
                @empty
                    <x-empty-state title="No users yet" />
                @endforelse
            </div>

            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Recent campaigns</h3>
                </div>

                @forelse ($recentCampaigns as $campaign)
                    <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-3 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink-900">{{ $campaign->name }}</p>
                            <p class="truncate text-xs text-ink-500">{{ $campaign->account?->name }}</p>
                        </div>
                        <span class="kn-badge-gray shrink-0">{{ ucfirst($campaign->status) }}</span>
                    </div>
                @empty
                    <x-empty-state title="No campaigns yet"
                                   message="Campaigns created by any account will appear here." />
                @endforelse
            </div>
        </div>

        <div class="kn-card overflow-hidden">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Recent system activity</h3>
                <a href="{{ route('admin.activity.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Full log</a>
            </div>

            @forelse ($recentActivity as $log)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-2.5 last:border-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-ink-800">{{ $log->description }}</p>
                        <p class="truncate text-xs text-ink-500">{{ $log->event }} · {{ $log->user?->name ?? 'System' }}</p>
                    </div>
                    <span class="shrink-0 text-xs text-ink-400">{{ $log->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <x-empty-state title="No activity recorded yet" />
            @endforelse
        </div>
    </div>
</x-admin-layout>
