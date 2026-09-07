<x-app-layout>
    <x-slot name="header">Email Logs</x-slot>

    @php
        /**
         * The sending log.
         *
         * Everything here comes from what EmailLogController@index passes:
         * $logs (a paginator with `campaign` and `smtpAccount` eager loaded,
         * trashed rows included), $filters, $statusCounts, $campaigns, $zone,
         * $accountHasLogs. No relation is touched that was not loaded — lazy
         * loading is prevented outside production, and a list screen that only
         * breaks on a developer's machine is worse than one that never had the
         * field.
         */

        $search = (string) ($filters['q'] ?? '');
        $typeFilter = (string) ($filters['type'] ?? '');
        $statusFilter = (string) ($filters['status'] ?? '');
        $campaignFilter = (string) ($filters['campaign'] ?? '');
        $fromFilter = (string) ($filters['from'] ?? '');
        $toFilter = (string) ($filters['to'] ?? '');

        // Only values the controller would actually honour count as "filtered",
        // so ?status=nonsense does not light up a Clear button that changes
        // nothing.
        $activeStatus = in_array($statusFilter, $statuses, true) ? $statusFilter : '';
        $activeType = in_array($typeFilter, $types, true) ? $typeFilter : '';
        $activeCampaign = ctype_digit($campaignFilter) ? $campaignFilter : '';

        $hasFilters = $search !== '' || $activeType !== '' || $activeStatus !== ''
            || $activeCampaign !== '' || $fromFilter !== '' || $toFilter !== '';

        // The current filter set as a query string, minus the status — the
        // chips replace that one and keep the rest.
        $keep = array_filter([
            'q' => $search,
            'type' => $activeType,
            'campaign' => $activeCampaign,
            'from' => $fromFilter,
            'to' => $toFilter,
        ], fn ($v) => $v !== '');

        $exportQuery = $activeStatus !== '' ? array_merge($keep, ['status' => $activeStatus]) : $keep;

        // Full literal class lists — never assembled from fragments, so
        // Tailwind still sees every class it has to compile.
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        // 'deferred' is not in the shared badge map — the provider accepted
        // nothing yet but did not refuse either, which is an amber state.
        $statusTones = [
            'sent' => 'kn-badge-green',
            'failed' => 'kn-badge-red',
            'bounced' => 'kn-badge-amber',
            'deferred' => 'kn-badge-amber',
        ];

        $typeTones = [
            'campaign' => 'kn-badge-blue',
            'automation' => 'kn-badge-blue',
            'transactional' => 'kn-badge-gray',
            'test' => 'kn-badge-gray',
            'reply' => 'kn-badge-gray',
        ];

        $typeLabels = [
            'campaign' => 'Campaign',
            'automation' => 'Automation',
            'transactional' => 'Transactional',
            'test' => 'Test',
            'reply' => 'Reply',
        ];

        $statusLabels = [
            'sent' => 'Sent',
            'failed' => 'Failed',
            'bounced' => 'Bounced',
            'deferred' => 'Deferred',
        ];

        $matched = $logs->total();
        $chipTotal = (int) $statusCounts->sum();

        $subtitle = $hasFilters
            ? number_format($matched).' '.\Illuminate\Support\Str::plural('send', $matched).' match these filters'
            : ($matched === 0
                ? 'Nothing has been sent from this account yet'
                : number_format($matched).' '.\Illuminate\Support\Str::plural('send', $matched).' recorded');

        // ?page=999 over a log that has 40 rows is not an empty log, and
        // saying "nothing has been sent" under a pager is the screen
        // contradicting itself.
        $pastTheEnd = $logs->isEmpty() && $logs->currentPage() > 1;

        $canViewCampaign = auth()->user()?->hasPermission('campaigns.view');
    @endphp

    <x-page-header title="Email Logs" :subtitle="$subtitle">
        <x-slot name="actions">
            @if ($matched > 0)
                <a href="{{ route('logs.export', $exportQuery) }}" class="kn-btn-secondary">
                    Export {{ $hasFilters ? 'these ' : '' }}{{ number_format($matched) }} {{ \Illuminate\Support\Str::plural('row', $matched) }} (CSV)
                </a>
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ status chips --}}
    @if ($chipTotal > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('logs.index', $keep) }}"
               class="{{ $chipBase }} {{ $activeStatus === '' ? $chipOn : $chipOff }}">
                All attempts
                <span class="{{ $activeStatus === '' ? $chipCountOn : $chipCountOff }}">{{ number_format($chipTotal) }}</span>
            </a>

            @foreach ($statuses as $status)
                @continue (! $statusCounts->has($status))
                @php $isOn = $activeStatus === $status; @endphp

                <a href="{{ route('logs.index', array_merge($keep, ['status' => $status])) }}"
                   class="{{ $chipBase }} {{ $isOn ? $chipOn : $chipOff }}">
                    {{ $statusLabels[$status] ?? ucfirst($status) }}
                    <span class="{{ $isOn ? $chipCountOn : $chipCountOff }}">
                        {{ number_format((int) $statusCounts[$status]) }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- ---------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('logs.index') }}" class="kn-card mb-5">
        @if ($activeStatus !== '')
            <input type="hidden" name="status" value="{{ $activeStatus }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <label for="q" class="kn-label">Search</label>
                <x-text-input name="q" id="q" :value="$search"
                              placeholder="Recipient address, sender address or subject" />
            </div>

            <div>
                <label for="type" class="kn-label">Type</label>
                <select name="type" id="type" class="kn-select">
                    <option value="">Any type</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($activeType === $type)>
                            {{ $typeLabels[$type] ?? ucfirst($type) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="campaign" class="kn-label">Campaign</label>
                <select name="campaign" id="campaign" class="kn-select">
                    <option value="">Any campaign</option>
                    @foreach ($campaigns as $campaign)
                        <option value="{{ $campaign->id }}" @selected($activeCampaign === (string) $campaign->id)>
                            {{ $campaign->name }}@if ($campaign->trashed()) (deleted)@endif
                        </option>
                    @endforeach
                </select>
                @if ($campaignTotal > $campaigns->count())
                    <p class="mt-1 text-xs text-ink-500">
                        The {{ number_format($campaigns->count()) }} most recent of
                        {{ number_format($campaignTotal) }} campaigns.
                    </p>
                @endif
            </div>

            <div>
                <label for="from" class="kn-label">From date</label>
                <x-text-input type="date" name="from" id="from" :value="$fromFilter" />
            </div>

            <div>
                <label for="to" class="kn-label">To date</label>
                <x-text-input type="date" name="to" id="to" :value="$toFilter" />
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
                @if ($hasFilters)
                    <a href="{{ route('logs.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>

        <div class="border-t border-ink-100 px-4 py-2.5 text-xs text-ink-500">
            Dates are read in {{ $zone }}, the account time zone, and both ends are included.
            Campaign sends and automation sends are what the app records today — the other
            types exist in the log for mail sent through it later, so filtering on them now
            returns nothing.
        </div>
    </form>

    {{-- ------------------------------------------------------------ table --}}
    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th class="min-w-[13rem]">Recipient</th>
                        <th class="min-w-[14rem]">Subject</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="min-w-[9rem]">SMTP account</th>
                        <th class="min-w-[10rem]">Campaign</th>
                        <th class="min-w-[9rem]">When</th>
                        <th class="text-right">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            $succeeded = $log->status === 'sent';

                            // sent_at is only stamped on success, so a failed
                            // attempt has to show when it was tried instead of
                            // an empty cell that reads like "never happened".
                            $stamp = $log->sent_at ?? $log->created_at;
                            $stampLabel = $log->sent_at ? 'Sent' : 'Attempted';
                            $local = $stamp?->copy()->setTimezone($zone);

                            $campaign = $log->campaign;
                            $campaignGone = $campaign === null && $log->campaign_id === null
                                && $log->type === 'campaign';
                        @endphp

                        <tr>
                            {{-- recipient --}}
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    <a href="{{ route('logs.show', $log) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600"
                                       title="{{ $log->recipient_email }}">
                                        {{ $log->recipient_email }}
                                    </a>
                                    @if ($log->sender_email)
                                        <span class="block truncate text-xs text-ink-500"
                                              title="Sent from {{ $log->sender_email }}">
                                            from {{ $log->sender_email }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- subject --}}
                            <td>
                                <div class="min-w-0 max-w-sm">
                                    @if (filled($log->subject))
                                        <span class="block truncate text-ink-700" title="{{ $log->subject }}">
                                            {{ $log->subject }}
                                        </span>
                                    @else
                                        <span class="text-xs text-ink-400">No subject recorded</span>
                                    @endif

                                    @unless ($succeeded)
                                        @if (filled($log->error))
                                            <span class="mt-0.5 block truncate text-xs text-red-600"
                                                  title="{{ $log->error }}">
                                                {{ \Illuminate\Support\Str::limit($log->error, 90) }}
                                            </span>
                                        @else
                                            <span class="mt-0.5 block text-xs text-ink-400">
                                                No reason was recorded
                                            </span>
                                        @endif
                                    @endunless
                                </div>
                            </td>

                            {{-- type --}}
                            <td>
                                <span class="{{ $typeTones[$log->type] ?? 'kn-badge-gray' }}">
                                    {{ $typeLabels[$log->type] ?? ucfirst((string) $log->type) }}
                                </span>
                            </td>

                            {{-- status --}}
                            <td>
                                <span class="{{ $statusTones[$log->status] ?? 'kn-badge-gray' }}">
                                    {{ $statusLabels[$log->status] ?? ucfirst((string) $log->status) }}
                                </span>
                            </td>

                            {{-- smtp account --}}
                            <td>
                                @if ($log->smtpAccount)
                                    <span class="block truncate text-ink-700" title="{{ $log->smtpAccount->name }}">
                                        {{ $log->smtpAccount->name }}
                                    </span>
                                    @if ($log->smtpAccount->trashed())
                                        <span class="block text-xs text-ink-400">Removed since</span>
                                    @endif
                                @else
                                    {{-- Nothing recorded the connection: the attempt failed
                                         before one was chosen, or the row predates it. --}}
                                    <span class="text-xs text-ink-400">Not recorded</span>
                                @endif
                            </td>

                            {{-- campaign --}}
                            <td>
                                @if ($campaign && ! $campaign->trashed() && $canViewCampaign)
                                    <a href="{{ route('campaigns.show', $campaign->id) }}"
                                       class="block truncate text-brand-600 hover:text-brand-700"
                                       title="{{ $campaign->name }}">
                                        {{ $campaign->name }}
                                    </a>
                                @elseif ($campaign)
                                    <span class="block truncate text-ink-700" title="{{ $campaign->name }}">
                                        {{ $campaign->name }}
                                    </span>
                                    @if ($campaign->trashed())
                                        <span class="block text-xs text-ink-400">Deleted campaign</span>
                                    @endif
                                @elseif ($campaignGone)
                                    <span class="text-xs text-ink-400">Campaign no longer exists</span>
                                @elseif ($log->type === 'automation')
                                    <span class="text-xs text-ink-400">Sent by an automation</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>

                            {{-- when --}}
                            <td class="whitespace-nowrap">
                                <span class="block text-[11px] font-medium uppercase tracking-wide text-ink-400">
                                    {{ $stampLabel }}
                                </span>
                                @if ($local)
                                    <span class="block text-xs text-ink-600"
                                          title="{{ $local->format('D, d M Y H:i:s') }} ({{ $zone }})">
                                        {{ $stamp->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="block text-xs text-ink-400">No time recorded</span>
                                @endif
                            </td>

                            {{-- details --}}
                            <td class="text-right">
                                <a href="{{ route('logs.show', $log) }}" class="kn-btn-ghost kn-btn-sm">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                @if ($pastTheEnd)
                                    <x-empty-state title="Nothing on this page"
                                                   message="This log has fewer pages than that. Go back to the first page to see the most recent attempts.">
                                        <x-slot name="action">
                                            <a href="{{ route('logs.index', $exportQuery) }}" class="kn-btn-secondary">
                                                First page
                                            </a>
                                        </x-slot>
                                    </x-empty-state>
                                @elseif ($hasFilters && $accountHasLogs)
                                    <x-empty-state title="No sends match these filters"
                                                   message="Nothing in the log matches that search, type, campaign or date range. Clear the filters to see every attempt.">
                                        <x-slot name="action">
                                            <a href="{{ route('logs.index') }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No email has been sent from this account yet"
                                                   message="A row lands here every time this account attempts a send — one per recipient of a campaign, and one for every email an automation sends. Successes and failures are both recorded, with the SMTP account used and the server's reply, so nothing here is written by hand.">
                                        <x-slot name="action">
                                            @permission('campaigns.view')
                                                <a href="{{ route('campaigns.index') }}" class="kn-btn-primary">Go to campaigns</a>
                                            @endpermission
                                            @permission('automation.view')
                                                @if (\Illuminate\Support\Facades\Route::has('automations.index'))
                                                    <a href="{{ route('automations.index') }}" class="kn-btn-secondary">Automations</a>
                                                @endif
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

        @if ($logs->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $logs->links() }}</div>
        @endif
    </div>
</x-app-layout>
