<x-app-layout>
    <x-slot name="header">New list</x-slot>

    <x-page-header title="New list"
                   subtitle="A list groups contacts so a campaign can target them. Contacts can belong to several lists at once."
                   :back="route('lists.index')" />

    <form method="POST" action="{{ route('lists.store') }}" class="space-y-6">
        @csrf

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">List details</h3>
            </div>

            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="name" value="List name" />
                    <x-text-input id="name" name="name" :value="old('name', $list->name)"
                                  placeholder="e.g. Newsletter subscribers" required autofocus maxlength="191" />
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
            <a href="{{ route('lists.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">Create list</button>
        </div>
    </form>
</x-app-layout>
