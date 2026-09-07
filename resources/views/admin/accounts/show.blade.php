<x-admin-layout>
    <x-slot name="header">{{ $account->name }}</x-slot>

    @php
        $subscription = $account->subscription;
        $plan = $subscription?->plan;
        $overrides = $subscription?->overrides ?? [];
        $limitLabels = [
            'max_contacts' => 'Contacts',
            'max_emails_per_month' => 'Emails / month',
            'max_emails_per_day' => 'Emails / day',
            'max_emails_received_per_month' => 'Received / month',
            'max_campaigns_per_month' => 'Campaigns / month',
            'max_smtp_accounts' => 'SMTP accounts',
            'max_mailboxes' => 'Mailboxes',
            'max_lists' => 'Lists',
            'max_templates' => 'Templates',
            'max_automations' => 'Automations',
            'max_team_members' => 'Team members',
            'max_storage_mb' => 'Storage (MB)',
        ];
        $featureLabels = [
            'allow_custom_smtp' => 'Custom SMTP',
            'allow_smtp_rotation' => 'SMTP rotation',
            'allow_admin_smtp' => 'Admin SMTP',
            'allow_imap' => 'IMAP inbox',
            'allow_automation' => 'Automation',
            'allow_ab_testing' => 'A/B testing',
            'allow_advanced_analytics' => 'Advanced analytics',
            'allow_segments' => 'Segments',
            'allow_custom_fields' => 'Custom fields',
            'allow_attachments' => 'Attachments',
            'allow_scheduling' => 'Scheduling',
            'allow_template_builder' => 'Template builder',
            'allow_api' => 'API access',
        ];
    @endphp

    <x-page-header :title="$account->name"
                   :subtitle="'Owner: '.($account->owner?->email ?? 'none').' · '.$account->timezone"
                   :back="route('admin.accounts.index')">
        <x-slot name="actions">
            <x-confirm-form :action="route('admin.accounts.status', $account)"
                            method="PATCH"
                            :label="$account->isSuspended() ? 'Activate account' : 'Suspend account'"
                            button-class="kn-btn-secondary"
                            :message="$account->isSuspended() ? 'Reactivate this account?' : 'Suspend this account? Every user inside it is locked out immediately.'" />
            <x-confirm-form :action="route('admin.accounts.destroy', $account)"
                            label="Delete account"
                            message="Delete this account? All of its data is soft-deleted." />
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-5">
        <x-stat-card label="Users" :value="number_format($counts['users'])" />
        <x-stat-card label="Subscribers" :value="number_format($counts['subscribers'])" />
        <x-stat-card label="Campaigns" :value="number_format($counts['campaigns'])" />
        <x-stat-card label="SMTP accounts" :value="number_format($counts['smtp'])" />
        <x-stat-card label="Mailboxes" :value="number_format($counts['mailboxes'])" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- Account details --}}
            <form method="POST" action="{{ route('admin.accounts.update', $account) }}" class="kn-card">
                @csrf @method('PUT')
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Account details</h3></div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Account name" />
                        <x-text-input id="name" name="name" :value="old('name', $account->name)" required />
                        <x-input-error :messages="$errors->get('name')" />
                    </div>
                    <div>
                        <x-input-label for="company_name" value="Company name" />
                        <x-text-input id="company_name" name="company_name" :value="old('company_name', $account->company_name)" />
                    </div>
                    <div>
                        <x-input-label for="website" value="Website" />
                        <x-text-input id="website" name="website" type="url" :value="old('website', $account->website)" />
                        <x-input-error :messages="$errors->get('website')" />
                    </div>
                    <div>
                        <x-input-label for="phone" value="Phone" />
                        <x-text-input id="phone" name="phone" :value="old('phone', $account->phone)" />
                    </div>
                    <div>
                        <x-input-label for="country" value="Country" />
                        <x-text-input id="country" name="country" :value="old('country', $account->country)" />
                    </div>
                    <div>
                        <x-input-label for="timezone" value="Timezone" />
                        <select id="timezone" name="timezone" class="kn-select">
                            @foreach (\DateTimeZone::listIdentifiers() as $tz)
                                <option value="{{ $tz }}" @selected(old('timezone', $account->timezone) === $tz)>{{ $tz }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="kn-select">
                            @foreach (['active', 'suspended', 'pending'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $account->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end border-t border-ink-100 px-5 py-3">
                    <button type="submit" class="kn-btn-primary">Save details</button>
                </div>
            </form>

            {{-- Limit overrides --}}
            @if ($subscription)
                <form method="POST" action="{{ route('admin.subscriptions.overrides', $subscription) }}" class="kn-card">
                    @csrf @method('PUT')
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Per-account limit overrides</h3>
                        <span class="text-xs text-ink-500">Blank = use the {{ $plan?->name }} plan value</span>
                    </div>

                    <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($limitLabels as $key => $label)
                            @php $planValue = $plan?->{$key}; @endphp
                            <div>
                                <x-input-label :for="'ov_'.$key" :value="$label" />
                                <x-text-input :id="'ov_'.$key" :name="'overrides['.$key.']'" type="number" min="0"
                                              :value="old('overrides.'.$key, $overrides[$key] ?? null)"
                                              :placeholder="$planValue === null ? 'Plan: unlimited' : 'Plan: '.number_format($planValue)" />
                                <x-input-error :messages="$errors->get('overrides.'.$key)" />
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-ink-100 px-5 py-4">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-500">Feature overrides</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($featureLabels as $key => $label)
                                @php $overrideValue = array_key_exists($key, $overrides) ? ($overrides[$key] ? '1' : '0') : ''; @endphp
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-ink-200 px-3 py-2">
                                    <span class="min-w-0 truncate text-sm text-ink-700">{{ $label }}</span>
                                    <select name="overrides[{{ $key }}]" class="kn-select w-32 shrink-0 text-xs">
                                        <option value="" @selected($overrideValue === '')>
                                            Plan ({{ $plan?->{$key} ? 'on' : 'off' }})
                                        </option>
                                        <option value="1" @selected($overrideValue === '1')>Force on</option>
                                        <option value="0" @selected($overrideValue === '0')>Force off</option>
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-ink-100 px-5 py-3">
                        <button type="submit" class="kn-btn-primary">Save overrides</button>
                    </div>
                </form>
            @endif

            {{-- Team --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Users in this account</h3></div>
                <div class="overflow-x-auto">
                    <table class="kn-table">
                        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th></tr></thead>
                        <tbody>
                            @foreach ($account->users as $member)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.users.show', $member) }}" class="font-medium text-ink-900 hover:text-brand-600">{{ $member->name }}</a>
                                        <div class="text-xs text-ink-500">{{ $member->email }}</div>
                                    </td>
                                    <td>
                                        {{ $member->role?->name ?? '—' }}
                                        @if ($member->id === $account->owner_id)
                                            <span class="kn-badge-blue ml-1">Owner</span>
                                        @endif
                                    </td>
                                    <td><span class="{{ $member->status === 'active' ? 'kn-badge-green' : 'kn-badge-red' }}">{{ ucfirst($member->status) }}</span></td>
                                    <td class="text-xs text-ink-500">{{ $member->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Subscription --}}
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Subscription</h3></div>

                @if ($subscription)
                    <form method="POST" action="{{ route('admin.subscriptions.update', $subscription) }}" class="space-y-4 p-5">
                        @csrf @method('PUT')

                        <div>
                            <p class="kn-stat-label">Current plan</p>
                            <p class="mt-0.5 text-sm font-medium text-ink-900">{{ $plan?->name ?? 'None' }}</p>
                        </div>

                        <div>
                            <x-input-label for="sub_status" value="Status" />
                            <select id="sub_status" name="status" class="kn-select">
                                @foreach (['trial', 'active', 'expired', 'cancelled', 'pending'] as $status)
                                    <option value="{{ $status }}" @selected($subscription->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <x-input-label for="ends_at" value="Expires on" />
                            <x-text-input id="ends_at" name="ends_at" type="date"
                                          :value="old('ends_at', $subscription->ends_at?->format('Y-m-d'))" />
                        </div>

                        <div>
                            <x-input-label for="extend_days" value="Extend by (days)" />
                            <x-text-input id="extend_days" name="extend_days" type="number" min="1" placeholder="e.g. 30" />
                            <p class="kn-help">Adds to the expiry date above.</p>
                        </div>

                        <div>
                            <x-input-label for="notes" value="Internal note" />
                            <textarea id="notes" name="notes" rows="2" class="kn-textarea">{{ old('notes', $subscription->notes) }}</textarea>
                        </div>

                        <button type="submit" class="kn-btn-primary w-full">Update subscription</button>
                    </form>
                @else
                    <p class="px-5 py-4 text-sm text-ink-500">This account has no subscription yet.</p>
                @endif
            </div>

            {{-- Change / assign plan --}}
            <form method="POST" action="{{ route('admin.subscriptions.store', $account) }}" class="kn-card">
                @csrf
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Assign a plan</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <x-input-label for="plan_id" value="Plan" />
                        <select id="plan_id" name="plan_id" class="kn-select" required>
                            @foreach ($plans as $p)
                                <option value="{{ $p->id }}" @selected($plan?->id === $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_status" value="Start as" />
                        <select id="new_status" name="status" class="kn-select">
                            <option value="active">Active</option>
                            <option value="trial">Trial</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_ends_at" value="Expires on" />
                        <x-text-input id="new_ends_at" name="ends_at" type="date" />
                        <p class="kn-help">Leave blank for one month from today.</p>
                    </div>
                    <button type="submit" class="kn-btn-secondary w-full">Assign plan</button>
                </div>
            </form>

            {{-- Usage --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Usage</h3>
                    <span class="text-xs text-ink-500">{{ $current->period }}</span>
                </div>
                <div class="space-y-2.5 p-5 text-sm">
                    @foreach ([
                        'Emails sent' => $current->emails_sent,
                        'Emails failed' => $current->emails_failed,
                        'Emails received' => $current->emails_received,
                        'Campaigns created' => $current->campaigns_created,
                        'Contacts added' => $current->contacts_added,
                    ] as $label => $value)
                        <div class="flex items-center justify-between">
                            <span class="text-ink-600">{{ $label }}</span>
                            <span class="font-semibold text-ink-900">{{ number_format((int) $value) }}</span>
                        </div>
                    @endforeach
                </div>

                @if ($usage->count() > 1)
                    <div class="border-t border-ink-100 p-5">
                        <div class="h-40">
                            @php $usageChart = ['type' => 'bar', 'labels' => $usage->pluck('period')->reverse()->values(), 'datasets' => [['label' => 'Emails sent', 'data' => $usage->pluck('emails_sent')->reverse()->values()]]]; @endphp
                            <canvas data-chart='@json($usageChart)'></canvas>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
