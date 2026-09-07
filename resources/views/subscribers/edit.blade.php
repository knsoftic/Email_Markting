<x-app-layout>
    <x-slot name="header">Edit contact</x-slot>

    <x-page-header :title="'Edit '.$subscriber->displayName()"
                   :subtitle="$subscriber->email"
                   :back="route('subscribers.show', $subscriber)">
        <x-slot name="actions">
            <a href="{{ route('subscribers.show', $subscriber) }}" class="kn-btn-secondary">View contact</a>

            @permission('contacts.delete')
                <x-confirm-form :action="route('subscribers.destroy', $subscriber)"
                                label="Delete"
                                button-class="kn-btn-danger"
                                :message="'Delete '.$subscriber->email.'? This cannot be undone.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('subscribers.update', $subscriber) }}">
        @csrf
        @method('PUT')

        @include('subscribers.partials.form')
    </form>
</x-app-layout>
