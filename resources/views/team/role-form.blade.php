<x-app-layout>
    <x-slot name="header">{{ $role->exists ? 'Edit role' : 'New role' }}</x-slot>

    <x-page-header :title="$role->exists ? 'Edit '.$role->name : 'New role'"
                   subtitle="Pick exactly what members on this role can reach. Controls they lack permission for are not rendered at all."
                   :back="route('team.index')" />

    <form method="POST"
          action="{{ $role->exists ? route('team.roles.update', $role) : route('team.roles.store') }}"
          class="space-y-6">
        @csrf
        @if ($role->exists) @method('PUT') @endif

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Role details</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-3">
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" :value="old('name', $role->name)" required placeholder="e.g. Campaign Manager" />
                    <x-input-error :messages="$errors->get('name')" />
                </div>
                <div>
                    <x-input-label for="slug" value="Slug" />
                    <x-text-input id="slug" name="slug" :value="old('slug', $role->slug)" placeholder="auto from name" />
                    <x-input-error :messages="$errors->get('slug')" />
                </div>
                <div>
                    <x-input-label for="description" value="Description" />
                    <x-text-input id="description" name="description" :value="old('description', $role->description)" />
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Permissions</h3></div>
            <x-permission-matrix :groups="$groups" :selected="$selected" />
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('team.index') }}" class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">{{ $role->exists ? 'Save role' : 'Create role' }}</button>
        </div>
    </form>
</x-app-layout>
