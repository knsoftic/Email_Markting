<x-admin-layout>
    <x-slot name="header">System health</x-slot>

    <x-page-header title="System health" subtitle="Each check below actually exercises the subsystem it reports on.">
        <x-slot name="actions">
            <form method="POST" action="{{ route('admin.system.cache.clear') }}">
                @csrf
                <button type="submit" class="kn-btn-secondary">Clear caches</button>
            </form>
            <a href="{{ route('admin.system.health') }}" class="kn-btn-primary">Re-run checks</a>
        </x-slot>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="kn-card overflow-hidden">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Checks</h3></div>
            @foreach ($checks as $check)
                <div class="flex items-start justify-between gap-4 border-b border-ink-100 px-5 py-3.5 last:border-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink-900">{{ $check['label'] }}</p>
                        <p class="mt-0.5 break-words text-xs text-ink-500">{{ $check['detail'] }}</p>
                    </div>
                    <span class="{{ $check['ok'] ? 'kn-badge-green' : 'kn-badge-red' }} shrink-0">
                        {{ $check['ok'] ? 'Healthy' : 'Attention' }}
                    </span>
                </div>
            @endforeach
        </div>

        <div class="kn-card overflow-hidden">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Environment</h3></div>
            <dl>
                @foreach ($environment as $label => $value)
                    <div class="flex items-center justify-between gap-4 border-b border-ink-100 px-5 py-2.5 last:border-0">
                        <dt class="text-sm text-ink-600">{{ $label }}</dt>
                        <dd class="text-sm font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</x-admin-layout>
