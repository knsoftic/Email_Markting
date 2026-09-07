{{--
    Shared create / edit form for a tenant SMTP account.

    Included by smtp/create and smtp/edit. It renders the whole form element,
    so the parent view only supplies the layout and the page header.

    Everything handed to Alpine is built in the single-line raw PHP blocks
    below and passed on one line: a multi-line array literal inside an HTML
    attribute breaks the Blade parser. Nothing in this comment may name a
    Blade directive either, because raw PHP blocks are extracted before
    comments are stripped.

    The password is never rendered. It is encrypted at rest and hidden from
    serialisation, so the field starts blank on an edit and a blank value
    means "keep the stored credential".
--}}

@php $isEdit = $account->exists; @endphp
@php $formAction = $isEdit ? route('smtp.update', $account) : route('smtp.store'); @endphp
@php $cancelUrl = $isEdit ? route('smtp.show', $account) : route('smtp.index'); @endphp
@php $submitLabel = $isEdit ? 'Save changes' : 'Create SMTP account'; @endphp
@php $formState = ['provider' => old('provider', $account->provider ?: 'custom'), 'host' => old_text('host', $account->host), 'port' => old_text('port', $account->port ?: 587), 'encryption' => old('encryption', $account->encryption ?: 'tls'), 'dailyLimit' => old_text('daily_limit', $account->daily_limit)]; @endphp

