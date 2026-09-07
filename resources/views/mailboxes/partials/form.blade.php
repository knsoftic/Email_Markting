{{--
    Shared create / edit form for an IMAP mailbox.

    Included by mailboxes/create and mailboxes/edit. It renders the whole form
    element, so the parent view only supplies the layout and the page header.

    Everything handed to Alpine is built in the single-line raw PHP blocks
    below and passed on one line: a multi-line array literal inside an HTML
    attribute breaks the Blade parser. Nothing in this comment may name a
    Blade directive either, because raw PHP blocks are extracted before
    comments are stripped.

    The password is never rendered. It is encrypted at rest and hidden from
    serialisation, so the field starts blank on an edit and a blank value
    means "keep the stored credential".

    The connection test sits inside this form but posts by fetch, so a failed
    test never costs the operator what they have typed. It tests what is
    SAVED, not what is on screen — the panel says so, because a test that
    quietly checked different settings than the ones displayed would be worse
    than no test at all.
--}}

@php $isEdit = $mailbox->exists; @endphp
@php $formAction = $isEdit ? route('mailboxes.update', $mailbox) : route('mailboxes.store'); @endphp
@php $cancelUrl = $isEdit ? route('mailboxes.show', $mailbox) : route('mailboxes.index'); @endphp
@php $submitLabel = $isEdit ? 'Save changes' : 'Connect mailbox'; @endphp
{{-- old_text() throughout, not old(): provider and imap_encryption are single
     -value fields, so a posted provider[]=x is not a value either could have
     held. Left as old() it survives into @selected and @checked as an array,
     every strict comparison fails, and the redisplayed form comes back with no
     provider chosen and no encryption radio ticked — which then fails
     validation a second time for a field the operator never touched. --}}
@php $formState = ['provider' => old_text('provider', $mailbox->provider ?: 'custom'), 'host' => old_text('imap_host', $mailbox->imap_host), 'port' => old_text('imap_port', $mailbox->imap_port ?: 993), 'encryption' => old_text('imap_encryption', $mailbox->imap_encryption ?: 'ssl'), 'username' => old_text('imap_username', $mailbox->imap_username), 'email' => old_text('email', $mailbox->email)]; @endphp
@php $encryptionChoices = ['ssl' => ['Implicit SSL', 'Encrypted from the first byte. This is what port 993 expects, and what almost every hosted provider wants.'], 'tls' => ['STARTTLS (TLS)', 'Connects in the clear, then upgrades to an encrypted channel. This is what port 143 expects.'], 'none' => ['None', 'No encryption at all. The password crosses the network in plain text — only defensible for a server on the same private network.']]; @endphp

