<x-admin-layout>
    <x-slot name="header">{{ $user->exists ? 'Edit user' : 'New user' }}</x-slot>

    <x-page-header :title="$user->exists ? 'Edit '.$user->name : 'New user'"
                   :subtitle="$user->exists ? $user->email : 'Creates the login and, for account users, a full account with a plan.'"
                   :back="route('admin.users.index')" />

    <form method="POST"
          action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}"
          class="space-y-6"
          x-data="{ superAdmin: {{ old('is_super_admin', $user->is_super_admin) ? 'true' : 'false' }} }">
        @csrf
        @if ($user->exists) @method('PUT') @endif

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Login details</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Full name" />
                    <x-text-input id="name" name="name" :value="old('name', $user->name)" required />
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="email" value="Email address" />
                    <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" required />
                    <x-input-error :messages="$errors->get('email')" />
                </div>

                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input id="phone" name="phone" :value="old('phone', $user->phone)" />
                </div>

                <div>
                    <x-input-label for="designation" value="Designation" />
                    <x-text-input id="designation" name="designation" :value="old('designation', $user->designation)" />
                </div>

                <div>
                    <x-input-label for="password" :value="$user->exists ? 'New password (leave blank to keep)' : 'Password'" />
                    <x-text-input id="password" name="password" type="password" autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" value="Confirm password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                </div>

                <div>
                    <x-input-label for="timezone" value="Timezone" />
                    <select id="timezone" name="timezone" class="kn-select">
                        @foreach (\DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $user->timezone ?? 'UTC') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="kn-select">
                        @foreach (['active', 'suspended', 'pending'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $user->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm text-ink-700">
                    <input type="hidden" name="email_verified" value="0">
                    <input type="checkbox" name="email_verified" value="1" class="kn-checkbox"
                           @checked(old('email_verified', $user->email_verified_at !== null))>
                    Mark email address as verified
                </label>
            </div>
        </div>

        @unless ($user->exists)
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Account type</h3></div>
                <div class="space-y-5 p-5">
                    <label class="flex items-start gap-3 rounded-lg border border-ink-200 p-3">
                        <input type="hidden" name="is_super_admin" value="0">
                        <input type="checkbox" name="is_super_admin" value="1" class="kn-checkbox mt-0.5"
                               x-model="superAdmin" @checked(old('is_super_admin'))>
                        <span>
                            <span class="block text-sm font-medium text-ink-800">Platform super admin</span>
                            <span class="block text-xs text-ink-500">
                                Full access to this panel and every account. No tenant account is created.
                            </span>
                        </span>
                    </label>

                    <div x-show="!superAdmin" x-cloak class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="company_name" value="Account / company name" />
                            <x-text-input id="company_name" name="company_name" :value="old('company_name')" />
                            <p class="kn-help">A new account is created and this user becomes its owner.</p>
                            <x-input-error :messages="$errors->get('company_name')" />
                        </div>

                        <div>
                            <x-input-label for="plan_id" value="Plan" />
                            <select id="plan_id" name="plan_id" class="kn-select">
                                <option value="">Use the default plan</option>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}" @selected((int) old('plan_id') === $plan->id)>{{ $plan->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="kn-card">
                <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Role</h3></div>
                <div class="p-5">
                    <x-input-label for="role_id" value="Assigned role" />
                    <select id="role_id" name="role_id" class="kn-select sm:max-w-sm">
                        @foreach ($roles as $role)
                            {{-- Loose comparison on purpose: old() hands back a
                                 string from the session while $role->id is an
                                 int, so === matched nothing after a validation
                                 error. Every option came back unselected, the
                                 browser showed the first one, and resubmitting
                                 quietly reassigned the user's role. --}}
                            <option value="{{ $role->id }}" @selected((int) old('role_id', $user->role_id) === (int) $role->id)>
                                {{ $role->name }}{{ $role->account_id ? ' (account role)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="kn-help">Super Admin and Account Owner always hold every permission.</p>
                    <x-input-error :messages="$errors->get('role_id')" />
                </div>
            </div>
        @endunless

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">{{ $user->exists ? 'Save user' : 'Create user' }}</button>
        </div>
    </form>
</x-admin-layout>