<form method="POST" action="{{ $formAction }}" class="space-y-6"
      x-data="knSmtpForm(@js($providers), @js($formState))">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    {{-- ------------------------------------------------------- provider --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Provider</h3>
            <span class="text-xs text-ink-500">Fills in the connection details for you</span>
        </div>

        <div class="p-5">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($providers as $key => $preset)
                    <button type="button" @click="choose('{{ $key }}')"
                            :aria-pressed="provider === '{{ $key }}' ? 'true' : 'false'"
                            class="rounded-lg border px-3 py-3 text-left transition"
                            :class="provider === '{{ $key }}'
                                ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500'
                                : 'border-ink-200 bg-white hover:border-ink-300 hover:bg-ink-50'">
                        <span class="block text-sm font-semibold"
                              :class="provider === '{{ $key }}' ? 'text-brand-700' : 'text-ink-800'">
                            {{ $preset['label'] }}
                        </span>
                        <span class="mt-0.5 block break-all text-xs text-ink-500">
                            {{ $preset['host'] !== '' ? $preset['host'].':'.$preset['port'] : 'Your own mail server' }}
                        </span>
                    </button>
                @endforeach
            </div>

            {{-- The provider itself posts from here; the cards only drive it. --}}
            <input type="hidden" name="provider" x-model="provider">
            <x-input-error :messages="$errors->get('provider')" class="mt-2" />

            <div class="mt-4 rounded-lg bg-ink-50 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">
                    Notes for <span x-text="preset.label"></span>
                </p>
                <p class="mt-1.5 text-sm text-ink-700" x-text="preset.notes"></p>
                <p class="mt-2 text-xs text-ink-600">
                    Username: <span class="font-medium text-ink-800" x-text="preset.username_hint"></span> ·
                    Password: <span class="font-medium text-ink-800" x-text="preset.password_hint"></span>
                </p>
                <a x-show="preset.docs" x-cloak :href="preset.docs" target="_blank" rel="noopener noreferrer"
                   class="mt-2 inline-block text-xs font-semibold text-brand-600 hover:text-brand-700">
                    Read the provider's own SMTP guide
                </a>
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------- connection --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Connection</h3>
            <span class="text-xs text-ink-500">Outgoing mail only</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" :value="old('name', $account->name)"
                              placeholder="e.g. Gmail — marketing@yourdomain.com"
                              required autofocus maxlength="191" />
                <p class="kn-help">Only you see this. It labels the account in lists, logs and campaign reports.</p>
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="host" value="SMTP host" />
                {{-- The server-rendered value matters: x-model overwrites it with
                     the identical value on init, but if Alpine never boots the
                     field still posts what is stored instead of nothing. --}}
                <x-text-input id="host" name="host" x-model="host"
                              :value="old('host', $account->host)"
                              placeholder="e.g. smtp.example.com" required maxlength="191" />
                <p class="kn-help">Hostname only. A pasted <span class="font-mono">smtp://host:587</span> is cleaned up on save.</p>
                <x-input-error :messages="$errors->get('host')" />
            </div>

            <div>
                <x-input-label for="port" value="Port" />
                <x-text-input id="port" name="port" x-model="port" list="smtp-common-ports"
                              :value="old('port', $account->port ?: 587)"
                              inputmode="numeric" required />
                <datalist id="smtp-common-ports">
                    @foreach ($ports as $port)
                        <option value="{{ $port }}"></option>
                    @endforeach
                </datalist>
                <p class="kn-help" x-text="portHint"></p>
                <x-input-error :messages="$errors->get('port')" />
            </div>

            <div class="sm:col-span-2">
                <span class="kn-label">Encryption</span>
                <div class="grid gap-2 sm:grid-cols-3">
                    @php $encryptionChoices = ['tls' => ['STARTTLS (TLS)', 'Connects plain, then upgrades to an encrypted channel. The normal choice, and what port 587 expects.'], 'ssl' => ['Implicit SSL', 'Encrypted from the very first byte. Use this with port 465.'], 'none' => ['None', 'No encryption at all. Only safe for a relay on the same machine — credentials would otherwise cross the network in the clear.']]; @endphp
                    @foreach ($encryptionChoices as $value => $choice)
                        <label class="flex cursor-pointer flex-col gap-1 rounded-lg border px-3 py-2.5 transition"
                               :class="encryption === '{{ $value }}'
                                   ? 'border-brand-500 bg-brand-50'
                                   : 'border-ink-200 hover:bg-ink-50'">
                            <span class="flex items-center gap-2 text-sm font-medium text-ink-800">
                                <input type="radio" name="encryption" value="{{ $value }}"
                                       x-model="encryption" class="kn-checkbox rounded-full"
                                       @checked(old('encryption', $account->encryption ?: 'tls') === $value)>
                                {{ $choice[0] }}
                            </span>
                            <span class="text-xs text-ink-500">{{ $choice[1] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('encryption')" />

                <p x-show="mismatch" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"
                   x-text="mismatch"></p>
            </div>

            <div>
                <x-input-label for="username" value="Username" />
                <x-text-input id="username" name="username" :value="old('username', $account->username)"
                              autocomplete="off" maxlength="191" />
                <p class="kn-help" x-text="preset.username_hint"></p>
                <x-input-error :messages="$errors->get('username')" />
            </div>

            <div>
                <x-input-label for="password" value="Password or API key" />
                {{-- Never pre-filled: the stored credential is encrypted and
                     hidden from serialisation, so it cannot reach this page. --}}
                <x-text-input id="password" name="password" type="password" value=""
                              autocomplete="new-password" maxlength="500"
                              :required="! $isEdit" />
                <p class="kn-help">
                    @if ($isEdit)
                        Leave blank to keep the stored password. Type a new one only to replace it.
                    @else
                        <span x-text="preset.password_hint"></span>
                    @endif
                </p>
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div class="sm:col-span-2">
                {{-- The form request reads verify_peer with a default of true,
                     so an unchecked box must still post a value. --}}
                <input type="hidden" name="verify_peer" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" id="verify_peer" name="verify_peer" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('verify_peer', $account->verify_peer ?? true))>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-ink-800">Verify the server's TLS certificate</span>
                        <span class="block text-xs text-ink-500">
                            Keep this on. Turning it off means the connection is encrypted but unauthenticated —
                            anything on the network path can impersonate your provider and collect the password
                            you send it. Only ever disable it for a server you control on a private network.
                        </span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('verify_peer')" />
            </div>
        </div>
    </div>

    {{-- --------------------------------------------------------- sender --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Sender</h3>
            <span class="text-xs text-ink-500">What recipients see</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="from_name" value="From name" />
                <x-text-input id="from_name" name="from_name" :value="old('from_name', $account->from_name)"
                              placeholder="e.g. KN Softic" required maxlength="191" />
                <x-input-error :messages="$errors->get('from_name')" />
            </div>

            <div>
                <x-input-label for="from_email" value="From email" />
                <x-text-input id="from_email" name="from_email" type="email"
                              :value="old('from_email', $account->from_email)"
                              placeholder="e.g. news@yourdomain.com" required maxlength="191" />
                <p class="kn-help">Most providers reject a from address that is not verified on their side.</p>
                <x-input-error :messages="$errors->get('from_email')" />
            </div>

            <div class="sm:col-span-2">
                <x-input-label for="reply_to" value="Reply-to address" />
                <x-text-input id="reply_to" name="reply_to" type="email"
                              :value="old('reply_to', $account->reply_to)"
                              placeholder="Optional — where replies should land" maxlength="191" />
                <p class="kn-help">Leave blank to send replies to the from address.</p>
                <x-input-error :messages="$errors->get('reply_to')" />
            </div>
        </div>
    </div>

    {{-- --------------------------------------------------------- limits --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Sending limits</h3>
            <span class="text-xs text-ink-500">Leave a limit blank for unlimited</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <div>
                <x-input-label for="hourly_limit" value="Hourly limit" />
                <x-text-input id="hourly_limit" name="hourly_limit" type="number" min="1" max="1000000"
                              :value="old('hourly_limit', $account->hourly_limit)" placeholder="Unlimited" />
                <p class="kn-help">Messages this account may send in one hour.</p>
                <x-input-error :messages="$errors->get('hourly_limit')" />
            </div>

            <div>
                <x-input-label for="daily_limit" value="Daily limit" />
                {{-- Without the server value a failed Alpine boot would post a
                     blank field, and a blank limit means "unlimited" — the
                     stored daily cap would be silently wiped on save. --}}
                <x-text-input id="daily_limit" name="daily_limit" type="number" min="1" max="10000000"
                              x-model="dailyLimit" :value="old('daily_limit', $account->daily_limit)"
                              placeholder="Unlimited" />
                <p class="kn-help">
                    Stay under what your provider allows, or it will start rejecting mail.
                </p>
                <button type="button" x-show="suggested !== null" x-cloak @click="useSuggested()"
                        class="mt-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700">
                    Use the suggested <span x-text="suggested"></span> a day for <span x-text="preset.label"></span>
                </button>
                <x-input-error :messages="$errors->get('daily_limit')" />
            </div>

            <div>
                <x-input-label for="monthly_limit" value="Monthly limit" />
                <x-text-input id="monthly_limit" name="monthly_limit" type="number" min="1" max="100000000"
                              :value="old('monthly_limit', $account->monthly_limit)" placeholder="Unlimited" />
                <p class="kn-help">Counters roll over at the start of each calendar month.</p>
                <x-input-error :messages="$errors->get('monthly_limit')" />
            </div>

            <div>
                <x-input-label for="send_delay_ms" value="Delay between sends (ms)" />
                <x-text-input id="send_delay_ms" name="send_delay_ms" type="number" min="0" max="60000"
                              :value="old('send_delay_ms', $account->send_delay_ms ?? 0)" placeholder="0" />
                <p class="kn-help">Paces the sending. 1000 waits a second between messages, which keeps
                   providers that throttle per minute happy. 0 sends as fast as the queue allows.</p>
                <x-input-error :messages="$errors->get('send_delay_ms')" />
            </div>

            <div>
                <x-input-label for="priority" value="Priority" />
                <x-text-input id="priority" name="priority" type="number" min="0" max="9999"
                              :value="old('priority', $account->priority ?? 0)" placeholder="0" />
                <p class="kn-help">Lower goes first. 0 is tried before 1, and so on down the list.</p>
                <x-input-error :messages="$errors->get('priority')" />
            </div>

            <div class="flex items-end">
                <div class="w-full">
                    {{-- Same default-true handling as verify_peer above. --}}
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-3 py-2.5">
                        <input type="checkbox" id="is_active" name="is_active" value="1" class="kn-checkbox mt-0.5"
                               @checked(old('is_active', $account->is_active ?? true))>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-ink-800">Active</span>
                            <span class="block text-xs text-ink-500">A paused account is never picked for sending.</span>
                        </span>
                    </label>
                    <x-input-error :messages="$errors->get('is_active')" />
                </div>
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
             * Alpine component behind the SMTP connection form.
             *
             * A classic script so the factory exists before Alpine.start()
             * runs from the deferred module bundle.
             */
            window.knSmtpForm = (presets, state) => ({
                presets: presets,
                provider: presets[state.provider] ? state.provider : 'custom',
                host: state.host || '',
                port: state.port || '587',
                encryption: state.encryption || 'tls',
                dailyLimit: state.dailyLimit || '',

                get preset() {
                    return this.presets[this.provider] || this.presets.custom;
                },

                /** The suggested daily cap for the chosen preset, or null. */
                get suggested() {
                    const value = this.preset.suggested_daily_limit;

                    return value === null || value === undefined ? null : value;
                },

                /** One line about what the typed port normally implies. */
                get portHint() {
                    const port = String(this.port || '').trim();

                    if (port === '587') return 'Port 587 is the submission port and expects STARTTLS.';
                    if (port === '465') return 'Port 465 is implicit SSL — the connection is encrypted immediately.';
                    if (port === '2525') return 'Port 2525 is an alternative submission port, used when 587 is blocked.';
                    if (port === '25') return 'Port 25 is server-to-server relay and is blocked by most hosts. Use 587 unless your relay insists on it.';

                    return 'Common ports are 587 (STARTTLS), 465 (SSL) and 2525.';
                },

                /** Flags the port and encryption pair that providers reject. */
                get mismatch() {
                    const port = String(this.port || '').trim();

                    if (port === '465' && this.encryption !== 'ssl') {
                        return 'Port 465 almost always needs implicit SSL. With STARTTLS the connection usually times out.';
                    }

                    if (port === '587' && this.encryption === 'ssl') {
                        return 'Port 587 almost always needs STARTTLS. With implicit SSL the handshake usually fails.';
                    }

                    if (this.encryption === 'none') {
                        return 'Without encryption the username and password travel in plain text. Use it only on a private network.';
                    }

                    return '';
                },

                /** Applies a preset. An empty preset host leaves what was typed. */
                choose(key) {
                    if (!this.presets[key]) return;

                    this.provider = key;

                    const preset = this.presets[key];

                    if (preset.host) this.host = preset.host;

                    this.port = String(preset.port);
                    this.encryption = preset.encryption;
                },

                useSuggested() {
                    if (this.suggested !== null) this.dailyLimit = String(this.suggested);
                },
            });
            @endverbatim
        </script>
    @endpush
@endonce