<form method="POST" action="{{ $formAction }}" class="space-y-6"
      x-data="knMailboxForm(@js($providers), @js($formState))">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    {{-- ------------------------------------------------------- identity --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Mailbox</h3>
            <span class="text-xs text-ink-500">Which address this is</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" :value="old_text('name', $mailbox->name)"
                              placeholder="e.g. Support inbox"
                              required autofocus maxlength="191" />
                <p class="kn-help">Only your team sees this. It labels the mailbox in lists, the inbox and the activity log.</p>
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" value="Email address" />
                <x-text-input id="email" name="email" type="email" x-model="email"
                              :value="old_text('email', $mailbox->email)"
                              placeholder="e.g. support@yourdomain.com" required maxlength="191" />
                <p class="kn-help">
                    The address whose mail is synced. One mailbox per address — connecting the same
                    address twice would fetch every message twice.
                </p>
                <x-input-error :messages="$errors->get('email')" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- provider --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Provider</h3>
            <span class="text-xs text-ink-500">Fills in the connection details for you</span>
        </div>

        <div class="p-5">
            <div class="sm:max-w-sm">
                <x-input-label for="provider" value="Who hosts this mailbox" />
                <select id="provider" name="provider" class="kn-select"
                        x-model="provider" @change="applyPreset()">
                    @foreach ($providers as $key => $preset)
                        <option value="{{ $key }}" @selected($formState['provider'] === $key)>
                            {{ $preset['label'] }}
                        </option>
                    @endforeach
                </select>
                <p class="kn-help">
                    Choosing a provider fills in the host, port and encryption below. Every one of those
                    fields stays editable afterwards — the preset is a starting point, not a lock.
                </p>
                <x-input-error :messages="$errors->get('provider')" />
            </div>

            <div class="mt-4 rounded-lg bg-ink-50 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">
                    Notes for <span x-text="preset.label"></span>
                </p>
                <p class="mt-1.5 text-sm text-ink-700" x-text="preset.note"></p>
                <p class="mt-2 text-xs text-ink-600">
                    Username: <span class="font-medium text-ink-800" x-text="preset.username_hint"></span> ·
                    Password: <span class="font-medium text-ink-800" x-text="preset.password_hint"></span>
                </p>
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------- connection --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">IMAP connection</h3>
            <span class="text-xs text-ink-500">Incoming mail only</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="imap_host" value="IMAP host" />
                {{-- The server-rendered value matters: x-model overwrites it with
                     the identical value on init, but if Alpine never boots the
                     field still posts what is stored instead of nothing. --}}
                <x-text-input id="imap_host" name="imap_host" x-model="host"
                              :value="old_text('imap_host', $mailbox->imap_host)"
                              placeholder="e.g. imap.example.com" required maxlength="191" />
                <p class="kn-help">Hostname only — no <span class="font-mono">imaps://</span> and no path.</p>
                <x-input-error :messages="$errors->get('imap_host')" />
            </div>

            <div>
                <x-input-label for="imap_port" value="Port" />
                <x-text-input id="imap_port" name="imap_port" x-model="port" list="imap-common-ports"
                              :value="old_text('imap_port', $mailbox->imap_port ?: 993)"
                              inputmode="numeric" required />
                <datalist id="imap-common-ports">
                    <option value="993"></option>
                    <option value="143"></option>
                </datalist>
                <p class="kn-help" x-text="portHint"></p>
                <x-input-error :messages="$errors->get('imap_port')" />
            </div>

            <div class="sm:col-span-2">
                <span class="kn-label">Encryption</span>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($encryptionChoices as $value => $choice)
                        <label class="flex cursor-pointer flex-col gap-1 rounded-lg border px-3 py-2.5 transition"
                               :class="encryption === '{{ $value }}'
                                   ? 'border-brand-500 bg-brand-50'
                                   : 'border-ink-200 hover:bg-ink-50'">
                            <span class="flex items-center gap-2 text-sm font-medium text-ink-800">
                                <input type="radio" name="imap_encryption" value="{{ $value }}"
                                       x-model="encryption" class="kn-checkbox rounded-full"
                                       @checked($formState['encryption'] === $value)>
                                {{ $choice[0] }}
                            </span>
                            <span class="text-xs text-ink-500">{{ $choice[1] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('imap_encryption')" />

                <p class="mt-2 text-xs text-ink-500">
                    The pairing that works is <span class="font-semibold text-ink-700">993 with implicit SSL</span>
                    or <span class="font-semibold text-ink-700">143 with STARTTLS</span>. Nothing here stops you
                    saving another combination — some servers really do want one — but a mismatch is by far the
                    most common reason a connection fails.
                </p>

                <p x-show="mismatch" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"
                   x-text="mismatch"></p>

                {{-- Separate from the pairing warning on purpose: choosing no
                     encryption on port 993 is both a mismatch and a security
                     decision, and the security half must not be swallowed by
                     the connectivity half. --}}
                <p x-show="plaintext" x-cloak class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800"
                   x-text="plaintext"></p>
            </div>

            <div>
                <x-input-label for="imap_username" value="Username" />
                <x-text-input id="imap_username" name="imap_username" x-model="username"
                              :value="old_text('imap_username', $mailbox->imap_username)"
                              autocomplete="off" required maxlength="191" />
                <p class="kn-help" x-text="preset.username_hint"></p>
                <button type="button" x-show="canCopyEmail" x-cloak @click="useEmailAsUsername()"
                        class="mt-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700">
                    Use the mailbox address, <span x-text="email"></span>
                </button>
                <x-input-error :messages="$errors->get('imap_username')" />
            </div>

            <div>
                <x-input-label for="imap_password" value="Password" />
                {{-- Never pre-filled, not even masked: the stored credential is
                     encrypted and hidden from serialisation, so it cannot reach
                     this page at all. --}}
                <x-text-input id="imap_password" name="imap_password" type="password" value=""
                              autocomplete="new-password" maxlength="500"
                              :required="! $isEdit"
                              :placeholder="$isEdit ? 'Leave blank to keep the stored password' : null" />
                <p class="kn-help">
                    @if ($isEdit)
                        Leave this blank and the password already stored stays exactly as it is — you can fix a
                        typo in the host without digging out an app password again. Type something here only to
                        replace it.
                    @else
                        <span x-text="preset.password_hint"></span>
                    @endif
                </p>
                <x-input-error :messages="$errors->get('imap_password')" />
            </div>

            <div class="sm:col-span-2">
                {{-- The form request reads imap_validate_cert as a boolean, so an
                     unchecked box must still post a value. --}}
                <input type="hidden" name="imap_validate_cert" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" id="imap_validate_cert" name="imap_validate_cert" value="1"
                           class="kn-checkbox mt-0.5"
                           @checked(old('imap_validate_cert', $mailbox->imap_validate_cert ?? true))>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink-800">Validate the server's TLS certificate</span>
                        <span class="block text-xs text-ink-500">
                            Keep this on for any hosted provider. The one legitimate reason to turn it off is an
                            internal mail server on your own network using a self-signed certificate that nothing
                            in this container trusts. The cost is real: the connection stays encrypted but becomes
                            unauthenticated, so anything sitting on the network path can pose as your mail server
                            and collect the password we send it, along with every message we download.
                        </span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('imap_validate_cert')" />
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------------- test --}}
    @if ($isEdit)
        <div class="kn-card" x-data="knMailboxTest(@js(route('mailboxes.test', $mailbox)))">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Test connection</h3>
                <span class="text-xs text-ink-500">Signs in and reads the folder list</span>
            </div>

            <div class="kn-card-body">
                <p class="mb-3 text-xs text-ink-500">
                    The test connects with the settings that are <span class="font-semibold text-ink-700">already
                    saved</span>, not with what is typed on this page. If you have changed anything above, save
                    first and then test. Nothing is sent and no message is marked read; the result is written to
                    this mailbox's status.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="kn-btn-secondary kn-btn-sm" @click="run()" :disabled="loading"
                            :aria-busy="loading ? 'true' : 'false'"
                            aria-describedby="mailbox-test-result-{{ $mailbox->id }}">
                        <svg x-show="loading" x-cloak class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                            <path class="opacity-90" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z"/>
                        </svg>
                        <span x-text="loading ? 'Testing…' : (result ? 'Test again' : 'Test connection')">Test connection</span>
                    </button>

                    <span x-show="loading" x-cloak class="text-xs text-ink-500">
                        Connecting, authenticating and listing folders — this can take a few seconds.
                    </span>
                </div>

                <div id="mailbox-test-result-{{ $mailbox->id }}" role="status" aria-live="polite" class="min-w-0">

                    {{-- ------------------------------------------------ success --}}
                    <div x-show="result && result.ok" x-cloak
                         class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <p class="text-sm font-semibold text-emerald-800" x-text="result ? result.summary : ''"></p>
                        <p class="mt-1 break-words text-xs text-emerald-700" x-text="result ? result.detail : ''"></p>

                        <div x-show="folders.length > 0" x-cloak class="mt-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                Folders found, and what each was recognised as
                            </p>
                            <ul class="mt-1.5 divide-y divide-emerald-200 overflow-hidden rounded-lg border border-emerald-200 bg-white">
                                <template x-for="folder in folders" :key="folder.path">
                                    <li class="flex items-start justify-between gap-3 px-3 py-2">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm text-ink-800" x-text="folder.name"></span>
                                            <span class="block truncate font-mono text-xs text-ink-500" x-text="folder.path"></span>
                                        </span>
                                        <span class="shrink-0" :class="folderClass(folder.type)"
                                              x-text="folderLabel(folder.type)"></span>
                                    </li>
                                </template>
                            </ul>
                            <p class="mt-2 text-xs text-emerald-700">
                                Anything shown as Other is still synced — we simply do not claim it is one of the
                                well-known folders. Spam and Trash are recognised but never synced.
                            </p>
                        </div>

                        <p x-show="folders.length === 0" x-cloak class="mt-2 text-xs text-emerald-700">
                            The sign-in worked but the server returned no folders. That usually means the account
                            has no permission to list them.
                        </p>

                        <p class="mt-2 text-xs text-emerald-600">
                            Tested <span x-text="result ? result.tested_at : ''"></span>. This mailbox is now marked
                            connected; reload the page to see the stored status catch up.
                        </p>
                    </div>

                    {{-- ------------------------------------------------ failure --}}
                    <div x-show="result && ! result.ok" x-cloak
                         class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <p class="text-sm font-semibold text-red-800" x-text="result ? result.summary : ''"></p>

                        <p class="mt-1 text-xs text-red-700">
                            <span x-show="result && result.kind" x-cloak>
                                <span x-text="kindLabel"></span> ·
                            </span>
                            <span x-show="result && result.tested_at" x-cloak>
                                Tested <span x-text="result ? result.tested_at : ''"></span>
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

                        <p class="mt-2 text-xs text-red-700">
                            Nothing on this page has been lost — fix the settings above, save, and test again.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-700">
            The connection test runs against a saved mailbox, so it appears once you have connected this one.
            Saving does not start a sync: the mailbox is stored as untested until a test succeeds.
        </div>
    @endif

    {{-- -------------------------------------------------------- replies --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Sending replies</h3>
            <span class="text-xs text-ink-500">Separate from the credentials above</span>
        </div>

        <div class="p-5">
            <div class="sm:max-w-md">
                <x-input-label for="smtp_account_id" value="SMTP account for replies" />
                <select id="smtp_account_id" name="smtp_account_id" class="kn-select">
                    <option value="">None — replies cannot be sent from this mailbox</option>
                    @foreach ($smtpAccounts as $smtpAccount)
                        <option value="{{ $smtpAccount->id }}"
                                @selected((int) old('smtp_account_id', $mailbox->smtp_account_id) === (int) $smtpAccount->id)>
                            {{ $smtpAccount->name }} — {{ $smtpAccount->from_email }} ({{ $smtpAccount->host }})
                        </option>
                    @endforeach
                </select>
                <p class="kn-help">
                    IMAP only receives. When you reply to a message in this mailbox, the reply goes out through
                    this SMTP account — a different server, a different set of credentials, and one you configure
                    on the SMTP screens. Leaving it as None is fine if you only want to read this mailbox; replies
                    simply will not be offered.
                </p>
                <x-input-error :messages="$errors->get('smtp_account_id')" />

                @if ($smtpAccounts->isEmpty())
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        There are no active SMTP accounts to choose from yet.
                        @if (Route::has('smtp.create'))
                            <a href="{{ route('smtp.create') }}" class="font-semibold underline underline-offset-2">Add one</a>
                            and come back to link it.
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- --------------------------------------------------------- syncing --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Syncing</h3>
            <span class="text-xs text-ink-500">How often, and how much at a time</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                {{-- Same default handling as the certificate box: the request
                     reads this as a boolean, so an unchecked box must post. --}}
                <input type="hidden" name="sync_enabled" value="0">
                <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-3 py-2.5">
                    <input type="checkbox" id="sync_enabled" name="sync_enabled" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('sync_enabled', $mailbox->sync_enabled ?? true))>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink-800">Fetch new mail automatically</span>
                        <span class="block text-xs text-ink-500">
                            On, the scheduler collects new messages on the interval below. Off, nothing arrives on
                            its own and the mailbox is only updated when someone runs a sync by hand.
                        </span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('sync_enabled')" />
            </div>

            <div>
                <x-input-label for="sync_interval_minutes" value="Check every (minutes)" />
                <x-text-input id="sync_interval_minutes" name="sync_interval_minutes" type="number"
                              min="1" max="1440"
                              :value="old_text('sync_interval_minutes', $mailbox->sync_interval_minutes ?: 5)"
                              required />
                <p class="kn-help">
                    How long to wait after one pass before starting the next. 5 minutes suits a mailbox somebody
                    is watching; a quiet archive can sit on 60 and stop spending your provider's rate limit.
                </p>
                <x-input-error :messages="$errors->get('sync_interval_minutes')" />
            </div>

            <div>
                <x-input-label for="sync_limit" value="Messages per pass" />
                <x-text-input id="sync_limit" name="sync_limit" type="number" min="1" max="500"
                              :value="old_text('sync_limit', $mailbox->sync_limit ?: 100)"
                              required />
                <p class="kn-help">
                    The most messages one pass will fetch. Nothing is skipped when the limit is hit — the next
                    pass carries on from where this one stopped, so a low number is safe. It only means a large
                    backlog takes several passes to come down, in exchange for short jobs that do not tie up a
                    worker.
                </p>
                <x-input-error :messages="$errors->get('sync_limit')" />
            </div>

            <div class="sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-3 py-2.5">
                    <input type="checkbox" id="is_active" name="is_active" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('is_active', $mailbox->is_active ?? true))>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink-800">Active</span>
                        <span class="block text-xs text-ink-500">
                            The master switch. Switched off, nothing syncs at all — not automatically and not by
                            hand — and the messages already downloaded stay exactly where they are.
                        </span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('is_active')" />
            </div>
        </div>
    </div>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ $cancelUrl }}" class="kn-btn-secondary">Cancel</a>
        <button type="submit" class="kn-btn-primary">{{ $submitLabel }}</button>
    </div>
