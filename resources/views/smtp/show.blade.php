<x-app-layout>
    <x-slot name="header">{{ $account->name }}</x-slot>

    @php
        $encryptionLabels = ['tls' => 'STARTTLS (TLS)', 'ssl' => 'Implicit SSL', 'none' => 'None'];
        $inCooldown = $account->isInCooldown();

        $percentOf = fn (?int $limit, int $used) => $limit === null || $limit <= 0
            ? 0
            : min(100, (int) round($used / $limit * 100));

        $barTone = fn (int $percent) => $percent >= 90
            ? 'bg-red-500'
            : ($percent >= 75 ? 'bg-amber-500' : 'bg-brand-600');

        $remainingText = function (?int $limit, int $used) {
            if ($limit === null) {
                return 'No cap set';
            }

            return number_format(max(0, $limit - $used)).' left of '.number_format($limit);
        };

        $windows = [
            ['label' => 'Sent this hour', 'limit' => $account->hourly_limit, 'used' => (int) $account->sent_this_hour, 'window' => 'Hourly limit'],
            ['label' => 'Sent today', 'limit' => $account->daily_limit, 'used' => (int) $account->sent_today, 'window' => 'Daily limit'],
            ['label' => 'Sent this month', 'limit' => $account->monthly_limit, 'used' => (int) $account->sent_this_month, 'window' => 'Monthly limit'],
        ];

        $connection = [
            'Provider' => $provider['label'],
            'Host' => $account->host,
            'Port' => (string) $account->port,
            'Encryption' => $encryptionLabels[$account->encryption] ?? $account->encryption,
            'Certificate verification' => $account->verify_peer ? 'On' : 'Off — the connection is encrypted but unauthenticated',
            'Username' => $account->maskedUsername(),
            'From name' => $account->from_name,
            'From email' => $account->from_email,
            'Reply-to' => $account->reply_to,
            'Priority' => number_format($account->priority).' (lower goes first)',
            'Delay between sends' => $account->send_delay_ms > 0 ? number_format($account->send_delay_ms).' ms' : 'None',
            'Added' => $account->created_at?->format('d M Y H:i'),
        ];
    @endphp

    <x-page-header :title="$account->name"
                   :subtitle="$provider['label'].' · '.$account->host.':'.$account->port"
                   :back="route('smtp.index')">
        <x-slot name="actions">
            <x-status-badge :status="$account->is_active ? 'active' : 'paused'" />

            @if ($editable)
                @permission('smtp.manage')
                    <a href="{{ route('smtp.edit', $account) }}" class="kn-btn-primary">Edit</a>

                    <form method="POST" action="{{ route('smtp.status', $account) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="kn-btn-secondary">
                            {{ $account->is_active ? 'Pause' : 'Resume' }}
                        </button>
                    </form>

                    <x-confirm-form :action="route('smtp.destroy', $account)"
                                    label="Delete"
                                    button-class="kn-btn-danger"
                                    :message="'Delete '.$account->name.'? Campaigns already sent keep their sending history, but nothing new will go out through it.'" />
                @endpermission
            @else
                <span class="kn-badge-blue">Provided by KN Softic</span>
            @endif
        </x-slot>
    </x-page-header>

    @unless ($editable)
        <div class="mb-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
            KN Softic manages this account. It is available to your plan for sending, but its credentials,
            limits and status can only be changed by an administrator.
        </div>
    @endunless

    {{-- ----------------------------------------------------------- stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($windows as $window)
            @php $percent = $percentOf($window['limit'], $window['used']); @endphp
            <x-stat-card :label="$window['label']"
                         :value="number_format($window['used'])"
                         :meta="$remainingText($window['limit'], $window['used'])"
                         :tone="$window['limit'] !== null && $percent >= 90 ? 'danger' : ($window['limit'] !== null && $percent >= 75 ? 'warning' : 'default')" />
        @endforeach

        <x-stat-card label="Total sent"
                     :value="number_format($account->total_sent)"
                     :meta="number_format($account->total_failed).' failed since this account was added'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ left --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Connection --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Connection</h3>
                    <span class="text-xs text-ink-500">Sending only</span>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    @foreach ($connection as $label => $value)
                        <div class="min-w-0">
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">
                                {{ filled($value) ? $value : '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <div class="border-t border-ink-100 px-5 py-4">
                    <p class="text-xs text-ink-500">
                        The password is encrypted at rest and is never displayed again — replace it from the edit
                        screen if it changes at your provider.
                    </p>
                </div>
            </div>

            {{-- Limits --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Sending limits</h3>
                    <span class="text-xs text-ink-500">Counters roll over automatically</span>
                </div>

                <div class="space-y-5 p-5">
                    @foreach ($windows as $window)
                        @php $percent = $percentOf($window['limit'], $window['used']); @endphp
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="font-medium text-ink-800">{{ $window['window'] }}</span>
                                <span class="text-ink-600">
                                    @if ($window['limit'] === null)
                                        {{ number_format($window['used']) }} sent · unlimited
                                    @else
                                        {{ number_format($window['used']) }} / {{ number_format($window['limit']) }} · {{ $percent }}% used
                                    @endif
                                </span>
                            </div>
                            <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                <div class="h-2 rounded-full {{ $window['limit'] === null ? 'bg-ink-300' : $barTone($percent) }}"
                                     style="width: {{ $window['limit'] === null ? 100 : $percent }}%"></div>
                            </div>
                        </div>
                    @endforeach

                    <p class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                        @if ($account->hasCapacity())
                            This account still has room under every limit and can be picked for the next send.
                        @else
                            Every send is skipping this account: at least one limit is full. It becomes available
                            again when the counter for that window rolls over.
                        @endif
                    </p>
                </div>
            </div>

            {{-- Usage --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Last 30 days</h3>
                    <span class="text-xs text-ink-500">{{ number_format($usage->sum('sent')) }} sent · {{ number_format($usage->sum('failed')) }} failed</span>
                </div>

                @if ($usage->isEmpty())
                    <x-empty-state title="No sending history yet"
                                   message="Once a campaign goes out through this account, every day's sent and failed counts appear here." />
                @else
                    @if ($usage->count() > 1)
                        <div class="border-b border-ink-100 p-5">
                            <div class="h-40">
                                @php $usageChart = ['type' => 'bar', 'labels' => $usage->sortBy('date')->map(fn ($row) => $row->date?->format('j M'))->values(), 'datasets' => [['label' => 'Sent', 'data' => $usage->sortBy('date')->pluck('sent')->values()]]]; @endphp
                                <canvas data-chart='@json($usageChart)'></canvas>
                            </div>
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-right">Sent</th>
                                    <th class="text-right">Failed</th>
                                    <th class="text-right">Failure rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($usage as $row)
                                    @php
                                        $attempted = (int) $row->sent + (int) $row->failed;
                                        $failureRate = $attempted > 0 ? round($row->failed / $attempted * 100, 1) : 0.0;
                                    @endphp
                                    <tr>
                                        <td class="whitespace-nowrap">{{ $row->date?->format('D, d M Y') ?? '—' }}</td>
                                        <td class="text-right font-medium text-ink-900">{{ number_format($row->sent) }}</td>
                                        <td class="text-right {{ $row->failed > 0 ? 'font-medium text-red-600' : 'text-ink-500' }}">
                                            {{ number_format($row->failed) }}
                                        </td>
                                        <td class="text-right text-xs {{ $failureRate >= 5 ? 'font-semibold text-red-600' : 'text-ink-500' }}">
                                            {{ $attempted > 0 ? $failureRate.'%' : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- =========================================================== right --}}
        <div class="space-y-6">

            {{-- Health --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Health</h3>
                    @if ($inCooldown)
                        <span class="kn-badge-red">In cooldown</span>
                    @elseif ($account->test_passed)
                        <span class="kn-badge-green">Healthy</span>
                    @endif
                </div>

                <div class="space-y-4 p-5">
                    <div>
                        <p class="kn-stat-label">Last test</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @if ($account->last_tested_at === null)
                                <span class="kn-badge-gray">Never tested</span>
                            @elseif ($account->test_passed)
                                <span class="kn-badge-green">Passed</span>
                                <span class="text-xs text-ink-500" title="{{ $account->last_tested_at->format('D, d M Y H:i') }}">
                                    {{ $account->last_tested_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="kn-badge-red">Failed</span>
                                <span class="text-xs text-ink-500" title="{{ $account->last_tested_at->format('D, d M Y H:i') }}">
                                    {{ $account->last_tested_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <p class="kn-stat-label">Last successful send</p>
                        <p class="mt-0.5 text-sm text-ink-800">
                            @if ($account->last_success_at)
                                <span title="{{ $account->last_success_at->format('D, d M Y H:i') }}">{{ $account->last_success_at->diffForHumans() }}</span>
                            @else
                                Nothing has been sent through this account yet
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="kn-stat-label">Consecutive failures</p>
                        <p class="mt-0.5 text-sm {{ $account->consecutive_failures > 0 ? 'font-semibold text-red-600' : 'text-ink-800' }}">
                            {{ number_format($account->consecutive_failures) }}
                        </p>
                    </div>

                    <div>
                        <p class="kn-stat-label">Cooldown</p>
                        <p class="mt-0.5 text-sm {{ $inCooldown ? 'font-semibold text-red-600' : 'text-ink-800' }}">
                            @if ($inCooldown)
                                Until {{ $account->cooldown_until->format('D, d M Y H:i') }} ({{ $account->cooldown_until->diffForHumans() }})
                            @else
                                Not in cooldown
                            @endif
                        </p>
                    </div>

                    @if (filled($account->last_error))
                        <div>
                            <p class="kn-stat-label">Last error</p>
                            @if ($account->last_error_at)
                                <p class="mt-0.5 text-xs text-ink-500" title="{{ $account->last_error_at->format('D, d M Y H:i') }}">
                                    {{ $account->last_error_at->diffForHumans() }}
                                </p>
                            @endif
                            <p class="mt-1.5 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-50 p-3 font-mono text-xs text-red-900">
                                {{ $account->last_error }}
                            </p>
                        </div>
                    @endif

                    @if ($inCooldown && $editable)
                        @permission('smtp.manage')
                            <x-confirm-form :action="route('smtp.reset-cooldown', $account)"
                                            method="POST"
                                            label="Clear cooldown"
                                            button-class="kn-btn-danger kn-btn-sm"
                                            message="Clear the cooldown and let sending use this account again? Test the connection first, or it will fail straight back into a cooldown." />
                        @endpermission
                    @endif
                </div>
            </div>

            {{-- Test --}}
            @permission('smtp.manage')
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Connection test</h3>
                    </div>
                    <div class="kn-card-body">
                        <p class="mb-3 text-xs text-ink-500">
                            Connects, negotiates encryption and authenticates against the provider. No email is
                            sent and nobody receives anything.
                        </p>
                        @include('smtp.partials.test-widget', [
                            'testId' => $account->id,
                            'testUrl' => route('smtp.test', $account),
                        ])
                    </div>
                </div>
            @endpermission

            {{-- Provider notes --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">{{ $provider['label'] }}</h3>
                </div>
                <div class="kn-card-body space-y-3">
                    <p class="text-sm text-ink-700">{{ $provider['notes'] }}</p>
                    <p class="text-xs text-ink-600">
                        Username: <span class="font-medium text-ink-800">{{ $provider['username_hint'] }}</span>
                    </p>
                    <p class="text-xs text-ink-600">
                        Password: <span class="font-medium text-ink-800">{{ $provider['password_hint'] }}</span>
                    </p>
                    @if (filled($provider['docs']))
                        <a href="{{ $provider['docs'] }}" target="_blank" rel="noopener noreferrer"
                           class="inline-block text-xs font-semibold text-brand-600 hover:text-brand-700">
                            Read the provider's own SMTP guide
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
