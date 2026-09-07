<x-admin-layout>
    <x-slot name="header">Accounts</x-slot>

    <x-page-header title="Accounts" subtitle="Each account is an isolated tenant with its own data." />

    <form method="GET" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search account or owner email" />
            </div>
            <select name="status" class="kn-select">
                <option value="">Any status</option>
                @foreach (['active', 'suspended', 'pending'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <select name="plan_id" class="kn-select">
                    <option value="">Any plan</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected((int) ($filters['plan_id'] ?? 0) === $plan->id)>{{ $plan->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
            </div>
        </div>
    </form>

    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Owner</th>
                        <th>Plan</th>
                        <th>Users</th>
                        <th>Subscribers</th>
                        <th>Campaigns</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <td>
                                <a href="{{ route('admin.accounts.show', $account) }}" class="font-medium text-ink-900 hover:text-brand-600">
                                    {{ $account->name }}
                                </a>
                                <div class="text-xs text-ink-500">{{ $account->timezone }}</div>
                            </td>
                            <td>
                                @if ($account->owner)
                                    <a href="{{ route('admin.users.show', $account->owner) }}" class="text-brand-600 hover:text-brand-700">
                                        {{ $account->owner->email }}
                                    </a>
                                @else
                                    <span class="text-ink-400">No owner</span>
                                @endif
                            </td>
                            <td>
                                {{ $account->subscription?->plan?->name ?? '—' }}
                                @if ($account->subscription)
                                    <span class="kn-badge-gray ml-1">{{ ucfirst($account->subscription->status) }}</span>
                                @endif
                            </td>
                            <td>{{ number_format($account->users_count) }}</td>
                            <td>{{ number_format($account->subscribers_count) }}</td>
                            <td>{{ number_format($account->campaigns_count) }}</td>
                            <td>
                                <span class="{{ $account->isActive() ? 'kn-badge-green' : 'kn-badge-red' }}">
                                    {{ ucfirst($account->status) }}
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('admin.accounts.show', $account) }}" class="kn-btn-secondary kn-btn-sm">Manage</a>
                                    <x-confirm-form :action="route('admin.accounts.status', $account)"
                                                    method="PATCH"
                                                    :label="$account->isSuspended() ? 'Activate' : 'Suspend'"
                                                    button-class="kn-btn-ghost kn-btn-sm"
                                                    :message="$account->isSuspended() ? 'Reactivate this account?' : 'Suspend this account? Every user inside it will be locked out.'" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8"><x-empty-state title="No accounts match these filters" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $accounts->links() }}</div>
        @endif
    </div>
</x-admin-layout>
