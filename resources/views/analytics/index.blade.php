<x-app-layout>
    <x-slot name="header">Analytics</x-slot>

    @php
        /**
         * The account-wide report.
         *
         * Every figure on this page comes from CampaignAnalytics::accountSummary()
         * and ::accountTimeline(), and every one of them names its own
         * denominator — a percentage with no divisor is a number people
         * misquote in a meeting.
         *
         * "Sent" throughout means accepted by the receiving mail server. A
         * bounce increments bounced_count and never sent_count, so the sent
         * figure is already the delivered figure and the rates below are not
         * flattered by mail that never arrived.
         */
        $campaignCount = (int) ($summary['campaigns'] ?? 0);
        $sent = (int) ($summary['sent'] ?? 0);
        $uniqueOpens = (int) ($summary['unique_opens'] ?? 0);
        $uniqueClicks = (int) ($summary['unique_clicks'] ?? 0);
        $bounced = (int) ($summary['bounced'] ?? 0);
        $unsubscribed = (int) ($summary['unsubscribed'] ?? 0);

        // Nothing has ever been sent from this account — not "nothing in this
        // window". $recent carries no date filter, so an empty one means no
        // campaign has ever started, whichever window is selected.
        $neverSent = $sent === 0 && $recent->isEmpty();

        $canSeeCampaigns = (bool) auth()->user()?->hasPermission('campaigns.view');

        // Full literal class lists, never assembled from fragments, so Tailwind
        // still compiles every class this page can render.
        $windowBase = 'inline-flex items-center rounded-full px-3.5 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $windowOn = 'bg-brand-600 text-white ring-brand-600';
        $windowOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';

        // Y-m-d is the key the service groups by; it is not what a person reads.
        // Mapped in place, so the order the service built stays the order shown.
        $shortDay = function (string $day): string {
            return \Illuminate\Support\Carbon::hasFormat($day, 'Y-m-d')
                ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $day)->format('j M')
                : $day;
        };

        $chartLabels = array_map($shortDay, array_values($timeline['labels'] ?? []));

        // Dataset order fixes the colour: app.js gives dataset 0 the brand
        // colour, then #0ea5e9, then #10b981 — the swatches in the key below
        // are bg-brand-600 / bg-sky-500 / bg-emerald-500 to match exactly.
        $activityChart = [
            'type' => 'line',
            'labels' => $chartLabels,
            'datasets' => [
                ['label' => 'Sent', 'data' => array_values($timeline['sent'] ?? [])],
                ['label' => 'Opens', 'data' => array_values($timeline['opens'] ?? [])],
                ['label' => 'Clicks', 'data' => array_values($timeline['clicks'] ?? [])],
            ],
            'options' => ['interaction' => ['mode' => 'index', 'intersect' => false]],
        ];

        $chartKey = [
            ['Sent', 'bg-brand-600', 'Messages the receiving server accepted, by the day they went out'],
            ['Opens', 'bg-sky-500', 'Every tracking-pixel fetch, including repeats and machines'],
            ['Clicks', 'bg-emerald-500', 'Every tracked link click, including repeats'],
        ];

        $chartHasData = array_sum($activityChart['datasets'][0]['data'])
            + array_sum($activityChart['datasets'][1]['data'])
            + array_sum($activityChart['datasets'][2]['data']) > 0;

        $subtitle = $neverSent
            ? 'No campaign activity to report yet'
            : trim(($campaignCount === 1 ? '1 campaign' : number_format($campaignCount).' campaigns')
                .' active in the last '.$days.' days · '
                .number_format($sent).' '.\Illuminate\Support\Str::plural('email', $sent).' delivered');
    @endphp

    <x-page-header title="Analytics" :subtitle="$subtitle">
        <x-slot name="actions">
            @unless ($neverSent)
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Reporting window">
                    @foreach ($windows as $window)
                        @php $isCurrent = (int) $days === (int) $window; @endphp
                        <a href="{{ route('analytics.index', ['days' => $window]) }}"
                           aria-current="{{ $isCurrent ? 'page' : 'false' }}"
                           class="{{ $windowBase }} {{ $isCurrent ? $windowOn : $windowOff }}">
                            {{ $window }} days
                        </a>
                    @endforeach
                </div>
            @endunless

            @permission('campaigns.view')
                <a href="{{ route('campaigns.index') }}" class="kn-btn-secondary">Campaigns</a>
            @endpermission
        </x-slot>
    </x-page-header>

    @if ($neverSent)
        {{-- Six panels of 0% would look like a broken report rather than an
             account that has not started. --}}
        <div class="kn-card">
            <x-empty-state title="No campaign activity to report"
                           message="This dashboard covers campaigns that are sending, paused, completed or failed, and none of those has recorded a send. Opens, clicks, bounces and unsubscribes are recorded from the moment a campaign goes out, and every campaign also keeps its own report.">
                <x-slot name="action">
                    @permission('campaigns.create')
                        <a href="{{ route('campaigns.create') }}" class="kn-btn-primary">Create your first campaign</a>
                    @endpermission
                </x-slot>
            </x-empty-state>
        </div>
    @else

        {{-- ------------------------------------------------------------ stats.
             Each meta line names the denominator, because "27%" on its own is
             a number two people will read two different ways. --}}
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <x-stat-card label="Campaigns"
                         :value="number_format($campaignCount)"
                         :meta="'Sending, completed or paused in the last '.$days.' days'" />

            <x-stat-card label="Emails sent"
                         :value="number_format($sent)"
                         meta="Accepted by the receiving server — bounces are not counted here" />

            <x-stat-card label="Unique opens"
                         :value="number_format($uniqueOpens)"
                         :meta="$summary['open_rate'].'% of sent — contacts who opened or clicked at least once. See the caveat below.'" />

            <x-stat-card label="Unique clicks"
                         :value="number_format($uniqueClicks)"
                         :meta="$summary['click_rate'].'% of sent — contacts who clicked at least once'" />

            <x-stat-card label="Bounced"
                         :value="number_format($bounced)"
                         :tone="$bounced > 0 ? 'warning' : 'default'"
                         :meta="$summary['bounce_rate'].'% of everything accepted plus bounced'" />

            <x-stat-card label="Unsubscribed"
                         :value="number_format($unsubscribed)"
                         :tone="$unsubscribed > 0 ? 'warning' : 'default'"
                         :meta="$summary['unsubscribe_rate'].'% of sent'" />
        </div>

        {{-- ----------------------------------------------- the open-rate caveat.
             Printed next to the open figure, not hidden in a tooltip: it is the
             single most misread number in email. --}}
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm text-amber-900">
                <span class="font-semibold">An open is a one-pixel image fetch, not a person reading.</span>
                It undercounts, because most mail clients block remote images until the reader allows them,
                and it overcounts, because Apple Mail Privacy Protection and Gmail's image proxy fetch that
                pixel whether or not anyone looked.
            </p>
            @if (isset($summary['machine_opens'], $summary['machine_open_share']))
                <p class="mt-1.5 text-xs text-amber-800">
                    {{ number_format((int) $summary['machine_opens']) }} of the opens in this window
                    ({{ $summary['machine_open_share'] }}%) came from a proxy or scanner rather than a mail client.
                </p>
            @else
                <p class="mt-1.5 text-xs text-amber-800">
                    This account-wide figure does not separate machines from people. Open a single campaign's
                    report to see how many of its opens came from a proxy or a scanner. Clicks are the firmer
                    number — image proxies do not follow links — but they are not proof of a person either:
                    corporate link scanners visit every URL in a message, and a scanned click is counted here
                    as a click and, if the pixel never loaded, as that contact's first open.
                </p>
            @endif
        </div>

        {{-- ---------------------------------------------------------- activity --}}
        <div class="kn-card mb-6">
            <div class="kn-card-header">
                <h2 class="text-sm font-semibold text-ink-900">Activity per day</h2>
                <span class="text-xs text-ink-500">Last {{ $days }} days</span>
            </div>

            <div class="p-5">
                <div class="mb-4 flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($chartKey as [$label, $swatch, $explains])
                        <span class="inline-flex items-center gap-2 text-xs text-ink-600" title="{{ $explains }}">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $swatch }}"></span>
                            {{ $label }}
                        </span>
                    @endforeach
                </div>

                @if ($chartHasData)
                    {{-- An explicit height: Chart.js runs with maintainAspectRatio
                         off here, so a wrapper with no height renders nothing. --}}
                    <div class="h-72 sm:h-80">
                        <canvas data-chart='@json($activityChart)'
                                aria-label="Emails sent, opens and clicks per day over the last {{ $days }} days"
                                role="img"></canvas>
                    </div>
                @else
                    <x-empty-state title="No activity in this window"
                                   message="Nothing was sent, opened or clicked in these days. Widen the window above, or check back once a campaign is running." />
                @endif

                <p class="mt-4 text-xs text-ink-500">
                    Counted by the day each event was recorded, in {{ config('app.timezone') }} — quiet days are
                    drawn as zero rather than skipped. These are raw event counts, repeats included, and they
                    do not add up to the cards above: the Sent line counts every message logged as accepted
                    inside the window, whatever became of its campaign afterwards, while the card counts whole
                    campaigns that started inside it. The Opens line counts pixel fetches only, so a contact who
                    clicked without ever loading the pixel is in Unique opens above but not on this line.
                </p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">

            {{-- ------------------------------------------------------ leaderboard --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Best performing</h2>
                    <span class="text-xs text-ink-500">Completed, last {{ $days }} days</span>
                </div>

                @if ($leaderboard->isEmpty())
                    <x-empty-state title="Nothing finished in this window"
                                   message="Only campaigns that have completed and actually sent something are ranked here. A campaign still sending appears once it finishes." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th class="text-right">Sent</th>
                                    <th class="text-right">Open rate</th>
                                    <th class="text-right">Click rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leaderboard as $index => $campaign)
                                    <tr>
                                        <td>
                                            <div class="flex min-w-0 items-start gap-2">
                                                <span class="mt-0.5 shrink-0 text-xs font-semibold text-ink-400">{{ $index + 1 }}</span>
                                                <div class="min-w-0">
                                                    <a href="{{ route('analytics.campaign', $campaign) }}"
                                                       class="block max-w-[13rem] truncate font-medium text-ink-900 hover:text-brand-600">
                                                        {{ $campaign->name }}
                                                    </a>
                                                    <span class="block text-xs text-ink-500">
                                                        {{ $campaign->completed_at?->diffForHumans() ?? 'Completed' }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap text-right text-ink-700">
                                            {{ number_format((int) $campaign->sent_count) }}
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <span class="font-medium text-ink-900">{{ $campaign->openRate() }}%</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format((int) $campaign->unique_opens) }} unique
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            <span class="font-medium text-ink-900">{{ $campaign->clickRate() }}%</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format((int) $campaign->unique_clicks) }} unique
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                        Ranked by unique opens ÷ sent, the softest number on this page — read the caveat above
                        before treating the order as a verdict. The click rate underneath it is the firmer of
                        the two, because an image proxy fetches the pixel but does not follow the links.
                    </p>
                @endif
            </div>

            {{-- --------------------------------------------------------- recent --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Recent campaigns</h2>
                    <span class="text-xs text-ink-500">Latest {{ $recent->count() }}, newest first</span>
                </div>

                @if ($recent->isEmpty())
                    <x-empty-state title="No campaigns have started"
                                   message="Campaigns appear here once sending begins and stay while they are running, paused, finished or failed. Cancelled campaigns are not reported on." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th class="text-right">Sent</th>
                                    <th class="text-right">Opens</th>
                                    <th class="text-right">Clicks</th>
                                    <th class="text-right">Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recent as $campaign)
                                    @php
                                        $rowSent = (int) $campaign->sent_count;

                                        // A failed or paused campaign can have no
                                        // started_at at all, so "when" falls back
                                        // rather than printing an empty cell.
                                        $when = $campaign->started_at ?? $campaign->completed_at ?? $campaign->updated_at;
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="min-w-0">
                                                <a href="{{ route('analytics.campaign', $campaign) }}"
                                                   class="block max-w-[13rem] truncate font-medium text-ink-900 hover:text-brand-600">
                                                    {{ $campaign->name }}
                                                </a>
                                                <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <x-status-badge :status="$campaign->status" />
                                                    <span class="text-xs text-ink-500">
                                                        {{ $when?->diffForHumans() ?? 'Not started' }}
                                                    </span>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap text-right text-ink-700">
                                            {{ number_format($rowSent) }}
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            @if ($rowSent === 0)
                                                <span class="text-ink-400">—</span>
                                            @else
                                                <span class="font-medium text-ink-900">{{ number_format((int) $campaign->unique_opens) }}</span>
                                                <span class="block text-xs text-ink-500">{{ $campaign->openRate() }}% of sent</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap text-right">
                                            @if ($rowSent === 0)
                                                <span class="text-ink-400">—</span>
                                            @else
                                                <span class="font-medium text-ink-900">{{ number_format((int) $campaign->unique_clicks) }}</span>
                                                <span class="block text-xs text-ink-500">{{ $campaign->clickRate() }}% of sent</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <div class="flex flex-wrap justify-end gap-1.5">
                                                <a href="{{ route('analytics.campaign', $campaign) }}" class="kn-btn-ghost kn-btn-sm">Report</a>
                                                @if ($canSeeCampaigns)
                                                    <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-ghost kn-btn-sm">Campaign</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                        Opens and clicks here are unique contacts, both divided by sent — and sent already
                        excludes anything that bounced.
                    </p>
                @endif
            </div>
        </div>
    @endif
</x-app-layout>
