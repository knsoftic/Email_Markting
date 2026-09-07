<x-app-layout>
    <x-slot name="header">Edit {{ $segment->name }}</x-slot>

    @php $lastCalculated = $segment->last_calculated_at?->diffForHumans(); @endphp

    <x-page-header :title="'Edit '.$segment->name"
                   subtitle="Changing the conditions changes who is in this segment straight away — saving recalculates the count."
                   :back="route('segments.show', $segment)">
        <x-slot name="actions">
            <a href="{{ route('segments.show', $segment) }}" class="kn-btn-secondary">View matches</a>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('segments.update', $segment) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Segment details</h3>
                <span class="text-xs text-ink-500">
                    {{ number_format((int) $segment->cached_count) }} contacts matched{{ $lastCalculated ? ' · '.$lastCalculated : '' }}
                </span>
            </div>

            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Segment name" />
                    <x-text-input id="name" name="name" :value="old('name', $segment->name)"
                                  placeholder="e.g. Active buyers in Germany" required maxlength="191" />
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
            <a href="{{ route('segments.show', $segment) }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">Save segment</button>
        </div>
    </form>

    @permission('contacts.delete')
        <div class="kn-card mt-6 border-red-200">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-red-700">Delete this segment</h3>
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-ink-600">
                    Removes the saved filter only. Every contact it matched stays in your account and keeps
                    its lists, tags and history.
                </p>
                <div class="shrink-0">
                    <x-confirm-form :action="route('segments.destroy', $segment)"
                                    label="Delete segment"
                                    button-class="kn-btn-danger"
                                    :message="'Delete the segment '.$segment->name.'? No contacts are deleted — only the saved filter is removed.'" />
                </div>
            </div>
        </div>
    @endpermission
</x-app-layout>
