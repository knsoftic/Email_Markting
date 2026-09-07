<x-app-layout>
    <x-slot name="header">Review &amp; send</x-slot>

    @php
        /**
         * The last screen before real email leaves the building. It is written
         * as a summary to sign off on: who it reaches, who it does not, what
         * they receive, what carries it, and what the plan has left.
         *
         * Nothing here is a live-looking control that the dispatcher would
         * refuse. CampaignDispatcher::assertSendable() accepts only draft,
         * scheduled, paused and failed campaigns and only when blockers() is
         * empty, so both conditions are mirrored into one list of stoppers and
         * both actions are really disabled while it is non-empty.
         */
        $reach = (int) ($audience['reach'] ?? 0);
        $excluded = (int) ($audience['excluded'] ?? 0);

        $audienceGroups = [
            'Lists' => (array) ($audience['lists'] ?? []),
            'Tags' => (array) ($audience['tags'] ?? []),
            'Segments' => (array) ($audience['segments'] ?? []),
        ];
        $audienceChosen = array_sum(array_map('count', $audienceGroups));

        $statusLabels = [
            'queued' => 'This campaign is already queued for sending.',
            'sending' => 'This campaign is sending right now.',
            'completed' => 'This campaign has already been sent.',
            'cancelled' => 'This campaign was cancelled and cannot be restarted.',
        ];

        $stoppers = array_values((array) $blockers);

        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused', 'failed'], true)) {
            array_unshift($stoppers, $statusLabels[$campaign->status] ?? 'This campaign is not in a state that can be sent.');
        }

        $blocked = count($stoppers) > 0;

        $overLimit = $monthlyRemaining !== null && $reach > $monthlyRemaining;
        $nearLimit = $monthlyRemaining !== null && ! $overLimit && $reach > 0 && $reach >= $monthlyRemaining * 0.8;

        $preferredId = (int) $campaign->smtp_account_id;
        $preferredIsUsable = $preferredId > 0 && $smtp->contains('id', $preferredId);

        $timezones = \DateTimeZone::listIdentifiers();

        // Never hand an unvalidated zone to now(): old() carries back whatever
        // was posted, and an unknown identifier would throw here.
        $chosenTimezone = old('timezone', $campaign->timezone);
        $chosenTimezone = in_array($chosenTimezone, $timezones, true) ? $chosenTimezone : 'UTC';

        // datetime-local wants "Y-m-d\TH:i" read in that zone. The minimum is
        // now in the same zone, so the browser blocks a time the dispatcher
        // would reject as already past.
        $nowLocal = now($chosenTimezone);
        $minLocal = $nowLocal->format('Y-m-d\TH:i');

        // The same instant as an absolute UTC stamp. The zone select can change
        // the reading of "now" after this page is rendered, so the minimum has
        // to be recomputed in the browser — from THIS instant, never from the
        // visitor's own clock, which may be wrong.
        $nowUtcIso = $nowLocal->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $defaultLocal = old(
            'scheduled_at',
            $campaign->scheduled_at
                ? $campaign->scheduled_at->copy()->setTimezone($chosenTimezone)->format('Y-m-d\TH:i')
                : $nowLocal->copy()->addHour()->startOfHour()->format('Y-m-d\TH:i')
        );

        $canSeeSmtp = (bool) auth()->user()?->hasPermission('smtp.view');

        $sendPrompt = 'Send "'.$campaign->name.'" to '.number_format($reach).' contact(s) now? '
            .'Email starts going out immediately and messages already sent cannot be recalled.';

        $identity = [
            'Subject' => $campaign->subject,
            'Preheader' => $campaign->preview_text ?: 'None — inboxes will show the first line of the email instead',
            'From name' => $campaign->from_name,
            'From address' => $campaign->from_email,
            'Reply-to' => $campaign->reply_to ?: $campaign->from_email.' (replies go to the from address)',
            'Open tracking' => $campaign->track_opens ? 'On' : 'Off',
            'Click tracking' => $campaign->track_clicks ? 'On' : 'Off',
        ];
    @endphp

    <x-page-header title="Review & send"
                   :subtitle="$campaign->name"
                   :back="route('campaigns.show', $campaign)">
        <x-slot name="actions">
            <x-status-badge :status="$campaign->status" />

            <a href="{{ route('campaigns.preview', $campaign) }}" target="_blank" rel="noopener noreferrer"
               class="kn-btn-secondary">Preview the email</a>

            @permission('campaigns.update')
                @if ($campaign->isEditable())
                    <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-secondary">Edit</a>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------------ stoppers --}}
    @if ($blocked)
        <div class="kn-card mb-6 border-red-300">
            <div class="kn-card-header border-red-200 bg-red-50">
                <h2 class="text-sm font-semibold text-red-900">
                    {{ count($stoppers) === 1 ? 'This campaign cannot be sent yet' : 'This campaign cannot be sent yet — '.count($stoppers).' things to fix' }}
                </h2>
                @permission('campaigns.update')
                    @if ($campaign->isEditable())
                        <a href="{{ route('campaigns.edit', $campaign) }}" class="kn-btn-primary kn-btn-sm shrink-0">Open the editor</a>
                    @endif
                @endpermission
            </div>

            <ul class="divide-y divide-ink-100">
                @foreach ($stoppers as $stopper)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <span class="flex min-w-0 items-start gap-2 text-sm text-ink-800">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"></span>
                            <span class="min-w-0">{{ $stopper }}</span>
                        </span>
                        @permission('campaigns.update')
                            @if ($campaign->isEditable())
                                <a href="{{ route('campaigns.edit', $campaign) }}"
                                   class="shrink-0 text-xs font-semibold text-brand-600 hover:text-brand-700">Fix this</a>
                            @endif
                        @endpermission
                    </li>
                @endforeach
            </ul>

            <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                Sending and scheduling stay switched off until every line above is cleared.
            </p>
        </div>
    @endif

    {{--
        Warnings, not stoppers. Kept visually distinct from the red panel
        above: a warning that blocks the send teaches people to ignore
        warnings, and a blocker that is only advice teaches them to look for a
        way around it. These do not disable anything.
    --}}
    @if (! empty($warnings))
        <div class="kn-card mb-6 border-amber-300">
            <div class="kn-card-header border-amber-200 bg-amber-50">
                <h2 class="text-sm font-semibold text-amber-900">
                    Worth knowing before you send
                </h2>
                <span class="text-xs text-amber-700">These do not stop the send</span>
            </div>

            <ul class="divide-y divide-ink-100">
                @foreach ($warnings as $warning)
                    <li class="flex items-start gap-2 px-5 py-3 text-sm text-ink-800">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                        <span class="min-w-0">{{ $warning }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ left --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Who it reaches --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Who this reaches</h2>
                    <span class="text-xs text-ink-500">Counted a moment ago</span>
                </div>

                <div class="space-y-5 p-5">
                    <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                        <div>
                            <p class="kn-stat-label">Contacts who will be mailed</p>
                            <p class="mt-1 text-3xl font-semibold {{ $reach === 0 ? 'text-red-600' : 'text-ink-900' }}">
                                {{ number_format($reach) }}
                            </p>
                        </div>

                        @if ($excluded > 0)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <p class="text-sm font-semibold text-amber-900">
                                    {{ number_format($excluded) }} contact(s) match but will not be mailed
                                </p>
                                <p class="mt-1 text-xs text-amber-800">
                                    They are unsubscribed, bounced, blocked or on the suppression list. They stay in
                                    the lists and tags below — they are simply never sent to.
                                </p>
                            </div>
                        @endif
                    </div>

                    @if ($audienceChosen === 0)
                        <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                            No list, tag or segment is selected. An empty audience deliberately matches nobody rather
                            than your whole database, so this campaign would reach no one.
                        </p>
                    @else
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

                        <p class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                            A contact in two of these is counted once. The list is rebuilt at the moment of sending, so
                            anyone who unsubscribes between now and then is dropped automatically.
                        </p>
                    @endif
                </div>
            </div>

            {{-- What they receive --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">What they receive</h2>
                    <a href="{{ route('campaigns.preview', $campaign) }}" target="_blank" rel="noopener noreferrer"
                       class="text-xs font-semibold text-brand-600 hover:text-brand-700">Open the rendered email</a>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    @foreach ($identity as $label => $value)
                        <div class="min-w-0">
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                    Every recipient sees the from name and address above in their inbox. Replies go to the reply-to
                    address, so make sure someone reads it.
                </p>
            </div>

            {{-- What carries it --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">What carries it</h2>
                    <span class="text-xs text-ink-500">{{ $smtp->count() }} account(s) available</span>
                </div>

                @if ($smtp->isEmpty())
                    <x-empty-state title="No SMTP account can send right now"
                                   message="Every account is paused, in cooldown, over a limit, or none is assigned to your plan. Nothing can go out until one is available.">
                        <x-slot name="action">
                            @permission('smtp.view')
                                <a href="{{ route('smtp.index') }}" class="kn-btn-primary">Check your SMTP accounts</a>
                            @endpermission
                        </x-slot>
                    </x-empty-state>
                @else
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Host</th>
                                    <th>Owner</th>
                                    <th class="text-right">Priority</th>
                                    <th class="text-right">Left today</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($smtp as $account)
                                    <tr>
                                        <td class="min-w-0">
                                            @if ($canSeeSmtp)
                                                <a href="{{ route('smtp.show', $account) }}"
                                                   class="font-medium text-ink-900 hover:text-brand-600">{{ $account->name }}</a>
                                            @else
                                                <span class="font-medium text-ink-900">{{ $account->name }}</span>
                                            @endif

                                            @if ($account->id === $preferredId)
                                                <span class="kn-badge-blue ml-1.5">Preferred</span>
                                            @endif
                                        </td>
                                        <td class="text-ink-600">{{ $account->host }}</td>
                                        <td>
                                            @if ($account->is_global)
                                                <span class="kn-badge-gray">Provided by KN Softic</span>
                                            @else
                                                <span class="kn-badge-gray">Yours</span>
                                            @endif
                                        </td>
                                        <td class="text-right text-ink-600">{{ number_format((int) $account->priority) }}</td>
                                        <td class="text-right text-ink-600">
                                            {{ $account->remainingToday() === null ? 'No cap' : number_format($account->remainingToday()) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                        @if ($smtp->count() > 1)
                            Messages are handed to these accounts in the order shown — lowest priority number first,
                            then the one that has sent least today. Each message goes to the first account with room
                            left, so a single account running out of capacity does not stop the send.
                        @else
                            Every message goes through this one account — it is the only one this send can use. If it
                            reaches its limit part way through, the campaign is not failed: the unsent messages stay
                            queued and sending picks up again every few minutes until there is room.
                        @endif
                        @if ($preferredId > 0 && ! $preferredIsUsable)
                            <span class="mt-1 block font-medium text-amber-700">
                                The account this campaign prefers is not in the list: it is paused, cooling down, over a
                                limit or no longer assigned to you. The accounts above carry the send instead.
                            </span>
                        @endif
                    </p>
                @endif
            </div>
        </div>

        {{-- =========================================================== right --}}
        <div class="space-y-6">

            {{-- Plan allowance --}}
            <div class="kn-card {{ $overLimit ? 'border-red-300' : '' }}">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">This month's allowance</h2>
                </div>
                <div class="space-y-3 p-5">
                    @if ($monthlyRemaining === null)
                        <p class="kn-stat-label">Emails left this month</p>
                        <p class="text-2xl font-semibold text-ink-900">Unlimited</p>
                        <p class="text-xs text-ink-500">Your plan sets no monthly cap on emails.</p>
                    @else
                        <div>
                            <p class="kn-stat-label">Emails left this month</p>
                            <p class="text-2xl font-semibold {{ $overLimit ? 'text-red-600' : ($nearLimit ? 'text-amber-600' : 'text-ink-900') }}">
                                {{ number_format($monthlyRemaining) }}
                            </p>
                        </div>

                        <p class="text-sm text-ink-600">
                            This send needs {{ number_format($reach) }}.
                        </p>

                        @if ($overLimit)
                            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                That is {{ number_format($reach - $monthlyRemaining) }} more than the plan allows this
                                month. Starting the send now will be refused before a single message goes out — reduce
                                the audience or upgrade the plan first.
                            </p>
                        @elseif ($nearLimit)
                            <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                This send uses most of what is left this month
                                ({{ number_format(max(0, $monthlyRemaining - $reach)) }} would remain afterwards).
                            </p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Where the numbers came from --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Before you sign this off</h2>
                </div>
                <ul class="space-y-2.5 p-5 text-sm text-ink-600">
                    <li>Send yourself a test from the campaign page and read it in a real inbox.</li>
                    <li>Check the from name and reply-to address — recipients see both.</li>
                    <li>Sent email cannot be recalled. Pausing only stops what has not gone out yet.</li>
                </ul>
                <div class="border-t border-ink-100 px-5 py-3">
                    <a href="{{ route('campaigns.show', $campaign) }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700">
                        Back to the campaign to send a test
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------ the decision --}}
    <div class="kn-card mt-6 {{ $blocked ? 'border-ink-200' : 'border-brand-200' }}">
        <div class="kn-card-header {{ $blocked ? '' : 'bg-brand-50' }}">
            <h2 class="text-sm font-semibold {{ $blocked ? 'text-ink-900' : 'text-brand-900' }}">Send it</h2>
            <span class="text-xs {{ $blocked ? 'text-ink-500' : 'text-brand-700' }}">
                {{ $blocked ? 'Disabled until the problems above are fixed' : 'Both choices below send real email to '.number_format($reach).' contact(s)' }}
            </span>
        </div>

        <div class="grid gap-6 p-5 lg:grid-cols-2 lg:divide-x lg:divide-ink-100">

            {{-- Send now --}}
            <div class="lg:pr-6">
                <h3 class="text-sm font-semibold text-ink-900">Send now</h3>
                <p class="mt-1 text-sm text-ink-600">
                    The recipient list is written and the first messages leave within seconds. You can pause it part
                    way through, but whatever has already gone cannot be recalled.
                </p>

                <form method="POST" action="{{ route('campaigns.send', $campaign) }}" class="mt-4"
                      onsubmit="return confirm(@js($sendPrompt));">
                    @csrf
                    <button type="submit" class="kn-btn-primary" @disabled($blocked)>
                        Send to {{ number_format($reach) }} contact(s) now
                    </button>
                </form>

                @if ($blocked)
                    <p class="mt-2 text-xs text-ink-500">Fix the problems listed above to switch this back on.</p>
                @endif
            </div>

            {{-- Schedule.
                 min is "now" read in the SELECTED zone. Server-rendered it is
                 right for the zone the page loaded with, but the select below
                 can change that zone, and moving to a zone ahead of it would
                 let a time the dispatcher rejects as past through the browser —
                 abort_if($when->isPast(), 422) is an error page, not a field
                 error. So min is recomputed from the server's own instant using
                 nothing but the browser's time-zone table. --}}
            <div class="lg:pl-6"
                 x-data="{
                     min: @js($minLocal),
                     serverNow: @js($nowUtcIso),

                     retime(zone) {
                         try {
                             const parts = new Intl.DateTimeFormat('en-GB', {
                                 timeZone: zone,
                                 hourCycle: 'h23',
                                 year: 'numeric', month: '2-digit', day: '2-digit',
                                 hour: '2-digit', minute: '2-digit'
                             }).formatToParts(new Date(this.serverNow))
                               .reduce((carry, part) => (carry[part.type] = part.value, carry), {});

                             this.min = parts.year + '-' + parts.month + '-' + parts.day
                                 + 'T' + parts.hour + ':' + parts.minute;
                         } catch (error) {
                             // An identifier this browser does not know: keep the
                             // server's value rather than replacing it with a wrong one.
                         }
                     }
                 }">
                <h3 class="text-sm font-semibold text-ink-900">Schedule it</h3>
                <p class="mt-1 text-sm text-ink-600">
                    The campaign waits as <em>scheduled</em> and goes out on its own at the time you pick. You can
                    unschedule it any time before then.
                </p>

                <form method="POST" action="{{ route('campaigns.schedule', $campaign) }}" class="mt-4 space-y-3">
                    @csrf

                    <div>
                        <label for="scheduled_at" class="kn-label">Date and time</label>
                        <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                               value="{{ $defaultLocal }}" min="{{ $minLocal }}" :min="min" required
                               class="kn-input" @disabled($blocked) />
                        <p class="kn-help">Read in the time zone below, and it must be in the future.</p>
                        <x-input-error :messages="$errors->get('scheduled_at')" />
                    </div>

                    <div>
                        <label for="timezone" class="kn-label">Time zone</label>
                        <select name="timezone" id="timezone" class="kn-select" required
                                @change="retime($event.target.value)" @disabled($blocked)>
                            @foreach ($timezones as $timezone)
                                <option value="{{ $timezone }}" @selected($timezone === $chosenTimezone)>{{ $timezone }}</option>
                            @endforeach
                        </select>
                        <p class="kn-help">Defaults to this campaign's own time zone ({{ $campaign->timezone }}).</p>
                        <x-input-error :messages="$errors->get('timezone')" />
                    </div>

                    <button type="submit" class="kn-btn-secondary" @disabled($blocked)>Schedule this campaign</button>
                </form>
            </div>
        </div>

        <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
            Nothing on this page has sent anything yet. Leaving it changes nothing — the campaign stays exactly as it
            is now.
        </p>
    </div>
</x-app-layout>
