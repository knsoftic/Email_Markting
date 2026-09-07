<x-app-layout>
    <x-slot name="header">Campaigns</x-slot>

    @php
        /**
         * Status chips are driven by the grouped counts the controller passes,
         * so a status only appears once something is actually in it. "All" is
         * the sum of the same collection rather than a second query.
         */
        $activeStatus = (string) ($filters['status'] ?? '');
        $search = (string) ($filters['q'] ?? '');
        $hasFilters = $activeStatus !== '' || $search !== '';

        // Everything except the status, so a chip keeps the current search.
        $keepQuery = $search !== '' ? ['q' => $search] : [];

        $totalCampaigns = (int) $statusCounts->sum();

        $statusLabels = [
            'draft' => 'Drafts',
            'scheduled' => 'Scheduled',
            'queued' => 'Queued',
            'sending' => 'Sending',
            'paused' => 'Paused',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
        ];

        // Full literal class lists — never assembled from fragments, so Tailwind
        // still sees every class it has to compile.
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        // Timezones come off the row, so a stale value can never blow up
        // Carbon's setTimezone() mid-table.
        $knownZones = array_flip(timezone_identifiers_list());

        $subtitle = $totalCampaigns === 1
            ? '1 campaign in this account'
            : number_format($totalCampaigns).' campaigns in this account';
    @endphp

    <x-page-header title="Campaigns" :subtitle="$subtitle">
        <x-slot name="actions">
            <a href="{{ route('campaigns.scheduled') }}" class="kn-btn-ghost">Scheduled</a>

            @permission('templates.view')
                <a href="{{ route('templates.index') }}" class="kn-btn-secondary">Templates</a>
            @endpermission

            @permission('campaigns.create')
                <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">New campaign</a>
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ status chips --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('campaigns.index', $keepQuery) }}"
           class="{{ $chipBase }} {{ $activeStatus === '' ? $chipOn : $chipOff }}">
            All campaigns
            <span class="{{ $activeStatus === '' ? $chipCountOn : $chipCountOff }}">{{ number_format($totalCampaigns) }}</span>
        </a>

        @foreach ($statusLabels as $status => $label)
            @continue (! $statusCounts->has($status))
            @php $isOn = $activeStatus === $status; @endphp

            <a href="{{ route('campaigns.index', array_merge($keepQuery, ['status' => $status])) }}"
               class="{{ $chipBase }} {{ $isOn ? $chipOn : $chipOff }}">
                {{ $label }}
                <span class="{{ $isOn ? $chipCountOn : $chipCountOff }}">
                    {{ number_format((int) $statusCounts[$status]) }}
                </span>
            </a>
        @endforeach
    </div>

    {{-- ----------------------------------------------------------- search --}}
    <form method="GET" action="{{ route('campaigns.index') }}" class="kn-card mb-5">
        @if ($activeStatus !== '')
            <input type="hidden" name="status" value="{{ $activeStatus }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-3">
                <x-text-input name="q" :value="$search" placeholder="Search campaigns by name" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($hasFilters)
                    <a href="{{ route('campaigns.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------------------ table --}}
    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th class="min-w-[11rem]">Audience</th>
                        <th>Sent</th>
                        <th>Opens</th>
                        <th>Clicks</th>
                        <th>When</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        @php
                            $running = $campaign->isRunning();
                            $editable = $campaign->isEditable();

                            // Mirrors CampaignDispatcher::assertSendable() — the
                            // only statuses a send may still start from.
                            $sendable = in_array($campaign->status, ['draft', 'scheduled', 'paused', 'failed'], true);

                            $percent = $campaign->progressPercent();
                            $sent = (int) $campaign->sent_count;
                            $total = (int) $campaign->total_recipients;
                            $failed = (int) $campaign->failed_count;
                            $bounced = (int) $campaign->bounced_count;

                            $zone = isset($knownZones[$campaign->timezone])
                                ? $campaign->timezone
                                : config('app.timezone');

                            // What "when" means depends on where the campaign is
                            // in its life, so the column never shows a timestamp
                            // that has not happened yet as if it had.
                            $when = match ($campaign->status) {
                                'scheduled' => ['Goes out', $campaign->scheduled_at],
                                'queued', 'sending' => ['Started', $campaign->started_at ?? $campaign->updated_at],
                                'paused' => ['Paused', $campaign->paused_at ?? $campaign->updated_at],
                                'completed' => ['Finished', $campaign->completed_at ?? $campaign->updated_at],
                                'failed', 'cancelled' => ['Stopped', $campaign->completed_at ?? $campaign->updated_at],
                                default => ['Created', $campaign->created_at],
                            };
                            $whenAt = $when[1];
                            $whenIsSchedule = $campaign->status === 'scheduled';
                        @endphp

                        <tr>
                            {{-- name + subject --}}
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    <a href="{{ route('campaigns.show', $campaign) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $campaign->name }}
                                    </a>
                                    <span class="block truncate text-xs text-ink-500" title="{{ $campaign->subject }}">
                                        {{ $campaign->subject }}
                                    </span>
                                </div>
                            </td>

                            {{-- status --}}
                            <td><x-status-badge :status="$campaign->status" /></td>

                            {{-- audience size, or live progress while it runs --}}
                            <td>
                                @if ($running)
                                    <div class="min-w-[10rem]">
                                        <div class="flex items-center justify-between text-xs text-ink-500">
                                            <span class="font-semibold text-ink-700">{{ $percent }}%</span>
                                            <span>{{ number_format($campaign->remainingCount()) }} left</span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-ink-200">
                                            <div class="h-full rounded-full bg-brand-600" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @elseif ($total > 0)
                                    <span class="whitespace-nowrap text-ink-700">
                                        {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('contact', $total) }}
                                    </span>
                                @else
                                    {{-- A cancelled schedule never generated recipients, so
                                         "resolved at send time" would promise a send that
                                         can no longer happen. --}}
                                    <span class="text-xs text-ink-400">
                                        {{ $sendable ? 'Resolved at send time' : 'No recipients' }}
                                    </span>
                                @endif
                            </td>

                            {{-- sent --}}
                            <td class="whitespace-nowrap">
                                @if ($sent === 0 && $total === 0)
                                    <span class="text-ink-400">—</span>
                                @else
                                    <span class="font-medium text-ink-900">{{ number_format($sent) }}</span>
                                    <span class="block text-xs text-ink-500">of {{ number_format($total) }}</span>
                                    @if ($failed > 0 || $bounced > 0)
                                        <span class="mt-0.5 block text-xs text-red-600">
                                            @if ($failed > 0){{ number_format($failed) }} failed @endif
                                            @if ($failed > 0 && $bounced > 0)· @endif
                                            @if ($bounced > 0){{ number_format($bounced) }} bounced @endif
                                        </span>
                                    @endif
                                @endif
                            </td>

                            {{-- opens --}}
                            <td class="whitespace-nowrap">
                                @if ($sent === 0)
                                    <span class="text-ink-400">—</span>
                                @else
                                    <span class="font-medium text-ink-900">{{ $campaign->openRate() }}%</span>
                                    <span class="block text-xs text-ink-500"
                                          title="{{ number_format((int) $campaign->opened_count) }} opens in total">
                                        {{ number_format((int) $campaign->unique_opens) }} unique
                                    </span>
                                @endif
                            </td>

                            {{-- clicks --}}
                            <td class="whitespace-nowrap">
                                @if ($sent === 0)
                                    <span class="text-ink-400">—</span>
                                @else
                                    <span class="font-medium text-ink-900">{{ $campaign->clickRate() }}%</span>
                                    <span class="block text-xs text-ink-500"
                                          title="{{ number_format((int) $campaign->clicked_count) }} clicks in total">
                                        {{ number_format((int) $campaign->unique_clicks) }} unique
                                    </span>
                                @endif
                            </td>

                            {{-- when --}}
                            <td class="whitespace-nowrap">
                                <span class="block text-[11px] font-medium uppercase tracking-wide text-ink-400">{{ $when[0] }}</span>
                                @if ($whenAt)
                                    <span class="block text-xs text-ink-600"
                                          title="{{ $whenAt->copy()->setTimezone($zone)->format('D, d M Y H:i') }} ({{ $zone }})">
                                        @if ($whenIsSchedule)
                                            {{ $whenAt->copy()->setTimezone($zone)->format('d M Y H:i') }}
                                        @else
                                            {{ $whenAt->diffForHumans() }}
                                        @endif
                                    </span>
                                    @if ($whenIsSchedule)
                                        <span class="block text-[11px] text-ink-400">{{ $whenAt->diffForHumans() }}</span>
                                    @endif
                                @else
                                    <span class="block text-xs text-ink-400">Not set</span>
                                @endif
                            </td>

                            {{-- actions: only what this status actually allows --}}
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-ghost kn-btn-sm">View</a>

                                    @permission('campaigns.update')
                                        @if ($editable)
                                            <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>
                                        @endif
                                    @endpermission

                                    @permission('campaigns.send')
                                        @if ($running)
                                            {{-- The one control worth having in the row while
                                                 email is going out. --}}
                                            <form method="POST" action="{{ route('campaigns.pause', $campaign) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="kn-btn-secondary kn-btn-sm">Pause</button>
                                            </form>
                                        @elseif ($sendable)
                                            <a href="{{ route('campaigns.confirm', $campaign) }}" class="kn-btn-primary kn-btn-sm">
                                                Review &amp; send
                                            </a>
                                        @endif
                                    @endpermission

                                    @permission('campaigns.create')
                                        <form method="POST" action="{{ route('campaigns.duplicate', $campaign) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="kn-btn-ghost kn-btn-sm">Duplicate</button>
                                        </form>
                                    @endpermission

                                    @permission('campaigns.delete')
                                        {{-- Deleting mid-send is refused by the controller, so the
                                             button is not rendered at all rather than 422-ing. --}}
                                        @unless ($running)
                                            <x-confirm-form :action="route('campaigns.destroy', $campaign)"
                                                            label="Delete"
                                                            :message="'Delete '.$campaign->name.'? Its sending history stays in the logs, but the campaign is gone.'" />
                                        @endunless
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                @if ($hasFilters)
                                    <x-empty-state title="No campaigns match this search"
                                                   message="Nothing here matches that name or status. Clear the filters to see every campaign.">
                                        <x-slot name="action">
                                            <a href="{{ route('campaigns.index') }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No campaigns yet"
                                                   message="A campaign is one email sent to a chosen audience. Build the content, pick who receives it, then review the recipient count before anything goes out.">
                                        <x-slot name="action">
                                            @permission('campaigns.create')
                                                <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">Create campaign</a>
                                            @endpermission
                                            @permission('templates.view')
                                                <a href="{{ route('templates.index') }}" class="kn-btn-secondary">Start from a template</a>
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

        @if ($campaigns->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $campaigns->links() }}</div>
        @endif
    </div>
</x-app-layout>
