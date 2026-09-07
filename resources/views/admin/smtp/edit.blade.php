<x-admin-layout>
    <x-slot name="header">Edit {{ $account->name }}</x-slot>

    <x-page-header :title="'Edit '.$account->name"
                   subtitle="Changing the host, port, username or password clears the tested flag — run the connection test again afterwards."
                   :back="route('admin.smtp.show', $account)">
        <x-slot name="actions">
            <a href="{{ route('admin.smtp.show', $account) }}" class="kn-btn-secondary">Overview</a>
        </x-slot>
    </x-page-header>

    @include('admin.smtp.partials.form')
</x-admin-layout>
