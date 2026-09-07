<x-app-layout>
    <x-slot name="header">Edit {{ $list->name }}</x-slot>

    <x-page-header :title="'Edit '.$list->name"
                   subtitle="Renaming a list never changes who is on it."
                   :back="route('lists.show', $list)">
        <x-slot name="actions">
            @permission('contacts.view')
                <a href="{{ route('subscribers.index', ['list_id' => $list->id]) }}" class="kn-btn-secondary">
                    Manage in contacts
                </a>
            @endpermission
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('lists.update', $list) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">List details</h3>
                <span class="text-xs text-ink-500">{{ number_format((int) $list->total_count) }} contacts on this list</span>
            </div>

            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="name" value="List name" />
                    <x-text-input id="name" name="name" :value="old('name', $list->name)"
                                  placeholder="e.g. Newsletter subscribers" required maxlength="191" />
                    <p class="kn-help">Must be unique inside your account.</p>
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="description" value="Description" />
                    <textarea id="description" name="description" rows="2" maxlength="255"
                              class="kn-textarea"
                              placeholder="What this list is for — who is on it and where they came from.">{{ old('description', $list->description) }}</textarea>
                    <p class="kn-help">Optional. Shown to your team on the lists screen, never to contacts.</p>
                    <x-input-error :messages="$errors->get('description')" />
                </div>

                <div>
                    <x-input-label for="from_name" value="Default from name" />
                    <x-text-input id="from_name" name="from_name" :value="old('from_name', $list->from_name)"
                                  placeholder="e.g. KN Softic Team" maxlength="191" />
                    <x-input-error :messages="$errors->get('from_name')" />
                </div>

                <div>
                    <x-input-label for="from_email" value="Default from email" />
                    <x-text-input id="from_email" name="from_email" type="email"
                                  :value="old('from_email', $list->from_email)"
                                  placeholder="e.g. news@yourdomain.com" maxlength="191" />
                    <x-input-error :messages="$errors->get('from_email')" />
                </div>

                <div class="sm:col-span-2">
                    <p class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                        The from name and from email are defaults only. A campaign sent to this list inherits
                        them unless the campaign sets its own sender, and either field can be left blank.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('lists.show', $list) }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">Save list</button>
        </div>
    </form>

    @permission('contacts.delete')
        <div class="kn-card mt-6 border-red-200">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-red-700">Delete this list</h3>
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-ink-600">
                    Removes the list and its membership rows. Every contact on it stays in your account
                    and keeps its other lists, tags and history.
                </p>
                <div class="shrink-0">
                    <x-confirm-form :action="route('lists.destroy', $list)"
                                    label="Delete list"
                                    button-class="kn-btn-danger"
                                    :message="'Delete the list '.$list->name.'? The '.number_format((int) $list->total_count).' contacts on it stay in your account — only the list is removed.'" />
                </div>
            </div>
        </div>
    @endpermission
</x-app-layout>
