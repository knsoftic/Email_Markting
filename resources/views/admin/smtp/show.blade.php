<x-admin-layout>
    <x-slot name="header">{{ $account->name }}</x-slot>

    @php
        /**
         * $account         — the platform SMTP row, with assignments (+ plan, account) loaded.
         * $scope           — none | all | plan | account, derived from the current assignments.
         * $selectedPlans   — plan ids currently assigned.
         * $selectedAccounts— account ids currently assigned.
         * $usage           — up to 30 SmtpUsage rows (one per account per day).
         * $topTenants      — SUM(sent) per account, account eager-loaded.
         *
         * The password is deliberately absent from this screen: it is encrypted,
         * hidden from serialisation and never rendered anywhere.
         */
        $currentScope = old('scope', $scope);
        $planIds = old('plan_ids', $selectedPlans);
        $accountIds = old('account_ids', $selectedAccounts);

        $reachValue = match ($scope) {
            'all' => 'Everyone',
            'plan' => number_format(count($selectedPlans)),
            'account' => number_format(count($selectedAccounts)),
            default => 'Nobody',
        };
        $reachMeta = match ($scope) {
            'all' => 'Every account on the platform',
            'plan' => \Illuminate\Support\Str::plural('plan', count($selectedPlans)).' — everyone subscribed to them',
            'account' => 'hand-picked '.\Illuminate\Support\Str::plural('account', count($selectedAccounts)),
            default => 'Not assigned — this account is idle',
        };

        // Usage rows are per account per day, so collapse them to one row a day.
        $usageByDay = $usage->groupBy(fn ($row) => $row->date?->format('Y-m-d'));
        $chartDays = $usageByDay->keys()->reverse()->values();
        $chartSent = $chartDays->map(fn ($day) => (int) $usageByDay[$day]->sum('sent'));
        $topMax = (int) ($topTenants->max('total') ?: 0);
    @endphp

    @php $usageChart = ['type' => 'bar', 'labels' => $chartDays, 'datasets' => [['label' => 'Emails sent', 'data' => $chartSent]]]; @endphp

    <x-page-header :title="$account->name"
                   :subtitle="$provider['label'].' · '.$account->host.':'.$account->port.' · shared platform credentials'"
                   :back="route('admin.smtp.index')">
        <x-slot name="actions">
            <a href="{{ route('admin.smtp.edit', $account) }}" class="kn-btn-secondary">Edit</a>

            <x-confirm-form :action="route('admin.smtp.status', $account)"
                            method="PATCH"
                            :label="$account->is_active ? 'Pause' : 'Resume'"
                            button-class="kn-btn-secondary"
                            :message="$account->is_active
                                ? 'Pause this account? Every assigned customer stops sending through it immediately.'
                                : 'Resume this account? Assigned customers can send through it again.'" />

            <x-confirm-form :action="route('admin.smtp.destroy', $account)"
                            label="Delete"
                            button-class="kn-btn-danger"
                            message="Delete this platform SMTP? Its assignments go with it and the accounts using it fall back to their own SMTP." />
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Sent today" :value="number_format($account->sent_today)"
                     :meta="$account->daily_limit
                        ? number_format($account->remainingToday()).' left of '.number_format($account->daily_limit).' today'
                        : 'No daily cap'" />
        <x-stat-card label="Sent this month" :value="number_format($account->sent_this_month)"
                     :meta="$account->monthly_limit
                        ? 'of '.number_format($account->monthly_limit).' this month'
                        : 'No monthly cap'" />
        <x-stat-card label="Sent all time" :value="number_format($account->total_sent)"
                     :meta="number_format($account->total_failed).' failed'" />
        <x-stat-card label="Reaches" :value="$reachValue" :meta="$reachMeta"
                     :tone="$scope === 'none' ? 'warning' : 'default'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- ------------------------------------------------- assignments --}}
            <form method="POST" action="{{ route('admin.smtp.assignments', $account) }}" class="kn-card"
                  x-data="{ scope: @js($currentScope) }">
                @csrf
                @method('PUT')

                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Who may send through this account</h3>
                    <span class="text-xs text-ink-500">Saving replaces the whole set</span>
                </div>

                <div class="space-y-5 p-5">
                    <p class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                        An unassigned platform account reaches nobody: it sits idle, sends nothing and no customer
                        ever sees it. Pick a scope below to put it to work. A customer also needs the
                        <span class="font-medium text-ink-800">Admin SMTP</span> feature on their plan before this
                        account appears to them.
                    </p>

                    <div class="space-y-3">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 transition hover:bg-ink-50"
                               :class="scope === 'none' ? 'border-brand-500 ring-1 ring-brand-500' : ''">
                            <input type="radio" name="scope" value="none" x-model="scope"
                                   class="kn-checkbox mt-0.5 rounded-full" @checked($currentScope === 'none')>
                            <span class="text-sm">
                                <span class="font-medium text-ink-900">Nobody</span>
                                <span class="block text-xs text-ink-500">
                                    Keep the credentials on file but stop lending them. Nothing sends through this account.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 transition hover:bg-ink-50"
                               :class="scope === 'all' ? 'border-brand-500 ring-1 ring-brand-500' : ''">
                            <input type="radio" name="scope" value="all" x-model="scope"
                                   class="kn-checkbox mt-0.5 rounded-full" @checked($currentScope === 'all')>
                            <span class="text-sm">
                                <span class="font-medium text-ink-900">Every account</span>
                                <span class="mt-1 block rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-800">
                                    Warning: every customer on the platform will send through these credentials.
                                    One customer's spam complaint damages the sending reputation of all of them,
                                    and they share the hourly, daily and monthly caps set on this account.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 transition hover:bg-ink-50"
                               :class="scope === 'plan' ? 'border-brand-500 ring-1 ring-brand-500' : ''">
                            <input type="radio" name="scope" value="plan" x-model="scope"
                                   class="kn-checkbox mt-0.5 rounded-full" @checked($currentScope === 'plan')>
                            <span class="text-sm">
                                <span class="font-medium text-ink-900">Accounts on selected plans</span>
                                <span class="block text-xs text-ink-500">
                                    Anyone subscribed to one of the chosen plans, including accounts created later.
                                </span>
                            </span>
                        </label>

                        <div x-show="scope === 'plan'" x-cloak class="rounded-lg border border-ink-200 p-4">
                            @if ($plans->isEmpty())
                                <p class="text-sm text-ink-500">
                                    There are no plans yet.
                                    <a href="{{ route('admin.plans.create') }}" class="font-medium text-brand-600 hover:text-brand-700">Create one first</a>.
                                </p>
                            @else
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($plans as $plan)
                                        <label class="flex items-center gap-2.5 rounded-md px-2 py-1.5 hover:bg-ink-50">
                                            <input type="checkbox" name="plan_ids[]" value="{{ $plan->id }}"
                                                   class="kn-checkbox" @checked(in_array($plan->id, (array) $planIds))>
                                            <span class="min-w-0 truncate text-sm text-ink-700">{{ $plan->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('plan_ids')" />
                            <x-input-error :messages="$errors->get('plan_ids.*')" />
                        </div>

                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 transition hover:bg-ink-50"
                               :class="scope === 'account' ? 'border-brand-500 ring-1 ring-brand-500' : ''">
                            <input type="radio" name="scope" value="account" x-model="scope"
                                   class="kn-checkbox mt-0.5 rounded-full" @checked($currentScope === 'account')>
                            <span class="text-sm">
                                <span class="font-medium text-ink-900">Selected accounts only</span>
                                <span class="block text-xs text-ink-500">
                                    A fixed list. New accounts do not inherit it.
                                </span>
                            </span>
                        </label>

                        <div x-show="scope === 'account'" x-cloak class="rounded-lg border border-ink-200 p-4">
                            @if ($accounts->isEmpty())
                                <p class="text-sm text-ink-500">There are no customer accounts yet.</p>
                            @else
                                <x-input-label for="account_ids" value="Accounts" />
                                <select id="account_ids" name="account_ids[]" multiple size="10" class="kn-select">
                                    @foreach ($accounts as $tenant)
                                        <option value="{{ $tenant->id }}" @selected(in_array($tenant->id, (array) $accountIds))>
                                            {{ $tenant->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="kn-help">
                                    Ctrl-click (Cmd-click on a Mac) to pick several. The first 500 accounts by name are listed.
                                </p>
                            @endif
                            <x-input-error :messages="$errors->get('account_ids')" />
                            <x-input-error :messages="$errors->get('account_ids.*')" />
                        </div>
                    </div>

                    <x-input-error :messages="$errors->get('scope')" />
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-5 py-3">
                    <p class="text-xs text-ink-500">
                        @if ($account->assignments->isEmpty())
                            No assignment rules stored right now.
                        @else
                            {{ $account->assignments->count() }}
                            assignment {{ \Illuminate\Support\Str::plural('rule', $account->assignments->count()) }} stored.
                        @endif
                    </p>
                    <button type="submit" class="kn-btn-primary">Save assignment</button>
                </div>
            </form>

            {{-- ------------------------------------------- current assignments --}}
            @if ($account->assignments->isNotEmpty())
                <div class="kn-card overflow-hidden">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Stored assignment rules</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Scope</th>
                                    <th>Target</th>
                                    <th>Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($account->assignments as $assignment)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <span class="kn-badge-blue">{{ ucfirst($assignment->scope) }}</span>
                                        </td>
                                        <td>
                                            @if ($assignment->scope === 'all')
                                                Every account on the platform
                                            @elseif ($assignment->scope === 'plan')
                                                {{ $assignment->plan?->name ?? 'Plan removed' }}
                                            @elseif ($assignment->account)
                                                <a href="{{ route('admin.accounts.show', $assignment->account) }}"
                                                   class="font-medium text-ink-900 hover:text-brand-600">
                                                    {{ $assignment->account->name }}
                                                </a>
                                            @else
                                                <span class="text-ink-400">Account removed</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-xs text-ink-500"
                                            title="{{ $assignment->created_at?->format('D, d M Y H:i') }}">
                                            {{ $assignment->created_at?->diffForHumans() ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- -------------------------------------------------- daily usage --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Daily usage</h3>
                    <span class="text-xs text-ink-500">Most recent days first</span>
                </div>

                @if ($usageByDay->isEmpty())
                    <x-empty-state title="Nothing sent through this account yet"
                                   message="Usage appears here the first time a campaign goes out through these credentials." />
                @else
                    @if ($chartDays->count() > 1)
                        <div class="border-b border-ink-100 p-5">
                            <div class="h-44">
                                <canvas data-chart='@json($usageChart)'></canvas>
                            </div>
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Sent</th>
                                    <th>Failed</th>
                                    <th>Accounts sending</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($usageByDay as $day => $rows)
                                    <tr>
                                        <td class="whitespace-nowrap font-medium text-ink-900">{{ $day }}</td>
                                        <td class="whitespace-nowrap">{{ number_format((int) $rows->sum('sent')) }}</td>
                                        <td class="whitespace-nowrap {{ $rows->sum('failed') > 0 ? 'text-red-600' : '' }}">
                                            {{ number_format((int) $rows->sum('failed')) }}
                                        </td>
                                        <td class="whitespace-nowrap text-ink-500">
                                            {{ number_format($rows->pluck('account_id')->filter()->unique()->count()) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- --------------------------------------------------- top tenants --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Top accounts by volume</h3>
                    <span class="text-xs text-ink-500">Who is consuming the shared quota</span>
                </div>

                @if ($topTenants->isEmpty())
                    <x-empty-state title="No account has sent through this yet"
                                   message="Once customers start sending, the heaviest users of these shared credentials are listed here." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Emails sent</th>
                                    <th class="w-40">Relative to the busiest</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topTenants as $row)
                                    @php $share = $topMax > 0 ? (int) round((int) $row->total / $topMax * 100) : 0; @endphp
                                    <tr>
                                        <td>
                                            @if ($row->account)
                                                <a href="{{ route('admin.accounts.show', $row->account) }}"
                                                   class="block max-w-[16rem] truncate font-medium text-ink-900 hover:text-brand-600">
                                                    {{ $row->account->name }}
                                                </a>
                                            @else
                                                <span class="text-ink-400">Account removed</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap font-medium text-ink-900">{{ number_format((int) $row->total) }}</td>
                                        <td>
                                            <div class="h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                                <div class="h-2 rounded-full bg-brand-600" style="width: {{ $share }}%"></div>
                                            </div>
                                            <span class="mt-1 block text-xs text-ink-500">{{ $share }}%</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------- side column --}}
        <div class="space-y-6">

            {{-- Connection test. The widget is shared with the tenant screens; it
                 posts to the route given here and reports the result inline. --}}
            @include('smtp.partials.test-widget', [
                'testId' => $account->id,
                'testUrl' => route('admin.smtp.test', $account),
            ])

            {{-- ------------------------------------------ connection details --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Connection details</h3>
                    <span class="{{ $account->is_active ? 'kn-badge-green' : 'kn-badge-gray' }}">
                        {{ $account->is_active ? 'Active' : 'Paused' }}
                    </span>
                </div>

                <dl class="divide-y divide-ink-100 text-sm">
                    @foreach ([
                        'Provider' => $provider['label'],
                        'Host' => $account->host.':'.$account->port,
                        'Encryption' => $account->encryption === 'none' ? 'None' : strtoupper($account->encryption),
                        'Certificate check' => $account->verify_peer ? 'Verified' : 'Skipped',
                        'Username' => $account->username ? $account->maskedUsername() : 'None',
                        'From' => $account->from_name.' <'.$account->from_email.'>',
                        'Reply-to' => $account->reply_to ?: 'Not set',
                        'Priority' => (string) (int) $account->priority,
                        'Send delay' => number_format((int) $account->send_delay_ms).' ms',
                    ] as $term => $value)
                        <div class="flex items-start justify-between gap-4 px-5 py-2.5">
                            <dt class="shrink-0 text-ink-500">{{ $term }}</dt>
                            <dd class="min-w-0 break-words text-right font-medium text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach

                    <div class="flex items-start justify-between gap-4 px-5 py-2.5">
                        <dt class="shrink-0 text-ink-500">Password</dt>
                        <dd class="text-right">
                            <span class="kn-badge-gray">Stored, encrypted</span>
                            <span class="mt-1 block text-xs text-ink-500">Never displayed. Replace it on the edit form.</span>
                        </dd>
                    </div>
                </dl>

                <div class="border-t border-ink-100 px-5 py-3">
                    <a href="{{ route('admin.smtp.edit', $account) }}" class="kn-btn-secondary w-full">Edit connection</a>
                </div>
            </div>

            {{-- ---------------------------------------------------- limits --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Limits</h3>
                </div>
                <div class="space-y-4 p-5">
                    @foreach ([
                        ['This hour', $account->sent_this_hour, $account->hourly_limit],
                        ['Today', $account->sent_today, $account->daily_limit],
                        ['This month', $account->sent_this_month, $account->monthly_limit],
                    ] as [$period, $used, $limit])
                        @php
                            $percent = $limit ? min(100, (int) round($used / max($limit, 1) * 100)) : 0;
                            $bar = $percent >= 90 ? 'bg-red-500' : ($percent >= 75 ? 'bg-amber-500' : 'bg-brand-600');
                        @endphp
                        <div>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-ink-600">{{ $period }}</span>
                                <span class="text-right font-medium text-ink-900">
                                    @if ($limit)
                                        {{ number_format($used) }} / {{ number_format($limit) }}
                                    @else
                                        {{ number_format($used) }} <span class="text-ink-500">· no cap</span>
                                    @endif
                                </span>
                            </div>
                            @if ($limit)
                                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-ink-200">
                                    <div class="h-2 rounded-full {{ $bar }}" style="width: {{ $percent }}%"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ---------------------------------------------------- health --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Health</h3>
                    @if ($account->last_tested_at)
                        <span class="{{ $account->test_passed ? 'kn-badge-green' : 'kn-badge-red' }}">
                            {{ $account->test_passed ? 'Test passed' : 'Test failed' }}
                        </span>
                    @else
                        <span class="kn-badge-gray">Never tested</span>
                    @endif
                </div>

                <div class="space-y-3 p-5 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-ink-500">Last tested</span>
                        <span class="text-right font-medium text-ink-900"
                              title="{{ $account->last_tested_at?->format('D, d M Y H:i') }}">
                            {{ $account->last_tested_at?->diffForHumans() ?? 'Never' }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <span class="text-ink-500">Last successful send</span>
                        <span class="text-right font-medium text-ink-900"
                              title="{{ $account->last_success_at?->format('D, d M Y H:i') }}">
                            {{ $account->last_success_at?->diffForHumans() ?? 'Never' }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <span class="text-ink-500">Consecutive failures</span>
                        <span class="text-right font-medium {{ $account->consecutive_failures > 0 ? 'text-red-600' : 'text-ink-900' }}">
                            {{ number_format((int) $account->consecutive_failures) }}
                        </span>
                    </div>

                    @if ($account->last_error)
                        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-red-700">Last error</p>
                            <p class="mt-1 break-words text-xs text-red-800">{{ $account->last_error }}</p>
                            <p class="mt-1 text-xs text-red-600">{{ $account->last_error_at?->diffForHumans() }}</p>
                        </div>
                    @endif

                    @if ($account->isInCooldown())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">In cooldown</p>
                            <p class="mt-1 text-xs text-amber-800">
                                Too many failures in a row, so sending is held until
                                {{ $account->cooldown_until->format('D, d M Y H:i') }}
                                ({{ $account->cooldown_until->diffForHumans() }}). Fix the cause first —
                                clearing the cooldown on a broken account just repeats the failures.
                            </p>
                            <div class="mt-2">
                                <x-confirm-form :action="route('admin.smtp.reset-cooldown', $account)"
                                                method="POST"
                                                label="Clear cooldown"
                                                button-class="kn-btn-secondary kn-btn-sm"
                                                message="Clear the cooldown and reset the failure counter? Sending resumes immediately." />
                            </div>
                        </div>
                    @elseif ($account->consecutive_failures > 0)
                        <div class="pt-1">
                            <x-confirm-form :action="route('admin.smtp.reset-cooldown', $account)"
                                            method="POST"
                                            label="Reset failure counter"
                                            button-class="kn-btn-secondary kn-btn-sm"
                                            message="Reset the failure counter back to zero?" />
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
