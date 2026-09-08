<x-app-layout>
    <x-slot name="header">SMTP accounts</x-slot>

    @php
        $atLimit = $smtpLimit !== null && $smtpUsed >= $smtpLimit;

        // Decided once: the "Add" control appears in three places and must not
        // leave an empty action row behind when the plan or the role says no.
        $canShowAdd = $canAddCustom && ! $atLimit && (bool) auth()->user()?->hasPermission('smtp.manage');

        $encryptionLabels = ['tls' => 'STARTTLS', 'ssl' => 'Implicit SSL', 'none' => 'No encryption'];

        $subtitle = $smtpLimit === null
            ? number_format($smtpUsed).' of your own '.\Illuminate\Support\Str::plural('account', $smtpUsed).' · your plan sets no limit'
            : number_format($smtpUsed).' of '.number_format($smtpLimit).' own '.\Illuminate\Support\Str::plural('account', $smtpLimit).' used';

        // Rendered for each of the three windows on every card.
        $limitText = fn (?int $limit, int $used) => $limit === null
            ? number_format($used).' sent · unlimited'
            : number_format($used).' / '.number_format($limit);

        $percentOf = fn (?int $limit, int $used) => $limit === null || $limit <= 0
            ? 0
            : min(100, (int) round($used / $limit * 100));

        $barTone = fn (int $percent) => $percent >= 90
            ? 'bg-red-500'
            : ($percent >= 75 ? 'bg-amber-500' : 'bg-brand-600');
    @endphp

    <x-page-header title="SMTP accounts" :subtitle="$subtitle">
        <x-slot name="actions">
            @if (! $canAddCustom)
                <p class="max-w-xs text-xs text-ink-500">
                    Your plan does not include your own SMTP accounts.
                    {{-- Do not promise accounts "below" when none is shared: the
                         customer reads that next to a section saying nothing has
                         been assigned, and cannot tell which half to believe. --}}
                    @if (! $adminSmtpAllowed)
                        Contact support to enable sending.
                    @elseif ($shared->isNotEmpty())
                        Sending uses the accounts KN Softic provides below.
                    @else
                        Nothing has been shared with your account yet, so there is no way to send —
                        ask KN Softic to assign you an SMTP account.
                    @endif
                </p>
            @elseif ($atLimit)
                <span class="kn-badge-amber">SMTP account limit reached</span>
            @elseif ($canShowAdd)
                <a href="{{ route('smtp.create') }}" class="kn-btn-primary">Add SMTP account</a>
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------- explainer --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body space-y-2">
            <p class="text-sm text-ink-700">
                An SMTP account is used for <span class="font-semibold text-ink-900">sending only</span>. Receiving
                and reading replies is configured separately under Mailboxes, which connects over IMAP — the two
                never share credentials.
            </p>
            <p class="text-sm text-ink-600">
                @if ($rotationAllowed)
                    Sending spreads across every active account: each message goes to the highest-priority account
                    that still has room, and once one reaches its hourly, daily or monthly limit the next one
                    takes over.
                @else
                    Your plan sends through one account at a time — the highest-priority active account. When it
                    reaches a limit, sending waits rather than moving to another account.
                @endif
            </p>
        </div>
    </div>

    {{-- Only when there is genuinely nothing else to say. A tenant whose plan
         allows the platform's own accounts must reach the section below even
         when none has been assigned yet — otherwise the screen never mentions
         that route exists, and "no SMTP account" reads as a fault in the
         product rather than something an administrator has still to do. --}}
    @if ($own->isEmpty() && $shared->isEmpty() && ! $adminSmtpAllowed)
        <div class="kn-card">
            {{-- The message has to match what this user can actually do: telling
                 someone whose plan has no sending at all to "add credentials"
                 is a dead end, because there is no control to do it with. --}}
            <x-empty-state title="No SMTP account yet"
                           :message="$canAddCustom
                               ? 'A campaign cannot send a single email until an SMTP account exists. Add your own provider credentials to start sending.'
                               : 'A campaign cannot send a single email until an SMTP account exists. Your plan does not include your own SMTP accounts, so contact support to have sending enabled.'">
                @if ($canShowAdd)
                    <x-slot name="action">
                        <a href="{{ route('smtp.create') }}" class="kn-btn-primary">Add SMTP account</a>
                    </x-slot>
                @endif
            </x-empty-state>
        </div>
    @else

        {{-- ------------------------------------------------ own accounts --}}
        <div class="mb-3 flex items-center justify-between gap-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Your SMTP accounts</h2>
            <span class="text-xs text-ink-500">{{ number_format($own->count()) }} {{ \Illuminate\Support\Str::plural('account', $own->count()) }}</span>
        </div>

        @if ($own->isEmpty())
            <div class="kn-card mb-8">
                <x-empty-state title="You have not added your own SMTP account"
                               :message="$shared->isNotEmpty()
                                   ? 'You are sending through the accounts KN Softic provides, listed below. Add your own provider to send from your domain and control the limits yourself.'
                                   : 'Nothing has been shared with your account yet either, so there is no way to send at present. Add your own provider, or ask KN Softic to assign you one.'">
                    @if ($canShowAdd)
                        <x-slot name="action">
                            <a href="{{ route('smtp.create') }}" class="kn-btn-primary">Add SMTP account</a>
                        </x-slot>
                    @endif
                </x-empty-state>
            </div>
        @else
            <div class="mb-8 space-y-4">
                @foreach ($own as $smtp)
                    @php
                        $inCooldown = $smtp->isInCooldown();
                        $dailyPercent = $percentOf($smtp->daily_limit, (int) $smtp->sent_today);
                        $windows = [
                            ['label' => 'This hour', 'limit' => $smtp->hourly_limit, 'used' => (int) $smtp->sent_this_hour],
                            ['label' => 'Today', 'limit' => $smtp->daily_limit, 'used' => (int) $smtp->sent_today],
                            ['label' => 'This month', 'limit' => $smtp->monthly_limit, 'used' => (int) $smtp->sent_this_month],
                        ];
                    @endphp

                    <div class="kn-card">
                        <div class="kn-card-header flex-wrap">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('smtp.show', $smtp) }}"
                                       class="truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                                        {{ $smtp->name }}
                                    </a>
                                    <x-status-badge :status="$smtp->is_active ? 'active' : 'paused'" />
                                    @if ($inCooldown)
                                        <span class="kn-badge-red">In cooldown</span>
                                    @elseif (! $smtp->hasCapacity())
                                        <span class="kn-badge-amber">Limit reached</span>
                                    @endif
                                </div>
                                <p class="mt-1 break-words text-xs text-ink-500">
                                    {{ \App\Support\SmtpProviders::label($smtp->provider) }}
                                    · {{ $smtp->maskedUsername() }}
                                    · {{ $smtp->host }}:{{ $smtp->port }}
                                    · {{ $encryptionLabels[$smtp->encryption] ?? $smtp->encryption }}
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="{{ route('smtp.show', $smtp) }}" class="kn-btn-ghost kn-btn-sm">View</a>

                                @permission('smtp.manage')
                                    <a href="{{ route('smtp.edit', $smtp) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                    <form method="POST" action="{{ route('smtp.status', $smtp) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="kn-btn-secondary kn-btn-sm">
                                            {{ $smtp->is_active ? 'Pause' : 'Resume' }}
                                        </button>
                                    </form>

                                    <x-confirm-form :action="route('smtp.destroy', $smtp)"
                                                    label="Delete"
                                                    :message="'Delete '.$smtp->name.'? Campaigns already sent keep their sending history, but nothing new will go out through it.'" />
                                @endpermission
                            </div>
                        </div>

                        <div class="kn-card-body space-y-4">

                            {{-- limits and today's usage --}}
                            <div class="grid gap-4 sm:grid-cols-3">
                                @foreach ($windows as $window)
                                    <div class="min-w-0">
                                        <p class="kn-stat-label">{{ $window['label'] }}</p>
                                        <p class="mt-0.5 text-sm font-semibold text-ink-900">
                                            {{ $limitText($window['limit'], $window['used']) }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>

                            <div>
                                <div class="flex items-center justify-between text-xs text-ink-500">
                                    <span>Daily limit</span>
                                    <span>
                                        @if ($smtp->daily_limit === null)
                                            No daily cap set
                                        @else
                                            {{ number_format($smtp->remainingToday()) }} left today · {{ $dailyPercent }}% used
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                    <div class="h-2 rounded-full {{ $smtp->daily_limit === null ? 'bg-ink-300' : $barTone($dailyPercent) }}"
                                         style="width: {{ $smtp->daily_limit === null ? 100 : $dailyPercent }}%"></div>
                                </div>
                            </div>

                            {{-- last test --}}
                            <div class="flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                @if ($smtp->last_tested_at === null)
                                    <span class="kn-badge-gray">Never tested</span>
                                    <span>Run a test before your first campaign.</span>
                                @elseif ($smtp->test_passed)
                                    <span class="kn-badge-green">Last test passed</span>
                                    <span title="{{ $smtp->last_tested_at->format('D, d M Y H:i') }}">
                                        {{ $smtp->last_tested_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="kn-badge-red">Last test failed</span>
                                    <span title="{{ $smtp->last_tested_at->format('D, d M Y H:i') }}">
                                        {{ $smtp->last_tested_at->diffForHumans() }}
                                    </span>
                                @endif

                                @if ($smtp->send_delay_ms > 0)
                                    <span>· {{ number_format($smtp->send_delay_ms) }} ms between sends</span>
                                @endif
                                <span>· Priority {{ number_format($smtp->priority) }}</span>
                            </div>

                            {{-- cooldown --}}
                            @if ($inCooldown)
                                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                                    <p class="text-sm font-semibold text-red-800">
                                        Skipped by sending until {{ $smtp->cooldown_until->format('D, d M Y H:i') }}
                                    </p>
                                    <p class="mt-1 text-xs text-red-700">
                                        {{ number_format($smtp->consecutive_failures) }} consecutive
                                        {{ \Illuminate\Support\Str::plural('failure', $smtp->consecutive_failures) }}
                                        put this account into a cooldown
                                        ({{ $smtp->cooldown_until->diffForHumans() }}).
                                    </p>
                                    @if (filled($smtp->last_error))
                                        <p class="mt-2 break-words rounded bg-red-100/70 p-2 font-mono text-xs text-red-900">
                                            {{ $smtp->last_error }}
                                        </p>
                                    @endif
                                    @permission('smtp.manage')
                                        <div class="mt-3">
                                            <x-confirm-form :action="route('smtp.reset-cooldown', $smtp)"
                                                            method="POST"
                                                            label="Clear cooldown"
                                                            button-class="kn-btn-danger kn-btn-sm"
                                                            message="Clear the cooldown and let sending use this account again? Test the connection first, or it will fail straight back into a cooldown." />
                                        </div>
                                    @endpermission
                                </div>
                            @endif

                            @permission('smtp.manage')
                                <div class="border-t border-ink-100 pt-4">
                                    @include('smtp.partials.test-widget', [
                                        'testId' => $smtp->id,
                                        'testUrl' => route('smtp.test', $smtp),
                                    ])
                                </div>
                            @endpermission
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- --------------------------------------------- shared accounts --}}
        @if ($adminSmtpAllowed)
            <div class="mb-3 flex items-center justify-between gap-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Provided by KN Softic</h2>
                <span class="text-xs text-ink-500">Read-only</span>
            </div>

            <p class="mb-3 text-sm text-ink-600">
                These accounts are managed by KN Softic and made available to your plan — you can send through
                them, but only an administrator can change or remove them.
            </p>

            <div class="kn-card overflow-hidden">
                @if ($shared->isEmpty())
                    <x-empty-state title="No shared accounts assigned yet"
                                   message="Your plan allows sending through KN Softic's own SMTP accounts, but none has been assigned to your account." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Provider</th>
                                    <th>Hourly</th>
                                    <th>Daily</th>
                                    <th>Monthly</th>
                                    <th>Status</th>
                                    <th class="text-right">Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shared as $smtp)
                                    <tr>
                                        <td>
                                            <div class="min-w-0 max-w-xs">
                                                <span class="block truncate font-medium text-ink-900">{{ $smtp->name }}</span>
                                                <span class="block truncate text-xs text-ink-500">
                                                    {{ $smtp->host }}:{{ $smtp->port }} · {{ $encryptionLabels[$smtp->encryption] ?? $smtp->encryption }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap text-ink-600">
                                            {{ \App\Support\SmtpProviders::label($smtp->provider) }}
                                        </td>
                                        <td class="whitespace-nowrap">{{ $limitText($smtp->hourly_limit, (int) $smtp->sent_this_hour) }}</td>
                                        <td class="whitespace-nowrap">{{ $limitText($smtp->daily_limit, (int) $smtp->sent_today) }}</td>
                                        <td class="whitespace-nowrap">{{ $limitText($smtp->monthly_limit, (int) $smtp->sent_this_month) }}</td>
                                        <td>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <x-status-badge :status="$smtp->is_active ? 'active' : 'paused'" />
                                                @if ($smtp->isInCooldown())
                                                    <span class="kn-badge-red">In cooldown</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('smtp.show', $smtp) }}" class="kn-btn-ghost kn-btn-sm">View</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @endif
</x-app-layout>
