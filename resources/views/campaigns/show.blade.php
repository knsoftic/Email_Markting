<x-app-layout>
    <x-slot name="header">{{ $campaign->name }}</x-slot>

    @php
        /**
         * The campaign's home once it exists. Every control here is a real
         * form, and each one is rendered only when the dispatcher would
         * actually accept it — the state machine in CampaignDispatcher is
         * mirrored exactly so nothing on this page can 422.
         */
        $status = (string) $campaign->status;

        $isRunning = $campaign->isRunning();                                        // queued | sending
        $isPaused = $status === 'paused';
        $isScheduled = $status === 'scheduled';
        $isFinished = in_array($status, ['completed', 'failed', 'cancelled'], true);
        $hasStarted = $campaign->started_at !== null;

        // Mirrors CampaignDispatcher::assertSendable() / cancel() / destroy().
        $canSend = in_array($status, ['draft', 'scheduled', 'paused', 'failed'], true);
        $canCancel = in_array($status, ['queued', 'sending', 'paused', 'scheduled'], true);
        $canDelete = ! in_array($status, ['queued', 'sending'], true);

        $total = (int) $campaign->total_recipients;
        $sent = (int) $campaign->sent_count;
        $failed = (int) $campaign->failed_count;
        $bounced = (int) $campaign->bounced_count;
        $skipped = (int) ($counts['skipped'] ?? 0);

        $reach = (int) ($audience['reach'] ?? 0);
        $excluded = (int) ($audience['excluded'] ?? 0);
        $audienceGroups = [
            'Lists' => (array) ($audience['lists'] ?? []),
            'Tags' => (array) ($audience['tags'] ?? []),
            'Segments' => (array) ($audience['segments'] ?? []),
        ];
        $audienceChosen = array_sum(array_map('count', $audienceGroups));

        $sentShare = $total > 0 ? round($sent / $total * 100, 1) : 0.0;

        // Same keys and shape the progress endpoint answers with, so the panel
        // renders identically before the first poll lands.
        $progress = [
            'status' => $status,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'bounced' => $bounced,
            'skipped' => $skipped,
            'remaining' => (int) $outstanding,
            'percent' => $campaign->progressPercent(),
            'finished' => $isFinished,
            'paused' => $isPaused,
            'last_error' => $campaign->last_error,
        ];

        $recipientStates = [
            'pending' => 'Waiting its turn in the queue',
            'queued' => 'Handed to a sending worker',
            'sending' => 'Being sent right now',
            'sent' => 'Accepted by the receiving mail server',
            'failed' => 'The receiving server refused it',
            'bounced' => 'Returned after it was accepted',
            'skipped' => 'Not mailed — unsubscribed, suppressed or cancelled',
        ];

        // The stored zone is validated on save, but a row written before an
        // identifier was dropped from the tz database still holds it, and
        // setTimezone() throws on one it does not know — the same guard the
        // index and scheduled screens use, so a row that lists there cannot
        // 500 when it is opened.
        $knownZones = array_flip(timezone_identifiers_list());
        $campaignZone = isset($knownZones[$campaign->timezone]) ? $campaign->timezone : config('app.timezone');

        $localScheduled = $campaign->scheduled_at?->copy()->setTimezone($campaignZone);

        $template = $campaign->template;
        $canSeeTemplates = (bool) auth()->user()?->hasPermission('templates.view');
        $canSeeSmtp = (bool) auth()->user()?->hasPermission('smtp.view');

        // The Manage card is only worth a panel when it will hold a control.
        // A read-only member has none of these, and an empty titled card is
        // the sort of dead furniture this app does not ship.
        $hasManageControls = auth()->user()?->hasPermission('campaigns.create')
            || auth()->user()?->hasPermission('campaigns.delete')
            || (auth()->user()?->hasPermission('campaigns.send') && ($isScheduled || $canCancel));

        $delivery = [
            'Subject' => $campaign->subject,
            'Preheader' => $campaign->preview_text,
            'From' => trim($campaign->from_name.' <'.$campaign->from_email.'>', ' <>'),
            'Reply-to' => $campaign->reply_to ?: $campaign->from_email.' (the from address)',
            'Open tracking' => $campaign->track_opens ? 'On' : 'Off',
            'Click tracking' => $campaign->track_clicks ? 'On' : 'Off',
            'Time zone' => $campaign->timezone,
        ];

        $timeline = array_filter([
            'Created' => $campaign->created_at,
            'Scheduled for' => $localScheduled,
            'Started' => $campaign->started_at,
            'Paused' => $campaign->paused_at,
            'Finished' => $campaign->completed_at,
        ]);
    @endphp

    <x-page-header :title="$campaign->name"
                   :subtitle="$campaign->subject ?: 'No subject line yet'"
                   :back="route('campaigns.index')">
        <x-slot name="actions">
            <x-status-badge :status="$status" />

            @permission('campaigns.send')
                @if ($canSend)
                    <a href="{{ route('campaigns.confirm', $campaign) }}" class="kn-btn-primary">Review &amp; send</a>
                @endif

                @if ($isRunning)
                    <form method="POST" action="{{ route('campaigns.pause', $campaign) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary">Pause</button>
                    </form>
                @endif

                @if ($isPaused)
                    <form method="POST" action="{{ route('campaigns.resume', $campaign) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-primary">Resume</button>
                    </form>
                @endif
            @endpermission

            @permission('campaigns.update')
                @if ($campaign->isEditable())
                    <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-secondary">Edit</a>
                @endif
            @endpermission

            <a href="{{ route('campaigns.preview', $campaign) }}" target="_blank" rel="noopener noreferrer"
               class="kn-btn-secondary">Preview</a>
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------------- blockers.
         Hidden only once the campaign is really over. `failed` is NOT over:
         assertSendable() accepts it and the Review & send button above is
         offered for it, so the reasons that send would be refused have to be
         visible here too. --}}
    @if (! empty($blockers) && ! in_array($status, ['completed', 'cancelled'], true))
        <div class="kn-card mb-6 border-amber-300">
            <div class="kn-card-header border-amber-200 bg-amber-50">
                <h2 class="text-sm font-semibold text-amber-900">
                    {{ count($blockers) === 1 ? 'One thing stops this campaign going out' : count($blockers).' things stop this campaign going out' }}
                </h2>
                @permission('campaigns.update')
                    @if ($campaign->isEditable())
                        <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-secondary kn-btn-sm shrink-0">Fix them</a>
                    @endif
                @endpermission
            </div>
            <ul class="space-y-2 p-5 text-sm text-ink-700">
                @foreach ($blockers as $blocker)
                    <li class="flex items-start gap-2">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                        <span class="min-w-0">{{ $blocker }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- --------------------------------------------------------- last error --}}
    @if (filled($campaign->last_error))
        <div class="kn-card mb-6 border-red-200">
            <div class="kn-card-header border-red-200 bg-red-50">
                <h2 class="text-sm font-semibold text-red-900">Last error from the sender</h2>
                <span class="text-xs text-red-700">Recorded during sending</span>
            </div>
            <div class="p-5">
                <p class="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-50 p-3 font-mono text-xs text-red-900">
                    {{ $campaign->last_error }}
                </p>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------- send time.
         Its own banner rather than a note inside the progress panel: that
         panel only renders once a campaign has started or has recipient rows,
         and a campaign scheduled from a draft has neither — the send time
         would never have been shown. --}}
    @if ($isScheduled && $localScheduled)
        <p class="mb-6 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
            {{ $hasStarted ? 'Sending is on hold.' : 'Nothing has been sent yet.' }} This goes out on
            <span class="font-semibold">{{ $localScheduled->format('D, d M Y H:i') }}</span>
            ({{ $campaignZone }}) — {{ $campaign->scheduled_at->diffForHumans() }}.
        </p>
    @endif

    {{-- -------------------------------------------------------------- stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <x-stat-card label="Recipients"
                     :value="number_format($hasStarted ? $total : $reach)"
                     :meta="$hasStarted
                        ? 'Rows created when sending started'
                        : 'Contacts the audience reaches right now'" />

        <x-stat-card label="Sent"
                     :value="number_format($sent)"
                     :meta="$total > 0 ? $sentShare.'% of the recipient list' : 'Nothing has gone out yet'" />

        <x-stat-card label="Failed"
                     :value="number_format($failed)"
                     :tone="$failed > 0 ? 'danger' : 'default'"
                     meta="Refused by the receiving server" />

        <x-stat-card label="Bounced"
                     :value="number_format($bounced)"
                     :tone="$bounced > 0 ? 'warning' : 'default'"
                     :meta="$sent > 0 ? $campaign->bounceRate().'% of sent' : 'Returned after being accepted'" />

        <x-stat-card label="Opened"
                     :value="number_format((int) $campaign->unique_opens)"
                     :meta="! $campaign->track_opens
                        ? 'Open tracking is off for this campaign'
                        : ($sent > 0 ? $campaign->openRate().'% of sent — a floor, image blocking hides many opens' : 'A floor: image blocking hides many opens')" />

        <x-stat-card label="Clicked"
                     :value="number_format((int) $campaign->unique_clicks)"
                     :meta="! $campaign->track_clicks
                        ? 'Click tracking is off for this campaign'
                        : ($sent > 0 ? $campaign->clickRate().'% of sent — contacts who clicked at least once' : 'Contacts who clicked at least once')" />
    </div>

    {{-- ----------------------------------------------------- live progress --}}
    @if ($hasStarted || $isRunning || $total > 0)
        <div class="kn-card mb-6"
             x-data="{
                 p: @js($progress),
                 live: @js($isRunning),
                 timer: null,
                 failures: 0,
                 stopped: false,
                 reloaded: false,
                 wentPaused: false,

                 init() { if (this.live) { this.start() } },

                 start() {
                     this.stopped = false;
                     this.failures = 0;
                     if (this.timer) { clearInterval(this.timer) }
                     this.timer = setInterval(() => this.poll(), 4000);
                 },

                 stop() { if (this.timer) { clearInterval(this.timer); this.timer = null } },

                 destroy() { this.stop() },

                 retry() { this.start(); this.poll() },

                 async poll() {
                     try {
                         const response = await fetch(@js(route('campaigns.progress', $campaign)), {
                             headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                             credentials: 'same-origin'
                         });

                         if (! response.ok) { this.miss(); return }

                         this.p = await response.json();
                         this.failures = 0;

                         // Once it is over the controls on this page change, so
                         // the final numbers come from the server, not from here.
                         if (this.p.finished) {
                             this.stop();
                             if (! this.reloaded) { this.reloaded = true; window.location.reload() }
                             return;
                         }

                         // Someone paused it from another screen: the controls
                         // here are now wrong, so stop and say so.
                         if (this.p.paused) { this.stop(); this.wentPaused = true }
                     } catch (error) {
                         this.miss();
                     }
                 },

                 miss() {
                     this.failures = this.failures + 1;
                     if (this.failures >= 3) { this.stop(); this.stopped = true }
                 },

                 percent() { return Math.min(100, Math.max(0, Number(this.p.percent) || 0)) },
                 fmt(value) { return Number(value || 0).toLocaleString() }
             }">

            <div class="kn-card-header">
                <h2 class="text-sm font-semibold text-ink-900">
                    @if ($isRunning)
                        Sending now
                    @elseif ($isPaused)
                        Paused part way through
                    @else
                        Delivery
                    @endif
                </h2>

                @if ($isRunning)
                    <span class="text-xs text-ink-500">
                        <span x-show="! stopped">Updating every 4 seconds</span>
                        <span x-show="stopped" x-cloak class="font-medium text-amber-700">Live updates stopped</span>
                    </span>
                @else
                    <span class="text-xs text-ink-500">{{ $campaign->completed_at?->diffForHumans() ?? 'Not sending' }}</span>
                @endif
            </div>

            <div class="space-y-5 p-5">
                <div>
                    <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                        <p class="text-sm text-ink-600">
                            <span class="font-semibold text-ink-900" x-text="fmt(p.sent + p.failed)">{{ number_format($sent + $failed) }}</span>
                            of
                            <span x-text="fmt(p.total)">{{ number_format($total) }}</span>
                            messages handled ·
                            <span x-text="fmt(p.remaining)">{{ number_format((int) $outstanding) }}</span> still to go
                        </p>
                        <p class="text-sm font-semibold text-brand-700">
                            <span x-text="percent()">{{ $campaign->progressPercent() }}</span>%
                        </p>
                    </div>

                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-ink-200"
                         role="progressbar" aria-label="Sending progress">
                        <div class="h-2.5 rounded-full bg-brand-600 transition-all duration-500"
                             :style="'width: ' + percent() + '%'"
                             style="width: {{ $campaign->progressPercent() }}%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Sent</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.sent)">{{ number_format($sent) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Failed</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.failed)">{{ number_format($failed) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Bounced</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.bounced)">{{ number_format($bounced) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Skipped</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.skipped)">{{ number_format($skipped) }}</p>
                    </div>
                    <div class="rounded-lg border border-ink-200 px-3 py-2.5">
                        <p class="kn-stat-label">Remaining</p>
                        <p class="mt-0.5 text-lg font-semibold text-ink-900" x-text="fmt(p.remaining)">{{ number_format((int) $outstanding) }}</p>
                    </div>
                </div>

                {{-- The fetch has failed three times in a row: say so plainly and
                     let the reader start it again rather than staring at numbers
                     that quietly stopped moving. --}}
                <div x-show="stopped" x-cloak
                     class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-amber-900">
                        Live updates stopped after three failed attempts. Sending itself carries on in the
                        background — only this panel has gone quiet.
                    </p>
                    <div class="flex shrink-0 gap-2">
                        <button type="button" class="kn-btn-secondary kn-btn-sm" @click="retry()">Retry</button>
                        <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-ghost kn-btn-sm">Reload page</a>
                    </div>
                </div>

                <div x-show="wentPaused && ! stopped" x-cloak
                     class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-amber-900">
                        This campaign is paused, so nothing is moving. Reload the page to get the resume control.
                    </p>
                    <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-secondary kn-btn-sm shrink-0">Reload page</a>
                </div>

            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ left --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Audience --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Audience</h2>
                    <span class="text-xs text-ink-500">Counted right now</span>
                </div>

                @if ($audienceChosen === 0)
                    <x-empty-state title="No audience selected"
                                   message="A campaign with nothing selected goes to nobody — it deliberately does not fall back to your whole database. Pick at least one list, tag or segment.">
                        <x-slot name="action">
                            @permission('campaigns.update')
                                @if ($campaign->isEditable())
                                    <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-primary">Choose an audience</a>
                                @endif
                            @endpermission
                        </x-slot>
                    </x-empty-state>
                @else
                    <div class="space-y-4 p-5">
                        @foreach ($audienceGroups as $label => $names)
                            @if (! empty($names))
                                <div>
                                    <p class="kn-stat-label">{{ $label }}</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach ($names as $name)
                                            <span class="kn-badge-gray max-w-full">
                                                <span class="min-w-0 truncate">{{ $name }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-ink-200 px-4 py-3">
                                <p class="kn-stat-label">Will be mailed</p>
                                <p class="mt-0.5 text-lg font-semibold text-ink-900">{{ number_format($reach) }}</p>
                                <p class="mt-1 text-xs text-ink-500">Active, unsuppressed contacts matching the selection.</p>
                            </div>
                            <div class="rounded-lg border border-ink-200 px-4 py-3">
                                <p class="kn-stat-label">Matched but excluded</p>
                                <p class="mt-0.5 text-lg font-semibold {{ $excluded > 0 ? 'text-amber-600' : 'text-ink-900' }}">
                                    {{ number_format($excluded) }}
                                </p>
                                <p class="mt-1 text-xs text-ink-500">
                                    Unsubscribed, bounced, blocked or on the suppression list.
                                </p>
                            </div>
                        </div>

                        @if ($hasStarted && $reach !== $total)
                            <p class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                                The audience matches {{ number_format($reach) }} contact(s) today, while this campaign
                                was built for {{ number_format($total) }}. Contacts joining, leaving or unsubscribing
                                after the send started explain the difference — the recipient list itself is frozen.
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Recipient breakdown --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Recipient status</h2>
                    <span class="text-xs text-ink-500">{{ number_format($counts->sum()) }} row(s)</span>
                </div>

                @if ($counts->isEmpty())
                    <x-empty-state title="No recipient rows yet"
                                   message="The recipient list is written the moment sending starts, so this table fills in then — nothing is created while the campaign is a draft." />
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>What it means</th>
                                    <th class="text-right">Contacts</th>
                                    <th class="text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recipientStates as $state => $meaning)
                                    @php
                                        $value = (int) ($counts[$state] ?? 0);
                                        $share = $counts->sum() > 0 ? round($value / $counts->sum() * 100, 1) : 0.0;
                                    @endphp
                                    @if ($value > 0)
                                        <tr>
                                            <td><x-status-badge :status="$state" /></td>
                                            <td class="text-ink-600">{{ $meaning }}</td>
                                            <td class="text-right font-medium text-ink-900">{{ number_format($value) }}</td>
                                            <td class="text-right text-xs text-ink-500">{{ $share }}%</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Delivery details --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Delivery details</h2>
                    <span class="text-xs text-ink-500">What every recipient sees</span>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    @foreach ($delivery as $label => $value)
                        <div class="min-w-0">
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach

                    <div class="min-w-0">
                        <dt class="kn-stat-label">Template</dt>
                        <dd class="mt-0.5 break-words text-sm text-ink-800">
                            @if ($template && $canSeeTemplates)
                                <a href="{{ route('templates.preview', $template) }}" target="_blank" rel="noopener noreferrer"
                                   class="font-medium text-brand-600 hover:text-brand-700">{{ $template->name }}</a>
                            @elseif ($template)
                                {{ $template->name }}
                            @else
                                Built block by block, not from a saved template
                            @endif
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt class="kn-stat-label">SMTP account</dt>
                        <dd class="mt-0.5 break-words text-sm text-ink-800">
                            @if ($smtp && $canSeeSmtp)
                                <a href="{{ route('smtp.show', $smtp) }}" class="font-medium text-brand-600 hover:text-brand-700">{{ $smtp->name }}</a>
                                <span class="text-ink-500">· {{ $smtp->host }}</span>
                            @elseif ($smtp)
                                {{ $smtp->name }} <span class="text-ink-500">· {{ $smtp->host }}</span>
                            @else
                                Chosen automatically at send time, rotating across the accounts your plan can use
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- =========================================================== right --}}
        <div class="space-y-6">

            {{-- Timeline --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Timeline</h2>
                </div>
                <div class="space-y-4 p-5">
                    @foreach ($timeline as $label => $moment)
                        <div>
                            <p class="kn-stat-label">{{ $label }}</p>
                            <p class="mt-0.5 text-sm text-ink-800" title="{{ $moment->format('D, d M Y H:i') }}">
                                {{ $moment->format('D, d M Y H:i') }}
                                <span class="text-ink-500">· {{ $moment->diffForHumans() }}</span>
                            </p>
                        </div>
                    @endforeach

                    <p class="text-xs text-ink-500">
                        Times are shown in {{ config('app.timezone') }}, except the scheduled time, which is shown in
                        the campaign's own time zone ({{ $campaignZone }}).
                    </p>
                </div>
            </div>

            {{-- Test send --}}
            @permission('campaigns.send')
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h2 class="text-sm font-semibold text-ink-900">Send a test</h2>
                    </div>
                    <div class="kn-card-body">
                        <p class="mb-3 text-xs text-ink-500">
                            One message to an address you choose, personalised with a sample contact's details.
                            It touches no counter here and creates no recipient row.
                        </p>

                        <form method="POST" action="{{ route('campaigns.test', $campaign) }}" class="space-y-3">
                            @csrf
                            <div>
                                <label for="test-email" class="kn-label">Send it to</label>
                                <x-text-input type="email" name="email" id="test-email" required
                                              :value="old('email', auth()->user()?->email)"
                                              placeholder="you@example.com" />
                                <x-input-error :messages="$errors->get('email')" />
                            </div>
                            <button type="submit" class="kn-btn-secondary w-full">Send test email</button>
                        </form>
                    </div>
                </div>
            @endpermission

            {{-- Manage --}}
            @if ($hasManageControls)
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Manage</h2>
                </div>
                <div class="space-y-3 p-5">

                    @permission('campaigns.create')
                        <form method="POST" action="{{ route('campaigns.duplicate', $campaign) }}">
                            @csrf
                            <button type="submit" class="kn-btn-secondary w-full">Duplicate as a new draft</button>
                        </form>
                        <p class="text-xs text-ink-500">
                            Copies the content, audience and sender settings into a fresh draft. Nothing is sent.
                        </p>
                    @endpermission

                    @permission('campaigns.send')
                        @if ($isScheduled)
                            <form method="POST" action="{{ route('campaigns.unschedule', $campaign) }}">
                                @csrf
                                <button type="submit" class="kn-btn-secondary w-full">Unschedule</button>
                            </form>
                            <p class="text-xs text-ink-500">
                                Removes the send time and puts the campaign back to a draft. Nothing goes out.
                            </p>
                        @endif

                        @if ($canCancel)
                            <div>
                                <x-confirm-form :action="route('campaigns.cancel', $campaign)"
                                                method="POST"
                                                label="Cancel this campaign"
                                                button-class="kn-btn-danger"
                                                :message="'Cancel '.$campaign->name.'? Messages already sent cannot be recalled — only the unsent ones are dropped, and the campaign cannot be restarted.'" />
                            </div>
                            <p class="text-xs text-ink-500">
                                Stops it for good. {{ number_format((int) $outstanding) }} unsent message(s) would be dropped.
                            </p>
                        @endif
                    @endpermission

                    @permission('campaigns.delete')
                        @if ($canDelete)
                            <div>
                                <x-confirm-form :action="route('campaigns.destroy', $campaign)"
                                                label="Delete this campaign"
                                                button-class="kn-btn-ghost text-red-600 hover:bg-red-50"
                                                :message="'Delete '.$campaign->name.'? Its sending history stays in the logs, but the campaign, its content and its recipient list go.'" />
                            </div>
                        @else
                            <p class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                                A campaign that is sending cannot be deleted. Pause or cancel it first.
                            </p>
                        @endif
                    @endpermission
                </div>
            </div>
            @endif

            {{-- Reporting elsewhere (later phases; hidden until those routes exist) --}}
            @if ($hasStarted)
                @php
                    // `scoped` says whether the route takes this campaign.
                    // analytics.index does not: passing one there produced
                    // /analytics?campaign=5, a parameter nothing reads, so the
                    // link went to the account dashboard rather than to this
                    // campaign's report.
                    $reporting = collect([
                        ['route' => 'analytics.campaign', 'permission' => 'analytics.view', 'label' => 'Full report', 'scoped' => true],
                        ['route' => 'logs.index', 'permission' => 'logs.view', 'label' => 'Delivery log', 'scoped' => false],
                        ['route' => 'campaign-replies.index', 'permission' => 'inbox.view', 'label' => 'Replies to this campaign', 'scoped' => false],
                    ])->filter(fn ($item) => \Illuminate\Support\Facades\Route::has($item['route'])
                        && auth()->user()?->hasPermission($item['permission']));
                @endphp

                @if ($reporting->isNotEmpty())
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h2 class="text-sm font-semibold text-ink-900">Reporting</h2>
                        </div>
                        <div class="space-y-2 p-5">
                            @foreach ($reporting as $item)
                                <a href="{{ $item['scoped'] ? route($item['route'], $campaign) : route($item['route'], ['campaign' => $campaign->id]) }}"
                                   class="kn-btn-secondary w-full">{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
