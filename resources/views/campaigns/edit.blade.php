<x-app-layout>
    <x-slot name="header">{{ $campaign->exists ? $campaign->name : 'New campaign' }}</x-slot>

    @php
        $isNew = ! $campaign->exists;
        $action = $isNew ? route('campaigns.store') : route('campaigns.update', $campaign);
        $method = $isNew ? 'POST' : 'PUT';
        $saveLabel = $isNew ? 'Save draft' : 'Save changes';

        /*
         * A rejected save must not throw the email away. The controller rebuilds
         * $document from the database, so on a validation failure the blocks the
         * user had just edited would silently revert. old('blocks') holds exactly
         * what the builder serialised, so it is preferred when present.
         *
         * The builder posts the document as JSON in a hidden field, so old()
         * normally holds a string — but nothing forces that shape. Anything that
         * is not this form (an integration, a replayed request, a test) can post
         * blocks[] as a real array, and casting an array to string is a fatal
         * "Array to string conversion" on the one response that is carrying the
         * user's unsaved email. Both shapes are accepted, exactly as
         * CampaignRequest accepts both.
         */
        $decodeOld = function (string $key) {
            $value = old($key);

            return is_array($value) ? $value : json_decode((string) $value, true);
        };

        $oldBlocks = $decodeOld('blocks');
        $oldSettings = $decodeOld('settings');

        if (is_array($oldBlocks) && $oldBlocks !== []) {
            $document = [
                'settings' => is_array($oldSettings) ? $oldSettings : ($document['settings'] ?? []),
                'blocks' => $oldBlocks,
            ];
        }

        /*
         * Audience ids come back from old() as strings; the models hold ints.
         *
         * Unchecked boxes post nothing, so after a rejected save old() has no
         * audience key at all — and falling back to the saved campaign there
         * would silently re-tick every box the user had just cleared. Once a
         * save has been attempted, old() is the only truth.
         */
        $saved = session()->hasOldInput() ? [] : (array) $campaign->audience;

        $chosen = [
            'lists' => array_map('intval', (array) old('audience.lists', data_get($saved, 'lists', []))),
            'tags' => array_map('intval', (array) old('audience.tags', data_get($saved, 'tags', []))),
            'segments' => array_map('intval', (array) old('audience.segments', data_get($saved, 'segments', []))),
        ];

        $chosenCount = count($chosen['lists']) + count($chosen['tags']) + count($chosen['segments']);

        $trackOpens = (bool) old('track_opens', $campaign->track_opens);
        $trackClicks = (bool) old('track_clicks', $campaign->track_clicks);

        $selectedTemplate = (int) old('email_template_id', $campaign->email_template_id);

        $selectedTimezone = old('timezone', $campaign->timezone ?: 'UTC');
        $selectedTimezone = is_string($selectedTimezone) ? $selectedTimezone : 'UTC';

        // A linked template can be deactivated after the fact, which drops it
        // out of the list. Without an option to hold it, the select would show
        // blank and the next save would quietly unlink it.
        $templateIds = $templates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orphanTemplate = $selectedTemplate > 0 && ! in_array($selectedTemplate, $templateIds, true);

        // The stored SMTP account can drop out of the candidate list (paused, over
        // its limit, unassigned). Keeping it as an option means saving the form
        // does not quietly change a choice the user never revisited.
        $selectedSmtp = (int) old('smtp_account_id', $campaign->smtp_account_id);
        $smtpIds = $smtpAccounts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orphanSmtp = $selectedSmtp > 0 && ! in_array($selectedSmtp, $smtpIds, true);

        $timezoneGroups = collect($timezones)
            ->groupBy(fn ($tz) => str_contains($tz, '/') ? \Illuminate\Support\Str::before($tz, '/') : 'Other')
            ->sortKeys();

        // The stored zone does not have to come from this select. The account's
        // own setting seeds a new campaign, the review screen writes one when a
        // send is scheduled, and imported rows carry whatever they carry — and
        // Laravel's `timezone` rule accepts backward-compatible names
        // (Asia/Calcutta, US/Pacific) that listIdentifiers() leaves out. With no
        // option to hold it, nothing in the select is selected, the browser
        // submits whichever zone happens to be first, and the campaign's send
        // time silently moves. Same reasoning as $orphanSmtp; the show,
        // scheduled and confirm screens already guard the same value.
        $orphanTimezone = $selectedTimezone !== '' && ! in_array($selectedTimezone, $timezones, true);

        // URLs are built here, from the route names, so the markup never
        // string-builds a path.
        $templatePreviews = $templates->mapWithKeys(fn ($t) => [(string) $t->id => route('templates.preview', $t->id)]);
        $templateDocuments = $templates->mapWithKeys(fn ($t) => [(string) $t->id => route('templates.document', $t->id)]);
        $templateNames = $templates->mapWithKeys(fn ($t) => [(string) $t->id => $t->name]);

        $hasError = fn (array $keys) => collect($keys)->contains(fn ($key) => $errors->has($key));

        $tabErrors = [
            'basics' => $hasError(['name', 'subject', 'preview_text', 'from_name', 'from_email', 'reply_to']),
            'audience' => $hasError(['audience', 'audience.lists', 'audience.lists.*', 'audience.tags', 'audience.tags.*', 'audience.segments', 'audience.segments.*']),
            'sending' => $hasError(['smtp_account_id', 'timezone', 'track_opens', 'track_clicks']),
            'template' => $hasError(['email_template_id']),
        ];

        // Open on the first tab that has something wrong with it.
        $initialTab = collect($tabErrors)->filter()->keys()->first() ?? 'basics';

        // get() on a wildcard key returns messages grouped by key — an array of
        // arrays — so this has to be flattened before it can be printed.
        $documentErrors = collect([
            $errors->get('blocks'),
            $errors->get('blocks.*'),
            $errors->get('settings'),
        ])->flatten()->all();

        $subtitle = $isNew
            ? 'Nothing is sent while you build. Saving keeps it as a draft.'
            : 'Draft edits are saved to this campaign. Nothing goes out until you review and send.';
    @endphp

    <x-page-header :title="$campaign->exists ? $campaign->name : 'New campaign'"
                   :subtitle="$subtitle"
                   :back="route('campaigns.index')">
        <x-slot name="actions">
            @if ($campaign->exists)
                <x-status-badge :status="$campaign->status" />

                <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-secondary">Overview</a>

                @permission('campaigns.send')
                    <a href="{{ route('campaigns.confirm', $campaign) }}" class="kn-btn-primary">Review &amp; send</a>
                @endpermission
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------- state warnings --}}
    @if ($campaign->exists && ! $campaign->isEditable())
        <div class="kn-card mb-5 border-amber-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">This campaign can no longer be edited</p>
                <p class="mt-1 text-sm text-ink-600">
                    It is <span class="font-medium">{{ $campaign->status }}</span>. Changing the content now would
                    mean some recipients get one email and some another, with no way to tell them apart, so saving
                    is refused. Duplicate it to carry on from a copy.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('campaigns.show', $campaign) }}" class="kn-btn-secondary kn-btn-sm">Back to the campaign</a>

                    @permission('campaigns.create')
                        <form method="POST" action="{{ route('campaigns.duplicate', $campaign) }}" class="inline">
                            @csrf
                            <button type="submit" class="kn-btn-primary kn-btn-sm">Duplicate as a new draft</button>
                        </form>
                    @endpermission
                </div>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="kn-card mb-5 border-red-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-red-800">This campaign was not saved</p>
                <p class="mt-1 text-sm text-ink-600">
                    Nothing was lost — your content is still here. Fix the points below and save again.
                </p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <x-block-builder :document="$document"
                     :block-types="$blockTypes"
                     :tokens="$tokens"
                     :action="$action"
                     :method="$method"
                     :save-label="$saveLabel">

        {{-- ================================================ extra actions --}}
        @if ($campaign->exists)
            <x-slot name="actions">
                <a href="{{ route('campaigns.preview', $campaign) }}" target="_blank" rel="noopener"
                   class="kn-btn-secondary kn-btn-sm"
                   title="Opens the last saved version in a new tab">Open last saved</a>
            </x-slot>
        @endif

        {{-- ========================================================= meta --}}
        <x-slot name="meta">
            {{--
                @invalid: a required field on a hidden tab cannot be focused, so
                the browser refuses the submit and reports nothing at all — Save
                would simply look broken. Opening the tab the offending field is
                on puts it back in front of the user. invalid does not bubble,
                hence the capture phase.
            --}}
            <div class="kn-card"
                 x-data="{
                     tab: '{{ $initialTab }}',
                     subject: @js(old_text('subject', $campaign->subject ?? '')),
                     template: @js((string) ($selectedTemplate ?: '')),
                     templateUrls: @js($templatePreviews),
                     templateDocumentUrls: @js($templateDocuments),
                     templateNames: @js($templateNames),
                 }"
                 @invalid.capture="tab = $event.target.closest('[data-tab]')?.dataset.tab || tab">

                {{-- tab strip --}}
                <div class="flex flex-wrap items-center gap-1 border-b border-ink-200/70 px-3 py-2">
                    @foreach ([
                        'basics' => 'Basics',
                        'audience' => 'Audience',
                        'sending' => 'Sending',
                        'template' => 'Template',
                    ] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                :class="tab === '{{ $key }}'
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'">
                            {{ $label }}
                            @if ($key === 'audience' && $chosenCount > 0)
                                <span class="kn-badge-gray">{{ $chosenCount }}</span>
                            @endif
                            @if ($tabErrors[$key])
                                <span class="h-1.5 w-1.5 rounded-full bg-red-500" title="Something on this tab needs fixing"></span>
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- ------------------------------------------------ basics --}}
                <div x-show="tab === 'basics'" x-cloak data-tab="basics" class="kn-card-body grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="kn-label">Campaign name</label>
                        <x-text-input id="name" name="name" type="text" required maxlength="191"
                                      :value="old('name', $campaign->name)"
                                      placeholder="March newsletter" />
                        <p class="kn-help">Internal only — recipients never see it. Used to find this campaign in lists and reports.</p>
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div class="sm:col-span-2">
                        <label for="subject" class="kn-label">Subject line</label>
                        <input id="subject" name="subject" type="text" class="kn-input" required maxlength="255"
                               x-model="subject"
                               placeholder="Your March update is here">

                        <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-ink-500">
                                Placeholders work here too, so a subject can carry the contact's first name.
                            </p>
                            <p class="text-xs tabular-nums"
                               :class="subject.length > 60 ? 'text-amber-600' : 'text-ink-500'">
                                <span x-text="subject.length"></span> characters
                                <span x-show="subject.length > 60" x-cloak>— most inboxes cut the subject around 60</span>
                            </p>
                        </div>
                        <x-input-error :messages="$errors->get('subject')" />
                    </div>

                    <div class="sm:col-span-2">
                        <label for="preview_text" class="kn-label">Preview text</label>
                        <input id="preview_text" name="preview_text" type="text" class="kn-input" maxlength="255"
                               x-model="doc.settings.preheader"
                               x-init="if (! doc.settings.preheader) doc.settings.preheader = @js(old('preview_text', $campaign->preview_text ?? ''))"
                               placeholder="The line shown after the subject in the inbox">
                        <p class="kn-help">
                            This is the preheader. It is written into the email itself, so it is the same field as
                            <span class="font-medium">Preheader</span> under Email design in the builder — editing
                            either one updates both.
                        </p>
                        <x-input-error :messages="$errors->get('preview_text')" />
                    </div>

                    <div>
                        <label for="from_name" class="kn-label">From name</label>
                        <x-text-input id="from_name" name="from_name" type="text" required maxlength="191"
                                      :value="old('from_name', $campaign->from_name)"
                                      placeholder="KN Softic" />
                        <p class="kn-help">The name shown in the inbox. A person or a company people recognise.</p>
                        <x-input-error :messages="$errors->get('from_name')" />
                    </div>

                    <div>
                        <label for="from_email" class="kn-label">From address</label>
                        <x-text-input id="from_email" name="from_email" type="email" required maxlength="191"
                                      :value="old('from_email', $campaign->from_email)"
                                      placeholder="news@yourdomain.com" />
                        <p class="kn-help">
                            Use an address on a domain you control and have authorised for sending — a mismatch is
                            the fastest route to the spam folder.
                        </p>
                        <x-input-error :messages="$errors->get('from_email')" />
                    </div>

                    <div class="sm:col-span-2">
                        <label for="reply_to" class="kn-label">Reply-to address <span class="font-normal text-ink-400">(optional)</span></label>
                        <x-text-input id="reply_to" name="reply_to" type="email" maxlength="191"
                                      :value="old('reply_to', $campaign->reply_to)"
                                      placeholder="hello@yourdomain.com" />
                        <p class="kn-help">Where replies land. Leave it empty and replies go to the from address.</p>
                        <x-input-error :messages="$errors->get('reply_to')" />
                    </div>
                </div>

                {{-- ---------------------------------------------- audience --}}
                <div x-show="tab === 'audience'" x-cloak data-tab="audience" class="kn-card-body space-y-4">
                    <div class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                        <p>
                            Pick the lists, tags and segments to mail. A contact in more than one of them is still
                            mailed once.
                        </p>
                        <p class="mt-1">
                            Unsubscribed, bounced, blocked and suppressed contacts are removed automatically, so the
                            number actually sent is usually lower than the totals shown here. The exact figure is
                            worked out on the review screen, just before sending.
                        </p>
                        <p class="mt-1 font-medium text-ink-800">
                            Selecting nothing means nobody is mailed — it does not mean everyone.
                        </p>
                    </div>

                    <x-input-error :messages="$errors->get('audience')" />

                    <div class="grid gap-4 lg:grid-cols-3">
                        {{-- lists --}}
                        <div class="rounded-lg border border-ink-200">
                            <div class="flex items-center justify-between border-b border-ink-200/70 px-3 py-2">
                                <h3 class="text-sm font-semibold text-ink-900">Lists</h3>
                                <span class="text-xs text-ink-500">{{ number_format($lists->count()) }}</span>
                            </div>

                            <div class="max-h-64 space-y-1 overflow-y-auto p-2">
                                @forelse ($lists as $list)
                                    <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 hover:bg-ink-50">
                                        <input type="checkbox" class="kn-checkbox mt-0.5" name="audience[lists][]"
                                               value="{{ $list->id }}"
                                               @checked(in_array((int) $list->id, $chosen['lists'], true))>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-ink-800">{{ $list->name }}</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format((int) $list->active_count) }} active
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="px-2 py-6 text-center text-sm text-ink-500">
                                        No lists yet.
                                        @permission('contacts.view')
                                            <a href="{{ route('lists.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Create one</a>
                                        @endpermission
                                    </p>
                                @endforelse
                            </div>
                        </div>

                        {{-- tags --}}
                        <div class="rounded-lg border border-ink-200">
                            <div class="flex items-center justify-between border-b border-ink-200/70 px-3 py-2">
                                <h3 class="text-sm font-semibold text-ink-900">Tags</h3>
                                <span class="text-xs text-ink-500">{{ number_format($tags->count()) }}</span>
                            </div>

                            <div class="max-h-64 space-y-1 overflow-y-auto p-2">
                                @forelse ($tags as $tag)
                                    <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 hover:bg-ink-50">
                                        <input type="checkbox" class="kn-checkbox mt-0.5" name="audience[tags][]"
                                               value="{{ $tag->id }}"
                                               @checked(in_array((int) $tag->id, $chosen['tags'], true))>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-ink-800">{{ $tag->name }}</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format((int) $tag->subscribers_count) }} tagged
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="px-2 py-6 text-center text-sm text-ink-500">
                                        No tags yet.
                                        @permission('contacts.view')
                                            <a href="{{ route('tags.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Create one</a>
                                        @endpermission
                                    </p>
                                @endforelse
                            </div>
                        </div>

                        {{-- segments --}}
                        <div class="rounded-lg border border-ink-200">
                            <div class="flex items-center justify-between border-b border-ink-200/70 px-3 py-2">
                                <h3 class="text-sm font-semibold text-ink-900">Segments</h3>
                                <span class="text-xs text-ink-500">{{ number_format($segments->count()) }}</span>
                            </div>

                            <div class="max-h-64 space-y-1 overflow-y-auto p-2">
                                @forelse ($segments as $segment)
                                    <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 hover:bg-ink-50">
                                        <input type="checkbox" class="kn-checkbox mt-0.5" name="audience[segments][]"
                                               value="{{ $segment->id }}"
                                               @checked(in_array((int) $segment->id, $chosen['segments'], true))>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-ink-800">{{ $segment->name }}</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format((int) $segment->cached_count) }} matched at the last recalculation
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="px-2 py-6 text-center text-sm text-ink-500">
                                        No segments yet.
                                        @permission('contacts.view')
                                            <a href="{{ route('segments.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Create one</a>
                                        @endpermission
                                    </p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-ink-500">
                        Segment counts are from the last time each segment was recalculated, not from this moment.
                    </p>
                </div>

                {{-- ----------------------------------------------- sending --}}
                <div x-show="tab === 'sending'" x-cloak data-tab="sending" class="kn-card-body grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="smtp_account_id" class="kn-label">Send through</label>
                        <select id="smtp_account_id" name="smtp_account_id" class="kn-select">
                            <option value="">Choose automatically (recommended)</option>

                            @foreach ($smtpAccounts as $smtpAccount)
                                <option value="{{ $smtpAccount->id }}" @selected($selectedSmtp === (int) $smtpAccount->id)>
                                    {{ $smtpAccount->name }} — {{ $smtpAccount->host }}{{ $smtpAccount->is_global ? ' (shared)' : '' }}
                                </option>
                            @endforeach

                            @if ($orphanSmtp)
                                <option value="{{ $selectedSmtp }}" selected>
                                    {{ $campaign->smtpAccount?->name ?? 'Account #'.$selectedSmtp }} — not available right now
                                </option>
                            @endif
                        </select>
                        <p class="kn-help">
                            Left on automatic, each message goes to the highest-priority account that still has room,
                            and moves on when one hits its limit. Pinning one account means sending stops when that
                            account runs out.
                        </p>

                        @if ($orphanSmtp)
                            <p class="mt-1.5 text-xs font-medium text-amber-700">
                                The account this campaign was pointed at is not in the sendable list at the moment —
                                it may be paused, over a limit, or no longer assigned to you. Leave it as it is, or
                                switch to automatic.
                            </p>
                        @endif

                        @if ($smtpAccounts->isEmpty() && ! $orphanSmtp)
                            <p class="mt-1.5 text-xs font-medium text-red-700">
                                No SMTP account can send right now, so this campaign cannot go out.
                                @permission('smtp.manage')
                                    <a href="{{ route('smtp.index') }}" class="underline">Check your SMTP accounts</a>.
                                @endpermission
                            </p>
                        @endif

                        <x-input-error :messages="$errors->get('smtp_account_id')" />
                    </div>

                    <div>
                        <label for="timezone" class="kn-label">Timezone</label>
                        <select id="timezone" name="timezone" class="kn-select" required>
                            @if ($orphanTimezone)
                                {{-- Held so the campaign keeps the zone it already has. --}}
                                <option value="{{ $selectedTimezone }}" selected>{{ $selectedTimezone }}</option>
                            @endif

                            @foreach ($timezoneGroups as $region => $zones)
                                <optgroup label="{{ $region }}">
                                    @foreach ($zones as $tz)
                                        <option value="{{ $tz }}" @selected($selectedTimezone === $tz)>
                                            {{ str_contains($tz, '/') ? str_replace('_', ' ', \Illuminate\Support\Str::after($tz, '/')) : $tz }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="kn-help">
                            A scheduled send time is read in this timezone, and send times are shown in it throughout.
                        </p>
                        <x-input-error :messages="$errors->get('timezone')" />
                    </div>

                    <div class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border border-ink-200 p-3">
                            {{-- The hidden field posts the "off" value, so unchecking actually saves. --}}
                            <input type="hidden" name="track_opens" value="0">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" class="kn-checkbox mt-0.5" name="track_opens" value="1"
                                       @checked($trackOpens)>
                                <span>
                                    <span class="block text-sm font-medium text-ink-800">Track opens</span>
                                    <span class="mt-1 block text-xs text-ink-500">
                                        Counted with a 1&times;1 tracking image. Many clients block images by default
                                        and Apple Mail loads them for everyone, so treat the open count as a floor
                                        and a trend — never as the truth.
                                    </span>
                                </span>
                            </label>
                            <x-input-error :messages="$errors->get('track_opens')" />
                        </div>

                        <div class="rounded-lg border border-ink-200 p-3">
                            <input type="hidden" name="track_clicks" value="0">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" class="kn-checkbox mt-0.5" name="track_clicks" value="1"
                                       @checked($trackClicks)>
                                <span>
                                    <span class="block text-sm font-medium text-ink-800">Track clicks</span>
                                    <span class="mt-1 block text-xs text-ink-500">
                                        Every link is rewritten to route through this app before forwarding the
                                        reader on. Accurate, but the address shown on hover is ours, not yours.
                                    </span>
                                </span>
                            </label>
                            <x-input-error :messages="$errors->get('track_clicks')" />
                        </div>
                    </div>
                </div>

                {{-- ---------------------------------------------- template --}}
                <div x-show="tab === 'template'" x-cloak data-tab="template" class="kn-card-body space-y-4">
                    <div>
                        <label for="email_template_id" class="kn-label">Linked template</label>
                        <select id="email_template_id" name="email_template_id" class="kn-select" x-model="template">
                            <option value="">Not based on a template</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}">
                                    {{ $template->name }}{{ $template->is_system ? ' (built in)' : '' }}
                                </option>
                            @endforeach

                            @if ($orphanTemplate)
                                <option value="{{ $selectedTemplate }}">
                                    {{ $campaign->template?->name ?? 'Template #'.$selectedTemplate }} — no longer available
                                </option>
                            @endif
                        </select>

                        <p class="kn-help">
                            This records which template the campaign is based on, and it is carried over when the
                            campaign is duplicated. Choosing one here does <span class="font-medium">not</span> change
                            your content on its own — use the button below to pull the template's blocks in.
                        </p>
                        <x-input-error :messages="$errors->get('email_template_id')" />

                        @if ($orphanTemplate)
                            <p class="mt-1.5 text-xs font-medium text-amber-700">
                                The template this campaign is based on has been deactivated or deleted, so it can no
                                longer be opened. The link is kept until you change it.
                            </p>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-2" x-show="template" x-cloak>
                            {{--
                                The one control that actually moves a template's content into a
                                campaign. It replaces the whole document and says so first, because
                                merging two block documents produces something neither the author nor
                                the user asked for.
                            --}}
                            <button type="button"
                                    x-show="templateDocumentUrls[template]"
                                    :disabled="loadingTemplate"
                                    @click="applyTemplate(templateDocumentUrls[template], templateNames[template])"
                                    class="kn-btn-primary kn-btn-sm">
                                <span x-show="! loadingTemplate">Use this template's content</span>
                                <span x-show="loadingTemplate" x-cloak>Loading…</span>
                            </button>

                            @permission('templates.view')
                                {{-- Only offer the link for a template that is actually in the list; an
                                     orphaned id has no preview URL and would send the user to a 404. --}}
                                <a :href="templateUrls[template]" target="_blank" rel="noopener"
                                   x-show="templateUrls[template]"
                                   class="kn-btn-secondary kn-btn-sm">Open this template in a new tab</a>
                            @endpermission
                            <button type="button" @click="template = ''" class="kn-btn-ghost kn-btn-sm">Unlink</button>
                        </div>
                    </div>

                    <div class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                        <p class="font-medium text-ink-800">How a template becomes a campaign</p>
                        <p class="mt-1">
                            Pick one above, then press <span class="font-medium">Use this template's content</span>.
                            The campaign takes its own copy of the blocks — editing the template afterwards does not
                            change this campaign, and editing here does not change the template. Loading replaces
                            everything currently in the builder, so it asks first.
                        </p>
                        @permission('templates.view')
                            <a href="{{ route('templates.index') }}" class="mt-2 inline-block font-medium text-brand-600 hover:text-brand-700">
                                Browse templates
                            </a>
                        @endpermission
                    </div>
                </div>
            </div>

            @if ($documentErrors)
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <p class="font-medium">The email content was rejected</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        @foreach ($documentErrors as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-slot>

        {{-- ======================================================== aside --}}
        <x-slot name="aside">
            {{--
                The live half of this list reads the form itself: the fields sit in
                other slots, so there is no shared Alpine scope to bind to. Input
                and change events bubble to the window, which is the cue to recount.
                hasFooter() and doc come from the builder scope above.
            --}}
            <div class="kn-card"
                 x-data="{
                     subject: '',
                     sender: '',
                     audience: 0,
                     refresh() {
                         const form = this.$el.closest('form');
                         if (! form) return;
                         this.subject = (form.querySelector('[name=subject]')?.value || '').trim();
                         this.sender = (form.querySelector('[name=from_email]')?.value || '').trim();
                         this.audience = form.querySelectorAll('input[name^=audience]:checked').length;
                     },
                 }"
                 x-init="refresh()"
                 @input.window="refresh()"
                 @change.window="refresh()">

                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Before you send</h2>
                </div>

                <ul class="space-y-2.5 p-4 text-xs text-ink-600">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="subject ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                              x-text="subject ? '✓' : '!'"></span>
                        <span>Subject line written</span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="sender.includes('@') ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                              x-text="sender.includes('@') ? '✓' : '!'"></span>
                        <span>Sender address set</span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="audience > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                              x-text="audience > 0 ? '✓' : '!'"></span>
                        <span>
                            Audience chosen
                            <span class="text-ink-400" x-show="audience > 0" x-cloak>
                                (<span x-text="audience"></span> source<span x-show="audience !== 1">s</span>)
                            </span>
                        </span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="doc.blocks.length > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                              x-text="doc.blocks.length > 0 ? '✓' : '!'"></span>
                        <span>Some content added</span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="hasFooter() ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                              x-text="hasFooter() ? '✓' : '!'"></span>
                        <span>Unsubscribe footer present <span class="text-ink-400">— required</span></span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold {{ $smtpAccounts->isEmpty() ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                            {{ $smtpAccounts->isEmpty() ? '!' : '✓' }}
                        </span>
                        <span>
                            @if ($smtpAccounts->isEmpty())
                                No SMTP account can send
                            @else
                                {{ number_format($smtpAccounts->count()) }} SMTP account{{ $smtpAccounts->count() === 1 ? '' : 's' }} ready
                            @endif
                        </span>
                    </li>

                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px] font-bold"
                              :class="dirty || {{ $isNew ? 'true' : 'false' }} ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                              x-text="dirty || {{ $isNew ? 'true' : 'false' }} ? '!' : '✓'"></span>
                        <span>
                            @if ($isNew)
                                Not saved yet — save the draft before sending
                            @else
                                <span x-show="! dirty">Saved</span>
                                <span x-show="dirty" x-cloak>Unsaved changes</span>
                            @endif
                        </span>
                    </li>
                </ul>

                <div class="border-t border-ink-100 px-4 py-3 text-[11px] text-ink-500">
                    This is a guide. The real checks run again on the review screen, against what is saved — a
                    campaign with anything outstanding cannot be sent.
                </div>

                @if ($campaign->exists)
                    <div class="border-t border-ink-100 px-4 py-3">
                        @permission('campaigns.send')
                            <a href="{{ route('campaigns.confirm', $campaign) }}" class="kn-btn-secondary kn-btn-sm w-full justify-center">
                                Review &amp; send
                            </a>
                        @else
                            <p class="text-[11px] text-ink-500">
                                Sending needs the campaigns.send permission, which your role does not have. Save the
                                draft and ask someone who can send to review it.
                            </p>
                        @endpermission
                    </div>
                @endif
            </div>
        </x-slot>
    </x-block-builder>

    {{-- Split test (spec 10.4). Outside the builder because it carries its own
         <form> and HTML has no nested forms — it saves independently, so a
         rejected split test never takes the unsaved email down with it. --}}
    @include('campaigns.partials.ab-settings')
</x-app-layout>
