<x-app-layout>
    <x-slot name="header">New segment</x-slot>

    <x-page-header title="New segment"
                   subtitle="A segment is a saved filter. It has no fixed membership — every contact matching the conditions is in it, and the moment a contact stops matching it drops out."
                   :back="route('segments.index')" />

    <form method="POST" action="{{ route('segments.store') }}" class="space-y-6">
        @csrf

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Segment details</h3>
            </div>

            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Segment name" />
                    <x-text-input id="name" name="name" :value="old('name', $segment->name)"
                                  placeholder="e.g. Active buyers in Germany" required autofocus maxlength="191" />
                    <p class="kn-help">Must be unique inside your account.</p>
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="description" value="Description" />
                    <x-text-input id="description" name="description" :value="old('description', $segment->description)"
                                  placeholder="What this segment is for" maxlength="255" />
                    <p class="kn-help">Optional. Shown to your team on the segments screen, never to contacts.</p>
                    <x-input-error :messages="$errors->get('description')" />
                </div>
            </div>
        </div>

        @include('segments.partials.builder')

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('segments.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">Create segment</button>
        </div>
    </form>
</x-app-layout>
