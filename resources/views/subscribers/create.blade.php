<x-app-layout>
    <x-slot name="header">Add contact</x-slot>

    <x-page-header title="Add contact"
                   subtitle="Add one person by hand. To bring in many at once, import a CSV instead."
                   :back="route('subscribers.index')">
        <x-slot name="actions">
            @permission('contacts.import')
                <a href="{{ route('imports.create') }}" class="kn-btn-secondary">Import a CSV</a>
            @endpermission
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('subscribers.store') }}">
        @csrf

        @include('subscribers.partials.form')
    </form>
</x-app-layout>
