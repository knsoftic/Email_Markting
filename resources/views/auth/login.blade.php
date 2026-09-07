<x-guest-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Sign in</h1>
        <p class="mt-1.5 text-sm text-ink-500">Welcome back. Enter your details to continue.</p>
    </div>

    <x-auth-session-status class="mb-5" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email address')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')"
                          required autofocus autocomplete="username" placeholder="you@company.com" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" type="password" name="password"
                          required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-ink-600">
                <input id="remember_me" type="checkbox" name="remember" class="kn-checkbox">
                {{ __('Remember me') }}
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-primary-button class="w-full">{{ __('Sign in') }}</x-primary-button>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 text-center text-sm text-ink-500">
            Don't have an account?
            <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:text-brand-700">Create one</a>
        </p>
    @endif
</x-guest-layout>
