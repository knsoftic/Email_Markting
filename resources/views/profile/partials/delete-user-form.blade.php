<section class="kn-card border-red-200">
    <div class="kn-card-header border-red-100">
        <div>
            <h3 class="text-sm font-semibold text-red-700">Delete account</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                Your login is removed. Campaign and activity history stays attached to the account
                for audit purposes.
            </p>
        </div>
    </div>

    {{-- Reopens itself when the password check failed, so the error is visible. --}}
    <div class="p-5" x-data="{ open: {{ $errors->userDeletion->isNotEmpty() ? 'true' : 'false' }} }">
        <button type="button" class="kn-btn-danger" @click="open = true">Delete my account</button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink-900/50" @click="open = false"></div>

            <form method="POST" action="{{ route('profile.destroy') }}"
                  class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-panel">
                @csrf
                @method('DELETE')

                <h4 class="text-base font-semibold text-ink-900">Delete your account?</h4>
                <p class="mt-2 text-sm text-ink-600">
                    This cannot be undone from the app. Enter your password to confirm.
                </p>

                <div class="mt-4">
                    <x-input-label for="delete_password" value="Password" />
                    <x-text-input id="delete_password" name="password" type="password" placeholder="••••••••" />
                    <x-input-error :messages="$errors->userDeletion->get('password')" />
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" class="kn-btn-secondary" @click="open = false">Cancel</button>
                    <x-danger-button>Delete account</x-danger-button>
                </div>
            </form>
        </div>
    </div>
</section>
