<section class="kn-card">
    <div class="kn-card-header">
        <div>
            <h3 class="text-sm font-semibold text-ink-900">Profile information</h3>
            <p class="mt-0.5 text-xs text-ink-500">Your name, contact details and timezone.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('PATCH')

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="name" value="Full name" />
                <x-text-input id="name" name="name" :value="old('name', $user->name)" required autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" value="Email address" />
                <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" />

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <p class="mt-2 text-xs text-amber-700">
                        Your email address is unverified.
                        <button form="send-verification" class="font-medium underline hover:text-amber-800">
                            Resend the verification link
                        </button>
                    </p>
                @endif
            </div>

            <div>
                <x-input-label for="phone" value="Phone" />
                <x-text-input id="phone" name="phone" :value="old('phone', $user->phone)" />
                <x-input-error :messages="$errors->get('phone')" />
            </div>

            <div>
                <x-input-label for="designation" value="Job title" />
                <x-text-input id="designation" name="designation" :value="old('designation', $user->designation)" />
            </div>

            <div class="sm:col-span-2">
                <x-input-label for="timezone" value="Timezone" />
                <select id="timezone" name="timezone" class="kn-select sm:max-w-sm">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $user->timezone) === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
                <p class="kn-help">Dates and campaign schedules are shown in this timezone.</p>
                <x-input-error :messages="$errors->get('timezone')" />
            </div>
        </div>

        <div class="flex justify-end border-t border-ink-100 px-5 py-3">
            <x-primary-button>Save changes</x-primary-button>
        </div>
    </form>
</section>

<form id="send-verification" method="POST" action="{{ route('verification.send') }}" class="hidden">
    @csrf
</form>
