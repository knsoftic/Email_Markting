<x-app-layout>
    <x-slot name="header">Connect a mailbox</x-slot>

    <x-page-header title="Connect a mailbox"
                   subtitle="Read an existing mailbox over IMAP. Nothing is fetched until the connection has been tested successfully."
                   :back="route('mailboxes.index')" />

    <div class="mb-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
        Pick your provider below and the host, port and encryption fill themselves in — you can still change
        any of them afterwards. The password is encrypted before it is stored and is never shown again on any
        screen: you can replace it later, but you cannot read it back.
    </div>

    <div class="mb-5 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
        Most mailboxes that refuse to connect do so for one of two reasons: the big providers stopped accepting
        ordinary account passwords over IMAP and want an app password instead, or IMAP is switched off on the
        account altogether. The notes under the provider you choose say which applies.
    </div>

    @include('mailboxes.partials.form')
</x-app-layout>
