<x-app-layout>
    <x-slot name="header">Email Log</x-slot>

    @php
        /**
         * One send attempt, in full.
         *
         * $log arrives with `campaign`, `smtpAccount` and `subscriber` eager
         * loaded withTrashed(), because deleting a campaign afterwards does not
         * un-send the email — a log that can no longer say what it belonged to
         * has lost the one answer somebody came here for. Links are only
         * rendered for records that are still live.
         */

        $succeeded = $log->status === 'sent';

        $stamp = $log->sent_at ?? $log->created_at;
        $stampLabel = $log->sent_at ? 'Sent' : 'Attempted';
        $local = $stamp?->copy()->setTimezone($zone);
        $recorded = $log->created_at?->copy()->setTimezone($zone);

        $campaign = $log->campaign;
        $campaignGone = $campaign === null && $log->campaign_id === null && $log->type === 'campaign';

        $statusTones = [
            'sent' => 'kn-badge-green',
            'failed' => 'kn-badge-red',
            'bounced' => 'kn-badge-amber',
            'deferred' => 'kn-badge-amber',
        ];

        $typeLabels = [
            'campaign' => 'Campaign',
            'automation' => 'Automation',
            'transactional' => 'Transactional',
            'test' => 'Test',
            'reply' => 'Reply',
        ];

        // What each status actually means, so the badge is not the whole story.
        $statusExplained = [
            'sent' => 'The SMTP server accepted this message for delivery. That is as far as the log can see — acceptance is not proof it reached the inbox.',
            'failed' => 'The send did not complete. The reason recorded at the time is below.',
            'bounced' => 'The receiving side rejected the message. The rejection text is below.',
            'deferred' => 'The receiving side asked for a retry rather than accepting or rejecting. Nothing was delivered on this attempt.',
        ];

        $user = auth()->user();
        $canViewCampaign = (bool) $user?->hasPermission('campaigns.view');
        $canViewContact = (bool) $user?->hasPermission('contacts.view');
        $canViewSmtp = (bool) $user?->hasPermission('smtp.view');

        /**
         * Back goes to the list the reader came from, filters and page intact.
         *
         * The URL is rebuilt from our own route plus the previous query string
         * — never from the referrer itself — so a crafted Referer cannot turn
         * "Back" into a link off the site. Paths are compared rather than whole
         * URLs, because url()->previous() carries the host the browser used and
         * route() builds from APP_URL; behind a proxy or a port the two do not
         * have to agree.
         */
        $previous = (string) url()->previous();
        $previousPath = rtrim((string) parse_url($previous, PHP_URL_PATH), '/');
        $previousQuery = (string) parse_url($previous, PHP_URL_QUERY);
        $indexPath = route('logs.index', [], false);

        $back = ($previousPath !== '' && str_ends_with($previousPath, rtrim($indexPath, '/')))
            ? $indexPath.($previousQuery !== '' ? '?'.$previousQuery : '')
            : route('logs.index');

        $dt = 'text-[11px] font-medium uppercase tracking-wide text-ink-400';
        $dd = 'mt-0.5 break-words text-sm text-ink-800';
    @endphp

    <x-page-header :title="$log->recipient_email"
                   :subtitle="($typeLabels[$log->type] ?? ucfirst((string) $log->type)).' send · log #'.$log->id"
                   :back="$back">
        <x-slot name="actions">
            <span class="{{ $statusTones[$log->status] ?? 'kn-badge-gray' }}">
                {{ ucfirst((string) $log->status) }}
            </span>
        </x-slot>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- ------------------------------------------------- what happened --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">What happened</h2>
                </div>
                <div class="kn-card-body space-y-4">
                    <p class="text-sm text-ink-600">
                        {{ $statusExplained[$log->status] ?? 'This status is not one the application writes; it is shown exactly as it is stored.' }}
                    </p>

                    @if (filled($log->error))
                        <div>
                            <p class="{{ $dt }}">Error recorded</p>
                            <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-50 p-3 text-xs leading-relaxed text-red-800 ring-1 ring-inset ring-red-600/20">{{ $log->error }}</pre>
                        </div>
                    @elseif (! $succeeded)
                        <div class="rounded-lg bg-ink-50 p-3 text-sm text-ink-600 ring-1 ring-inset ring-ink-200">
                            No reason was stored with this attempt, so the log cannot say why it
                            did not go out. The SMTP account's own connection history is the next
                            place to look.
                        </div>
                    @endif

                    <div>
                        <p class="{{ $dt }}">SMTP response</p>
                        @if (filled($log->response))
                            <pre class="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-ink-50 p-3 text-xs leading-relaxed text-ink-700 ring-1 ring-inset ring-ink-200">{{ $log->response }}</pre>
                        @else
                            {{-- Honest about the gap: the column exists and neither sender
                                 currently fills it, so "none" would read like the server
                                 said nothing. --}}
                            <p class="mt-1 text-sm text-ink-500">
                                Not captured. The sender records the accept-or-fail outcome and the
                                error text, but does not keep the server's raw reply for this kind
                                of send.
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------- the message --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">The message</h2>
                </div>
                <dl class="kn-card-body grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="{{ $dt }}">Subject</dt>
                        <dd class="{{ $dd }}">
                            @if (filled($log->subject))
                                {{ $log->subject }}
                            @else
                                <span class="text-ink-400">No subject was recorded.</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $dt }}">To</dt>
                        <dd class="{{ $dd }}">{{ $log->recipient_email }}</dd>
                    </div>

                    <div>
                        <dt class="{{ $dt }}">From</dt>
                        <dd class="{{ $dd }}">
                            {{ filled($log->sender_email) ? $log->sender_email : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $dt }}">{{ $stampLabel }}</dt>
                        <dd class="{{ $dd }}">
                            @if ($local)
                                {{ $local->format('D, d M Y H:i:s') }}
                                <span class="block text-xs text-ink-500">
                                    {{ $zone }} · {{ $stamp->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-ink-400">No time recorded.</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $dt }}">Row written</dt>
                        <dd class="{{ $dd }}">
                            @if ($recorded)
                                {{ $recorded->format('D, d M Y H:i:s') }}
                                <span class="block text-xs text-ink-500">{{ $zone }}</span>
                            @else
                                <span class="text-ink-400">Not recorded.</span>
                            @endif
                        </dd>
                    </div>

                    @unless ($log->sent_at)
                        <div class="sm:col-span-2">
                            <p class="text-xs text-ink-500">
                                There is no send time on this row because nothing was delivered —
                                the time above is when the attempt was made.
                            </p>
                        </div>
                    @endunless
                </dl>
            </div>
        </div>

        {{-- ---------------------------------------------------- where from --}}
        <div class="space-y-5">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Where this came from</h2>
                </div>
                <dl class="kn-card-body space-y-4">
                    {{-- campaign or automation --}}
                    <div>
                        <dt class="{{ $dt }}">Source</dt>
                        <dd class="{{ $dd }}">
                            @if ($campaign && ! $campaign->trashed())
                                @if ($canViewCampaign)
                                    <a href="{{ route('campaigns.show', $campaign->id) }}"
                                       class="font-medium text-brand-600 hover:text-brand-700">
                                        {{ $campaign->name }}
                                    </a>
                                @else
                                    {{ $campaign->name }}
                                    <span class="block text-xs text-ink-500">
                                        You do not have permission to open campaigns.
                                    </span>
                                @endif
                            @elseif ($campaign)
                                {{ $campaign->name }}
                                <span class="block text-xs text-ink-500">
                                    This campaign was deleted. The send still happened; the campaign
                                    screen is gone, so there is nothing to open.
                                </span>
                            @elseif ($campaignGone)
                                <span class="text-ink-500">
                                    The campaign record no longer exists. This row keeps the subject,
                                    the recipient and the outcome, but the campaign it belonged to
                                    has been removed for good.
                                </span>
                            @elseif ($log->type === 'automation')
                                Sent by an automation
                                <span class="block text-xs text-ink-500">
                                    The log stores that this was automation mail, but not which
                                    automation sent it — <code>campaign_logs</code> has no column for
                                    one — so there is no automation to link to from here.
                                </span>
                                @permission('automation.view')
                                    @if (\Illuminate\Support\Facades\Route::has('automations.index'))
                                        <a href="{{ route('automations.index') }}"
                                           class="mt-1 inline-block text-xs font-medium text-brand-600 hover:text-brand-700">
                                            Browse automations
                                        </a>
                                    @endif
                                @endpermission
                            @else
                                <span class="text-ink-500">
                                    Not linked to a campaign.
                                </span>
                            @endif
                        </dd>
                    </div>

                    {{-- contact --}}
                    <div>
                        <dt class="{{ $dt }}">Contact</dt>
                        <dd class="{{ $dd }}">
                            @if ($log->subscriber && ! $log->subscriber->trashed())
                                @if ($canViewContact)
                                    <a href="{{ route('subscribers.show', $log->subscriber->id) }}"
                                       class="font-medium text-brand-600 hover:text-brand-700">
                                        {{ filled($log->subscriber->name) ? $log->subscriber->name : $log->subscriber->email }}
                                    </a>
                                    <span class="block text-xs text-ink-500">{{ $log->subscriber->email }}</span>
                                @else
                                    {{ $log->subscriber->email }}
                                    <span class="block text-xs text-ink-500">
                                        You do not have permission to open contacts.
                                    </span>
                                @endif
                            @elseif ($log->subscriber)
                                {{ $log->subscriber->email }}
                                <span class="block text-xs text-ink-500">
                                    This contact was deleted after the send.
                                </span>
                            @else
                                <span class="text-ink-500">
                                    No contact record is attached — the address above is everything
                                    this row holds.
                                </span>
                            @endif
                        </dd>
                    </div>

                    {{-- smtp --}}
                    <div>
                        <dt class="{{ $dt }}">SMTP account</dt>
                        <dd class="{{ $dd }}">
                            @if ($log->smtpAccount && ! $log->smtpAccount->trashed())
                                @if ($canViewSmtp)
                                    <a href="{{ route('smtp.show', $log->smtpAccount->id) }}"
                                       class="font-medium text-brand-600 hover:text-brand-700">
                                        {{ $log->smtpAccount->name }}
                                    </a>
                                @else
                                    {{ $log->smtpAccount->name }}
                                    <span class="block text-xs text-ink-500">
                                        You do not have permission to open SMTP accounts.
                                    </span>
                                @endif
                            @elseif ($log->smtpAccount)
                                {{ $log->smtpAccount->name }}
                                <span class="block text-xs text-ink-500">
                                    This sending account has since been removed.
                                </span>
                            @else
                                <span class="text-ink-500">
                                    No sending account was recorded. That usually means the attempt
                                    failed before one was picked.
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $dt }}">Type</dt>
                        <dd class="{{ $dd }}">{{ $typeLabels[$log->type] ?? ucfirst((string) $log->type) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="kn-card">
                <div class="kn-card-body text-xs leading-relaxed text-ink-500">
                    Log rows are written by the sender as each message goes out and are never
                    edited afterwards, which is why there is nothing to change on this screen.
                    Opens, clicks and unsubscribes are tracked separately and are not part of
                    this record.
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
