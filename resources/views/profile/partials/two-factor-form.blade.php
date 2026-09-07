<section class="kn-card">
    <div class="kn-card-header">
        <div>
            <h3 class="text-sm font-semibold text-ink-900">Two-factor authentication</h3>
            <p class="mt-0.5 text-xs text-ink-500">Extra verification on top of your password.</p>
        </div>
        <span class="{{ $user->hasTwoFactorEnabled() ? 'kn-badge-green' : 'kn-badge-gray' }}">
            {{ $user->hasTwoFactorEnabled() ? 'Enabled' : 'Not enabled' }}
        </span>
    </div>

    <div class="p-5 text-sm text-ink-600">
        {{--
            The storage side is done: encrypted secret, encrypted recovery codes
            and a confirmation timestamp on the user record, all hidden from
            serialisation. The enrolment flow (QR code + code verification) is
            scheduled with the rest of the security work, so no control is shown
            here that would not actually do anything yet.
        --}}
        <p>
            Your account is ready for two-factor authentication — the encrypted secret,
            recovery codes and confirmation timestamp are already part of your user record.
        </p>
        <p class="mt-2">
            Enrolment is delivered with the security hardening phase. Nothing is shown here
            until it can actually be switched on.
        </p>
    </div>
</section>
