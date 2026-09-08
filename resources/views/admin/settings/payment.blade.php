<x-admin-layout>
    <x-slot name="header">Payment settings</x-slot>

    <x-page-header title="Payment settings"
                   subtitle="Your own record of how customers pay you. Nothing here is charged or shown automatically." />

    {{-- Said plainly, because the previous wording promised the opposite.
         There is no payment gateway in this product and no billing screen for
         customers: these four values are stored and read back only here. --}}
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">This screen does not take payments.</p>
        <p class="mt-1">
            KN Softic has no payment gateway and does not show these details to customers anywhere.
            They are kept here for your own reference, so the terms you quote stay in one place.
        </p>
        <p class="mt-2">
            Money is collected outside the product. What actually changes a customer's access is
            assigning or extending their plan on
            <a href="{{ route('admin.accounts.index') }}" class="font-medium underline">Accounts</a>.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.settings.payment.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Currency</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="currency" value="Currency code" />
                    <x-text-input id="currency" name="currency" maxlength="3"
                                  :value="old('currency', $values['currency'])" required />
                    <p class="kn-help">Three-letter ISO code, e.g. USD, PKR, EUR.</p>
                    <x-input-error :messages="$errors->get('currency')" />
                </div>
                <div>
                    <x-input-label for="currency_symbol" value="Symbol" />
                    <x-text-input id="currency_symbol" name="currency_symbol" maxlength="5"
                                  :value="old('currency_symbol', $values['currency_symbol'])" required />
                    <x-input-error :messages="$errors->get('currency_symbol')" />
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Manual payment details</h3>
                <span class="text-xs text-ink-500">For your reference only</span>
            </div>
            <div class="space-y-5 p-5">
                <div>
                    <x-input-label for="bank_details" value="Bank / transfer details" />
                    <textarea id="bank_details" name="bank_details" rows="5" class="kn-textarea">{{ old('bank_details', $values['bank_details']) }}</textarea>
                    <p class="kn-help">Account title, number, IBAN, branch. For your reference — send these to a customer yourself.</p>
                    <x-input-error :messages="$errors->get('bank_details')" />
                </div>

                <div>
                    <x-input-label for="instructions" value="Payment instructions" />
                    <textarea id="instructions" name="instructions" rows="4" class="kn-textarea">{{ old('instructions', $values['instructions']) }}</textarea>
                    <p class="kn-help">Your standard wording for proof of payment and activation time. Not shown to customers automatically.</p>
                    <x-input-error :messages="$errors->get('instructions')" />
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-600">
            Plans are assigned and extended by you from the account screen. No payment gateway is
            wired in, so nothing here ever charges a card.
        </div>

        <div class="flex justify-end">
            <button type="submit" class="kn-btn-primary">Save payment settings</button>
        </div>
    </form>
</x-admin-layout>
