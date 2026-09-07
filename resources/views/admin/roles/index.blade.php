<x-admin-layout>
    <x-slot name="header">Roles &amp; permissions</x-slot>

    <x-page-header title="Roles &amp; permissions"
                   subtitle="{{ $permissionCount }} permissions across the platform. Super Admin and Account Owner always hold all of them.">
        <x-slot name="actions">
            <a href="{{ route('admin.roles.create') }}" class="kn-btn-primary">New system role</a>
        </x-slot>
    </x-page-header>

    <div class="kn-card mb-6 overflow-hidden">
        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">System roles</h3></div>
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead><tr><th>Role</th><th>Description</th><th>Permissions</th><th>Users</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($roles as $role)
                        @php $locked = in_array($role->slug, ['super-admin', 'account-owner'], true); @endphp
                        <tr>
                            <td>
                                <div class="font-medium text-ink-900">{{ $role->name }}</div>
                                <div class="text-xs text-ink-500">{{ $role->slug }}</div>
                            </td>
                            <td class="text-ink-600">{{ $role->description ?: '—' }}</td>
                            <td>
                                @if ($locked)
                                    <span class="kn-badge-blue">All permissions</span>
                                @else
                                    {{ $role->permissions_count }} of {{ $permissionCount }}
                                @endif
                            </td>
                            <td>{{ number_format($role->users_count) }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('admin.roles.edit', $role) }}" class="kn-btn-secondary kn-btn-sm">
                                        {{ $locked ? 'View' : 'Edit' }}
                                    </a>
                                    @unless (in_array($role->slug, ['super-admin', 'account-owner', 'staff'], true))
                                        <x-confirm-form :action="route('admin.roles.destroy', $role)"
                                                        label="Delete"
                                                        message="Delete this role? Users must be moved off it first." />
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Account-defined roles</h3>
            <span class="text-xs text-ink-500">Created by account owners for their staff</span>
        </div>

        @if ($accountRoles->isEmpty())
            <x-empty-state title="No account roles yet"
                           message="Account owners can create their own staff roles from their team settings." />
        @else
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead><tr><th>Role</th><th>Account</th><th>Users</th></tr></thead>
                    <tbody>
                        @foreach ($accountRoles as $role)
                            <tr>
                                <td class="font-medium text-ink-900">{{ $role->name }}</td>
                                <td>
                                    @if ($role->account)
                                        <a href="{{ route('admin.accounts.show', $role->account) }}" class="text-brand-600 hover:text-brand-700">
                                            {{ $role->account->name }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ number_format($role->users_count) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin-layout>
