<x-admin-layout>
    <x-slot name="header">Subscriptions</x-slot>

    <x-page-header title="Subscriptions" subtitle="Every account's plan, status and expiry." />

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Active" :value="number_format($summary['active'])" />
        <x-stat-card label="On trial" :value="number_format($summary['trial'])" />
        <x-stat-card label="Expiring in 14 days" :value="number_format($summary['expiring_soon'])"
                     :tone="$summary['expiring_soon'] > 0 ? 'warning' : 'default'"
                     :href="route('admin.subscriptions.index', ['expiring' => 1])" />
        <x-stat-card label="Expired" :value="number_format($summary['expired'])"
                     :tone="$summary['expired'] > 0 ? 'danger' : 'default'" />
    </div>

    <form method="GET" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search account" />
            <select name="status" class="kn-select">
                <option value="">Any status</option>
                @foreach (['trial', 'active', 'expired', 'cancelled', 'pending'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <select name="plan_id" class="kn-select">
                <option value="">Any plan</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((int) ($filters['plan_id'] ?? 0) === $plan->id)>{{ $plan->name }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-ink-700">
                    <input type="checkbox" name="expiring" value="1" class="kn-checkbox" @checked($filters['expiring'] ?? false)>
                    Expiring soon
                </label>
                <button type="submit" class="kn-btn-primary">Filter</button>
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
                        <th>Status</th>
                        <th>Started</th>
                        <th>Expires</th>
                        <th>Overrides</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td>
                                @if ($subscription->account)
                                    <a href="{{ route('admin.accounts.show', $subscription->account) }}" class="font-medium text-ink-900 hover:text-brand-600">
                                        {{ $subscription->account->name }}
                                    </a>
                                @else
                                    <span class="text-ink-400">Deleted account</span>
                                @endif
                            </td>
                            <td class="text-xs text-ink-500">{{ $subscription->account?->owner?->email ?? '—' }}</td>
                            <td>{{ $subscription->plan?->name ?? '—' }}</td>
                            <td>
                                <span class="{{ match ($subscription->status) {
                                    'active' => 'kn-badge-green',
                                    'trial' => 'kn-badge-blue',
                                    'expired', 'cancelled' => 'kn-badge-red',
                                    default => 'kn-badge-gray',
                                } }}">{{ ucfirst($subscription->status) }}</span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-500">{{ $subscription->starts_at?->format('d M Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-xs">
                                @if ($subscription->ends_at)
                                    <span class="{{ $subscription->ends_at->isPast() ? 'text-red-600' : ($subscription->ends_at->diffInDays() <= 14 ? 'text-amber-600' : 'text-ink-500') }}">
                                        {{ $subscription->ends_at->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="text-ink-400">Never</span>
                                @endif
                            </td>
                            <td>
                                @if ($subscription->overrides)
                                    <span class="kn-badge-amber">{{ count($subscription->overrides) }} custom</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($subscription->account)
                                    <a href="{{ route('admin.accounts.show', $subscription->account) }}" class="kn-btn-secondary kn-btn-sm">Manage</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state title="No subscriptions match these filters" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscriptions->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $subscriptions->links() }}</div>
        @endif
    </div>
</x-admin-layout>
