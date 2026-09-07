<section class="kn-card">
    <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Profile image</h3></div>

    <div class="p-5">
        <div class="mb-4 flex items-center gap-4">
            <img src="{{ $user->avatarUrl() }}" alt="" class="h-16 w-16 rounded-full object-cover ring-1 ring-ink-200">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-ink-900">{{ $user->name }}</p>
                <p class="truncate text-xs text-ink-500">{{ $user->email }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="avatar" accept="image/*" class="kn-input" required>
            <p class="kn-help">JPG, PNG or WebP up to 2 MB.</p>
            <x-input-error :messages="$errors->get('avatar')" />
            <x-primary-button class="w-full">Upload image</x-primary-button>
        </form>

        @if ($user->avatar_path)
            <form method="POST" action="{{ route('profile.avatar.delete') }}" class="mt-2">
                @csrf @method('DELETE')
                <button type="submit" class="kn-btn-ghost w-full text-xs">Remove current image</button>
            </form>
        @endif
    </div>
</section>
