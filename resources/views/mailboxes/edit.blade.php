<x-app-layout>
    <x-slot name="header">Edit {{ $mailbox->name }}</x-slot>

    @php $providerLabel = \App\Support\ImapProviders::get((string) $mailbox->provider)['label']; @endphp
    @php $statusMap = ['connected' => 'kn-badge-green', 'pending' => 'kn-badge-amber', 'error' => 'kn-badge-red', 'disconnected' => 'kn-badge-gray']; @endphp
    {{-- The sync job gives up after ten consecutive failures and clears
         sync_enabled. That pair of values is the only trace of it, and without
         saying so the mailbox simply looks as though it stopped. --}}
    @php $autoDisabled = ! $mailbox->sync_enabled && (int) $mailbox->consecutive_failures >= 10; @endphp

    <x-page-header :title="'Edit '.$mailbox->name"
                   :subtitle="$providerLabel.' · '.$mailbox->imap_host.':'.$mailbox->imap_port.' · '.$mailbox->email"
                   :back="route('mailboxes.show', $mailbox)">
        <x-slot name="actions">
            <x-status-badge :status="$mailbox->status" :map="$statusMap" />

            <a href="{{ route('mailboxes.show', $mailbox) }}" class="kn-btn-secondary">View mailbox</a>

            {{-- Not "the credentials are removed": Mailbox uses SoftDeletes and
                 destroy() calls delete(), so the row and its encrypted password
                 stay in the database. What actually happens is that KN Softic
                 stops connecting and the mailbox leaves the lists. --}}
            @permission('mailboxes.manage')
                <x-confirm-form :action="route('mailboxes.destroy', $mailbox)"
                                method="DELETE"
                                label="Disconnect"
                                button-class="kn-btn-danger"
                                :message="'Disconnect '.$mailbox->email.'? KN Softic stops connecting to it and it leaves your mailbox list. The messages already synced stay in your inbox, and nothing at all is changed on the mail server.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------ sync switched off by us --}}
    @if ($autoDisabled)
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-4">
            <p class="text-sm font-semibold text-red-800">
                Automatic syncing was switched off for this mailbox after
                {{ number_format($mailbox->consecutive_failures) }} consecutive failures.
            </p>
            <p class="mt-1 text-sm text-red-700">
                Nothing has arrived since. Polling a mailbox that has failed ten times running only spends your
                provider's rate limit to relearn the same fact, so it was stopped rather than left rattling.
                The credentials and every message already downloaded are untouched.
            </p>
            {{-- Resume and the checkbox are not interchangeable: resume() clears the
                 streak, update() does not. Without this the operator takes the control
                 that is right in front of them and it fails its way back off at once. --}}
            <p class="mt-2 text-sm text-red-700">
                Use <span class="font-semibold">Resume</span> rather than the
                <span class="font-semibold">Fetch new mail automatically</span> tick box further down.
                Both switch syncing on, but saving the form leaves the failure count at
                {{ number_format($mailbox->consecutive_failures) }} — so the very next failure would
                switch it straight back off. Resume clears the count as well.
            </p>

            @if (filled($mailbox->last_error))
                <p class="mt-3 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-100/70 p-3 font-mono text-xs text-red-900">{{ $mailbox->last_error }}</p>
            @endif

            @permission('mailboxes.manage')
                <div class="mt-3">
                    <x-confirm-form :action="route('mailboxes.resume', $mailbox)"
                                    method="POST"
                                    label="Resume automatic syncing"
                                    button-class="kn-btn-primary kn-btn-sm"
                                    message="Switch automatic syncing back on and clear the failure streak? Fix the cause first — a password or a disabled IMAP setting — or it will fail its way back off." />
                </div>
            @endpermission
        </div>
    @elseif (! $mailbox->sync_enabled)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Automatic syncing is switched off for this mailbox, so nothing arrives on its own. Tick
            <span class="font-semibold">Fetch new mail automatically</span> below to turn it back on.
        </div>
    @endif

    {{-- ------------------------------------------------- connection state --}}
    @if ($mailbox->status === 'connected' && $mailbox->last_tested_at)
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            These settings last connected successfully
            <span title="{{ $mailbox->last_tested_at->format('D, d M Y H:i') }}">{{ $mailbox->last_tested_at->diffForHumans() }}</span>.
            Changing the host, port, encryption, username or password clears that result, and the mailbox goes
            back to untested until you run the test again.
        </div>
    @elseif ($mailbox->status === 'error')
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-800">The last attempt to reach this mailbox failed.</p>
            @if (filled($mailbox->last_error))
                <p class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-100/70 p-3 font-mono text-xs text-red-900">{{ $mailbox->last_error }}</p>
            @endif
            @if ($mailbox->last_error_at)
                <p class="mt-2 text-xs text-red-600" title="{{ $mailbox->last_error_at->format('D, d M Y H:i') }}">
                    {{ $mailbox->last_error_at->diffForHumans() }}
                </p>
            @endif
        </div>
    @elseif ($mailbox->status === 'disconnected')
        <div class="mb-5 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
            This mailbox is marked disconnected. Correct the settings below, save, and run the connection test
            to bring it back.
        </div>
    @else
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            These connection settings have not been tested yet, so nothing here says they work. Save any changes
            and run the connection test — a mailbox that has never passed a test is not a working mailbox.
        </div>
    @endif

    @include('mailboxes.partials.form')
</x-app-layout>
