@php
    /**
     * Shared create/edit form for platform (global) SMTP accounts.
     *
     * $account   — an SmtpAccount, unsaved on create.
     * $providers — SmtpProviders::all(), used both for the picker and the
     *              Alpine presets that pre-fill the connection fields.
     * $ports     — SmtpProviders::commonPorts().
     *
     * The password is NEVER echoed back: the field starts blank on an edit and
     * a blank value tells the request to keep the stored (encrypted) one.
     */
    $isEdit = $account->exists;
    $currentProvider = old('provider', $account->provider ?: 'custom');
    $currentHost = old('host', $account->host);
    $currentPort = old('port', $account->port ?: 587);
    $currentEncryption = old('encryption', $account->encryption ?: 'tls');
@endphp

<form method="POST"
      action="{{ $isEdit ? route('admin.smtp.update', $account) : route('admin.smtp.store') }}"
      class="space-y-6"
      x-data="{
          provider: @js($currentProvider),
          presets: @js($providers),
          host: @js((string) $currentHost),
          port: @js((string) $currentPort),
          encryption: @js($currentEncryption),
          get preset() { return this.presets[this.provider] || {}; },
          applyPreset() {
              const preset = this.preset;
              if (preset.host) { this.host = preset.host; }
              if (preset.port) { this.port = String(preset.port); }
              if (preset.encryption) { this.encryption = preset.encryption; }
          },
      }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        These credentials are platform-owned and are shared by every account you assign them to.
        One mailbox, one reputation and one set of provider limits covers all of them, so set the
        hourly, daily and monthly ceilings below to what the provider actually allows.
    </div>

    {{-- ------------------------------------------------------------- basics --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Identification</h3>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="name" value="Internal name" />
                <x-text-input id="name" name="name" :value="old('name', $account->name)"
                              placeholder="e.g. Platform SendGrid — main" required autofocus maxlength="191" />
                <p class="kn-help">Only staff see this. Customers never see the name of a shared account.</p>
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="provider" value="Provider" />
                <select id="provider" name="provider" class="kn-select" x-model="provider" @change="applyPreset()">
                    @foreach ($providers as $key => $preset)
                        <option value="{{ $key }}" @selected($currentProvider === $key)>{{ $preset['label'] }}</option>
                    @endforeach
                </select>
                <p class="kn-help">Choosing a provider fills in the host, port and encryption below.</p>
                <x-input-error :messages="$errors->get('provider')" />
            </div>

            <div class="sm:col-span-2">
                <div class="rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                    <p x-text="preset.notes"></p>
                    <template x-if="preset.docs">
                        <a :href="preset.docs" target="_blank" rel="noopener noreferrer"
                           class="mt-2 inline-flex items-center gap-1 font-semibold text-brand-600 hover:text-brand-700">
                            Provider setup guide
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- --------------------------------------------------------- connection --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Connection</h3>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-2">
            <div>
                <x-input-label for="host" value="Host" />
                <x-text-input id="host" name="host" x-model="host" :value="$currentHost"
                              placeholder="smtp.example.com" required maxlength="191" />
                <p class="kn-help">Hostname only — no https:// and no trailing path.</p>
                <x-input-error :messages="$errors->get('host')" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="port" value="Port" />
                    <x-text-input id="port" name="port" type="number" min="1" max="65535"
                                  x-model="port" :value="$currentPort" list="kn-smtp-ports" required />
                    <datalist id="kn-smtp-ports">
                        @foreach ($ports as $port)
                            <option value="{{ $port }}"></option>
                        @endforeach
                    </datalist>
                    <p class="kn-help">587 with STARTTLS is the safe default.</p>
                    <x-input-error :messages="$errors->get('port')" />
                </div>

                <div>
                    <x-input-label for="encryption" value="Encryption" />
                    <select id="encryption" name="encryption" class="kn-select" x-model="encryption">
                        <option value="tls" @selected($currentEncryption === 'tls')>STARTTLS (587)</option>
                        <option value="ssl" @selected($currentEncryption === 'ssl')>SSL / implicit (465)</option>
                        <option value="none" @selected($currentEncryption === 'none')>None</option>
                    </select>
                    <x-input-error :messages="$errors->get('encryption')" />
                </div>
            </div>

            <div>
                <x-input-label for="username" value="Username" />
                <x-text-input id="username" name="username" :value="old('username', $account->username)"
                              autocomplete="off" maxlength="191" />
                <p class="kn-help" x-text="preset.username_hint"></p>
                <x-input-error :messages="$errors->get('username')" />
            </div>

            <div>
                <x-input-label for="password" value="Password / API key" />
                <x-text-input id="password" name="password" type="password"
                              autocomplete="new-password" maxlength="500"
                              :required="! $isEdit"
                              :placeholder="$isEdit ? 'Leave blank to keep the stored password' : ''" />
                @if ($isEdit)
                    <p class="kn-help">
                        Leave blank to keep the stored password. The stored value is encrypted and is
                        never shown again — type a new one only if you are replacing it.
                    </p>
                @else
                    <p class="kn-help" x-text="preset.password_hint"></p>
                @endif
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div class="sm:col-span-2">
                <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-4 py-3">
                    <input type="hidden" name="verify_peer" value="0">
                    <input type="checkbox" name="verify_peer" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('verify_peer', $account->verify_peer ?? true))>
                    <span class="text-sm">
                        <span class="font-medium text-ink-900">Verify the TLS certificate</span>
                        <span class="block text-xs text-ink-500">
                            Keep this on. Turn it off only for a relay with a self-signed certificate you control —
                            without verification the connection can be intercepted.
                        </span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('verify_peer')" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------- sender --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Sender</h3>
            <span class="text-xs text-ink-500">Used when a campaign does not set its own sender</span>
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
                              placeholder="e.g. mail@yourdomain.com" required maxlength="191" />
                <p class="kn-help">Must be an address the provider has authorised, or every send bounces.</p>
                <x-input-error :messages="$errors->get('from_email')" />
            </div>

            <div class="sm:col-span-2">
                <x-input-label for="reply_to" value="Reply-to" />
                <x-text-input id="reply_to" name="reply_to" type="email"
                              :value="old('reply_to', $account->reply_to)"
                              placeholder="Optional" maxlength="191" />
                <p class="kn-help">Optional. Replies from customers of every assigned account land here.</p>
                <x-input-error :messages="$errors->get('reply_to')" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------- limits & behaviour --}}
    <div class="kn-card">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Limits and behaviour</h3>
            <span class="text-xs text-ink-500">Blank = no cap</span>
        </div>

        <div class="grid gap-5 p-5 sm:grid-cols-3">
            <div>
                <x-input-label for="hourly_limit" value="Emails per hour" />
                <x-text-input id="hourly_limit" name="hourly_limit" type="number" min="1" max="1000000"
                              :value="old('hourly_limit', $account->hourly_limit)" placeholder="No cap" />
                <x-input-error :messages="$errors->get('hourly_limit')" />
            </div>

            <div>
                <x-input-label for="daily_limit" value="Emails per day" />
                <x-text-input id="daily_limit" name="daily_limit" type="number" min="1" max="10000000"
                              :value="old('daily_limit', $account->daily_limit)" placeholder="No cap" />
                <p class="kn-help" x-show="preset.suggested_daily_limit" x-cloak>
                    <span x-text="preset.label"></span> typically allows about
                    <span x-text="preset.suggested_daily_limit"></span> a day.
                </p>
                <x-input-error :messages="$errors->get('daily_limit')" />
            </div>

            <div>
                <x-input-label for="monthly_limit" value="Emails per month" />
                <x-text-input id="monthly_limit" name="monthly_limit" type="number" min="1" max="100000000"
                              :value="old('monthly_limit', $account->monthly_limit)" placeholder="No cap" />
                <x-input-error :messages="$errors->get('monthly_limit')" />
            </div>

            <div>
                <x-input-label for="send_delay_ms" value="Delay between sends (ms)" />
                <x-text-input id="send_delay_ms" name="send_delay_ms" type="number" min="0" max="60000"
                              :value="old('send_delay_ms', $account->send_delay_ms ?? 0)" />
                <p class="kn-help">Throttles the queue. Useful when a provider rate-limits per minute.</p>
                <x-input-error :messages="$errors->get('send_delay_ms')" />
            </div>

            <div>
                <x-input-label for="priority" value="Priority" />
                <x-text-input id="priority" name="priority" type="number" min="0" max="9999"
                              :value="old('priority', $account->priority ?? 0)" />
                <p class="kn-help">Lower runs first when several accounts are available.</p>
                <x-input-error :messages="$errors->get('priority')" />
            </div>

            <div>
                <x-input-label for="is_active" value="Availability" />
                <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-4 py-2.5">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1" class="kn-checkbox mt-0.5"
                           @checked(old('is_active', $account->is_active ?? true))>
                    <span class="text-sm">
                        <span class="font-medium text-ink-900">Active</span>
                        <span class="block text-xs text-ink-500">Paused accounts send nothing, for anyone.</span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('is_active')" />
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <a href="{{ $isEdit ? route('admin.smtp.show', $account) : route('admin.smtp.index') }}" class="kn-btn-secondary">Cancel</a>
        <button type="submit" class="kn-btn-primary">{{ $isEdit ? 'Save changes' : 'Create admin SMTP' }}</button>
    </div>
</form>
