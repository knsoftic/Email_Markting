<x-app-layout>
    <x-slot name="header">Add SMTP account</x-slot>

    <x-page-header title="Add SMTP account"
                   subtitle="Your own outgoing mail credentials. Nothing is sent through it until a test connects successfully."
                   :back="route('smtp.index')" />

    <div class="mb-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
        Pick your provider below and the host, port and encryption fill themselves in. The password is
        encrypted before it is stored and is never shown again on any screen — you can replace it later,
        but you cannot read it back.
    </div>

    @include('smtp.partials.form')
</x-app-layout>
