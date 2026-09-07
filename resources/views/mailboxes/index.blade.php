<x-app-layout>
    <x-slot name="header">Mailboxes</x-slot>

    @php
        // 0 is a real limit value meaning "switched off entirely" (PlanLimits),
        // so it is treated as "not included" rather than "you filled it up".
        $planBlocked = ! $imapAllowed || ($mailboxLimit !== null && $mailboxLimit <= 0);
        $atLimit     = ! $planBlocked && $mailboxLimit !== null && $mailboxesUsed >= $mailboxLimit;
        $canManage   = (bool) auth()->user()?->hasPermission('mailboxes.manage');

        // Decided once: the "Add" control appears in the header and in the
        // empty state, and must not leave a dead button behind when the plan
        // or the role says no.
        $canShowAdd = ! $planBlocked && ! $atLimit && $canManage;

        $subtitle = $planBlocked
            ? 'Receiving over IMAP is not part of your plan'
            : ($mailboxLimit === null
                ? number_format($mailboxesUsed).' '.\Illuminate\Support\Str::plural('mailbox', $mailboxesUsed).' connected · your plan sets no limit'
                : number_format($mailboxesUsed).' of '.number_format($mailboxLimit).' '.\Illuminate\Support\Str::plural('mailbox', $mailboxLimit).' used');

        // Literal map: 'pending' must not borrow the shared amber that reads
        // like a warning, and must never read as green.
        $statusMap = [
            'connected' => 'kn-badge-green',
            'pending' => 'kn-badge-gray',
            'error' => 'kn-badge-red',
            'disconnected' => 'kn-badge-amber',
        ];

        $encryptionLabels = ['ssl' => 'Implicit SSL', 'tls' => 'STARTTLS', 'none' => 'No encryption'];

        $megabytes = fn (int $value) => $value >= 1024
            ? number_format($value / 1024, 1).' GB'
            : number_format($value).' MB';

        // A limit of 0 is "no storage on this plan", not "an empty allowance".
        // PlanLimits::hasRoomFor() then refuses every attachment, so a meter is
        // meaningless here — "0 MB of 0 MB used · 0 MB still free" reads as room
        // to spare for an account that can store nothing. Say it in words.
        $storageOff = $storageLimit !== null && $storageLimit <= 0;

        $storagePercent = ($storageLimit === null || $storageOff)
            ? 0
            : min(100, (int) round($storageUsed / $storageLimit * 100));

        $storageBar = $storageLimit === null
            ? 'bg-ink-300'
            : ($storagePercent >= 90 ? 'bg-red-500' : ($storagePercent >= 75 ? 'bg-amber-500' : 'bg-brand-600'));

        // Read from the job rather than repeated here: if it ever gives up at a
        // different number, a hard-coded 10 would mislabel every mailbox it
        // switched off, and the sentence below would quote the wrong figure.
        $giveUpAfter = \App\Jobs\Imap\SyncMailboxJob::GIVE_UP_AFTER;
    @endphp

    <x-page-header title="Mailboxes" :subtitle="$subtitle">
        <x-slot name="actions">
            @if ($planBlocked)
                <p class="max-w-xs text-xs text-ink-500">
                    Your plan does not include receiving mail over IMAP. Contact support to have it enabled.
                </p>
            @elseif ($atLimit)
                <span class="kn-badge-amber">Mailbox limit reached</span>
            @elseif (! $canManage)
                <p class="max-w-xs text-xs text-ink-500">
                    You can read the mailboxes below, but connecting one needs the mailbox management permission.
                </p>
            @else
                <a href="{{ route('mailboxes.create') }}" class="kn-btn-primary">Add mailbox</a>
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------- explainer --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body space-y-2">
            <p class="text-sm text-ink-700">
                A mailbox is used for <span class="font-semibold text-ink-900">receiving only</span>. It connects
                over IMAP and pulls messages in. Sending is configured separately under SMTP accounts — the two
                never share credentials.
            </p>
            <p class="text-sm text-ink-600">
                Each mailbox is polled on its own interval, and a reply that arrives to one of these addresses is
                matched back to the campaign it answers. Disconnecting a mailbox here never touches anything on the
                mail server itself.
            </p>
        </div>
    </div>

    {{-- --------------------------------------------------------- storage --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-ink-900">Attachment storage</h2>
                <p class="text-sm font-semibold text-ink-900">
                    @if ($storageOff)
                        Not part of your plan
                    @elseif ($storageLimit === null)
                        {{ $megabytes($storageUsed) }} used · no limit on your plan
                    @else
                        {{ $megabytes($storageUsed) }} of {{ $megabytes($storageLimit) }} used
                    @endif
                </p>
            </div>

            {{-- No bar where there is no allowance: an empty meter beside a zero
                 limit is a picture of headroom that does not exist. --}}
            @unless ($storageOff)
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-ink-200">
                    <div class="h-2 rounded-full {{ $storageBar }}"
                         style="width: {{ $storageLimit === null ? 100 : $storagePercent }}%"></div>
                </div>
            @endunless

            <p class="mt-2 text-xs text-ink-500">
                Files attached to the messages your mailboxes sync are what use this space; the message text itself
                does not count against it.
                @if ($storageOff)
                    <span class="font-semibold text-amber-700">Your plan stores no attachments.</span>
                    Messages still sync in full; the files attached to them are skipped. Upgrade to keep attachments.
                    @if ($storageUsed > 0)
                        {{ $megabytes($storageUsed) }} stored under an earlier plan is still held and still readable.
                    @endif
                @elseif ($storageLimit === null)
                    Your plan sets no storage limit.
                @elseif ($storagePercent >= 90)
                    <span class="font-semibold text-red-700">Almost full.</span>
                    Once it is full, new attachments are skipped and the messages arrive without them — delete
                    messages you no longer need, or upgrade for more space.
                @elseif ($storagePercent >= 75)
                    <span class="font-semibold text-amber-700">{{ 100 - $storagePercent }}% left.</span>
                    When it runs out, messages still sync but their attachments are skipped.
                @else
                    {{ $megabytes(max(0, $storageLimit - $storageUsed)) }} still free.
                @endif
            </p>
        </div>
    </div>

    {{-- -------------------------------------------------------- mailboxes --}}
    @if ($mailboxes->isEmpty())
        <div class="kn-card">
            {{-- The message has to match what this user can actually do: telling
                 someone whose plan has no IMAP to "connect a mailbox" is a dead
                 end, because there is no control to do it with. --}}
            <x-empty-state title="No mailbox connected yet"
                           :message="$planBlocked
                               ? 'Replies to your campaigns cannot be read until a mailbox is connected. Receiving over IMAP is not part of your plan, so contact support to have it enabled.'
                               : ($canManage
                                   ? 'Replies to your campaigns cannot be read until a mailbox is connected. Add the IMAP details for the address your campaigns reply to, and messages start arriving here.'
                                   : 'Replies to your campaigns cannot be read until a mailbox is connected. Ask an administrator on your account to connect one.')">
                @if ($canShowAdd)
                    <x-slot name="action">
                        <a href="{{ route('mailboxes.create') }}" class="kn-btn-primary">Connect a mailbox</a>
                    </x-slot>
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($mailboxes as $mailbox)
                @php
                    // Cast at the edge: withCount() adds folders_count without a
                    // cast, and the counters come straight off the row, so a
                    // strict comparison against them is only as reliable as the
                    // driver's typing.
                    $failures = (int) $mailbox->consecutive_failures;
                    $messages = (int) $mailbox->messages_count;
                    $unread = (int) $mailbox->unread_count;
                    $folderCount = (int) ($mailbox->folders_count ?? 0);
                    $interval = (int) $mailbox->sync_interval_minutes;

                    // Switched off by the sync job itself after a long run of
                    // failures — a different thing from an operator pausing it,
                    // and the only case that gets the Resume control.
                    $autoOff = ! $mailbox->sync_enabled && $failures >= $giveUpAfter;
                    $manualSyncOff = ! $mailbox->sync_enabled && ! $autoOff;
                    $running = $mailbox->last_sync_status === 'running';

                    // SyncMailboxJob::recordFailure() writes last_error_at but
                    // never last_sync_at, so on a failed pass last_sync_at still
                    // holds the last pass that COMPLETED. Dating the failure
                    // from it puts the failure hours before it happened.
                    $failedAt = $mailbox->last_error_at ?? $mailbox->last_sync_at;

                    // The job returns early unless both flags are on, so a
                    // "Sync now" button on a paused mailbox would queue work
                    // that silently does nothing.
                    $canSyncNow = $mailbox->is_active && $mailbox->sync_enabled;

                    $provider = \App\Support\ImapProviders::get((string) $mailbox->provider);
                @endphp

                <div class="kn-card">
                    <div class="kn-card-header flex-wrap">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('mailboxes.show', $mailbox) }}"
                                   class="truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                                    {{ $mailbox->name }}
                                </a>
                                <x-status-badge :status="$mailbox->status" :map="$statusMap" />
                                @if (! $mailbox->is_active)
                                    <span class="kn-badge-gray">Switched off</span>
                                @endif
                                @if ($autoOff)
                                    <span class="kn-badge-red">Sync switched off automatically</span>
                                @elseif ($manualSyncOff)
                                    <span class="kn-badge-amber">Automatic sync off</span>
                                @endif
                                @if ($running)
                                    <span class="kn-badge-blue">Syncing now</span>
                                @endif
                            </div>
                            <p class="mt-1 break-words text-xs text-ink-500">
                                {{ $mailbox->email }}
                                · {{ $provider['label'] }}
                                · {{ $mailbox->imap_host }}:{{ $mailbox->imap_port }}
                                · {{ $encryptionLabels[$mailbox->imap_encryption] ?? $mailbox->imap_encryption }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <a href="{{ route('mailboxes.show', $mailbox) }}" class="kn-btn-ghost kn-btn-sm">View</a>

                            @permission('mailboxes.manage')
                                <a href="{{ route('mailboxes.edit', $mailbox) }}" class="kn-btn-secondary kn-btn-sm">Edit</a>

                                @if ($canSyncNow)
                                    <form method="POST" action="{{ route('mailboxes.sync', $mailbox) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="kn-btn-secondary kn-btn-sm">Sync now</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('mailboxes.toggle', $mailbox) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="kn-btn-secondary kn-btn-sm">
                                        {{ $mailbox->is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                </form>

                                <x-confirm-form :action="route('mailboxes.destroy', $mailbox)"
                                                label="Delete"
                                                :message="'Disconnect '.$mailbox->email.'? The '.($messages === 1 ? '1 message' : number_format($messages).' messages').' already synced stay here, and nothing is removed from the mail server — KN Softic simply stops connecting to it.'" />
                            @endpermission
                        </div>
                    </div>

                    <div class="kn-card-body space-y-4">

                        {{-- counts --}}
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="min-w-0">
                                <p class="kn-stat-label">Messages</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink-900">
                                    {{ number_format($messages) }}
                                </p>
                            </div>
                            <div class="min-w-0">
                                <p class="kn-stat-label">Unread</p>
                                <p class="mt-0.5 text-sm font-semibold {{ $unread > 0 ? 'text-brand-700' : 'text-ink-900' }}">
                                    {{ number_format($unread) }}
                                </p>
                            </div>
                            <div class="min-w-0">
                                <p class="kn-stat-label">Folders</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink-900">
                                    {{ number_format($folderCount) }}
                                    @if ($folderCount === 0)
                                        <span class="font-normal text-ink-500">· mapped on the first sync</span>
                                    @endif
                                </p>
                            </div>
                            <div class="min-w-0">
                                <p class="kn-stat-label">Checked every</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink-900">
                                    {{ number_format($interval) }}
                                    {{ \Illuminate\Support\Str::plural('minute', $interval) }}
                                    <span class="font-normal text-ink-500">· up to {{ number_format((int) $mailbox->sync_limit) }} a pass</span>
                                </p>
                            </div>
                        </div>

                        {{-- last sync --}}
                        <div class="flex flex-wrap items-center gap-2 text-xs text-ink-500">
                            @if ($mailbox->last_sync_at === null)
                                <span class="kn-badge-gray">Never synced</span>
                                <span>
                                    No pass has completed yet.
                                    @if ($mailbox->last_sync_status === 'failed')
                                        The first attempt failed.
                                    @endif
                                </span>
                            @elseif ($mailbox->last_sync_status === 'failed')
                                <span class="kn-badge-red">Last sync failed</span>
                                <span title="{{ $failedAt->format('D, d M Y H:i') }}">
                                    {{ $failedAt->diffForHumans() }}
                                </span>
                            @elseif ($mailbox->last_sync_status === 'success')
                                <span class="kn-badge-green">Last sync succeeded</span>
                                <span title="{{ $mailbox->last_sync_at->format('D, d M Y H:i') }}">
                                    {{ $mailbox->last_sync_at->diffForHumans() }}
                                </span>
                            @else
                                {{-- 'running' (or a row written before this
                                     column existed): the pass in flight has not
                                     said anything yet, and the one before it may
                                     well have failed. Report the completed pass
                                     without claiming it went well. --}}
                                <span class="kn-badge-gray">Last completed pass</span>
                                <span title="{{ $mailbox->last_sync_at->format('D, d M Y H:i') }}">
                                    {{ $mailbox->last_sync_at->diffForHumans() }}
                                </span>
                            @endif

                            @if ($running && $mailbox->last_sync_started_at !== null)
                                <span title="{{ $mailbox->last_sync_started_at->format('D, d M Y H:i') }}">
                                    · this pass started {{ $mailbox->last_sync_started_at->diffForHumans() }}
                                </span>
                            @endif

                            @if ($mailbox->isDueForSync())
                                <span>· due for the next pass now</span>
                            @endif

                            @if ($mailbox->last_tested_at !== null)
                                <span title="{{ $mailbox->last_tested_at->format('D, d M Y H:i') }}">
                                    · tested {{ $mailbox->last_tested_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>

                        {{-- ------------------------------------ health state --}}

                        @if ($autoOff)
                            {{-- The one state an operator cannot work out on their
                                 own: it stopped by itself, and without saying so
                                 the mailbox just looks idle. --}}
                            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                                <p class="text-sm font-semibold text-red-800">
                                    Automatic syncing was switched off automatically
                                </p>
                                <p class="mt-1 text-xs text-red-700">
                                    This mailbox failed {{ number_format($failures) }} times in a
                                    row, so syncing was switched off rather than keep spending the provider's rate
                                    limit on the same error. Nothing has been fetched since. The credentials are still
                                    stored — fix whatever the error below points at, then resume.
                                </p>
                                @if (filled($mailbox->last_error))
                                    <p class="mt-2 break-words rounded bg-red-100/70 p-2 font-mono text-xs text-red-900">
                                        {{ $mailbox->last_error }}
                                    </p>
                                @endif
                                @if ($mailbox->last_error_at !== null)
                                    <p class="mt-1 text-xs text-red-700" title="{{ $mailbox->last_error_at->format('D, d M Y H:i') }}">
                                        Last failure {{ $mailbox->last_error_at->diffForHumans() }}.
                                    </p>
                                @endif
                                @permission('mailboxes.manage')
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <form method="POST" action="{{ route('mailboxes.resume', $mailbox) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="kn-btn-primary kn-btn-sm">Resume syncing</button>
                                        </form>
                                        <a href="{{ route('mailboxes.edit', $mailbox) }}" class="kn-btn-secondary kn-btn-sm">
                                            Fix the settings
                                        </a>
                                        <span class="text-xs text-red-700">
                                            Resuming clears the failure count and puts the mailbox back to untested.
                                        </span>
                                    </div>
                                @endpermission
                            </div>

                        @elseif ($mailbox->status === 'error')
                            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                                <p class="text-sm font-semibold text-red-800">
                                    The last connection to this mailbox failed
                                </p>
                                <p class="mt-1 text-xs text-red-700">
                                    {{ number_format($failures) }} consecutive
                                    {{ \Illuminate\Support\Str::plural('failure', $failures) }} so far.
                                    After {{ $giveUpAfter }} in a row, automatic syncing switches itself off.
                                    @if ($provider['note'] ?? false)
                                        <span class="block mt-1">{{ $provider['note'] }}</span>
                                    @endif
                                </p>
                                @if (filled($mailbox->last_error))
                                    <p class="mt-2 break-words rounded bg-red-100/70 p-2 font-mono text-xs text-red-900">
                                        {{ $mailbox->last_error }}
                                    </p>
                                @endif
                                @if ($mailbox->last_error_at !== null)
                                    <p class="mt-1 text-xs text-red-700" title="{{ $mailbox->last_error_at->format('D, d M Y H:i') }}">
                                        Failed {{ $mailbox->last_error_at->diffForHumans() }}.
                                    </p>
                                @endif
                                @permission('mailboxes.manage')
                                    <div class="mt-3">
                                        <a href="{{ route('mailboxes.show', $mailbox) }}" class="kn-btn-secondary kn-btn-sm">
                                            Test the connection
                                        </a>
                                    </div>
                                @endpermission
                            </div>

                        @elseif ($mailbox->status === 'pending')
                            {{-- Never present 'pending' as if it were healthy: it
                                 means no test has succeeded against the settings
                                 currently saved. --}}
                            <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3">
                                <p class="text-sm font-semibold text-ink-800">Connection not proven yet</p>
                                <p class="mt-1 text-xs text-ink-600">
                                    No connection test has succeeded since these settings were saved, so nothing here
                                    says this mailbox works. Run a test before relying on it. For
                                    {{ $provider['label'] }} the password field expects:
                                    {{ $provider['password_hint'] }}.
                                </p>
                                @permission('mailboxes.manage')
                                    <div class="mt-3">
                                        <a href="{{ route('mailboxes.show', $mailbox) }}" class="kn-btn-secondary kn-btn-sm">
                                            Test the connection
                                        </a>
                                    </div>
                                @endpermission
                            </div>

                        @elseif ($mailbox->status === 'disconnected')
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <p class="text-sm font-semibold text-amber-800">Disconnected</p>
                                <p class="mt-1 text-xs text-amber-700">
                                    The server is no longer accepting this connection. Messages already synced stay
                                    here; nothing new arrives until the connection works again.
                                </p>
                            </div>
                        @endif

                        {{-- paused states, which are choices rather than faults --}}
                        @if (! $mailbox->is_active)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <p class="text-sm font-semibold text-amber-800">This mailbox is switched off</p>
                                <p class="mt-1 text-xs text-amber-700">
                                    Nothing syncs, and "Sync now" is hidden because it would do nothing. Messages
                                    already here stay readable. Switch it back on to start checking the server again.
                                </p>
                            </div>
                        @elseif ($manualSyncOff)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <p class="text-sm font-semibold text-amber-800">Automatic syncing is turned off</p>
                                <p class="mt-1 text-xs text-amber-700">
                                    This was set on the mailbox itself, not caused by an error. No pass runs on the
                                    interval and a manual sync would do nothing until it is turned back on under Edit.
                                </p>
                            </div>
                        @elseif ($mailbox->isHealthy())
                            <p class="text-xs text-ink-500">
                                {{-- Not "the last test connected": a successful sync
                                     also writes status = connected, so plenty of
                                     healthy mailboxes have never been tested. --}}
                                Healthy — the last connection succeeded, nothing has failed since, and the next pass
                                runs on the {{ number_format($interval) }}-minute interval.
                                @if ($mailbox->last_tested_at === null && $mailbox->last_sync_at !== null)
                                    <span class="text-ink-400">No manual test has been run — a completed sync is what proved it.</span>
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
