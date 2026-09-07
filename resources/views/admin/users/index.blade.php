<x-admin-layout>
    <x-slot name="header">Users</x-slot>

    <x-page-header title="Users" subtitle="Every login on the platform, across all accounts.">
        <x-slot name="actions">
            <a href="{{ route('admin.users.create') }}" class="kn-btn-primary">New user</a>
        </x-slot>
    </x-page-header>

    <form method="GET" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search name, email or account" />
            </div>
            <select name="status" class="kn-select">
                <option value="">Any status</option>
                @foreach (['active', 'suspended', 'pending'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <select name="type" class="kn-select">
                <option value="">All users</option>
                <option value="account" @selected(($filters['type'] ?? '') === 'account')>Account users</option>
                <option value="super" @selected(($filters['type'] ?? '') === 'super')>Super admins</option>
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
                        <th>User</th>
                        <th>Account</th>
                        <th>Plan</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <img src="{{ $user->avatarUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.users.show', $user) }}" class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                            {{ $user->name }}
                                        </a>
                                        <span class="block truncate text-xs text-ink-500">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($user->is_super_admin)
                                    <span class="kn-badge-amber">Platform</span>
                                @elseif ($user->account)
                                    <a href="{{ route('admin.accounts.show', $user->account) }}" class="text-brand-600 hover:text-brand-700">
                                        {{ $user->account->name }}
                                    </a>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td>{{ $user->account?->subscription?->plan?->name ?? '—' }}</td>
                            <td>{{ $user->role?->name ?? '—' }}</td>
                            <td>
                                <span class="{{ $user->status === 'active' ? 'kn-badge-green' : ($user->status === 'suspended' ? 'kn-badge-red' : 'kn-badge-amber') }}">
                                    {{ ucfirst($user->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-500">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('admin.users.edit', $user) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                    @unless ($user->is_super_admin)
                                        <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="kn-btn-ghost kn-btn-sm">Log in as</button>
                                        </form>
                                    @endunless

                                    <x-confirm-form :action="route('admin.users.status', $user)"
                                                    method="PATCH"
                                                    :label="$user->isSuspended() ? 'Activate' : 'Suspend'"
                                                    :button-class="$user->isSuspended() ? 'kn-btn-secondary kn-btn-sm' : 'kn-btn-ghost kn-btn-sm text-amber-700'"
                                                    :message="$user->isSuspended() ? 'Reactivate this user?' : 'Suspend this user? They will be logged out.'" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"><x-empty-state title="No users match these filters" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</x-admin-layout>
