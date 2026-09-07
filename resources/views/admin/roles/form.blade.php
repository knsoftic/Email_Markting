<x-admin-layout>
    <x-slot name="header">{{ $role->exists ? 'Edit role' : 'New role' }}</x-slot>

    @php $locked = $locked ?? false; @endphp

    <x-page-header :title="$role->exists ? $role->name : 'New system role'"
                   subtitle="System roles are available to every account."
                   :back="route('admin.roles.index')" />

    @if ($locked)
        <div class="mb-5 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
            {{ $role->name }} always holds every permission by design, so this matrix is read-only.
        </div>
    @endif

    <form method="POST"
          action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}"
          class="space-y-6">
        @csrf
        @if ($role->exists) @method('PUT') @endif

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Role details</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-3">
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" :value="old('name', $role->name)" required :disabled="$locked" />
                    <x-input-error :messages="$errors->get('name')" />
                </div>
                <div>
                    <x-input-label for="slug" value="Slug" />
                    <x-text-input id="slug" name="slug" :value="old('slug', $role->slug)" placeholder="auto from name" :disabled="$locked" />
                    <x-input-error :messages="$errors->get('slug')" />
                </div>
                <div>
                    <x-input-label for="description" value="Description" />
                    <x-text-input id="description" name="description" :value="old('description', $role->description)" :disabled="$locked" />
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Permissions</h3></div>
            <x-permission-matrix :groups="$groups" :selected="$selected" :disabled="$locked" />
        </div>

        @unless ($locked)
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.roles.index') }}" class="kn-btn-secondary">Cancel</a>
                <button type="submit" class="kn-btn-primary">{{ $role->exists ? 'Save role' : 'Create role' }}</button>
            </div>
        @endunless
    </form>
</x-admin-layout>
