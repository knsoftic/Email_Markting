<x-admin-layout>
    <x-slot name="header">{{ $user->name }}</x-slot>

    <x-page-header :title="$user->name" :subtitle="$user->email" :back="route('admin.users.index')">
        <x-slot name="actions">
            <a href="{{ route('admin.users.edit', $user) }}" class="kn-btn-secondary">Edit</a>

            @unless ($user->is_super_admin)
                <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" class="inline">
                    @csrf
                    <button type="submit" class="kn-btn-secondary">Log in as</button>
                </form>
            @endunless

            <x-confirm-form :action="route('admin.users.status', $user)"
                            method="PATCH"
                            :label="$user->isSuspended() ? 'Activate' : 'Suspend'"
                            button-class="kn-btn-secondary"
                            :message="$user->isSuspended() ? 'Reactivate this user?' : 'Suspend this user?'" />

            <x-confirm-form :action="route('admin.users.destroy', $user)"
                            label="Delete"
                            message="Delete this user? Their history is kept but they can no longer sign in." />
        </x-slot>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Details</h3></div>
                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    @foreach ([
                        'Status' => ucfirst($user->status),
                        'Type' => $user->is_super_admin ? 'Super admin' : ($user->isAccountOwner() ? 'Account owner' : 'Team member'),
                        'Role' => $user->role?->name ?? '—',
                        'Phone' => $user->phone ?: '—',
                        'Designation' => $user->designation ?: '—',
                        'Timezone' => $user->timezone,
                        'Email verified' => $user->email_verified_at?->format('d M Y H:i') ?? 'Not verified',
                        'Two-factor' => $user->hasTwoFactorEnabled() ? 'Enabled' : 'Not enabled',
                        'Last login' => $user->last_login_at?->format('d M Y H:i') ?? 'Never',
                        'Last login IP' => $user->last_login_ip ?: '—',
                        'Registered' => $user->created_at->format('d M Y H:i'),
                    ] as $label => $value)
                        <div>
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-ink-800">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="kn-card overflow-hidden">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Recent activity</h3></div>

                @forelse ($recentActivity as $log)
                    <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-2.5 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-ink-800">{{ $log->description }}</p>
                            <p class="truncate text-xs text-ink-500">{{ $log->event }} · {{ $log->ip }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-ink-400">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                @empty
                    <x-empty-state title="No activity recorded for this user yet" />
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            @if ($user->account)
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Account</h3>
                        <a href="{{ route('admin.accounts.show', $user->account) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Manage</a>
                    </div>
                    <div class="space-y-3 p-5 text-sm">
                        <div>
                            <p class="kn-stat-label">Account</p>
                            <p class="mt-0.5 font-medium text-ink-900">{{ $user->account->name }}</p>
                        </div>
                        <div>
                            <p class="kn-stat-label">Plan</p>
                            <p class="mt-0.5 text-ink-800">
                                {{ $user->account->subscription?->plan?->name ?? 'No plan' }}
                                @if ($user->account->subscription)
                                    <span class="kn-badge-gray ml-1">{{ ucfirst($user->account->subscription->status) }}</span>
                                @endif
                            </p>
                        </div>
                        @if ($user->account->subscription?->ends_at)
                            <div>
                                <p class="kn-stat-label">Renews / expires</p>
                                <p class="mt-0.5 text-ink-800">{{ $user->account->subscription->ends_at->format('d M Y') }}</p>
                            </div>
                        @endif
                        <div>
                            <p class="kn-stat-label">Account status</p>
                            <p class="mt-0.5">
                                <span class="{{ $user->account->isActive() ? 'kn-badge-green' : 'kn-badge-red' }}">
                                    {{ ucfirst($user->account->status) }}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                @if ($stats)
                    <div class="grid gap-4">
                        <x-stat-card label="Subscribers" :value="number_format($stats['subscribers'])" />
                        <x-stat-card label="Campaigns" :value="number_format($stats['campaigns'])" />
                        <x-stat-card label="Team members" :value="number_format($stats['team'])" />
                    </div>
                @endif
            @else
                <div class="kn-card p-5">
                    <p class="text-sm text-ink-600">
                        This is a platform super admin — no tenant account is attached.
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
