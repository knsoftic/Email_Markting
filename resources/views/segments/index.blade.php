<x-app-layout>
    <x-slot name="header">Segments</x-slot>

    @php
        $isFiltered = filled($filters['q'] ?? null);
        $subtitle = $segments->total() === 1
            ? '1 saved segment'
            : number_format($segments->total()).' saved segments';
    @endphp

    <x-page-header title="Segments" :subtitle="$subtitle">
        <x-slot name="actions">
            <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">All contacts</a>

            @permission('contacts.create')
                @if ($allowed)
                    <a href="{{ route('segments.create') }}" class="kn-btn-primary">New segment</a>
                @else
                    <span class="kn-badge-amber">Segments are not included in your plan</span>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    @unless ($allowed)
        <div class="kn-card mb-5 border-amber-200">
            <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-ink-900">Saved segments are not included in your plan</p>
                    <p class="mt-1 text-sm text-ink-600">
                        You can still open and recalculate the segments already saved here, but creating and
                        editing them is switched off. Ask your account owner to upgrade to turn it back on.
                    </p>
                </div>
                <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary shrink-0">Filter contacts instead</a>
            </div>
        </div>
    @endunless

    <form method="GET" action="{{ route('segments.index') }}" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-3">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search segments by name" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($isFiltered)
                    <a href="{{ route('segments.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($segments->isEmpty())
        <div class="kn-card">
            @if ($isFiltered)
                <x-empty-state title="No segments match this search"
                               message="Nothing here matches that name. Try a shorter search term.">
                    <x-slot name="action">
                        <a href="{{ route('segments.index') }}" class="kn-btn-secondary">Clear search</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-empty-state title="No segments yet"
                               message="A segment is a saved filter — “active contacts in Germany who opened the last campaign”. It stays up to date on its own, so a campaign can target it without you rebuilding the filter each time.">
                    <x-slot name="action">
                        @permission('contacts.create')
                            @if ($allowed)
                                <a href="{{ route('segments.create') }}" class="kn-btn-primary">Create your first segment</a>
                            @else
                                <span class="kn-badge-amber">Segments are not included in your plan</span>
                            @endif
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($segments as $segment)
                @php
                    $summary = (array) ($segment->rule_summary ?? []);
                    $shown = array_slice($summary, 0, 4);
                    $hidden = max(0, count($summary) - count($shown));
                    $matchLabel = $segment->match_type === 'any' ? 'Match ANY condition' : 'Match ALL conditions';
                @endphp

                <div class="kn-card flex flex-col overflow-hidden">
                    <div class="flex-1 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ route('segments.show', $segment) }}"
                               class="block min-w-0 truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                                {{ $segment->name }}
                            </a>
                            <span class="kn-badge-blue shrink-0">{{ $segment->match_type === 'any' ? 'ANY' : 'ALL' }}</span>
                        </div>

                        <p class="mt-1 line-clamp-2 text-sm text-ink-500">
                            {{ $segment->description ?: 'No description' }}
                        </p>

                        <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-ink-500">{{ $matchLabel }}</p>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @forelse ($shown as $line)
                                <span class="kn-badge-gray max-w-full" title="{{ $line }}">
                                    <span class="min-w-0 truncate">{{ $line }}</span>
                                </span>
                            @empty
                                <span class="kn-badge-amber">No usable conditions — matches every contact</span>
                            @endforelse

                            @if ($hidden > 0)
                                <span class="kn-badge-gray" title="{{ implode(' · ', $summary) }}">+{{ $hidden }} more</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 divide-x divide-ink-100 border-t border-ink-100 text-center">
                        <div class="px-2 py-3">
                            <p class="text-sm font-semibold text-ink-900">{{ number_format((int) $segment->cached_count) }}</p>
                            <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-ink-500">Contacts matched</p>
                        </div>
                        <div class="px-2 py-3">
                            <p class="text-sm font-semibold text-ink-900"
                               title="{{ $segment->last_calculated_at?->format('D, d M Y H:i') ?? 'Not calculated yet' }}">
                                {{ $segment->last_calculated_at?->diffForHumans() ?? 'Never' }}
                            </p>
                            <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-ink-500">Last calculated</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-1.5 border-t border-ink-100 px-5 py-3">
                        <a href="{{ route('segments.show', $segment) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                        @permission('contacts.update')
                            @if ($allowed)
                                <a href="{{ route('segments.edit', $segment) }}" class="kn-btn-ghost kn-btn-sm">Edit</a>
                            @endif

                            <form method="POST" action="{{ route('segments.recalculate', $segment) }}" class="inline">
                                @csrf
                                <button type="submit" class="kn-btn-ghost kn-btn-sm">Recalculate</button>
                            </form>
                        @endpermission

                        @permission('contacts.delete')
                            <x-confirm-form :action="route('segments.destroy', $segment)"
                                            label="Delete"
                                            :message="'Delete the segment '.$segment->name.'? No contacts are deleted — only the saved filter is removed.'" />
                        @endpermission
                    </div>
                </div>
            @endforeach
        </div>

        @if ($segments->hasPages())
            <div class="mt-6">
                <div class="border-t border-ink-100 px-5 py-3">{{ $segments->links() }}</div>
            </div>
        @endif
    @endif
</x-app-layout>
