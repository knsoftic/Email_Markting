<x-app-layout>
    <x-slot name="header">{{ $member->exists ? 'Edit team member' : 'Add team member' }}</x-slot>

    @php $isOwner = $member->exists && $member->id === auth()->user()->account?->owner_id; @endphp

    <x-page-header :title="$member->exists ? 'Edit '.$member->name : 'Add team member'"
                   subtitle="Members sign in with their own credentials and see only what their role allows."
                   :back="route('team.index')" />

    <form method="POST"
          action="{{ $member->exists ? route('team.update', $member) : route('team.store') }}"
          class="space-y-6">
        @csrf
        @if ($member->exists) @method('PUT') @endif

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Details</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Full name" />
                    <x-text-input id="name" name="name" :value="old('name', $member->name)" required />
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="email" value="Email address" />
                    <x-text-input id="email" name="email" type="email" :value="old('email', $member->email)" required />
                    <x-input-error :messages="$errors->get('email')" />
                </div>

                <div>
                    <x-input-label for="designation" value="Job title" />
                    <x-text-input id="designation" name="designation" :value="old('designation', $member->designation)" />
                </div>

                <div>
                    <x-input-label for="timezone" value="Timezone" />
                    <select id="timezone" name="timezone" class="kn-select">
                        @foreach (\DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $member->timezone ?? 'UTC') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label for="password" :value="$member->exists ? 'New password (leave blank to keep)' : 'Password'" />
                    <x-text-input id="password" name="password" type="password" autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" value="Confirm password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="role_id" value="Role" />
                    <select id="role_id" name="role_id" class="kn-select sm:max-w-sm" @disabled($isOwner)>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id', $member->role_id) === $role->id)>
                                {{ $role->name }}{{ $role->account_id ? '' : ' (system)' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($isOwner)
                        <p class="kn-help">The account owner always holds every permission and cannot be demoted here.</p>
                        <input type="hidden" name="role_id" value="{{ $member->role_id }}">
                    @else
                        <p class="kn-help">
                            Need something different? <a href="{{ route('team.roles.create') }}" class="text-brand-600 hover:text-brand-700">Create a role</a>.
                        </p>
                    @endif
                    <x-input-error :messages="$errors->get('role_id')" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('team.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">{{ $member->exists ? 'Save member' : 'Add member' }}</button>
        </div>
    </form>
</x-app-layout>
