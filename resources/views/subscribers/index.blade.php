<x-app-layout>
    <x-slot name="header">Contacts</x-slot>

    @php
        /**
         * The controller eager-loads tags only. The list membership count is
         * loaded here in ONE extra query for the whole page rather than one
         * query per row (lazy loading is blocked in local anyway).
         */
        $subscribers->getCollection()->loadCount('lists');

        $statusMeta = [
            'active' => ['label' => 'Active', 'tone' => 'positive'],
            'pending' => ['label' => 'Pending', 'tone' => 'warning'],
            'unsubscribed' => ['label' => 'Unsubscribed', 'tone' => 'default'],
            'bounced' => ['label' => 'Bounced', 'tone' => 'warning'],
            'blocked' => ['label' => 'Blocked', 'tone' => 'danger'],
        ];

        $totalContacts = array_sum($statusCounts);

        // Active filters, so a stat card keeps the rest of the filter set.
        $activeFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $withoutStatus = array_diff_key($activeFilters, ['status' => '']);

        // Plan usage.
        $usagePercent = $contactLimit ? min(100, (int) round($contactsUsed / max($contactLimit, 1) * 100)) : 0;
        $usageBar = $usagePercent >= 90 ? 'bg-red-500' : ($usagePercent >= 75 ? 'bg-amber-500' : 'bg-brand-600');
        $usageLabel = $contactLimit === null
            ? number_format($contactsUsed).' contacts · unlimited on your plan'
            : number_format($contactsUsed).' of '.number_format($contactLimit).' contacts used';

        // Tag colours come from user input, so only a real hex reaches the style attribute.
        $swatch = fn ($color) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? $color : '#64748b';

        $canBulk = (bool) auth()->user()?->hasPermission('contacts.update');
        $columnCount = $canBulk ? 8 : 7;
        $hasFilters = count($activeFilters) > 0;
    @endphp

    <x-page-header title="Contacts"
                   :subtitle="number_format($totalContacts).' contacts in this account'">
        <x-slot name="actions">
            @permission('contacts.view')
                <a href="{{ route('custom-fields.index') }}" class="kn-btn-ghost">Custom fields</a>
            @endpermission
            @permission('contacts.export')
                <a href="{{ route('exports.index') }}" class="kn-btn-secondary">Export</a>
            @endpermission
            @permission('contacts.import')
                <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Import</a>
            @endpermission
            @permission('contacts.create')
                <a href="{{ route('subscribers.create') }}" class="kn-btn-primary">Add contact</a>
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------------ stats --}}
    <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        @foreach ($statusMeta as $status => $meta)
            @php
                $count = (int) ($statusCounts[$status] ?? 0);
                $share = $totalContacts > 0 ? round($count / $totalContacts * 100) : 0;
            @endphp
            <x-stat-card :label="$meta['label']"
                         :value="number_format($count)"
                         :meta="$totalContacts > 0 ? $share.'% of all contacts' : 'No contacts yet'"
                         :tone="$meta['tone']"
                         :href="route('subscribers.index', array_merge($withoutStatus, ['status' => $status]))"
                         class="{{ ($filters['status'] ?? '') === $status ? 'ring-2 ring-brand-500' : '' }}" />
        @endforeach
    </div>

    {{-- ------------------------------------------------------- plan usage --}}
    <div class="kn-card mb-5">
        <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="kn-stat-label">Plan usage</p>
                <p class="mt-0.5 text-sm font-medium text-ink-900">{{ $usageLabel }}</p>
            </div>
            @if ($contactLimit !== null)
                <div class="w-full sm:w-72">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-ink-200">
                        <div class="h-2 rounded-full {{ $usageBar }}" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    <p class="mt-1 text-right text-xs {{ $usagePercent >= 90 ? 'font-semibold text-red-600' : 'text-ink-500' }}">
                        {{ $usagePercent }}% used{{ $usagePercent >= 90 ? ' · almost full' : '' }}
                    </p>
                </div>
            @else
                <span class="kn-badge-blue shrink-0">Unlimited</span>
            @endif
        </div>
    </div>

    {{-- ----------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('subscribers.index') }}" class="kn-card mb-5">
        @if (($filters['city'] ?? '') !== '')
            <input type="hidden" name="city" value="{{ $filters['city'] }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-input-label for="q" value="Search" />
                <x-text-input id="q" name="q" :value="$filters['q'] ?? ''"
                              placeholder="Email, name, company or phone" />
            </div>

            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status" class="kn-select">
                    <option value="">Any status</option>
                    @foreach ($statusMeta as $status => $meta)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                            {{ $meta['label'] }} ({{ number_format($statusCounts[$status] ?? 0) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="list_id" value="List" />
                <select id="list_id" name="list_id" class="kn-select">
                    <option value="">Any list</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}" @selected((int) ($filters['list_id'] ?? 0) === $list->id)>{{ $list->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="tag_id" value="Tag" />
                <select id="tag_id" name="tag_id" class="kn-select">
                    <option value="">Any tag</option>
                    @foreach ($tags as $tag)
                        <option value="{{ $tag->id }}" @selected((int) ($filters['tag_id'] ?? 0) === $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="country" value="Country" />
                <select id="country" name="country" class="kn-select">
                    <option value="">Any country</option>
                    @foreach ($countries as $country)
                        <option value="{{ $country }}" @selected(($filters['country'] ?? '') === $country)>{{ $country }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="source" value="Source" />
                <x-text-input id="source" name="source" :value="$filters['source'] ?? ''" placeholder="e.g. import, manual, form" />
            </div>

            <div>
                <x-input-label for="consent_status" value="Consent" />
                <select id="consent_status" name="consent_status" class="kn-select">
                    <option value="">Any consent</option>
                    @foreach (['explicit' => 'Explicit', 'implied' => 'Implied', 'unknown' => 'Unknown'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['consent_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="created_from" value="Added from" />
                <x-text-input id="created_from" name="created_from" type="date" :value="$filters['created_from'] ?? ''" />
            </div>

            <div>
                <x-input-label for="created_to" value="Added to" />
                <x-text-input id="created_to" name="created_to" type="date" :value="$filters['created_to'] ?? ''" />
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-2">
                <button type="submit" class="kn-btn-primary">Apply filters</button>
                @if ($hasFilters)
                    <a href="{{ route('subscribers.index') }}" class="kn-btn-ghost">Clear filters</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------- table + bulk bar --}}
    <div x-data="knBulkSelect()">

        @permission('contacts.update')
            <x-bulk-bar :action="route('subscribers.bulk')" count-label="contacts selected">
                <div x-data="{ bulkAction: 'add_tags' }" class="flex flex-wrap items-end gap-2">
                    <select name="action" x-model="bulkAction" class="kn-select w-auto min-w-[11rem] py-1.5 text-xs">
                        <option value="add_tags">Add tags</option>
                        <option value="remove_tags">Remove tags</option>
                        <option value="add_to_lists">Add to lists</option>
                        <option value="remove_from_lists">Remove from lists</option>
                        <option value="change_status">Change status</option>
                        <option value="unsubscribe">Unsubscribe</option>
                        <option value="suppress">Add to suppression list</option>
                        {{-- Bulk delete removes contacts, so it needs the same slug as the per-row Delete. --}}
                        @permission('contacts.delete')
                            <option value="delete">Delete contacts</option>
                        @endpermission
                    </select>

                    <select name="tag_ids[]" multiple size="3"
                            x-show="['add_tags', 'remove_tags'].includes(bulkAction)" x-cloak
                            class="kn-select w-auto min-w-[11rem] text-xs">
                        @foreach ($tags as $tag)
                            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                        @endforeach
                    </select>

                    <select name="list_ids[]" multiple size="3"
                            x-show="['add_to_lists', 'remove_from_lists'].includes(bulkAction)" x-cloak
                            class="kn-select w-auto min-w-[11rem] text-xs">
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                        @endforeach
                    </select>

                    <select name="status" x-show="bulkAction === 'change_status'" x-cloak
                            class="kn-select w-auto min-w-[9rem] py-1.5 text-xs">
                        @foreach ($statusMeta as $status => $meta)
                            <option value="{{ $status }}">{{ $meta['label'] }}</option>
                        @endforeach
                    </select>

                    <input type="text" name="reason" maxlength="40" placeholder="Reason (optional)"
                           x-show="bulkAction === 'suppress'" x-cloak
                           class="kn-input w-auto min-w-[11rem] py-1.5 text-xs">

                    <button type="submit" class="kn-btn-primary kn-btn-sm"
                            @click="if (['delete', 'suppress', 'unsubscribe'].includes(bulkAction) && ! confirm('Apply this action to ' + selected.length + ' contact(s)? This cannot be undone.')) $event.preventDefault()">
                        Apply
                    </button>
                </div>
            </x-bulk-bar>
        @endpermission

        <div class="kn-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            @permission('contacts.update')
                                <th class="w-10">
                                    <input type="checkbox" class="kn-checkbox" x-model="all" @change="toggleAll()"
                                           aria-label="Select every contact on this page">
                                </th>
                            @endpermission
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Lists</th>
                            <th>Tags</th>
                            <th>Country</th>
                            <th>Added</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($subscribers as $subscriber)
                            @php $displayName = $subscriber->displayName(); @endphp
                            <tr>
                                @permission('contacts.update')
                                    <td>
                                        <input type="checkbox" class="kn-checkbox"
                                               data-row-id="{{ $subscriber->id }}" @change="sync()"
                                               aria-label="Select {{ $subscriber->email }}">
                                    </td>
                                @endpermission

                                <td>
                                    <div class="min-w-0 max-w-xs">
                                        <a href="{{ route('subscribers.show', $subscriber) }}"
                                           class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                            {{ $displayName }}
                                        </a>
                                        @if ($displayName !== $subscriber->email)
                                            <span class="block truncate text-xs text-ink-500">{{ $subscriber->email }}</span>
                                        @endif
                                    </div>
                                </td>

                                <td><x-status-badge :status="$subscriber->status" /></td>

                                <td class="whitespace-nowrap text-ink-600">{{ number_format($subscriber->lists_count ?? 0) }}</td>

                                <td>
                                    @if ($subscriber->tags->isEmpty())
                                        <span class="text-ink-400">—</span>
                                    @else
                                        <div class="flex flex-wrap items-center gap-1">
                                            @foreach ($subscriber->tags->take(3) as $tag)
                                                <span class="kn-badge ring-1 ring-inset"
                                                      style="background-color: {{ $swatch($tag->color) }}1a; color: {{ $swatch($tag->color) }}; --tw-ring-color: {{ $swatch($tag->color) }}33">
                                                    {{ $tag->name }}
                                                </span>
                                            @endforeach
                                            @if ($subscriber->tags->count() > 3)
                                                <span class="kn-badge-gray" title="{{ $subscriber->tags->pluck('name')->implode(', ') }}">
                                                    +{{ $subscriber->tags->count() - 3 }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap text-ink-600">{{ $subscriber->country ?: '—' }}</td>

                                <td class="whitespace-nowrap text-xs text-ink-500"
                                    title="{{ $subscriber->created_at?->format('D, d M Y H:i') }}">
                                    {{ $subscriber->created_at?->diffForHumans() ?? '—' }}
                                </td>

                                <td class="text-right">
                                    <div class="flex justify-end gap-1.5">
                                        <a href="{{ route('subscribers.show', $subscriber) }}" class="kn-btn-ghost kn-btn-sm">View</a>

                                        @permission('contacts.update')
                                            <a href="{{ route('subscribers.edit', $subscriber) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>
                                        @endpermission

                                        @permission('contacts.delete')
                                            <x-confirm-form :action="route('subscribers.destroy', $subscriber)"
                                                            label="Delete"
                                                            :message="'Delete '.$subscriber->email.'? This cannot be undone.'" />
                                        @endpermission
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $columnCount }}">
                                    @if ($hasFilters)
                                        <x-empty-state title="No contacts match these filters"
                                                       message="Try a wider date range, or clear the filters to see every contact.">
                                            <x-slot name="action">
                                                <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">Clear filters</a>
                                            </x-slot>
                                        </x-empty-state>
                                    @else
                                        <x-empty-state title="No contacts yet"
                                                       message="Add your first contact by hand, or import a CSV to bring in a whole list at once.">
                                            <x-slot name="action">
                                                @permission('contacts.create')
                                                    <a href="{{ route('subscribers.create') }}" class="kn-btn-primary">Add contact</a>
                                                @endpermission
                                                @permission('contacts.import')
                                                    <a href="{{ route('imports.create') }}" class="kn-btn-secondary">Import a CSV</a>
                                                @endpermission
                                            </x-slot>
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
    </div>
</x-app-layout>
