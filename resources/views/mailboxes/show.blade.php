<x-app-layout>
    <x-slot name="header">{{ $mailbox->name }}</x-slot>

    @php
        /**
         * Mailbox detail.
         *
         * Everything on this page comes from the row and the three collections
         * the controller passes ($folders, $recent, $provider). No relation is
         * touched here — lazy loading is prevented outside production, and a
         * detail screen that only breaks on a developer's machine is worse
         * than one that never had the field.
         */

        $canManage = (bool) auth()->user()?->hasPermission('mailboxes.manage');

        $encryptionLabels = [
            'ssl' => 'Implicit SSL',
            'tls' => 'STARTTLS (TLS)',
            'none' => 'No encryption',
        ];

        // 'pending' is amber on purpose: it means nobody has proved this
        // connection works, which is not the same thing as healthy.
        $statusMap = [
            'connected' => 'kn-badge-green',
            'pending' => 'kn-badge-amber',
            'error' => 'kn-badge-red',
            'disconnected' => 'kn-badge-gray',
        ];

        $syncStatusMap = [
            'success' => 'kn-badge-green',
            'failed' => 'kn-badge-red',
            'running' => 'kn-badge-blue',
        ];

        $folderTypeLabels = [
            'inbox' => 'Inbox',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'archive' => 'Archive',
            'spam' => 'Spam',
            'trash' => 'Trash',
            'custom' => 'Not recognised',
        ];

        $folderTypeBadges = [
            'inbox' => 'kn-badge-blue',
            'sent' => 'kn-badge-blue',
            'drafts' => 'kn-badge-gray',
            'archive' => 'kn-badge-gray',
            'spam' => 'kn-badge-amber',
            'trash' => 'kn-badge-amber',
            'custom' => 'kn-badge-gray',
        ];

        // Same rule as the SMTP screens: enough of the local part to recognise
        // the account, never enough to retype it somewhere else.
        $maskUsername = function (?string $username): string {
            $username = trim((string) $username);

            if ($username === '') {
                return '—';
            }

            if (! str_contains($username, '@')) {
                return mb_substr($username, 0, 2).str_repeat('*', max(0, mb_strlen($username) - 2));
            }

            [$local, $domain] = explode('@', $username, 2);

            return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
        };

        $failures = (int) $mailbox->consecutive_failures;
        $neverTested = $mailbox->last_tested_at === null;
        $neverSynced = $mailbox->last_sync_at === null;

        // Ten failures in a row is what the sync job gives up after. Both
        // conditions matter: sync_enabled alone could simply have been
        // unticked on the edit form, which is a different situation.
        $autoDisabled = ! $mailbox->sync_enabled && $failures >= \App\Jobs\Imap\SyncMailboxJob::GIVE_UP_AFTER;
        $manuallyPaused = ! $mailbox->sync_enabled && ! $autoDisabled;

        // The queued job returns immediately when either flag is off, so a
        // "Sync now" button in those states would be a button that does
        // nothing. It is not offered.
        $canSyncNow = $mailbox->is_active && $mailbox->sync_enabled;

        /**
         * Has this mailbox ever actually connected? There is no "ever
         * connected" column, so this is read from status + last_tested_at and
         * says only what those two can support.
         */
        $verdict = match (true) {
            $neverTested => [
                'box' => 'rounded-lg border border-ink-200 bg-ink-50 px-4 py-3',
                'title' => 'text-sm font-semibold text-ink-800',
                'body' => 'mt-1 text-xs text-ink-600',
                'heading' => 'This mailbox has never connected.',
                'text' => 'No connection has been attempted yet, so nothing here is confirmed — the host, port and '
                    .'password are only what was typed in. Run a connection test before the first sync.',
            ],

            $mailbox->status === 'connected' => [
                'box' => 'rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3',
                'title' => 'text-sm font-semibold text-emerald-800',
                'body' => 'mt-1 text-xs text-emerald-700',
                'heading' => 'This mailbox has connected successfully.',
                'text' => 'The last test signed in and listed the folders '.$mailbox->last_tested_at->diffForHumans()
                    .' ('.$mailbox->last_tested_at->format('D, d M Y H:i').').',
            ],

            $mailbox->status === 'error' => [
                'box' => 'rounded-lg border border-red-200 bg-red-50 px-4 py-3',
                'title' => 'text-sm font-semibold text-red-800',
                'body' => 'mt-1 text-xs text-red-700',
                'heading' => 'The last connection attempt failed.',
                'text' => 'Attempted '.$mailbox->last_tested_at->diffForHumans()
                    .' ('.$mailbox->last_tested_at->format('D, d M Y H:i').'). The error is under Health, '
                    .'on the right.',
            ],

            $mailbox->status === 'disconnected' => [
                'box' => 'rounded-lg border border-ink-200 bg-ink-50 px-4 py-3',
                'title' => 'text-sm font-semibold text-ink-800',
                'body' => 'mt-1 text-xs text-ink-600',
                'heading' => 'This mailbox is marked disconnected.',
                'text' => 'The server is no longer accepting this connection. The last attempt was '
                    .$mailbox->last_tested_at->diffForHumans().'. Test it again to find out why.',
            ],

            // status 'pending' with a test already on record: the settings were
            // changed, or syncing was resumed, since that test ran.
            default => [
                'box' => 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3',
                'title' => 'text-sm font-semibold text-amber-800',
                'body' => 'mt-1 text-xs text-amber-700',
                'heading' => 'Waiting to be tested — not confirmed working.',
                'text' => 'These settings have not been proved since they last changed. The earlier attempt on '
                    .$mailbox->last_tested_at->format('D, d M Y H:i').' was against different settings and no '
                    .'longer counts. Run a connection test.',
            ],
        };

        $connection = [
            'Provider preset' => $provider['label'],
            'Host' => $mailbox->imap_host,
            'Port' => (string) $mailbox->imap_port,
            'Encryption' => $encryptionLabels[$mailbox->imap_encryption] ?? $mailbox->imap_encryption,
            'Certificate validation' => $mailbox->imap_validate_cert
                ? 'On'
                : 'Off — the connection is encrypted but the server is not authenticated',
            'Username' => $maskUsername($mailbox->imap_username),
            'Address' => $mailbox->email,
            'Sending account for replies' => $mailbox->smtp_account_id
                ? 'Linked — replies go out through the chosen SMTP account'
                : 'Not linked — set one from Edit before replying from this address',
            'Sync interval' => number_format((int) $mailbox->sync_interval_minutes).' '
                .\Illuminate\Support\Str::plural('minute', (int) $mailbox->sync_interval_minutes),
            'Messages per pass' => number_format((int) $mailbox->sync_limit).' per folder',
            'Connected on' => $mailbox->created_at?->format('d M Y H:i'),
        ];

        $syncableFolders = $folders->where('is_syncable', true)->count();

        // Later-phase screens. Guarded rather than assumed: this page must not
        // link at a route that does not exist yet.
        $hasInboxShow = \Illuminate\Support\Facades\Route::has('inbox.show');
        $hasInboxIndex = \Illuminate\Support\Facades\Route::has('inbox.index');
    @endphp

    <div @if ($canManage) x-data="knMailboxTest(@js(route('mailboxes.test', $mailbox)))" @endif class="min-w-0">

        <x-page-header :title="$mailbox->name"
                       :subtitle="$mailbox->email.' · '.$provider['label'].' · '.$mailbox->imap_host.':'.$mailbox->imap_port"
                       :back="route('mailboxes.index')">
            <x-slot name="actions">
                <x-status-badge :status="$mailbox->status" :map="$statusMap" />
                <x-status-badge :status="$mailbox->is_active ? 'active' : 'paused'" />

                @if ($neverTested)
                    <span class="kn-badge-gray">Never tested</span>
                @endif

                @permission('mailboxes.manage')
                    <button type="button" class="kn-btn-secondary" @click="run()" :disabled="loading"
                            :aria-busy="loading ? 'true' : 'false'"
                            aria-describedby="mailbox-test-result">
                        <svg x-show="loading" x-cloak class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                            <path class="opacity-90" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z"/>
                        </svg>
                        <span x-text="loading ? 'Testing…' : (result ? 'Test again' : 'Test connection')">Test connection</span>
                    </button>
                @endpermission

                @if ($canSyncNow)
                    <form method="POST" action="{{ route('mailboxes.sync', $mailbox) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary">Sync now</button>
                    </form>
                @endif

                @permission('mailboxes.manage')
                    <a href="{{ route('mailboxes.edit', $mailbox) }}" class="kn-btn-primary">Edit</a>

                    <form method="POST" action="{{ route('mailboxes.toggle', $mailbox) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary">
                            {{ $mailbox->is_active ? 'Switch off' : 'Switch on' }}
                        </button>
                    </form>

                    <x-confirm-form :action="route('mailboxes.destroy', $mailbox)"
                                    label="Disconnect"
                                    :message="'Disconnect '.$mailbox->email.'? Messages already synced stay in this account, and nothing is deleted from the mail server — only the connection and its credentials are removed.'" />
                @endpermission
            </x-slot>
        </x-page-header>

        {{-- --------------------------------------- switched off automatically --}}
        @if ($autoDisabled)
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-4">
                <p class="text-sm font-semibold text-red-800">
                    Syncing was switched off automatically after repeated failures.
                </p>
                <p class="mt-1 text-sm text-red-700">
                    This mailbox failed {{ number_format($failures) }} times in a row, so it stopped being polled —
                    retrying every {{ number_format((int) $mailbox->sync_interval_minutes) }}
                    {{ \Illuminate\Support\Str::plural('minute', (int) $mailbox->sync_interval_minutes) }} was
                    spending the provider's rate limit to relearn the same error. Nothing has been deleted and the
                    credentials are still stored. Fix the cause — most often a changed or expired app password —
                    then use Resume. That is the only way to turn automatic syncing back on.
                </p>

                @permission('mailboxes.manage')
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-confirm-form :action="route('mailboxes.resume', $mailbox)"
                                        method="POST"
                                        label="Resume syncing"
                                        button-class="kn-btn-danger kn-btn-sm"
                                        message="Turn automatic syncing back on and clear the failure count? If the underlying problem is still there it will simply start failing again — test the connection first." />
                        <a href="{{ route('mailboxes.edit', $mailbox) }}" class="kn-btn-secondary kn-btn-sm">
                            Update the password
                        </a>
                    </div>
                @endpermission
            </div>
        @elseif ($manuallyPaused)
            <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-sm font-semibold text-amber-800">Automatic syncing is switched off.</p>
                <p class="mt-1 text-xs text-amber-700">
                    Nothing new is being fetched for this mailbox. Tick "Sync automatically" on the edit screen to
                    start again — messages already synced are unaffected.
                </p>
            </div>
        @elseif (! $mailbox->is_active)
            <div class="mb-5 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3">
                <p class="text-sm font-semibold text-ink-800">This mailbox is switched off.</p>
                <p class="mt-1 text-xs text-ink-600">
                    No sync runs and no connection is made while it is off. Everything already synced stays where
                    it is. Use "Switch on" to start again.
                </p>
            </div>
        @endif

        {{-- ------------------------------------------------------- counters --}}
        @if (! $neverSynced || $folders->isNotEmpty())
            <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat-card label="Messages synced"
                             :value="number_format((int) $mailbox->messages_count)"
                             meta="Stored in this account, across every synced folder" />

                <x-stat-card label="Unread"
                             :value="number_format((int) $mailbox->unread_count)"
                             :tone="(int) $mailbox->unread_count > 0 ? 'warning' : 'default'"
                             meta="As the server reported them at the last sync" />

                <x-stat-card label="Folders found"
                             :value="number_format($folders->count())"
                             :meta="number_format($syncableFolders).' '.\Illuminate\Support\Str::plural('folder', $syncableFolders).' set to sync'" />

                <x-stat-card label="Last sync"
                             :value="$mailbox->last_sync_at?->diffForHumans() ?? 'Never'"
                             :tone="$mailbox->last_sync_status === 'failed' ? 'danger' : 'default'"
                             :meta="$mailbox->last_sync_at?->format('D, d M Y H:i') ?? 'No pass has completed yet'" />
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">

            {{-- ======================================================== left --}}
            <div class="space-y-6 lg:col-span-2">

                {{-- ------------------------------------------- connection --}}
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">IMAP connection</h3>
                        <span class="text-xs text-ink-500">Receiving only</span>
                    </div>

                    <div class="px-5 pt-5">
                        <div class="{{ $verdict['box'] }}">
                            <p class="{{ $verdict['title'] }}">{{ $verdict['heading'] }}</p>
                            <p class="{{ $verdict['body'] }}">{{ $verdict['text'] }}</p>
                        </div>
                    </div>

                    <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                        @foreach ($connection as $label => $value)
                            <div class="min-w-0">
                                <dt class="kn-stat-label">{{ $label }}</dt>
                                <dd class="mt-0.5 break-words text-sm text-ink-800">
                                    {{ filled($value) ? $value : '—' }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="border-t border-ink-100 px-5 py-4">
                        <p class="text-xs text-ink-500">
                            The username is shown partly masked and the password is never displayed again — it is
                            encrypted at rest and can only be replaced, from the edit screen. Leaving the password
                            field blank there keeps the stored one.
                        </p>
                    </div>

                    {{-- ------------------------------------ live test result --}}
                    @permission('mailboxes.manage')
                        <div class="border-t border-ink-100 px-5 py-4">
                            <p class="text-xs text-ink-500">
                                A test connects, signs in and asks the server for its folder list. Listing folders is
                                part of it on purpose: a work account will often authenticate happily and then refuse
                                to list anything because IMAP is disabled for the mailbox. No mail is downloaded and
                                nothing is sent.
                            </p>

                            <div id="mailbox-test-result" role="status" aria-live="polite" class="min-w-0">

                                <div x-show="result && result.ok" x-cloak
                                     class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                                    <p class="text-sm font-semibold text-emerald-800" x-text="result ? result.summary : ''"></p>
                                    <p class="mt-1 break-words text-xs text-emerald-700" x-text="result ? result.detail : ''"></p>
                                    <p class="mt-2 text-xs text-emerald-600">
                                        Tested <span x-text="result ? result.tested_at : ''"></span>. The stored status
                                        on this page updates the next time you load it. The folder list below does not:
                                        a test reads the folders but never saves them — only a sync does that.
                                    </p>
                                </div>

                                <div x-show="result && ! result.ok" x-cloak
                                     class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                                    <p class="text-sm font-semibold text-red-800" x-text="result ? result.summary : ''"></p>

                                    <p class="mt-1 text-xs text-red-700">
                                        <span x-show="result && result.kindLabel" x-cloak>
                                            Cause: <span x-text="result ? result.kindLabel : ''"></span> ·
                                        </span>
                                        <span x-show="result && result.tested_at" x-cloak>
                                            Attempted <span x-text="result ? result.tested_at : ''"></span>
                                        </span>
                                    </p>

                                    <button type="button" x-show="result && result.detail" x-cloak
                                            @click="showDetail = ! showDetail"
                                            :aria-expanded="showDetail ? 'true' : 'false'"
                                            class="mt-2 text-xs font-semibold text-red-700 underline underline-offset-2 hover:text-red-900"
                                            x-text="showDetail ? 'Hide technical detail' : 'Show technical detail'"></button>

                                    <pre x-show="showDetail" x-cloak
                                         class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap break-words rounded bg-red-100/70 p-3 text-xs text-red-900"
                                         x-text="result ? result.detail : ''"></pre>
                                </div>
                            </div>
                        </div>
                    @endpermission
                </div>

                {{-- ----------------------------------------- never synced --}}
                @if ($neverSynced)
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h3 class="text-sm font-semibold text-ink-900">Nothing has synced yet</h3>
                        </div>
                        <div class="kn-card-body space-y-3">
                            <p class="text-sm text-ink-700">
                                No sync pass has finished for this mailbox, so there are no folders, counts or
                                messages to show — zeros here would only look like an empty mailbox, which is a
                                different thing.
                            </p>
                            <ol class="list-decimal space-y-1.5 pl-5 text-sm text-ink-600">
                                <li>Test the connection, so a failure is reported as a password or host problem
                                    rather than as a silent empty inbox.</li>
                                <li>The first pass reads the folder list and works out which folder is the Inbox,
                                    which is Sent, and so on.</li>
                                <li>It then fetches up to
                                    {{ number_format((int) $mailbox->sync_limit) }}
                                    {{ \Illuminate\Support\Str::plural('message', (int) $mailbox->sync_limit) }}
                                    per folder; the pass after that continues from where it stopped.</li>
                                <li>After that it repeats every
                                    {{ number_format((int) $mailbox->sync_interval_minutes) }}
                                    {{ \Illuminate\Support\Str::plural('minute', (int) $mailbox->sync_interval_minutes) }}
                                    while the mailbox is on and syncing is enabled.</li>
                            </ol>
                            <p class="text-xs text-ink-500">
                                Trash and Spam are never synced, so those folders stay empty here by design.
                            </p>
                        </div>
                    </div>
                @endif

                {{-- --------------------------------------------- folders --}}
                <div class="kn-card overflow-hidden">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Folders</h3>
                        <span class="text-xs text-ink-500">
                            {{ number_format($folders->count()) }} found ·
                            {{ number_format($syncableFolders) }} syncing
                        </span>
                    </div>

                    @if ($folders->isEmpty())
                        <x-empty-state title="No folders discovered yet"
                                       message="Folders are stored on the first sync. A connection test reads them, to prove the account can actually be used, but does not save them — so nothing is listed here until a sync has run." />
                    @else
                        <div class="border-b border-ink-100 px-5 py-3">
                            <p class="text-xs text-ink-500">
                                The type comes from the server's own SPECIAL-USE attribute where the server publishes
                                one, and from the folder name otherwise — so anything left as "Not recognised" is
                                still synced, just not claimed to be one of the well-known folders. Trash and Spam
                                are deliberately not synced.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="kn-table">
                                <thead>
                                    <tr>
                                        <th>Folder</th>
                                        <th>Recognised as</th>
                                        <th>Syncing</th>
                                        <th class="text-right">Messages</th>
                                        <th class="text-right">Unread</th>
                                        <th>Last synced</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($folders as $folder)
                                        <tr>
                                            <td>
                                                <div class="min-w-0 max-w-xs">
                                                    <span class="block truncate font-medium text-ink-900">{{ $folder->display_name }}</span>
                                                    <span class="block truncate font-mono text-xs text-ink-500" title="{{ $folder->path }}">
                                                        {{ $folder->path }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="{{ $folderTypeBadges[$folder->type] ?? 'kn-badge-gray' }}">
                                                    {{ $folderTypeLabels[$folder->type] ?? ucfirst((string) $folder->type) }}
                                                </span>
                                            </td>
                                            <td>
                                                @if ($folder->is_syncable)
                                                    <span class="kn-badge-green">Yes</span>
                                                @elseif (in_array($folder->type, ['trash', 'spam'], true))
                                                    <span class="kn-badge-gray">Never</span>
                                                @else
                                                    <span class="kn-badge-gray">No</span>
                                                @endif
                                            </td>
                                            <td class="text-right font-medium text-ink-900">{{ number_format((int) $folder->messages_count) }}</td>
                                            <td class="text-right {{ (int) $folder->unread_count > 0 ? 'font-medium text-amber-600' : 'text-ink-500' }}">
                                                {{ number_format((int) $folder->unread_count) }}
                                            </td>
                                            <td class="whitespace-nowrap text-xs text-ink-500">
                                                @if ($folder->last_sync_at)
                                                    <span title="{{ $folder->last_sync_at->format('D, d M Y H:i') }}">
                                                        {{ $folder->last_sync_at->diffForHumans() }}
                                                    </span>
                                                @elseif ($folder->is_syncable)
                                                    Not yet
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="border-t border-ink-100 px-5 py-3">
                            <p class="text-xs text-ink-500">
                                Each syncing folder is fetched incrementally: only UIDs above the highest one already
                                stored are requested, which is why a folder with thousands of old messages costs
                                nothing on later passes.
                            </p>
                        </div>
                    @endif
                </div>

                {{-- -------------------------------------- recent messages --}}
                <div class="kn-card overflow-hidden">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Recent messages</h3>
                        <span class="text-xs text-ink-500">
                            {{ $recent->isEmpty() ? 'Nothing yet' : 'Latest '.number_format($recent->count()).' by received date' }}
                        </span>
                    </div>

                    @if ($recent->isEmpty())
                        <x-empty-state title="No messages synced from this mailbox"
                                       :message="$neverSynced
                                           ? 'The first sync has not run. Messages appear here once a pass finishes.'
                                           : 'A sync has run but stored nothing new. Either the synced folders are empty, or everything in them was already fetched.'" />
                    @else
                        <div class="overflow-x-auto">
                            <table class="kn-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>From</th>
                                        <th>Folder</th>
                                        <th>Received</th>
                                        <th class="text-right">State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recent as $email)
                                        <tr>
                                            <td>
                                                <div class="min-w-0 max-w-sm">
                                                    @if ($hasInboxShow)
                                                        <a href="{{ route('inbox.show', $email) }}"
                                                           class="block truncate {{ $email->is_read ? 'text-ink-800' : 'font-semibold text-ink-900' }} hover:text-brand-600">
                                                            {{ filled($email->subject) ? $email->subject : '(no subject)' }}
                                                        </a>
                                                    @else
                                                        <span class="block truncate {{ $email->is_read ? 'text-ink-800' : 'font-semibold text-ink-900' }}">
                                                            {{ filled($email->subject) ? $email->subject : '(no subject)' }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                <div class="min-w-0 max-w-xs">
                                                    <span class="block truncate text-ink-800">
                                                        {{ filled($email->from_name) ? $email->from_name : ($email->from_email ?: 'Unknown sender') }}
                                                    </span>
                                                    @if (filled($email->from_name) && filled($email->from_email))
                                                        <span class="block truncate text-xs text-ink-500">{{ $email->from_email }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap">
                                                <span class="{{ $folderTypeBadges[$email->folder_type] ?? 'kn-badge-gray' }}">
                                                    {{ $folderTypeLabels[$email->folder_type] ?? ucfirst((string) $email->folder_type) }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap text-xs text-ink-500">
                                                @if ($email->received_at)
                                                    <span title="{{ $email->received_at->format('D, d M Y H:i') }}">
                                                        {{ $email->received_at->diffForHumans() }}
                                                    </span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                @if ($email->is_read)
                                                    <span class="kn-badge-gray">Read</span>
                                                @else
                                                    <span class="kn-badge-blue">Unread</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="border-t border-ink-100 px-5 py-3">
                            @if ($hasInboxIndex)
                                <a href="{{ route('inbox.index') }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700">
                                    Open the inbox
                                </a>
                            @else
                                <p class="text-xs text-ink-500">
                                    These messages are stored and searchable already. The inbox that opens and replies
                                    to them arrives in the next phase, so they are listed here without links for now.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- ======================================================= right --}}
            <div class="space-y-6">

                {{-- ---------------------------------------------- health --}}
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Health</h3>
                        @if ($mailbox->isHealthy())
                            <span class="kn-badge-green">Healthy</span>
                        @elseif ($autoDisabled)
                            <span class="kn-badge-red">Switched off automatically</span>
                        {{--
                            A failed test sets the status but not the failure
                            streak — only the sync job counts those — so status
                            is checked as well, or this header would be blank on
                            exactly the mailbox that needs attention.
                        --}}
                        @elseif ($failures > 0 || in_array($mailbox->status, ['error', 'disconnected'], true))
                            <span class="kn-badge-red">Failing</span>
                        @elseif ($neverTested)
                            <span class="kn-badge-gray">Unverified</span>
                        @elseif ($mailbox->status !== 'connected')
                            <span class="kn-badge-amber">Not confirmed</span>
                        @endif
                    </div>

                    <div class="space-y-4 p-5">
                        <div>
                            <p class="kn-stat-label">Last sync</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                @if ($mailbox->last_sync_at === null)
                                    <span class="kn-badge-gray">Never</span>
                                    <span class="text-xs text-ink-500">No pass has completed yet.</span>
                                @else
                                    @if ($mailbox->last_sync_status)
                                        <span class="{{ $syncStatusMap[$mailbox->last_sync_status] ?? 'kn-badge-gray' }}">
                                            {{ ucfirst($mailbox->last_sync_status) }}
                                        </span>
                                    @endif
                                    <span class="text-xs text-ink-500" title="{{ $mailbox->last_sync_at->format('D, d M Y H:i') }}">
                                        {{ $mailbox->last_sync_at->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            @if ($mailbox->last_sync_at)
                                <p class="mt-1 text-xs text-ink-500">{{ $mailbox->last_sync_at->format('D, d M Y H:i') }}</p>
                            @endif
                        </div>

                        <div>
                            <p class="kn-stat-label">Next automatic sync</p>
                            <p class="mt-0.5 text-sm text-ink-800">
                                @if (! $mailbox->is_active)
                                    Not scheduled — the mailbox is switched off
                                @elseif (! $mailbox->sync_enabled)
                                    Not scheduled — automatic syncing is off
                                @elseif ($mailbox->isDueForSync())
                                    Due now — the next scheduler run picks it up
                                @else
                                    {{ $mailbox->last_sync_at->copy()->addMinutes((int) $mailbox->sync_interval_minutes)->diffForHumans() }}
                                @endif
                            </p>
                        </div>

                        <div>
                            <p class="kn-stat-label">Last test</p>
                            <p class="mt-0.5 text-sm text-ink-800">
                                @if ($mailbox->last_tested_at === null)
                                    Never tested
                                @else
                                    <span title="{{ $mailbox->last_tested_at->format('D, d M Y H:i') }}">
                                        {{ $mailbox->last_tested_at->diffForHumans() }}
                                    </span>
                                    · {{ $mailbox->last_tested_at->format('D, d M Y H:i') }}
                                @endif
                            </p>
                        </div>

                        <div>
                            <p class="kn-stat-label">Consecutive failures</p>
                            <p class="mt-0.5 text-sm {{ $failures > 0 ? 'font-semibold text-red-600' : 'text-ink-800' }}">
                                {{ number_format($failures) }}
                                @if ($failures > 0 && ! $autoDisabled)
                                    <span class="text-xs font-normal text-ink-500">
                                        · syncing stops on its own at {{ \App\Jobs\Imap\SyncMailboxJob::GIVE_UP_AFTER }}
                                    </span>
                                @endif
                            </p>
                        </div>

                        @if ($autoDisabled)
                            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                                <p class="text-sm font-semibold text-red-800">
                                    Sync was switched off automatically after repeated failures.
                                </p>
                                <p class="mt-1 text-xs text-red-700">
                                    Resume is the only way to turn it back on — the control is in the banner at the
                                    top of this page.
                                </p>
                            </div>
                        @endif

                        @if (filled($mailbox->last_error))
                            <div>
                                <p class="kn-stat-label">Last error</p>
                                @if ($mailbox->last_error_at)
                                    <p class="mt-0.5 text-xs text-ink-500" title="{{ $mailbox->last_error_at->format('D, d M Y H:i') }}">
                                        {{ $mailbox->last_error_at->diffForHumans() }} · {{ $mailbox->last_error_at->format('D, d M Y H:i') }}
                                    </p>
                                @endif
                                <p class="mt-1.5 max-h-40 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-red-50 p-3 font-mono text-xs text-red-900">
                                    {{ $mailbox->last_error }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ------------------------------------- provider notes --}}
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">{{ $provider['label'] }}</h3>
                    </div>
                    <div class="kn-card-body space-y-3">
                        <p class="text-sm text-ink-700">{{ $provider['note'] }}</p>
                        <p class="text-xs text-ink-600">
                            Username: <span class="font-medium text-ink-800">{{ $provider['username_hint'] }}</span>
                        </p>
                        <p class="text-xs text-ink-600">
                            Password: <span class="font-medium text-ink-800">{{ $provider['password_hint'] }}</span>
                        </p>
                    </div>
                </div>

                {{-- ------------------------------------ what disconnect does --}}
                @permission('mailboxes.manage')
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h3 class="text-sm font-semibold text-ink-900">Disconnecting this mailbox</h3>
                        </div>
                        <div class="kn-card-body space-y-3">
                            <p class="text-sm text-ink-700">
                                Disconnecting removes the connection and its stored credentials from KN Softic.
                                Messages already synced stay in this account, and nothing at all is changed or
                                deleted on the mail server.
                            </p>
                            <x-confirm-form :action="route('mailboxes.destroy', $mailbox)"
                                            label="Disconnect mailbox"
                                            :message="'Disconnect '.$mailbox->email.'? Messages already synced stay in this account, and nothing is deleted from the mail server — only the connection and its credentials are removed.'" />
                        </div>
                    </div>
                @endpermission
            </div>
        </div>
    </div>

    @permission('mailboxes.manage')
        @push('scripts')
            <script>
                @verbatim
                /**
                 * ImapFailureClassifier answers with its own slug — auth, tls,
                 * connection and so on. Those are identifiers for us, not words
                 * for an operator, so each one is given a label here, and
                 * anything unrecognised (including 'unknown') shows no cause
                 * line at all rather than a slug nobody can act on.
                 */
                window.knMailboxTestCauses = {
                    auth: 'sign-in rejected',
                    connection: 'server unreachable',
                    tls: 'encrypted handshake failed',
                    folder: 'folder list refused',
                    server: 'mail server error',
                };

                /**
                 * Alpine component behind the mailbox connection test.
                 *
                 * A classic script, so the factory exists before Alpine.start()
                 * runs from the deferred module bundle.
                 *
                 * The endpoint answers 200 when the connection works and 422
                 * when it does not, and BOTH carry the same JSON body — so the
                 * status code is never the answer on its own.
                 */
                window.knMailboxTest = (url) => ({
                    url: url,
                    loading: false,
                    showDetail: false,
                    result: null,

                    csrfToken() {
                        const meta = document.querySelector('meta[name="csrf-token"]');

                        return meta ? meta.getAttribute('content') : '';
                    },

                    async run() {
                        if (this.loading) return;

                        this.loading = true;
                        this.showDetail = false;
                        this.result = null;

                        try {
                            const response = await fetch(this.url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': this.csrfToken(),
                                },
                                body: '{}',
                            });

                            let data = null;

                            try {
                                data = await response.json();
                            } catch (parseFailure) {
                                data = null;
                            }

                            if (data && typeof data.ok === 'boolean') {
                                this.result = {
                                    ok: data.ok,
                                    summary: data.summary || (data.ok ? 'Connected successfully.' : 'The connection failed.'),
                                    detail: data.detail || '',
                                    kind: data.kind || null,
                                    kindLabel: window.knMailboxTestCauses[data.kind] || '',
                                    tested_at: data.tested_at || '',
                                };
                            } else if (response.status === 419) {
                                this.result = {
                                    ok: false,
                                    summary: 'Your session expired before the test could run.',
                                    detail: 'Reload the page to get a fresh session, then test again.',
                                    kind: null,
                                    kindLabel: '',
                                    tested_at: '',
                                };
                            } else {
                                this.result = {
                                    ok: false,
                                    summary: 'The test could not be started (HTTP ' + response.status + ').',
                                    detail: (data && data.message) ? data.message : 'The server did not return a test result.',
                                    kind: null,
                                    kindLabel: '',
                                    tested_at: '',
                                };
                            }
                        } catch (failure) {
                            this.result = {
                                ok: false,
                                summary: 'The browser could not reach the server to run the test.',
                                detail: failure && failure.message ? failure.message : 'The request never completed. Check your connection and try again.',
                                kind: null,
                                kindLabel: '',
                                tested_at: '',
                            };
                        } finally {
                            this.loading = false;
                        }
                    },
                });
                @endverbatim
            </script>
        @endpush
    @endpermission
</x-app-layout>
