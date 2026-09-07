<x-app-layout>
    <x-slot name="header">{{ $campaign->name }}</x-slot>

    @php
        /**
         * One campaign's report.
         *
         * The whole point of this screen is that it does not flatter the
         * sender: every rate prints its own denominator, the open rate never
         * appears without the machine share next to it, and a campaign that
         * has not sent anything says so instead of showing a wall of 0%.
         */
        $sent = (int) $summary['sent'];
        $recipientTotal = (int) $summary['recipients'];
        $opens = (int) $summary['opens'];
        $clicks = (int) $summary['clicks'];
        $uniqueOpens = (int) $summary['unique_opens'];
        $uniqueClicks = (int) $summary['unique_clicks'];

        $hasStarted = $campaign->started_at !== null;

        // Sent as a share of the frozen recipient list — a different question
        // from delivered_of_attempted, which ignores the rows never tried.
        $sentShare = $recipientTotal > 0 ? round($sent / $recipientTotal * 100, 1) : 0.0;

        // Engagement is worth a panel when there is anything at all behind it.
        // Gating on sent alone would hide real recorded events on a campaign
        // whose counters have drifted — which is exactly when someone opens
        // this page and reaches for Recalculate.
        $hasEngagement = $sent > 0 || $opens > 0 || $clicks > 0;

        // ---------------------------------------------------------- filters
        $recipientStatuses = [
            'pending' => 'Pending — waiting in the queue',
            'queued' => 'Queued — handed to a worker',
            'sending' => 'Sending right now',
            'sent' => 'Sent — accepted by the server',
            'failed' => 'Failed — refused before delivery',
            'bounced' => 'Bounced — returned after acceptance',
            'skipped' => 'Skipped — never mailed',
        ];

        $engagementOptions = [
            'opened' => 'Opened at least once',
            'clicked' => 'Clicked at least once',
            'unopened' => 'Sent but never opened',
            'unsubscribed' => 'Unsubscribed',
            'bounced' => 'Bounced',
        ];

        $search = (string) ($filters['q'] ?? '');
        $statusFilter = (string) ($filters['status'] ?? '');
        $engagementFilter = (string) ($filters['engagement'] ?? '');

        // CampaignAnalytics::recipients() matches engagement against a fixed
        // list and ignores anything else, so ?engagement=nonsense narrows
        // nothing. Reporting it as a filter anyway would put "matching" in the
        // heading, offer a Clear button and ride along in the export URL, all
        // for a list that was never filtered. An unrecognised *status* is not
        // dropped the same way — that one really is applied, and really does
        // return no rows.
        if ($engagementFilter !== '' && ! isset($engagementOptions[$engagementFilter])) {
            $engagementFilter = '';
        }

        // Only the filters that are actually set, so the export URL and the
        // "clear" link carry exactly what is on screen and nothing else.
        $activeFilters = array_filter([
            'q' => $search,
            'status' => $statusFilter,
            'engagement' => $engagementFilter,
        ], fn ($value) => filled($value));

        $hasFilters = $activeFilters !== [];

        // A stored zone can outlive its tz-database entry, and this page must
        // not 500 over a label. The fallbacks mirror CampaignAnalytics exactly:
        // a blank zone buckets in the app timezone, but an unrecognised one
        // falls through utcOffset()'s catch and buckets at UTC — so the caption
        // has to say UTC there, or it names a zone the figures were never
        // grouped by.
        $knownZones = array_flip(timezone_identifiers_list());
        $campaignZone = blank($campaign->timezone)
            ? config('app.timezone')
            : (isset($knownZones[$campaign->timezone]) ? $campaign->timezone : 'UTC');

        // TrackingRecorder treats a click as proof of a read: when a clicker
        // has no recorded pixel fetch it backfills first_opened_at and bumps
        // unique_opens. So unique opens can exceed the total pixel fetches, and
        // on an image-blocked audience "Total opens 0 / Unique opens 4" is a
        // correct pair of numbers that looks like a bug unless it is explained.
        $impliedOpens = $uniqueOpens > $opens;

        // ------------------------------------------------------------ opens
        $sources = collect($openSources)->map(fn ($count) => (int) $count);
        $sourceTotal = (int) $sources->sum();

        $sourceLabels = [
            'reader' => 'Ordinary mail client',
            'gmail-proxy' => "Gmail's image proxy",
            'yahoo-proxy' => "Yahoo Mail's image proxy",
            'security-scanner' => 'Security scanner',
            'link-scanner' => 'Link preview scanner',
        ];

        $sourceNotes = [
            'reader' => 'Not identified as a proxy. Apple Mail Privacy Protection is invisible to this check, so some of these are machines too.',
            'gmail-proxy' => 'Google fetches the pixel when it caches images, whether or not the reader looked.',
            'yahoo-proxy' => 'Yahoo caches images the same way Gmail does.',
            'security-scanner' => 'A filtering appliance opened the message before the recipient could.',
            'link-scanner' => 'A preview or safety bot fetched the message content.',
        ];

        // ----------------------------------------------------------- timeline
        $unit = ($timeline['unit'] ?? 'day') === 'hour' ? 'hour' : 'day';
        $timelineLabels = (array) ($timeline['labels'] ?? []);
        $timelineOpens = array_map('intval', (array) ($timeline['opens'] ?? []));
        $timelineClicks = array_map('intval', (array) ($timeline['clicks'] ?? []));

        $hasTimeline = $timelineLabels !== [] && (array_sum($timelineOpens) + array_sum($timelineClicks)) > 0;

        // The buckets arrive as 'Y-m-d H:00' or 'Y-m-d' straight from SQL;
        // rescue() keeps a malformed one from taking the page down with it.
        $chartLabels = array_map(
            fn ($label) => rescue(
                fn () => \Illuminate\Support\Carbon::parse($label)->format($unit === 'hour' ? 'j M H:i' : 'j M'),
                (string) $label,
                false
            ),
            $timelineLabels
        );

        // Bars, not a line, for two reasons that both matter here.
        //
        // 1. CampaignAnalytics::timeline() does not zero-fill (unlike
        //    accountTimeline), so the buckets are only the ones that recorded
        //    something. A line — smoothed at tension 0.35 by renderCharts —
        //    would draw a curve straight across a fortnight of silence and
        //    invent a trend that the data does not contain.
        // 2. renderCharts() gives line datasets pointRadius: 0, so a campaign
        //    whose events all land in one bucket — which is every campaign for
        //    the first hour after it sends — renders an empty plot area. Bars
        //    are visible at any bucket count.
        $timelineChart = [
            'type' => 'bar',
            'labels' => $chartLabels,
            'datasets' => [
                ['label' => 'Opens', 'data' => $timelineOpens],
                ['label' => 'Clicks', 'data' => $timelineClicks, 'color' => '#0ea5e9'],
            ],
        ];

        // -------------------------------------------------------------- links
        // Someone who clicked two links counts once in each, so the shares add
        // up to the link total rather than to the campaign's unique clickers.
        $linkUniqueTotal = (int) $links->sum('unique_click_count');

        // CampaignAnalytics::topLinks() takes the best 20 by unique clicks and
        // stops. A campaign with 40 links therefore hands this view 20 rows,
        // and calling that "20 tracked links" states a total the page has not
        // been given. At the cap it says "top 20" instead.
        $linkCap = 20;
        $linksCapped = $links->count() >= $linkCap;

        $sectionHeading = 'mb-3 flex flex-wrap items-baseline justify-between gap-2';
        $sectionTitle = 'text-xs font-semibold uppercase tracking-wide text-ink-500';
        $sectionNote = 'text-xs text-ink-500';

        $canSeeContacts = (bool) auth()->user()?->hasPermission('contacts.view');
    @endphp

    <x-page-header :title="$campaign->name"
                   :subtitle="$campaign->subject ?: 'No subject line'"
                   :back="route('analytics.index')">
        <x-slot name="actions">
            <x-status-badge :status="$campaign->status" />

            @permission('campaigns.view')
                <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-secondary">Open campaign</a>
            @endpermission

            <a href="{{ route('analytics.export', array_merge(['campaign' => $campaign->id], $activeFilters)) }}"
               class="kn-btn-secondary">
                Export CSV{{ $hasFilters ? ' (filtered)' : '' }}
            </a>

            <x-confirm-form :action="route('analytics.rebuild', $campaign)"
                            method="POST"
                            label="Recalculate"
                            button-class="kn-btn-ghost"
                            :message="'Recalculate the totals for '.$campaign->name.'? This recounts the opens and clicks already recorded and writes the result back into the counters. It sends nothing, deletes nothing, and changes no history — only the summary figures on this page can move.'" />
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ nothing sent.
         Said once, at the top, plainly. The delivery figures below still
         render, because "0 sent, 12 failed" is the whole story in that case
         and hiding it would be worse than a row of zeros. --}}
    @if ($sent === 0)
        <div class="kn-card mb-6 border-amber-200">
            <div class="kn-card-header border-amber-200 bg-amber-50">
                <h2 class="text-sm font-semibold text-amber-900">Nothing has been delivered yet</h2>
                <span class="text-xs text-amber-700">{{ $hasStarted ? 'Sending started' : 'Sending has not started' }}</span>
            </div>
            <div class="p-5 text-sm text-ink-700">
                <p>
                    Not one message has been accepted by a receiving server, so there is no open rate, no click
                    rate and no engagement to report — every rate on this page divides by sent, and sent is zero.
                    @if ($summary['failed'] > 0 || $summary['bounced'] > 0)
                        What did happen is below: {{ number_format((int) $summary['failed']) }} refused
                        and {{ number_format((int) $summary['bounced']) }} bounced.
                    @elseif ($recipientTotal > 0)
                        {{ number_format($recipientTotal) }} recipient row(s) exist and are still waiting.
                    @else
                        No recipient rows exist yet either — they are written the moment sending starts.
                    @endif
                </p>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------ tracking off.
         With tracking disabled the engagement numbers are structurally zero,
         not evidence that nobody read it. --}}
    @if (! $campaign->track_opens || ! $campaign->track_clicks)
        <p class="mb-6 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
            @if (! $campaign->track_opens && ! $campaign->track_clicks)
                Open and click tracking were both off for this campaign.
            @elseif (! $campaign->track_opens)
                Open tracking was off for this campaign.
            @else
                Click tracking was off for this campaign.
            @endif
            Those figures below are zero because nothing was ever recorded — not because nobody engaged.
        </p>
    @endif

    {{-- Split test (spec 10.4). Renders nothing unless this campaign is one.
         Placed above delivery because "which version" changes how every figure
         below it should be read. --}}
    @include('campaigns.partials.ab-results')

    {{-- =========================================================== delivery --}}
    <section class="mb-8">
        <div class="{{ $sectionHeading }}">
            <h2 class="{{ $sectionTitle }}">Delivery</h2>
            <p class="{{ $sectionNote }}">What happened between this campaign and the receiving mail servers.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <x-stat-card label="Recipients"
                         :value="number_format($recipientTotal)"
                         :meta="$hasStarted
                            ? 'Rows frozen when sending started'
                            : 'Rows are written when sending starts'" />

            <x-stat-card label="Sent"
                         :value="number_format($sent)"
                         :meta="$recipientTotal > 0
                            ? 'Accepted by the receiving server — '.$sentShare.'% of the recipient list'
                            : 'Accepted by the receiving server; bounces are not counted here'" />

            <x-stat-card label="Bounced"
                         :value="number_format((int) $summary['bounced'])"
                         :tone="$summary['bounced'] > 0 ? 'warning' : 'default'"
                         :meta="number_format((int) $summary['bounced_hard']).' hard · '
                            .number_format((int) $summary['bounced_soft']).' soft — '
                            .$summary['bounce_rate'].'% of sent + bounced'" />

            <x-stat-card label="Failed"
                         :value="number_format((int) $summary['failed'])"
                         :tone="$summary['failed'] > 0 ? 'danger' : 'default'"
                         :meta="$summary['failure_rate'].'% of all recipients — refused before delivery'" />

            <x-stat-card label="Skipped"
                         :value="number_format((int) $summary['skipped'])"
                         :meta="'Never mailed — unsubscribed, suppressed or cancelled'" />

            <x-stat-card label="Delivered"
                         :value="$summary['delivered_of_attempted'].'%'"
                         :meta="'Sent ÷ (sent + bounced + failed)'" />
        </div>
    </section>

    {{-- ========================================================= engagement --}}
    <section class="mb-8">
        <div class="{{ $sectionHeading }}">
            <h2 class="{{ $sectionTitle }}">Engagement</h2>
            <p class="{{ $sectionNote }}">Every rate here divides by sent, and sent already excludes bounces.</p>
        </div>

        @if (! $hasEngagement)
            <div class="kn-card">
                <x-empty-state title="No engagement to report"
                               message="Nothing has been delivered, so there is nothing to open or click. Opens and clicks start being recorded from the first accepted message onwards." />
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <x-stat-card label="Unique opens"
                             :value="number_format($uniqueOpens)"
                             :meta="'Open rate '.$summary['open_rate'].'% — unique opens ÷ '.number_format($sent).' sent'" />

                <x-stat-card label="Unique clicks"
                             :value="number_format($uniqueClicks)"
                             :meta="'Click rate '.$summary['click_rate'].'% — unique clicks ÷ '.number_format($sent).' sent'" />

                <x-stat-card label="Click to open"
                             :value="$summary['click_to_open_rate'].'%'"
                             :meta="number_format($uniqueClicks).' unique clicks ÷ '.number_format($uniqueOpens).' unique opens'" />

                <x-stat-card label="Total opens"
                             :value="number_format($opens)"
                             meta="Every pixel fetch, repeats and machines included" />

                <x-stat-card label="Total clicks"
                             :value="number_format($clicks)"
                             meta="Every click, including the same person twice" />
            </div>

            {{-- ------------------------------------------- the open caveat.
                 Directly under the open rate, at full size, not in a tooltip:
                 an open rate read without this is read wrongly. --}}
            <div class="kn-card mt-4">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">What that open rate actually measures</h3>
                    <span class="text-xs text-ink-500">{{ number_format($opens) }} open event(s) recorded</span>
                </div>

                <div class="grid gap-6 p-5 lg:grid-cols-2">
                    <div class="space-y-3">
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                            <p class="kn-stat-label text-amber-800">Opens from a machine</p>
                            <p class="mt-0.5 text-2xl font-semibold text-amber-900">
                                {{ number_format((int) $summary['machine_opens']) }}
                                <span class="text-base font-medium">({{ $summary['machine_open_share'] }}% of all opens)</span>
                            </p>
                            <p class="mt-1 text-xs text-amber-800">
                                Fetched by a proxy or scanner we could identify by its user agent, not by a person.
                            </p>
                        </div>

                        <p class="text-sm text-ink-600">
                            An open is a pixel fetch, and that is all it is. It <span class="font-medium text-ink-800">undercounts</span>,
                            because most mail clients block remote images until the reader allows them, and it
                            <span class="font-medium text-ink-800">overcounts</span>, because Apple Mail Privacy Protection and
                            Gmail's image proxy fetch that pixel whether or not anyone looked at the message.
                        </p>

                        <p class="text-sm text-ink-600">
                            The {{ number_format((int) $summary['machine_opens']) }} machine fetch(es) above are only the ones
                            that identified themselves. Apple's proxy does not, so it is counted below as an ordinary client.
                            Treat the open rate as a soft ceiling on attention, and the click rate — where a person had to act —
                            as the number to trust.
                        </p>

                        <p class="text-sm text-ink-600">
                            A click also counts as an open. Someone who clicked with images blocked left no pixel fetch,
                            so they are recorded as an opener anyway — which is why
                            <span class="font-medium text-ink-800">unique opens can be higher than total opens</span>
                            @if ($impliedOpens)
                                and is on this campaign: {{ number_format($uniqueOpens) }} unique opener(s) against
                                {{ number_format($opens) }} pixel fetch(es). The split on the right covers the pixel
                                fetches only, so it does not add up to the unique figure.
                            @else
                                on a campaign whose audience blocks images. The split on the right covers pixel fetches
                                only.
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="kn-stat-label mb-2">Where the opens came from</p>

                        @if ($sourceTotal === 0)
                            <p class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                                No open events have been recorded yet, so there is nothing to split.
                            </p>
                        @else
                            <ul class="space-y-3">
                                @foreach ($sources as $source => $count)
                                    @php
                                        $key = (string) $source;
                                        $label = $sourceLabels[$key] ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $key));
                                        $note = $sourceNotes[$key] ?? 'Identified as an automated fetcher by its user agent.';
                                        $share = $sourceTotal > 0 ? round($count / $sourceTotal * 100, 1) : 0.0;
                                        $isReader = $key === 'reader';
                                    @endphp
                                    <li>
                                        <div class="flex items-baseline justify-between gap-3 text-sm">
                                            <span class="min-w-0 truncate font-medium text-ink-800">{{ $label }}</span>
                                            <span class="shrink-0 text-ink-600">
                                                {{ number_format($count) }}
                                                <span class="text-xs text-ink-500">· {{ $share }}%</span>
                                            </span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-ink-200">
                                            <div class="h-full rounded-full {{ $isReader ? 'bg-brand-600' : 'bg-amber-500' }}"
                                                 style="width: {{ $share }}%"></div>
                                        </div>
                                        <p class="mt-1 text-xs text-ink-500">{{ $note }}</p>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="mt-3 text-xs text-ink-500">
                                Shares are of all {{ number_format($sourceTotal) }} recorded open event(s), repeats included —
                                not of the {{ number_format($uniqueOpens) }} unique opener(s) the rate above uses.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </section>

    {{-- =========================================================== outcomes --}}
    <section class="mb-8">
        <div class="{{ $sectionHeading }}">
            <h2 class="{{ $sectionTitle }}">Outcomes</h2>
            <p class="{{ $sectionNote }}">What the send cost you, and what came back.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-stat-card label="Unsubscribes"
                         :value="number_format((int) $summary['unsubscribes'])"
                         :tone="$summary['unsubscribe_rate'] >= 1 ? 'warning' : 'default'"
                         :meta="$summary['unsubscribe_rate'].'% — unsubscribes ÷ '.number_format($sent).' sent'" />

            <x-stat-card label="Replies"
                         :value="number_format((int) $summary['replies'])"
                         :meta="$summary['reply_rate'].'% — replies ÷ '.number_format($sent).' sent, when something records one'" />
        </div>

        {{--
            Replies ARE counted now — but only for mail that lands in a mailbox
            this application polls. With none connected the figure is a
            structural zero, and a structural zero read as "nobody replied" is
            exactly the misreading this screen exists to prevent. So the note
            says which of the two it is.
        --}}
        @if ($hasMailbox)
            <p class="mt-3 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-xs text-ink-600">
                <span class="font-medium text-ink-800">How replies are counted.</span>
                Each message goes out with its own Message-ID, so a reply carrying it is matched to the exact
                contact who wrote back. When a mail client strips those headers, matching falls back on the
                sender's address plus a matching subject, which is a judgement and can occasionally be wrong.
                Only mail arriving in a connected mailbox can be counted at all — a reply sent to an address
                this application does not poll is invisible to it.
                @if (\Illuminate\Support\Facades\Route::has('campaign-replies.index'))
                    <a href="{{ route('campaign-replies.index') }}" class="font-medium text-brand-600 hover:text-brand-700">See the replies</a>.
                @endif
            </p>
        @else
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                <span class="font-medium">No mailbox is connected, so replies cannot be counted.</span>
                Every message carries the headers needed to match a reply back to the contact who sent it, but
                nothing can read those replies until a mailbox is connected. Read this figure as
                &ldquo;not measured&rdquo;, not as &ldquo;nobody replied&rdquo;.
                @if (\Illuminate\Support\Facades\Route::has('mailboxes.index'))
                    <a href="{{ route('mailboxes.index') }}" class="font-medium underline">Connect a mailbox</a>.
                @endif
            </p>
        @endif
    </section>

    {{-- ============================================================== chart --}}
    <div class="kn-card mb-6">
        <div class="kn-card-header">
            <h2 class="text-sm font-semibold text-ink-900">Opens and clicks over time</h2>
            <span class="text-xs text-ink-500">
                Grouped by {{ $unit }}{{ $unit === 'hour' ? ' (campaigns under two days old)' : '' }}
            </span>
        </div>

        @if (! $hasTimeline)
            <x-empty-state title="No events on the clock yet"
                           :message="$sent === 0
                                ? 'Nothing has been delivered, so no open or click has been timestamped. The chart fills in as events arrive.'
                                : 'Messages have gone out, but no open or click has been recorded yet. The chart fills in as events arrive.'" />
        @else
            <div class="p-5">
                <div class="mb-3 flex flex-wrap items-center gap-4 text-xs text-ink-600">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-brand-600"></span> Opens
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-sky-500"></span> Clicks
                    </span>
                </div>

                <div class="h-64">
                    <canvas data-chart='@json($timelineChart)'
                            aria-label="Opens and clicks per {{ $unit }} for this campaign"
                            role="img"></canvas>
                </div>

                <p class="mt-3 text-xs text-ink-500">
                    Each pair of bars is one {{ $unit }} in the campaign's own time zone ({{ $campaignZone }}).
                    Only {{ $unit }}s that recorded something are drawn — a {{ $unit }} with nothing in it is
                    absent from the axis rather than shown as zero, so read the bars as a list of active
                    {{ $unit }}s and not as an evenly spaced timeline. Opens carry the same caveat as everywhere
                    else on this page: a share of them are machines.
                </p>
            </div>
        @endif
    </div>

    {{-- ============================================================== links --}}
    <div class="kn-card mb-6 overflow-hidden">
        <div class="kn-card-header">
            <h2 class="text-sm font-semibold text-ink-900">Links</h2>
            <span class="text-xs text-ink-500">
                @if ($linksCapped)
                    Top {{ number_format($linkCap) }} by unique clicks
                @else
                    {{ $links->count() === 1 ? '1 tracked link' : number_format($links->count()).' tracked links' }}
                @endif
            </span>
        </div>

        @if ($links->isEmpty())
            <x-empty-state title="No tracked links"
                           :message="$campaign->track_clicks
                                ? 'No clickable link was found in this campaign\'s content, so there is nothing to attribute clicks to.'
                                : 'Click tracking was off for this campaign, so its links were never rewritten and no click could be recorded.'" />
        @else
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            <th class="min-w-[18rem]">Link</th>
                            <th class="text-right">Unique clicks</th>
                            <th class="text-right">Total clicks</th>
                            <th class="min-w-[10rem]">Share of link clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($links as $link)
                            @php
                                $url = (string) $link->url;
                                // A url column holds whatever was in the campaign body.
                                // Only http(s) becomes a clickable anchor — a
                                // javascript: href here would be a click away from
                                // running in the reader's session.
                                $isWeb = \Illuminate\Support\Str::startsWith(\Illuminate\Support\Str::lower($url), ['http://', 'https://']);
                                $unique = (int) $link->unique_click_count;
                                $total = (int) $link->click_count;
                                $share = $linkUniqueTotal > 0 ? round($unique / $linkUniqueTotal * 100, 1) : 0.0;
                            @endphp
                            <tr>
                                <td>
                                    <div class="min-w-0 max-w-md">
                                        <span class="block truncate font-medium text-ink-900">
                                            {{ filled($link->label) ? $link->label : \Illuminate\Support\Str::limit($url, 70) }}
                                        </span>

                                        @if ($isWeb)
                                            <a href="{{ $url }}" target="_blank" rel="noopener nofollow"
                                               title="{{ $url }}"
                                               class="block truncate text-xs text-brand-600 hover:text-brand-700">
                                                {{ \Illuminate\Support\Str::limit($url, 80) }}
                                            </a>
                                        @else
                                            <span class="block truncate text-xs text-ink-500" title="{{ $url }}">
                                                {{ \Illuminate\Support\Str::limit($url, 80) }}
                                                <span class="text-ink-400">· not a web link, shown as text</span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right font-medium text-ink-900">{{ number_format($unique) }}</td>
                                <td class="text-right text-ink-700">{{ number_format($total) }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-full min-w-[5rem] overflow-hidden rounded-full bg-ink-200">
                                            <div class="h-full rounded-full bg-brand-600" style="width: {{ $share }}%"></div>
                                        </div>
                                        <span class="shrink-0 text-xs text-ink-500">{{ $share }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                Most clicked first. Share is of the {{ number_format($linkUniqueTotal) }} unique link click(s)
                counted across the links listed here. Someone who clicked two different links counts once in each,
                which is why this total can exceed the {{ number_format($uniqueClicks) }} unique clicker(s) the
                click rate uses.
                @if ($linksCapped)
                    Only the {{ number_format($linkCap) }} most-clicked links are listed; if the campaign contains
                    more, their clicks are counted in the click rate above but not in these shares.
                @endif
                Link addresses come from the campaign content — open them at your own discretion.
            </div>
        @endif
    </div>

    {{-- ========================================================= recipients --}}
    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h2 class="text-sm font-semibold text-ink-900">Recipients</h2>
            <span class="text-xs text-ink-500">
                {{ number_format($recipients->total()) }} {{ \Illuminate\Support\Str::plural('row', $recipients->total()) }}{{ $hasFilters ? ' matching' : '' }}
            </span>
        </div>

        {{-- filters: a plain GET form, so the URL is the state and the export
             above carries the same three values --}}
        <form method="GET" action="{{ route('analytics.campaign', $campaign) }}"
              class="grid gap-3 border-b border-ink-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="q" class="kn-label">Email address</label>
                <x-text-input name="q" id="q" :value="$search" placeholder="Search by email" />
            </div>

            <div>
                <label for="status" class="kn-label">Send status</label>
                <select name="status" id="status" class="kn-select">
                    <option value="">Any status</option>
                    @foreach ($recipientStatuses as $value => $label)
                        <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="engagement" class="kn-label">Engagement</label>
                <select name="engagement" id="engagement" class="kn-select">
                    <option value="">Everyone</option>
                    @foreach ($engagementOptions as $value => $label)
                        <option value="{{ $value }}" @selected($engagementFilter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
                @if ($hasFilters)
                    <a href="{{ route('analytics.campaign', $campaign) }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th class="min-w-[14rem]">Contact</th>
                        <th>Status</th>
                        <th class="text-right">Opens</th>
                        <th class="text-right">Clicks</th>
                        <th>First opened</th>
                        <th>First clicked</th>
                        <th class="min-w-[12rem]">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recipients as $recipient)
                        @php
                            $subscriber = $recipient->subscriber;
                            $openCount = (int) $recipient->open_count;
                            $clickCount = (int) $recipient->click_count;
                        @endphp
                        <tr>
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    @if ($subscriber && $canSeeContacts)
                                        <a href="{{ route('subscribers.show', $subscriber) }}"
                                           class="block truncate font-medium text-ink-900 hover:text-brand-600"
                                           title="{{ $recipient->email }}">
                                            {{ $recipient->email }}
                                        </a>
                                    @else
                                        <span class="block truncate font-medium text-ink-900" title="{{ $recipient->email }}">
                                            {{ $recipient->email }}
                                        </span>
                                    @endif

                                    @if (filled($recipient->name))
                                        <span class="block truncate text-xs text-ink-500">{{ $recipient->name }}</span>
                                    @endif
                                </div>
                            </td>

                            <td>
                                <x-status-badge :status="$recipient->status" />

                                @if ($recipient->sent_at)
                                    <span class="mt-1 block text-[11px] text-ink-400"
                                          title="{{ $recipient->sent_at->format('D, d M Y H:i') }}">
                                        {{ $recipient->sent_at->diffForHumans() }}
                                    </span>
                                @endif

                                @if ($recipient->unsubscribed_at)
                                    <span class="mt-1 block text-[11px] font-medium text-amber-700"
                                          title="{{ $recipient->unsubscribed_at->format('D, d M Y H:i') }}">
                                        Unsubscribed {{ $recipient->unsubscribed_at->diffForHumans() }}
                                    </span>
                                @endif
                            </td>

                            <td class="text-right">
                                @if ($openCount > 0)
                                    <span class="font-medium text-ink-900">{{ number_format($openCount) }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>

                            <td class="text-right">
                                @if ($clickCount > 0)
                                    <span class="font-medium text-ink-900">{{ number_format($clickCount) }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap">
                                @if ($recipient->first_opened_at)
                                    <span class="text-xs text-ink-600"
                                          title="{{ $recipient->first_opened_at->format('D, d M Y H:i') }}">
                                        {{ $recipient->first_opened_at->format('d M H:i') }}
                                    </span>
                                    @if ($recipient->last_opened_at && $openCount > 1)
                                        <span class="block text-[11px] text-ink-400"
                                              title="{{ $recipient->last_opened_at->format('D, d M Y H:i') }}">
                                            last {{ $recipient->last_opened_at->format('d M H:i') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap">
                                @if ($recipient->first_clicked_at)
                                    <span class="text-xs text-ink-600"
                                          title="{{ $recipient->first_clicked_at->format('D, d M Y H:i') }}">
                                        {{ $recipient->first_clicked_at->format('d M H:i') }}
                                    </span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>

                            <td>
                                @if ($recipient->bounced_at || filled($recipient->bounce_type))
                                    <span class="{{ $recipient->bounce_type === 'hard' ? 'kn-badge-red' : 'kn-badge-amber' }}">
                                        {{ $recipient->bounce_type === 'hard' ? 'Hard bounce' : ($recipient->bounce_type === 'soft' ? 'Soft bounce' : 'Bounced') }}
                                    </span>
                                @endif

                                @if (filled($recipient->error))
                                    <span class="mt-1 block max-w-xs truncate text-xs text-red-600"
                                          title="{{ $recipient->error }}">
                                        {{ \Illuminate\Support\Str::limit($recipient->error, 90) }}
                                    </span>
                                @elseif (! $recipient->bounced_at && ! filled($recipient->bounce_type))
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                @if ($hasFilters)
                                    <x-empty-state title="No recipients match this filter"
                                                   message="Nothing in this campaign matches that combination of address, send status and engagement.">
                                        <x-slot name="action">
                                            <a href="{{ route('analytics.campaign', $campaign) }}" class="kn-btn-secondary">
                                                Clear filters
                                            </a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No recipient rows yet"
                                                   message="The recipient list is written the moment sending starts, so this table fills in then — nothing is created while the campaign is a draft." />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($recipients->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $recipients->links() }}</div>
        @endif

        <div class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
            Sorted by clicks, then opens, then address — the people who did something appear first.
            "First opened" is the first pixel fetch recorded for that contact, which may be their mail provider
            rather than them — or, for a contact who clicked with images blocked, the moment they clicked, since a
            click is taken as proof of a read. "First clicked" is a deliberate act and can be read at face value.
        </div>
    </div>
</x-app-layout>
