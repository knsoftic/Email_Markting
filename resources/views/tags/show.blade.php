<x-app-layout>
    <x-slot name="header">{{ $tag->name }}</x-slot>

    @php
        $q = trim((string) ($filters['q'] ?? ''));

        // The stored colour is user data; it is re-checked here before it is
        // ever written into a style attribute.
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tag->color) ? $tag->color : '#64748b';

        $manageUrl = route('subscribers.index', ['tag_id' => $tag->id]);
        $deleteMessage = 'Delete the tag "'.$tag->name.'"? It is removed from every contact that carries it. The contacts themselves are kept.';

        $subtitle = $tag->description
            ?: 'A label carried by '.number_format($tag->subscribers_count).' contact'.((int) $tag->subscribers_count === 1 ? '' : 's').'.';
    @endphp

    <x-page-header :title="$tag->name" :subtitle="$subtitle" :back="route('tags.index')">
        <x-slot name="actions">
            @permission('contacts.view')
                <a href="{{ $manageUrl }}" class="kn-btn-primary">Manage in contacts</a>
            @endpermission

            @permission('contacts.delete')
                <x-confirm-form :action="route('tags.destroy', $tag)"
                                label="Delete tag"
                                button-class="kn-btn-danger"
                                :message="$deleteMessage" />
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- The tag itself, rendered exactly as it appears on a contact row. --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <span class="inline-flex max-w-full items-center gap-2 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset"
              style="background-color: {{ $color }}1a; color: {{ $color }}; --tw-ring-color: {{ $color }}59">
            <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $color }}"></span>
            <span class="truncate">{{ $tag->name }}</span>
        </span>

        <code class="rounded bg-ink-100 px-2 py-0.5 text-xs text-ink-600">{{ $tag->slug }}</code>
        <span class="text-xs font-medium uppercase tracking-wide text-ink-400">{{ $color }}</span>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat-card label="Contacts tagged"
                     :value="number_format($tag->subscribers_count)"
                     meta="Open in contacts to filter or bulk-edit"
                     :href="$manageUrl" />

        <x-stat-card label="Listed here"
                     :value="number_format($subscribers->total())"
                     :meta="$q !== '' ? 'Matching your search' : 'Every contact carrying this tag'" />

        <x-stat-card label="Created"
                     :value="$tag->created_at?->format('M j, Y') ?? '—'"
                     :meta="$tag->updated_at ? 'Updated '.$tag->updated_at->diffForHumans() : null" />
    </div>

    <form method="GET" action="{{ route('tags.show', $tag) }}" class="kn-card mb-5">
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
            <div class="flex-1">
                <label for="q" class="sr-only">Search contacts with this tag</label>
                <x-text-input id="q" name="q" :value="$q" placeholder="Search these contacts by name or email" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($q !== '')
                    <a href="{{ route('tags.show', $tag) }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Contacts with this tag</h3>
            <span class="text-xs text-ink-500">{{ number_format($subscribers->total()) }} shown</span>
        </div>

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Country</th>
                        <th>Contact added</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscribers as $subscriber)
                        <tr>
                            <td>
                                <div class="min-w-0">
                                    <a href="{{ route('subscribers.show', $subscriber) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $subscriber->displayName() }}
                                    </a>
                                    <span class="block truncate text-xs text-ink-500">{{ $subscriber->email }}</span>
                                </div>
                            </td>
                            <td><x-status-badge :status="$subscriber->status" /></td>
                            <td class="text-ink-600">{{ $subscriber->country ?: '—' }}</td>
                            <td class="whitespace-nowrap text-xs text-ink-500">
                                {{ $subscriber->created_at?->format('M j, Y') ?? '—' }}
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('subscribers.show', $subscriber) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                                    @permission('contacts.update')
                                        <a href="{{ route('subscribers.edit', $subscriber) }}" class="kn-btn-ghost kn-btn-sm">Edit</a>
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @if ($q !== '')
                                    <x-empty-state title="No contact here matches that search"
                                                   message="Nothing carrying this tag matches what you typed. Clear the search to see everyone with the tag.">
                                        <x-slot name="action">
                                            <a href="{{ route('tags.show', $tag) }}" class="kn-btn-secondary">Clear search</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No contact carries this tag yet"
                                                   message="Tags are applied in bulk from the contacts screen: tick the contacts you want, then choose the tag action in the selection toolbar.">
                                        @permission('contacts.view')
                                            <x-slot name="action">
                                                <a href="{{ route('subscribers.index') }}" class="kn-btn-primary">Go to contacts</a>
                                            </x-slot>
                                        @endpermission
                                    </x-empty-state>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscribers->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $subscribers->links() }}</div>
        @endif
    </div>
</x-app-layout>
