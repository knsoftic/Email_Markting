<section class="kn-card">
    <div class="kn-card-header">
        <div>
            <h3 class="text-sm font-semibold text-ink-900">Password</h3>
            <p class="mt-0.5 text-xs text-ink-500">Use a long, unique password you do not reuse elsewhere.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('profile.password') }}">
        @csrf
        @method('PUT')

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <div>
                <x-input-label for="current_password" value="Current password" />
                <x-text-input id="current_password" name="current_password" type="password" autocomplete="current-password" />
                <x-input-error :messages="$errors->updatePassword->get('current_password')" />
            </div>

            <div>
                <x-input-label for="update_password" value="New password" />
                <x-text-input id="update_password" name="password" type="password" autocomplete="new-password" />
                <x-input-error :messages="$errors->updatePassword->get('password')" />
            </div>

            <div>
                <x-input-label for="update_password_confirmation" value="Confirm password" />
                <x-text-input id="update_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" />
            </div>
        </div>

        <div class="flex justify-end border-t border-ink-100 px-5 py-3">
            <x-primary-button>Change password</x-primary-button>
        </div>
    </form>
</section>
