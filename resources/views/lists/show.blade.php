<x-app-layout>
    <x-slot name="header">{{ $list->name }}</x-slot>

    @php
        // Explicit eager load so the Tags column below is one query, not one per row.
        $members->getCollection()->loadMissing('tags');

        $statuses = \App\Models\Subscriber::STATUSES;
        $isFiltered = filled($filters['q'] ?? null) || filled($filters['status'] ?? null);
        $contactsUrl = route('subscribers.index', ['list_id' => $list->id]);
    @endphp

    <x-page-header :title="$list->name"
                   :subtitle="$list->description ?: 'Created '.($list->created_at?->format('j M Y') ?? '—')"
                   :back="route('lists.index')">
        <x-slot name="actions">
            <a href="{{ $contactsUrl }}" class="kn-btn-secondary">Manage in contacts</a>

            @permission('contacts.update')
                <a href="{{ route('lists.edit', $list) }}" class="kn-btn-secondary">Edit list</a>
            @endpermission

            @permission('contacts.delete')
                <x-confirm-form :action="route('lists.destroy', $list)"
                                label="Delete list"
                                button-class="kn-btn-danger"
                                :message="'Delete the list '.$list->name.'? The contacts on it stay in your account — only the list is removed.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total contacts"
                     :value="number_format((int) $list->total_count)"
                     meta="Everyone on this list"
                     :href="$contactsUrl" />

        <x-stat-card label="Active"
                     :value="number_format((int) $list->active_count)"
                     meta="Can receive campaigns"
                     tone="positive"
                     :href="route('subscribers.index', ['list_id' => $list->id, 'status' => 'active'])" />

        <x-stat-card label="Unsubscribed"
                     :value="number_format((int) $list->unsubscribed_count)"
                     meta="Opted out of mail"
                     :href="route('subscribers.index', ['list_id' => $list->id, 'status' => 'unsubscribed'])" />

        <x-stat-card label="Bounced"
                     :value="number_format((int) $list->bounced_count)"
                     meta="Delivery failed"
                     tone="warning"
                     :href="route('subscribers.index', ['list_id' => $list->id, 'status' => 'bounced'])" />
    </div>

    @if ($list->from_name || $list->from_email)
        <div class="kn-card mb-5">
            <div class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-xs font-medium uppercase tracking-wide text-ink-500">Default sender</span>
                <span class="truncate text-sm text-ink-700">
                    {{ $list->from_name ?: 'No from name' }}
                    &lt;{{ $list->from_email ?: 'no from email' }}&gt;
                </span>
                <span class="text-xs text-ink-500">Campaigns sent to this list inherit these unless they set their own.</span>
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('lists.show', $list) }}" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$filters['q'] ?? ''" placeholder="Search members by name or email" />
            </div>
            <select name="status" class="kn-select">
                <option value="">Any status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
                @if ($isFiltered)
                    <a href="{{ route('lists.show', $list) }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Members</h3>
            <span class="text-xs text-ink-500">{{ number_format($members->total()) }} matching this view</span>
        </div>

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Tags</th>
                        <th>Added</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        @php
                            $addedRaw = $member->pivot?->subscribed_at ?: $member->pivot?->created_at;
                            $added = $addedRaw ? \Illuminate\Support\Carbon::parse($addedRaw) : null;
                        @endphp
                        <tr>
                            <td>
                                <div class="min-w-0">
                                    <a href="{{ route('subscribers.show', $member) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $member->displayName() }}
                                    </a>
                                    <span class="block truncate text-xs text-ink-500">{{ $member->email }}</span>
                                </div>
                            </td>
                            <td><x-status-badge :status="$member->status" /></td>
                            <td>
                                @if ($member->tags->isEmpty())
                                    <span class="text-ink-400">—</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($member->tags as $tag)
                                            @php
                                                $dot = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tag->color)
                                                    ? $tag->color
                                                    : '#94a3b8';
                                            @endphp
                                            <span class="kn-badge-gray">
                                                <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $dot }}"></span>
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-500">
                                {{ $added?->format('j M Y') ?? '—' }}
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('subscribers.show', $member) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                                    @permission('contacts.update')
                                        <x-confirm-form :action="route('lists.detach', [$list, $member])"
                                                        label="Remove"
                                                        :message="'Remove '.$member->email.' from the list '.$list->name.'? The contact is not deleted — it stays in your account and keeps its other lists and tags.'" />
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @if ($isFiltered)
                                    <x-empty-state title="No members match these filters"
                                                   message="Nothing on this list matches that search or status.">
                                        <x-slot name="action">
                                            <a href="{{ route('lists.show', $list) }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="This list is empty"
                                                   message="Add contacts to it from the contacts screen, or bring them in with an import.">
                                        <x-slot name="action">
                                            <div class="flex flex-wrap items-center justify-center gap-2">
                                                <a href="{{ $contactsUrl }}" class="kn-btn-secondary">Browse contacts</a>
                                                @permission('contacts.import')
                                                    <a href="{{ route('imports.create') }}" class="kn-btn-primary">Import contacts</a>
                                                @endpermission
                                            </div>
                                        </x-slot>
                                    </x-empty-state>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($members->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $members->links() }}</div>
        @endif
    </div>
</x-app-layout>
