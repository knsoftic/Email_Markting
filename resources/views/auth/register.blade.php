<x-guest-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Create your account</h1>
        <p class="mt-1.5 text-sm text-ink-500">
            Start on the {{ $defaultPlan?->name ?? 'free' }} plan. No card required.
        </p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="company_name" :value="__('Company / account name')" />
            <x-text-input id="company_name" type="text" name="company_name" :value="old('company_name')"
                          required autofocus placeholder="Acme Ltd" />
            <p class="kn-help">This becomes your workspace name. You can change it later in settings.</p>
            <x-input-error :messages="$errors->get('company_name')" />
        </div>

        <div>
            <x-input-label for="name" :value="__('Your name')" />
            <x-text-input id="name" type="text" name="name" :value="old('name')"
                          required autocomplete="name" placeholder="Jane Cooper" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Work email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')"
                          required autocomplete="username" placeholder="you@company.com" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" type="password" name="password"
                              required autocomplete="new-password" placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm password')" />
                <x-text-input id="password_confirmation" type="password" name="password_confirmation"
                              required autocomplete="new-password" placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password_confirmation')" />
            </div>
        </div>

        <div>
            <x-input-label for="timezone" :value="__('Timezone')" />
            <select id="timezone" name="timezone" class="kn-select">
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}" @selected(old('timezone', 'UTC') === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
            <p class="kn-help">Used for campaign scheduling and reports.</p>
            <x-input-error :messages="$errors->get('timezone')" />
        </div>

        <label class="flex items-start gap-2.5 text-sm text-ink-600">
            <input type="checkbox" name="terms" value="1" class="kn-checkbox mt-0.5" @checked(old('terms')) required>
            <span>
                I confirm I will only email contacts who gave permission, and I accept the
                {{ $brand['company_name'] }} acceptable use policy.
            </span>
        </label>
        <x-input-error :messages="$errors->get('terms')" />

        <x-primary-button class="w-full">{{ __('Create account') }}</x-primary-button>
    </form>

    <p class="mt-6 text-center text-sm text-ink-500">
        Already registered?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">Sign in</a>
    </p>
</x-guest-layout>
