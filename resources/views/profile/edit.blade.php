<x-app-layout>
    <x-slot name="header">Profile</x-slot>

    <x-page-header title="Your profile" subtitle="Account details, sign-in security and profile image." />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('profile.partials.update-profile-information-form')
            @include('profile.partials.update-password-form')
            @include('profile.partials.two-factor-form')
            @include('profile.partials.delete-user-form')
        </div>

        <div class="space-y-6">
            @include('profile.partials.avatar-form')

            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Account</h3></div>
                <div class="space-y-3 p-5 text-sm">
                    <div>
                        <p class="kn-stat-label">Workspace</p>
                        <p class="mt-0.5 font-medium text-ink-900">{{ $user->account?->name ?? 'Platform' }}</p>
                    </div>
                    <div>
                        <p class="kn-stat-label">Role</p>
                        <p class="mt-0.5 text-ink-800">
                            {{ $user->isSuperAdmin() ? 'Super Admin' : ($user->isAccountOwner() ? 'Account Owner' : ($user->role?->name ?? 'Member')) }}
                        </p>
                    </div>
                    @if ($user->account?->subscription)
                        <div>
                            <p class="kn-stat-label">Plan</p>
                            <p class="mt-0.5 text-ink-800">
                                {{ $user->account->subscription->plan?->name }}
                                <span class="kn-badge-gray ml-1">{{ ucfirst($user->account->subscription->status) }}</span>
                            </p>
                        </div>
                    @endif
                    <div>
                        <p class="kn-stat-label">Email verified</p>
                        <p class="mt-0.5 text-ink-800">
                            {{ $user->email_verified_at?->format('d M Y') ?? 'Not verified' }}
                        </p>
                    </div>
                    <div>
                        <p class="kn-stat-label">Last login</p>
                        <p class="mt-0.5 text-ink-800">{{ $user->last_login_at?->format('d M Y H:i') ?? 'This is your first session' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
