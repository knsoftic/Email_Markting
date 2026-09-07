<x-app-layout>
    <x-slot name="header">Edit {{ $account->name }}</x-slot>

    <x-page-header :title="'Edit '.$account->name"
                   :subtitle="\App\Support\SmtpProviders::label($account->provider).' · '.$account->host.':'.$account->port"
                   :back="route('smtp.show', $account)">
        <x-slot name="actions">
            <a href="{{ route('smtp.show', $account) }}" class="kn-btn-secondary">View account</a>
        </x-slot>
    </x-page-header>

    <div class="mb-5 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
        Changing the host, port, username or encryption clears the stored test result, so the account is
        marked untested until you run the connection test again.
    </div>

    @include('smtp.partials.form')
</x-app-layout>
