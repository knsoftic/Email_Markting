<x-app-layout>
    <x-slot name="header">Suppression list</x-slot>

    @php
        // Reason -> badge tone. Bounces and complaints are the ones that carry
        // real deliverability risk, so they read red.
        $reasonTones = [
            'unsubscribed' => 'kn-badge-gray',
            'hard_bounce' => 'kn-badge-red',
            'soft_bounce' => 'kn-badge-amber',
            'spam_complaint' => 'kn-badge-red',
            'manual' => 'kn-badge-amber',
            'invalid' => 'kn-badge-gray',
            'import' => 'kn-badge-blue',
        ];

        $reasonTitles = [
            'unsubscribed' => 'The person opted out from an email you sent.',
            'hard_bounce' => 'The mailbox does not exist. Sending again damages your sender reputation.',
            'soft_bounce' => 'Delivery failed repeatedly — full mailbox, server refusing, or similar.',
            'spam_complaint' => 'The person marked an email as spam.',
            'manual' => 'Added by hand from this screen.',
            'invalid' => 'The address is not a usable email address.',
            'import' => 'Came in on an import marked as do-not-send.',
        ];

        $statTones = [
            'hard_bounce' => 'danger',
            'spam_complaint' => 'danger',
            'soft_bounce' => 'warning',
            'manual' => 'warning',
        ];

        $activeReason = $filters['reason'] ?? '';
        $activeQuery = $filters['q'] ?? '';
        $hasFilters = $activeReason !== '' || $activeQuery !== '';
        $exportParams = $activeReason !== '' ? ['reason' => $activeReason] : [];

        $label = fn ($reason) => ucfirst(str_replace('_', ' ', (string) $reason));

        // The select column only renders for users who can act on a selection,
        // so the empty-state colspan has to follow it.
        $canSelect = (bool) auth()->user()?->hasPermission('contacts.update');
        $columnCount = $canSelect ? 7 : 6;
    @endphp

    <x-page-header title="Suppression list"
                   subtitle="Every address on this list is blocked from all marketing email sent by this account.">
        @permission('contacts.export')
            <x-slot name="actions">
                <a href="{{ route('suppressions.export', $exportParams) }}" class="kn-btn-secondary">
                    Export CSV{{ $activeReason !== '' ? ' ('.$label($activeReason).')' : '' }}
                </a>
            </x-slot>
        @endpermission
    </x-page-header>

    {{-- ------------------------------------------------------ what this is --}}
    <div class="kn-card mb-6 border-l-4 border-l-brand-500">
        <div class="kn-card-body">
            <h3 class="text-sm font-semibold text-ink-900">How the suppression list works</h3>
            <ul class="mt-2.5 space-y-1.5 text-sm text-ink-600">
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span>
                    <span>An address on this list never receives marketing email from this account. It is skipped when
                        recipients are built, so it does not matter which list, tag or segment it sits in.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span>
                    <span>Addresses land here automatically when someone unsubscribes, marks an email as spam, or hard
                        bounces. You can also add them by hand below.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500"></span>
                    <span><span class="font-semibold text-ink-800">Removing an address does not re-subscribe anyone.</span>
                        The contact record stays unsubscribed, bounced or blocked. To email that person again you have to
                        collect their consent again and set them back to active yourself.</span>
                </li>
            </ul>
        </div>
    </div>

    {{-- ------------------------------------------------------------- stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <x-stat-card label="Total suppressed"
                     :value="number_format($total)"
                     meta="Blocked from every send"
                     :href="route('suppressions.index')" />

        @foreach ($reasons as $reason)
            @if (($counts[$reason] ?? 0) > 0)
                <x-stat-card :label="$label($reason)"
                             :value="number_format($counts[$reason])"
                             :meta="$reasonTitles[$reason] ?? null"
                             :tone="$statTones[$reason] ?? 'default'"
                             :href="route('suppressions.index', ['reason' => $reason])" />
            @endif
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ filters --}}
    <form method="GET" action="{{ route('suppressions.index') }}" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$activeQuery" placeholder="Search by email address" aria-label="Search by email address" />
            </div>

            <select name="reason" class="kn-select" aria-label="Filter by reason">
                <option value="">Any reason</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason }}" @selected($activeReason === $reason)>
                        {{ $label($reason) }}@if (($counts[$reason] ?? 0) > 0) ({{ number_format($counts[$reason]) }})@endif
                    </option>
                @endforeach
            </select>

            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
                @if ($hasFilters)
                    <a href="{{ route('suppressions.index') }}" class="kn-btn-ghost">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- -------------------------------------------------- table + bulk bar --}}
    <div x-data="knBulkSelect()">
        @permission('contacts.update')
            <x-bulk-bar :action="route('suppressions.bulk')" count-label="addresses selected">
                <input type="hidden" name="action" value="release">
                <button type="submit" class="kn-btn-secondary kn-btn-sm"
                        onclick="return confirm('Remove the selected addresses from the suppression list? Their contact records are not re-activated — you still need fresh consent before emailing them.');">
                    Remove from suppression list
                </button>
            </x-bulk-bar>
        @endpermission

        <div class="kn-card overflow-hidden">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">
                    Suppressed addresses
                    <span class="ml-1 font-normal text-ink-500">({{ number_format($suppressions->total()) }})</span>
                </h3>
                @if ($hasFilters)
                    <span class="text-xs text-ink-500">Filtered view</span>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            @permission('contacts.update')
                                <th class="w-10">
                                    <input type="checkbox" class="kn-checkbox" x-model="all" @change="toggleAll()"
                                           aria-label="Select every address on this page">
                                </th>
                            @endpermission
                            <th>Email</th>
                            <th>Reason</th>
                            <th>Source</th>
                            <th>Notes</th>
                            <th>Suppressed</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($suppressions as $suppression)
                            <tr>
                                @permission('contacts.update')
                                    <td>
                                        <input type="checkbox" class="kn-checkbox" name="ids[]"
                                               value="{{ $suppression->id }}"
                                               data-row-id="{{ $suppression->id }}"
                                               @change="sync()"
                                               aria-label="Select {{ $suppression->email }}">
                                    </td>
                                @endpermission

                                <td class="font-medium text-ink-900">
                                    <span class="block break-all">{{ $suppression->email }}</span>
                                </td>

                                <td>
                                    <x-status-badge :status="$suppression->reason"
                                                    :map="$reasonTones"
                                                    :title="$reasonTitles[$suppression->reason] ?? ''" />
                                </td>

                                <td class="text-ink-600">
                                    {{ $suppression->source ?: '—' }}
                                    @if ($suppression->campaign_id)
                                        <span class="block text-xs text-ink-400">Campaign #{{ $suppression->campaign_id }}</span>
                                    @endif
                                </td>

                                <td class="max-w-xs text-ink-600">
                                    @if ($suppression->notes)
                                        <span class="block truncate" title="{{ $suppression->notes }}">{{ $suppression->notes }}</span>
                                    @else
                                        <span class="text-ink-400">—</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap">
                                    <span class="block text-ink-700">{{ $suppression->created_at?->format('M j, Y') ?? '—' }}</span>
                                    <span class="block text-xs text-ink-500">{{ $suppression->created_at?->diffForHumans() }}</span>
                                </td>

                                <td class="text-right">
                                    @permission('contacts.delete')
                                        <x-confirm-form :action="route('suppressions.destroy', $suppression)"
                                                        label="Remove"
                                                        button-class="kn-btn-ghost kn-btn-sm text-red-600 hover:bg-red-50"
                                                        :message="'Remove '.$suppression->email.' from the suppression list? This does NOT re-subscribe the contact — their record stays unsubscribed and you still need fresh consent before emailing them.'" />
                                    @else
                                        <span class="text-xs text-ink-400">—</span>
                                    @endpermission
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $columnCount }}">
                                    @if ($hasFilters)
                                        <x-empty-state title="No suppressed addresses match these filters"
                                                       message="Try a different reason, or clear the search to see the whole list.">
                                            <x-slot name="action">
                                                <a href="{{ route('suppressions.index') }}" class="kn-btn-secondary">Clear filters</a>
                                            </x-slot>
                                        </x-empty-state>
                                    @else
                                        <x-empty-state title="Nothing is suppressed yet"
                                                       message="Unsubscribes, spam complaints and hard bounces are added here automatically. You can also add addresses by hand below." />
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($suppressions->hasPages())
                <div class="border-t border-ink-100 px-5 py-3">{{ $suppressions->links() }}</div>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------ add addresses --}}
    @permission('contacts.update')
        <form method="POST" action="{{ route('suppressions.store') }}" class="kn-card mt-6">
            @csrf

            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Add addresses</h3>
                <span class="text-xs text-ink-500">Takes effect on the next send</span>
            </div>

            <div class="grid gap-5 p-5 lg:grid-cols-3">
                <div class="lg:col-span-3">
                    <x-input-label for="emails" value="Email addresses" />
                    <textarea id="emails" name="emails" rows="5" class="kn-textarea font-mono text-xs"
                              placeholder="jane@example.com&#10;bounced@example.com, complained@example.com"
                              required>{{ old('emails') }}</textarea>
                    <p class="kn-help">
                        One address per line, or separated by commas or semicolons. Duplicates and addresses already on
                        the list are skipped; anything that is not a valid email address is ignored and reported back.
                    </p>
                    <x-input-error :messages="$errors->get('emails')" />
                </div>

                <div>
                    <x-input-label for="reason" value="Reason" />
                    <select id="reason" name="reason" class="kn-select" required>
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason }}" @selected(old('reason', 'manual') === $reason)>
                                {{ $label($reason) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="kn-help">Recorded against every address in this batch. Pick the one that is actually true — it is your audit trail.</p>
                    <x-input-error :messages="$errors->get('reason')" />
                </div>

                <div class="lg:col-span-2">
                    <x-input-label for="notes" value="Note (optional)" />
                    <x-text-input id="notes" name="notes" :value="old('notes')"
                                  placeholder="e.g. Requested removal by phone on 3 Sep" />
                    <p class="kn-help">Stored against every address in this batch and shown in the table below.</p>
                    <x-input-error :messages="$errors->get('notes')" />
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-ink-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-ink-500">
                    Matching contacts are also marked unsubscribed, bounced or blocked so the two never disagree.
                </p>
                <x-primary-button class="shrink-0">Add to suppression list</x-primary-button>
            </div>
        </form>
    @endpermission
</x-app-layout>
