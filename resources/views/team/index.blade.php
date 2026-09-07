<x-app-layout>
    <x-slot name="header">Team</x-slot>

    @php
        $seatsLabel = $seatLimit === null
            ? number_format($seatsUsed).' members · unlimited seats'
            : number_format($seatsUsed).' of '.number_format($seatLimit).' seats used';
        $seatsFull = $seatLimit !== null && $seatsUsed >= $seatLimit;
    @endphp

    <x-page-header title="Team" :subtitle="$seatsLabel">
        <x-slot name="actions">
            <a href="{{ route('team.roles.create') }}" class="kn-btn-secondary">New role</a>
            @if ($seatsFull)
                <span class="kn-badge-amber">Seat limit reached</span>
            @else
                <a href="{{ route('team.create') }}" class="kn-btn-primary">Add member</a>
            @endif
        </x-slot>
    </x-page-header>

    <div class="kn-card mb-6 overflow-hidden">
        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Members</h3></div>
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Last login</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($members as $member)
                        @php $isOwner = $member->id === auth()->user()->account?->owner_id; @endphp
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <img src="{{ $member->avatarUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                    <div class="min-w-0">
                                        <span class="block truncate font-medium text-ink-900">{{ $member->name }}</span>
                                        <span class="block truncate text-xs text-ink-500">{{ $member->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                {{ $member->role?->name ?? '—' }}
                                @if ($isOwner)
                                    <span class="kn-badge-blue ml-1">Owner</span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ $member->status === 'active' ? 'kn-badge-green' : 'kn-badge-red' }}">
                                    {{ ucfirst($member->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-500">
                                {{ $member->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('team.edit', $member) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                    @unless ($isOwner || $member->id === auth()->id())
                                        <x-confirm-form :action="route('team.status', $member)"
                                                        method="PATCH"
                                                        :label="$member->isSuspended() ? 'Activate' : 'Suspend'"
                                                        button-class="kn-btn-ghost kn-btn-sm"
                                                        :message="$member->isSuspended() ? 'Reactivate this member?' : 'Suspend this member?'" />

                                        <x-confirm-form :action="route('team.destroy', $member)"
                                                        label="Remove"
                                                        message="Remove this member from your team?" />
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($members->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $members->links() }}</div>
        @endif
    </div>

    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Roles</h3>
            <span class="text-xs text-ink-500">System roles are shared; your own roles are editable</span>
        </div>
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead><tr><th>Role</th><th>Type</th><th>Description</th><th>Members</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($roles as $role)
                        <tr>
                            <td class="font-medium text-ink-900">{{ $role->name }}</td>
                            <td>
                                <span class="{{ $role->account_id ? 'kn-badge-blue' : 'kn-badge-gray' }}">
                                    {{ $role->account_id ? 'Your role' : 'System' }}
                                </span>
                            </td>
                            <td class="text-ink-600">{{ $role->description ?: '—' }}</td>
                            <td>{{ number_format($role->users_count) }}</td>
                            <td class="text-right">
                                @if ($role->account_id)
                                    <div class="flex justify-end gap-1.5">
                                        <a href="{{ route('team.roles.edit', $role) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>
                                        <x-confirm-form :action="route('team.roles.destroy', $role)"
                                                        label="Delete"
                                                        message="Delete this role? Members must be moved off it first." />
                                    </div>
                                @else
                                    <span class="text-xs text-ink-400">Managed by KN Softic</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
