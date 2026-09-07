<x-app-layout>
    <x-slot name="header">Lists</x-slot>

    @php
        $listsLabel = $listLimit === null
            ? number_format($listsUsed).' lists · unlimited on your plan'
            : number_format($listsUsed).' of '.number_format($listLimit).' lists used';
        $listsFull = $listLimit !== null && $listsUsed >= (int) $listLimit;
        $isFiltered = filled($filters['q'] ?? null);
    @endphp

    <x-page-header title="Lists" :subtitle="$listsLabel">
        <x-slot name="actions">
            <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">All contacts</a>

            @permission('contacts.create')
                @if ($listsFull)
                    <span class="kn-badge-amber">List limit reached</span>
                @else
                    <a href="{{ route('lists.create') }}" class="kn-btn-primary">New list</a>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    <form method="GET" action="{{ route('lists.index') }}" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-3">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search lists by name" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($isFiltered)
                    <a href="{{ route('lists.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($lists->isEmpty())
        <div class="kn-card">
            @if ($isFiltered)
                <x-empty-state title="No lists match this search"
                               message="Nothing here matches that name. Try a shorter search term.">
                    <x-slot name="action">
                        <a href="{{ route('lists.index') }}" class="kn-btn-secondary">Clear search</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-empty-state title="No lists yet"
                               message="Lists group your contacts so a campaign can target them. Create one, then add contacts to it from the contacts screen.">
                    <x-slot name="action">
                        @permission('contacts.create')
                            @if ($listsFull)
                                <span class="kn-badge-amber">List limit reached — upgrade your plan to add another</span>
                            @else
                                <a href="{{ route('lists.create') }}" class="kn-btn-primary">Create your first list</a>
                            @endif
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($lists as $list)
                <div class="kn-card flex flex-col overflow-hidden">
                    <div class="flex-1 p-5">
                        <a href="{{ route('lists.show', $list) }}"
                           class="block truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                            {{ $list->name }}
                        </a>

                        <p class="mt-1 line-clamp-2 text-sm text-ink-500">
                            {{ $list->description ?: 'No description' }}
                        </p>

                        @if ($list->from_email || $list->from_name)
                            <p class="mt-2 truncate text-xs text-ink-400">
                                Default sender: {{ trim(($list->from_name ?? '').' <'.($list->from_email ?? 'not set').'>') }}
                            </p>
                        @endif
                    </div>

                    <div class="grid grid-cols-4 divide-x divide-ink-100 border-t border-ink-100 text-center">
                        @foreach ([
                            'Total' => $list->total_count,
                            'Active' => $list->active_count,
                            'Unsub' => $list->unsubscribed_count,
                            'Bounced' => $list->bounced_count,
                        ] as $statLabel => $statValue)
                            <div class="px-2 py-3">
                                <p class="text-sm font-semibold text-ink-900">{{ number_format((int) $statValue) }}</p>
                                <p class="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-ink-500">{{ $statLabel }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-ink-100 px-5 py-3">
                        <span class="text-xs text-ink-500">
                            Created {{ $list->created_at?->format('j M Y') ?? '—' }}
                        </span>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <a href="{{ route('lists.show', $list) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                            @permission('contacts.update')
                                <a href="{{ route('lists.edit', $list) }}" class="kn-btn-ghost kn-btn-sm">Edit</a>
                            @endpermission

                            @permission('contacts.delete')
                                <x-confirm-form :action="route('lists.destroy', $list)"
                                                label="Delete"
                                                :message="'Delete the list '.$list->name.'? The contacts on it stay in your account — only the list is removed.'" />
                            @endpermission
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($lists->hasPages())
            <div class="mt-6">
                <div class="border-t border-ink-100 px-5 py-3">{{ $lists->links() }}</div>
            </div>
        @endif
    @endif
</x-app-layout>
