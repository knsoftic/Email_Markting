<x-admin-layout>
    <x-slot name="header">Admin SMTP</x-slot>

    @php
        /**
         * $accounts    — platform-owned rows (account_id = null), each with assignments_count.
         * $tenantOwned — paginator of customer-owned rows, read-only here, page name "tenant_page".
         */
        $providerLabels = \App\Support\SmtpProviders::all();
        $label = fn ($key) => $providerLabels[$key]['label'] ?? ucfirst((string) $key);

        // "Unlimited" is the default here, so only the caps that exist are listed.
        $caps = function ($smtp) {
            $parts = [];

            foreach ([['hourly_limit', 'hour'], ['daily_limit', 'day'], ['monthly_limit', 'month']] as [$key, $period]) {
                if ($smtp->{$key}) {
                    $parts[] = number_format($smtp->{$key}).' / '.$period;
                }
            }

            return $parts ? implode(' · ', $parts) : 'No caps set';
        };

        $activeCount = $accounts->where('is_active', true)->count();
        $unassignedCount = $accounts->filter(fn ($a) => (int) $a->assignments_count === 0)->count();
        $sentToday = $accounts->sum('sent_today');
    @endphp

    <x-page-header title="Platform SMTP"
                   subtitle="Credentials the platform owns and lends to customers. Nothing here is billed to an account — every send goes through your provider quota.">
        <x-slot name="actions">
            <a href="{{ route('admin.smtp.create') }}" class="kn-btn-primary">New admin SMTP</a>
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Platform accounts" :value="number_format($accounts->count())"
                     meta="Owned by you, shared with customers" />
        <x-stat-card label="Active" :value="number_format($activeCount)"
                     :meta="($accounts->count() - $activeCount).' paused'"
                     :tone="$activeCount > 0 ? 'positive' : 'default'" />
        <x-stat-card label="Reaching nobody" :value="number_format($unassignedCount)"
                     :meta="$unassignedCount > 0 ? 'Assign them or they sit idle' : 'Every account has an assignment'"
                     :tone="$unassignedCount > 0 ? 'warning' : 'default'" />
        <x-stat-card label="Sent today" :value="number_format($sentToday)"
                     meta="Across every platform account" />
    </div>

    {{-- ------------------------------------------------------ platform SMTP --}}
    <div class="kn-card mb-8 overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Platform SMTP</h3>
            <span class="text-xs text-ink-500">
                {{ number_format($accounts->count()) }}
                {{ \Illuminate\Support\Str::plural('account', $accounts->count()) }}, ordered by priority
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Provider</th>
                        <th>Connection</th>
                        <th>Limits &amp; today</th>
                        <th>Reach</th>
                        <th>Status</th>
                        <th>Last test</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $smtp)
                        @php $reach = (int) $smtp->assignments_count; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.smtp.show', $smtp) }}"
                                   class="block max-w-[14rem] truncate font-medium text-ink-900 hover:text-brand-600">
                                    {{ $smtp->name }}
                                </a>
                                <span class="block max-w-[14rem] truncate text-xs text-ink-500">
                                    {{ $smtp->from_email }} · priority {{ (int) $smtp->priority }}
                                </span>
                            </td>

                            <td class="whitespace-nowrap">{{ $label($smtp->provider) }}</td>

                            <td>
                                <span class="block max-w-[14rem] truncate text-ink-700">{{ $smtp->host }}:{{ $smtp->port }}</span>
                                <span class="block text-xs uppercase text-ink-500">
                                    {{ $smtp->encryption === 'none' ? 'No encryption' : $smtp->encryption }}
                                </span>
                            </td>

                            <td class="whitespace-nowrap">
                                <span class="font-medium text-ink-900">{{ number_format($smtp->sent_today) }}</span>
                                <span class="text-ink-500">
                                    @if ($smtp->daily_limit)
                                        sent today, {{ number_format($smtp->remainingToday()) }} left
                                    @else
                                        sent today
                                    @endif
                                </span>
                                <span class="block text-xs text-ink-500">Caps: {{ $caps($smtp) }}</span>
                            </td>

                            <td>
                                @if ($reach === 0)
                                    <span class="kn-badge-amber">Not assigned to anyone</span>
                                    <span class="mt-1 block text-xs text-ink-500">No customer can send through it.</span>
                                @else
                                    <span class="kn-badge-blue">{{ $reach }} assignment {{ \Illuminate\Support\Str::plural('rule', $reach) }}</span>
                                    <span class="mt-1 block text-xs text-ink-500">Open it to see who it covers.</span>
                                @endif
                            </td>

                            <td>
                                <span class="{{ $smtp->is_active ? 'kn-badge-green' : 'kn-badge-gray' }}">
                                    {{ $smtp->is_active ? 'Active' : 'Paused' }}
                                </span>
                                @if ($smtp->isInCooldown())
                                    <span class="kn-badge-red mt-1 block w-fit"
                                          title="{{ $smtp->cooldown_until?->format('D, d M Y H:i') }}">
                                        Cooling down
                                    </span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap">
                                @if ($smtp->last_tested_at)
                                    <span class="{{ $smtp->test_passed ? 'kn-badge-green' : 'kn-badge-red' }}">
                                        {{ $smtp->test_passed ? 'Passed' : 'Failed' }}
                                    </span>
                                    <span class="mt-1 block text-xs text-ink-500"
                                          title="{{ $smtp->last_tested_at->format('D, d M Y H:i') }}">
                                        {{ $smtp->last_tested_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="kn-badge-gray">Never tested</span>
                                @endif
                            </td>

                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('admin.smtp.show', $smtp) }}" class="kn-btn-ghost kn-btn-sm">View</a>
                                    <a href="{{ route('admin.smtp.edit', $smtp) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                    <x-confirm-form :action="route('admin.smtp.status', $smtp)"
                                                    method="PATCH"
                                                    :label="$smtp->is_active ? 'Pause' : 'Resume'"
                                                    button-class="kn-btn-secondary kn-btn-sm"
                                                    :message="$smtp->is_active
                                                        ? 'Pause '.$smtp->name.'? Every assigned account stops sending through it immediately.'
                                                        : 'Resume '.$smtp->name.'? Assigned accounts can send through it again.'" />

                                    <x-confirm-form :action="route('admin.smtp.destroy', $smtp)"
                                                    label="Delete"
                                                    :message="'Delete '.$smtp->name.'? Its assignments go with it and the accounts using it fall back to their own SMTP.'" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-empty-state title="No platform SMTP yet"
                                               message="Add one set of credentials here and lend it to customers whose plan has no custom SMTP of its own.">
                                    <x-slot name="action">
                                        <a href="{{ route('admin.smtp.create') }}" class="kn-btn-primary">New admin SMTP</a>
                                    </x-slot>
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ------------------------------------------------- customer-owned SMTP --}}
    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-ink-900">Customer-owned SMTP</h3>
                <p class="mt-0.5 text-xs text-ink-500">
                    These belong to the customer's account and are not editable here — this list is for support
                    visibility only. Ask the account owner, or use their own SMTP screen inside the account.
                </p>
            </div>
            <span class="shrink-0 text-xs text-ink-500">{{ number_format($tenantOwned->total()) }} total</span>
        </div>

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Owner account</th>
                        <th>SMTP</th>
                        <th>Provider</th>
                        <th>Connection</th>
                        <th>Status</th>
                        <th>Last error</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tenantOwned as $smtp)
                        <tr>
                            <td>
                                @if ($smtp->account)
                                    <a href="{{ route('admin.accounts.show', $smtp->account) }}"
                                       class="block max-w-[12rem] truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $smtp->account->name }}
                                    </a>
                                @else
                                    <span class="text-ink-400">Account removed</span>
                                @endif
                            </td>

                            <td>
                                <span class="block max-w-[12rem] truncate text-ink-900">{{ $smtp->name }}</span>
                                <span class="block max-w-[12rem] truncate text-xs text-ink-500">{{ $smtp->from_email }}</span>
                            </td>

                            <td class="whitespace-nowrap">{{ $label($smtp->provider) }}</td>

                            <td>
                                <span class="block max-w-[14rem] truncate text-ink-700">{{ $smtp->host }}:{{ $smtp->port }}</span>
                                <span class="block text-xs uppercase text-ink-500">
                                    {{ $smtp->encryption === 'none' ? 'No encryption' : $smtp->encryption }}
                                </span>
                            </td>

                            <td>
                                <span class="{{ $smtp->is_active ? 'kn-badge-green' : 'kn-badge-gray' }}">
                                    {{ $smtp->is_active ? 'Active' : 'Paused' }}
                                </span>
                                @if ($smtp->isInCooldown())
                                    <span class="kn-badge-red mt-1 block w-fit">Cooling down</span>
                                @endif
                            </td>

                            <td>
                                @if ($smtp->last_error)
                                    <span class="block max-w-xs truncate text-xs text-red-600" title="{{ $smtp->last_error }}">
                                        {{ $smtp->last_error }}
                                    </span>
                                    <span class="block text-xs text-ink-500">{{ $smtp->last_error_at?->diffForHumans() }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-empty-state title="No customer has added their own SMTP"
                                               message="Every account is sending through platform credentials, or is not sending at all yet." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($tenantOwned->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $tenantOwned->links() }}</div>
        @endif
    </div>
</x-admin-layout>