</form>

@once
    @push('scripts')
        <script>
            @verbatim
            /**
             * Alpine component behind the IMAP connection form.
             *
             * A classic script so the factory exists before Alpine.start()
             * runs from the deferred module bundle.
             */
            window.knMailboxForm = (presets, state) => ({
                presets: presets,
                provider: presets[state.provider] ? state.provider : 'custom',
                host: state.host || '',
                port: state.port || '993',
                encryption: state.encryption || 'ssl',
                username: state.username || '',
                email: state.email || '',

                get preset() {
                    return this.presets[this.provider] || this.presets.custom;
                },

                /** One line about what the typed port normally implies. */
                get portHint() {
                    const port = String(this.port || '').trim();

                    if (port === '993') return 'Port 993 is the standard IMAP port and expects implicit SSL.';
                    if (port === '143') return 'Port 143 is the plain IMAP port and expects STARTTLS to upgrade the connection.';

                    return 'Almost every server uses 993 (implicit SSL) or 143 (STARTTLS).';
                },

                /** Flags the port and encryption pair that servers reject. */
                get mismatch() {
                    const port = String(this.port || '').trim();

                    if (port === '993' && this.encryption !== 'ssl') {
                        return 'Port 993 almost always needs implicit SSL. Paired with STARTTLS or no encryption the connection usually just hangs until it times out.';
                    }

                    if (port === '143' && this.encryption === 'ssl') {
                        return 'Port 143 almost always needs STARTTLS. With implicit SSL the handshake fails immediately.';
                    }

                    return '';
                },

                /**
                 * Kept apart from the pairing warning so that one never hides
                 * the other: "no encryption on 993" is two different problems.
                 */
                get plaintext() {
                    if (this.encryption !== 'none') return '';

                    return 'Without encryption the username and password travel in plain text, and so does every message downloaded. Only defensible on a private network.';
                },

                /** Offered only when it would actually change something. */
                get canCopyEmail() {
                    const email = String(this.email || '').trim();

                    return email !== '' && email !== String(this.username || '').trim();
                },

                /**
                 * Applies the chosen preset. An empty preset host — the custom
                 * entry — leaves whatever was typed rather than wiping it.
                 */
                applyPreset() {
                    const preset = this.presets[this.provider];

                    if (!preset) return;

                    if (preset.host) this.host = preset.host;

                    this.port = String(preset.port);
                    this.encryption = preset.encryption;
                },

                useEmailAsUsername() {
                    this.username = String(this.email || '').trim();
                },
            });

            /**
             * Alpine component behind the mailbox test button.
             *
             * The endpoint answers 200 when the connection works and 422 when
             * it does not, and BOTH carry the same JSON body — so the status
             * code is never the answer on its own. The body is parsed either
             * way and only a missing or unparsable body falls back to a
             * transport-level message.
             */
            window.knMailboxTest = (url) => ({
                url: url,
                loading: false,
                showDetail: false,
                result: null,

                /** Always an array, so the folder loop never sees undefined. */
                get folders() {
                    return this.result && Array.isArray(this.result.folders) ? this.result.folders : [];
                },

                get kindLabel() {
                    const kinds = {
                        auth: 'Sign-in was refused',
                        connection: 'The server could not be reached',
                        tls: 'The encrypted handshake failed',
                        folder: 'Signed in, but the folders could not be listed',
                        server: 'The server returned an error',
                        unknown: 'Unrecognised failure',
                    };

                    const kind = this.result ? this.result.kind : null;

                    return kinds[kind] || 'Failed';
                },

                folderLabel(type) {
                    const labels = {
                        inbox: 'Inbox',
                        sent: 'Sent',
                        drafts: 'Drafts',
                        archive: 'Archive',
                        spam: 'Spam',
                        trash: 'Trash',
                        custom: 'Other',
                    };

                    return labels[type] || 'Other';
                },

                /**
                 * Whole class strings only. A class built by concatenation is
                 * one Tailwind never sees and therefore never compiles.
                 */
                folderClass(type) {
                    if (type === 'spam' || type === 'trash') return 'kn-badge-amber';
                    if (type === 'custom' || !type) return 'kn-badge-gray';

                    return 'kn-badge-blue';
                },

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
                                folders: Array.isArray(data.folders) ? data.folders : [],
                                ms: data.ms || null,
                                tested_at: data.tested_at || '',
                            };
                        } else if (response.status === 419) {
                            this.result = {
                                ok: false,
                                summary: 'Your session expired before the test could run.',
                                detail: 'Reload the page to get a fresh session, then test again. Nothing you typed has been saved yet.',
                                kind: null,
                                folders: [],
                                ms: null,
                                tested_at: '',
                            };
                        } else if (response.status === 403) {
                            this.result = {
                                ok: false,
                                summary: 'You do not have permission to test this mailbox.',
                                detail: 'Testing a connection needs the "Connect and manage mailboxes" permission.',
                                kind: null,
                                folders: [],
                                ms: null,
                                tested_at: '',
                            };
                        } else {
                            this.result = {
                                ok: false,
                                summary: 'The test could not be started (HTTP ' + response.status + ').',
                                detail: (data && data.message) ? data.message : 'The server did not return a test result.',
                                kind: null,
                                folders: [],
                                ms: null,
                                tested_at: '',
                            };
                        }
                    } catch (failure) {
                        this.result = {
                            ok: false,
                            summary: 'The browser could not reach the server to run the test.',
                            detail: failure && failure.message ? failure.message : 'The request never completed. Check your connection and try again.',
                            kind: null,
                            folders: [],
                            ms: null,
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
@endonce
