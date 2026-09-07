{{--
    The composer.

    ── Why the page is one form plus a few small ones ──────────────────────────
    Everything that makes up the message — mailbox, recipients, subject, body —
    lives in a single <form id="kn-compose">, and Send / Save / Schedule are
    submit buttons that vary its target with formaction. That is what keeps the
    three actions honest: they cannot disagree about what is being sent,
    because they post the same fields.

    Attachments cannot join that form (a file upload needs its own multipart
    request, and forms do not nest), so the upload and remove forms sit beside
    it and the action bar reaches back into the composer with the HTML `form`
    attribute. Every one of those small forms reloads the page, so each one
    saves the draft over XHR first — otherwise adding a file would silently
    throw away whatever had been typed since the last autosave.
--}}
<x-app-layout>
    <x-slot name="header">{{ trim((string) $draft->subject) !== '' ? $draft->subject : 'New message' }}</x-slot>

    @php
        /**
         * The draft stores addresses as [{name, email}]. A text field can only
         * hold a line, so they are rendered back the way a person would type
         * them — and ComposeController::parseAddresses() reads that same shape
         * back, commas, semicolons, newlines and "Name <a@b.com>" included.
         */
        $addressLine = static function ($list): string {
            return collect((array) ($list ?? []))
                ->map(function ($item) {
                    if (! is_array($item)) {
                        return trim((string) $item);
                    }

                    $email = trim((string) ($item['email'] ?? ''));
                    $name = trim((string) ($item['name'] ?? ''));

                    if ($email === '') {
                        return '';
                    }

                    return $name === '' ? $email : $name.' <'.$email.'>';
                })
                ->filter()
                ->implode(', ');
        };

        /**
         * old() first throughout: send() answers a failure with
         * back()->withInput(), and a rejected send must not lose the message.
         *
         * But old() hands back exactly what was posted, and this form is
         * redisplayed by that same failure path. A post of to[]=x comes back as
         * an array, (string) on an array raises "Array to string conversion",
         * and Laravel promotes that warning to a 500 — on the very screen that
         * existed to show the error. Anything that is not a scalar is read as
         * "nothing came back", the same reading Request::filter() takes of
         * ?q[]=x.
         */
        $oldLine = static function (string $key, string $fallback): string {
            $value = old($key, $fallback);

            return is_scalar($value) ? (string) $value : $fallback;
        };

        $toValue = $oldLine('to', $addressLine($draft->to));
        $ccValue = $oldLine('cc', $addressLine($draft->cc));
        $bccValue = $oldLine('bcc', $addressLine($draft->bcc));
        $subjectValue = $oldLine('subject', (string) $draft->subject);
        $bodyValue = $oldLine('body_html', (string) $draft->body_html);

        // A reply or forward arrives with the previous message quoted at the
        // bottom. MessageComposer::quote() wraps it in <blockquote> precisely
        // so it survives this editor, so the whole body loads into Quill and
        // the only thing left to get right here is where the cursor starts.
        $hasQuote = str_contains($bodyValue, '<blockquote');

        $selectedMailboxId = (int) $oldLine('mailbox_id', (string) $draft->mailbox_id);
        $selectedMailbox = $mailboxes->firstWhere('id', $selectedMailboxId);
        $noMailboxes = $mailboxes->isEmpty();

        // Cc and Bcc stay out of the way until they are wanted — but never
        // hide a value that is already there.
        $ccOpen = $ccValue !== '' || $errors->has('cc');
        $bccOpen = $bccValue !== '' || $errors->has('bcc');

        // Same rule for the schedule panel, and here it is the difference
        // between a rejected schedule and a button that appeared to do nothing:
        // schedule() answers a bad time with back()->withErrors(), and the field
        // those errors belong to is inside a panel that reopens closed.
        $scheduleOpen = $errors->has('scheduled_at') || $errors->has('timezone');

        /* ---------------------------------------------------- attachments */

        $maxAttachmentKb = max(1, (int) config('knsoftic.max_attachment_kb', 10240));

        /**
         * PHP's own upload ceiling can be lower than ours, and when it is, the
         * file never reaches the validator — it arrives empty and the upload
         * looks like it failed for no reason. So the smaller of the two is what
         * the reader is told.
         */
        $iniKb = static function (string $key): int {
            $raw = trim((string) ini_get($key));

            if ($raw === '') {
                return 0;
            }

            $number = (int) $raw;

            $bytes = match (strtolower(substr($raw, -1))) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };

            return (int) floor($bytes / 1024);
        };

        $serverKbLimits = array_filter([$iniKb('upload_max_filesize'), $iniKb('post_max_size')]);
        $serverKb = $serverKbLimits === [] ? 0 : (int) min($serverKbLimits);
        $effectiveKb = $serverKb > 0 ? min($maxAttachmentKb, $serverKb) : $maxAttachmentKb;

        $attachments = $draft->attachments;
        $attachedBytes = (int) $attachments->sum('size');

        $humanBytes = static function (int $bytes): string {
            $units = ['B', 'KB', 'MB', 'GB'];
            $i = 0;

            while ($bytes >= 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }

            return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
        };

        /* -------------------------------------------------------- schedule */

        $timezones = \DateTimeZone::listIdentifiers();

        // Never hand an unvalidated identifier to now(): old() carries back
        // whatever was posted, and an unknown zone would throw right here.
        $chosenTimezone = $oldLine('timezone', (string) (auth()->user()?->timezone ?: config('app.timezone')));
        $chosenTimezone = in_array($chosenTimezone, $timezones, true) ? $chosenTimezone : 'UTC';

        $nowLocal = now($chosenTimezone);
        $minLocal = $nowLocal->format('Y-m-d\TH:i');

        // The same instant, absolute. The zone select can change the reading of
        // "now" after this page is rendered, so the minimum is recomputed in the
        // browser from THIS stamp — never from the visitor's own clock, which
        // may well be wrong.
        $nowUtcIso = $nowLocal->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        $isScheduled = $draft->send_status === 'scheduled' && $draft->scheduled_at !== null;

        $nextHourLocal = $nowLocal->copy()->addHour()->startOfHour()->format('Y-m-d\TH:i');

        $scheduledAtValue = $oldLine('scheduled_at', $isScheduled
            ? $draft->scheduled_at->copy()->setTimezone($chosenTimezone)->format('Y-m-d\TH:i')
            : $nextHourLocal);

        /**
         * The value has to satisfy the min, even on the very first paint.
         *
         * A failed send answers with back()->withInput(), so old('scheduled_at')
         * is whatever was chosen before — and by the time this page is drawn
         * again that instant may have passed, which leaves the field out of
         * range. An out-of-range field fails constraint validation, and because
         * it belongs to the composer form (form="kn-compose") it takes SEND
         * down with it, silently: the browser refuses to submit and has nothing
         * visible to report the error on. Both stamps are Y-m-d\TH:i, so a
         * string comparison is a chronological one.
         */
        if ($scheduledAtValue === '' || $scheduledAtValue < $minLocal) {
            $scheduledAtValue = $nextHourLocal;
        }

        $draftCount = (int) ($counts['drafts']['total'] ?? 0);
    @endphp

    <x-page-header
        :title="trim((string) $draft->subject) !== '' ? $draft->subject : 'New message'"
        :subtitle="$draftCount === 1 ? '1 draft in this account' : number_format($draftCount).' drafts in this account'"
        :back="route('inbox.drafts')">
        <x-slot name="actions">
            @if ($isScheduled)
                <x-status-badge status="scheduled" />
            @else
                <x-status-badge :status="(string) ($draft->send_status ?: 'draft')" />
            @endif
        </x-slot>
    </x-page-header>

    @if ($draft->send_status === 'failed' && $draft->error)
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-medium">The last attempt to send this did not go through.</p>
            <p class="mt-1 break-words">{{ $draft->error }}</p>
            <p class="mt-1 text-xs text-red-700">
                Nothing was delivered and nothing was lost — the message is still a draft. Fix what the error
                points at, then send it again.
            </p>
        </div>
    @endif

    @if ($noMailboxes)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">There is no mailbox to send from.</p>
            <p class="mt-1">
                A message needs a From address. Connect a mailbox first — until then this draft can be written and
                saved, but not sent or scheduled.
            </p>
            @if (Route::has('mailboxes.index'))
                <a href="{{ route('mailboxes.index') }}" class="kn-btn-secondary kn-btn-sm mt-3">Go to mailboxes</a>
            @endif
        </div>
    @elseif ($selectedMailbox === null)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            The mailbox this draft was started from is no longer available, so the first one below is selected
            instead. Check the From address before you send.
        </div>
    @endif

    <div
        x-data="knInboxComposer(@js([
            'body' => $bodyValue,
            'showCc' => $ccOpen,
            'showBcc' => $bccOpen,
            'showSchedule' => $scheduleOpen,
            'updateUrl' => route('inbox.compose.update', $draft),
            'maxBytes' => $effectiveKb * 1024,
            'maxLabel' => number_format($effectiveKb).' KB',
            'minLocal' => $minLocal,
            'serverNow' => $nowUtcIso,
        ]))"
        class="space-y-5">

        {{-- =========================================================== the message --}}
        <form id="kn-compose"
              method="POST"
              action="{{ route('inbox.compose.send', $draft) }}"
              x-ref="form"
              @submit="serialise()"
              @keydown.enter="guardEnter($event)"
              @input="touch()"
              @change="touch()"
              @focusout="autosave()"
              class="kn-card">
            @csrf

            {{--
                The editor's content. Written by serialise() the moment before
                any submit, exactly as the block builder does it, so Send, Save
                and Schedule can never post a stale body.

                It also carries the stored body as its server-rendered value.
                Without that it is empty until Alpine boots, and a Send pressed
                in the second before that — or after a bundle that never
                loaded — would post body_html="" and overwrite the message with
                nothing. The field is only ever a copy of what the editor holds,
                so seeding it with what the editor is about to be given costs
                nothing and removes the window entirely.
            --}}
            <input type="hidden" name="body_html" x-ref="bodyField" value="{{ $bodyValue }}">

            <div class="divide-y divide-ink-100">

                {{-- ------------------------------------------------------------- From --}}
                <div class="px-5 py-4">
                    <x-input-label for="mailbox_id" value="From" />

                    <select id="mailbox_id" name="mailbox_id" class="kn-select" @disabled($noMailboxes)>
                        @forelse ($mailboxes as $mailbox)
                            <option value="{{ $mailbox->id }}" @selected($selectedMailbox?->id === $mailbox->id || ($selectedMailbox === null && $loop->first))>
                                {{ $mailbox->name }} — {{ $mailbox->email }}
                            </option>
                        @empty
                            <option value="">No mailbox connected</option>
                        @endforelse
                    </select>

                    <p class="kn-help">
                        This sets the From address the recipient sees. Delivery itself goes out over this account's
                        SMTP routing, picked at the moment you press Send — not back through the mailbox's IMAP
                        connection.
                    </p>

                    <x-input-error :messages="$errors->get('mailbox_id')" />
                </div>

                {{-- --------------------------------------------------------- Recipients --}}
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <div class="flex items-end justify-between gap-3">
                            <x-input-label for="to" value="To" class="mb-0" />

                            <div class="mb-1.5 flex items-center gap-1">
                                <button type="button" x-show="! showCc" @click="openField('showCc', 'cc')"
                                        class="kn-btn-ghost kn-btn-sm">Add Cc</button>
                                <button type="button" x-show="! showBcc" @click="openField('showBcc', 'bcc')"
                                        class="kn-btn-ghost kn-btn-sm">Add Bcc</button>
                            </div>
                        </div>

                        <x-text-input id="to" name="to" type="text" class="mt-1.5 block w-full"
                                      :value="$toValue" required
                                      autocomplete="off"
                                      placeholder="someone@example.com, someone-else@example.com" />

                        <p class="kn-help">Separate addresses with commas. Anything that is not a valid address is dropped when the draft is saved.</p>
                        <x-input-error :messages="$errors->get('to')" />
                    </div>

                    <div x-show="showCc" x-cloak>
                        <div class="flex items-end justify-between gap-3">
                            <x-input-label for="cc" value="Cc" class="mb-0" />
                            <button type="button" @click="closeField('showCc', 'cc')"
                                    class="kn-btn-ghost kn-btn-sm mb-1.5">Remove Cc</button>
                        </div>

                        <x-text-input id="cc" name="cc" type="text" class="mt-1.5 block w-full"
                                      :value="$ccValue" autocomplete="off" x-ref="cc"
                                      placeholder="Everyone here can see each other" />

                        <x-input-error :messages="$errors->get('cc')" />
                    </div>

                    <div x-show="showBcc" x-cloak>
                        <div class="flex items-end justify-between gap-3">
                            <x-input-label for="bcc" value="Bcc" class="mb-0" />
                            <button type="button" @click="closeField('showBcc', 'bcc')"
                                    class="kn-btn-ghost kn-btn-sm mb-1.5">Remove Bcc</button>
                        </div>

                        <x-text-input id="bcc" name="bcc" type="text" class="mt-1.5 block w-full"
                                      :value="$bccValue" autocomplete="off" x-ref="bcc"
                                      placeholder="Hidden from everyone else on the message" />

                        <p class="kn-help">Nobody on the To or Cc line is told these addresses were included.</p>
                        <x-input-error :messages="$errors->get('bcc')" />
                    </div>
                </div>

                {{-- ------------------------------------------------------------ Subject --}}
                <div class="px-5 py-4">
                    <x-input-label for="subject" value="Subject" />
                    <x-text-input id="subject" name="subject" type="text" class="block w-full"
                                  :value="$subjectValue" maxlength="255" autocomplete="off"
                                  placeholder="No subject" />
                    <x-input-error :messages="$errors->get('subject')" />
                </div>

                {{-- --------------------------------------------------------------- Body --}}
                <div class="px-5 py-4">
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span class="kn-label mb-0">Message</span>

                        <button type="button" @click="raw = ! raw" class="kn-btn-ghost kn-btn-sm"
                                x-text="raw ? 'Back to the editor' : 'Edit the HTML'"></button>
                    </div>

                    <div x-show="editorBroken" x-cloak
                         class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        The rich text editor could not be loaded, so this is the raw HTML view. Everything still
                        saves and sends — you are just writing the markup by hand.
                    </div>

                    {{--
                        x-if rather than x-show, for the same reason the block
                        builder does it: Quill puts its toolbar in the DOM
                        beside the editor, so hiding it would leave a stray
                        toolbar behind, and coming back from the HTML view has
                        to rebuild the editor from the edited markup.
                    --}}
                    <template x-if="! raw">
                        <div class="overflow-hidden rounded-lg border border-ink-200 bg-white">
                            <div x-init="startEditor($el)" style="min-height:18rem"></div>
                        </div>
                    </template>

                    <template x-if="raw">
                        <textarea rows="18" class="kn-textarea font-mono text-xs" x-model="body"
                                  spellcheck="false"></textarea>
                    </template>

                    <p class="kn-help">
                        The toolbar offers only formatting that inboxes actually render.
                        @if ($hasQuote)
                            The quoted message below the cursor goes out with your reply, and stays a quote the
                            recipient's client can collapse. Delete it if you would rather it did not.
                        @endif
                    </p>

                    <x-input-error :messages="$errors->get('body_html')" />
                </div>
            </div>
        </form>

        {{-- =========================================================== attachments --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <div>
                    <h2 class="text-sm font-semibold text-ink-900">Attachments</h2>
                    <p class="mt-0.5 text-xs text-ink-500">
                        @if ($attachments->isEmpty())
                            Nothing attached yet.
                        @else
                            {{ $attachments->count() === 1 ? '1 file' : $attachments->count().' files' }},
                            {{ $humanBytes($attachedBytes) }} in total
                        @endif
                    </p>
                </div>
            </div>

            @if ($attachments->isEmpty())
                <x-empty-state title="No files on this draft"
                               message="Attach anything the recipient needs. Files are stored with the draft, so they survive a closed tab." />
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($attachments as $attachment)
                        <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-100 text-ink-500">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739 10.682 20.4a3 3 0 0 1-4.243-4.243l7.81-7.81a2.25 2.25 0 1 1 3.182 3.182l-7.81 7.81a1.5 1.5 0 0 1-2.122-2.122l7.06-7.06"/>
                                </svg>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ink-900">{{ $attachment->name }}</span>
                                <span class="block text-xs text-ink-500">
                                    {{ $attachment->humanSize() }} · {{ $attachment->mime_type ?: 'unknown type' }}
                                </span>
                            </span>

                            {{--
                                A real DELETE form — confirmed, and it saves the
                                draft over XHR first, because the redirect it
                                comes back on rebuilds this page from the
                                database.

                                The confirmation is a parameter rather than an
                                onsubmit attribute: both listeners on a form
                                run, so an onsubmit returning false would cancel
                                the browser's own submit and then be overridden
                                by this handler submitting anyway.
                            --}}
                            <form method="POST" action="{{ route('inbox.compose.detach', [$draft, $attachment]) }}"
                                  class="shrink-0"
                                  @submit.prevent="submitAfterSaving($event.target, @js('Remove '.$attachment->name.' from this draft? The file is deleted.'))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="kn-btn-danger kn-btn-sm">Remove</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="border-t border-ink-100 px-5 py-4">
                <form method="POST" action="{{ route('inbox.compose.attach', $draft) }}"
                      enctype="multipart/form-data"
                      @submit.prevent="submitAfterSaving($event.target)"
                      class="flex flex-wrap items-end gap-3">
                    @csrf

                    <div class="min-w-0 flex-1">
                        <x-input-label for="file" value="Add a file" />
                        <input type="file" name="file" id="file" required
                               @change="checkFile($event)"
                               class="block w-full text-sm text-ink-700 file:mr-3 file:rounded-lg file:border-0
                                      file:bg-ink-100 file:px-3 file:py-2 file:text-sm file:font-medium
                                      file:text-ink-700 hover:file:bg-ink-200">
                        <x-input-error :messages="$errors->get('file')" />
                    </div>

                    <button type="submit" class="kn-btn-secondary shrink-0">Upload</button>
                </form>

                <p class="kn-help">
                    Up to {{ number_format($effectiveKb) }} KB
                    ({{ round($effectiveKb / 1024, 1) }} MB) per file.
                    @if ($serverKb > 0 && $serverKb < $maxAttachmentKb)
                        That is this server's own upload ceiling, which is lower than the
                        {{ number_format($maxAttachmentKb) }} KB this install allows.
                    @endif
                    Uploaded files are stored with the draft and count against the account's storage allowance until
                    the draft is sent or discarded.
                </p>
            </div>
        </div>

        {{-- =============================================================== actions --}}
        <div class="kn-card">
            <div class="kn-card-body space-y-4">

                {{-- Autosave state. Never silent: a draft that failed to save
                     has to say so where the person writing it will see it. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                    <span x-show="saving" x-cloak class="flex items-center gap-1.5 text-brand-700">
                        <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z"/>
                        </svg>
                        Saving…
                    </span>

                    <span x-show="! saving && savedAt !== '' && ! dirty" x-cloak class="text-ink-500">
                        Draft saved at <span x-text="savedAt"></span>.
                    </span>

                    <span x-show="! saving && dirty && saveError === ''" x-cloak class="kn-badge-amber">Unsaved changes</span>

                    <span x-show="! saving && savedAt === '' && ! dirty && saveError === ''" x-cloak class="text-ink-500">
                        Saves itself every 20 seconds and whenever you leave a field.
                    </span>
                </div>

                <div x-show="saveError !== ''" x-cloak
                     class="flex flex-wrap items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">This draft was not saved.</p>
                        <p class="mt-1 break-words" x-text="saveError"></p>
                        <p class="mt-1 text-xs text-red-700">
                            What is on screen is still here — do not close the tab until it saves.
                        </p>
                    </div>
                    <button type="button" @click="saveNow()" class="kn-btn-danger kn-btn-sm shrink-0">Try again</button>
                </div>

                @if ($isScheduled)
                    {{-- Scheduled: the schedule panel is replaced by what is
                         already set, and by the way to undo it. --}}
                    <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
                        <p class="font-medium">
                            This goes out on
                            {{ $draft->scheduled_at->copy()->setTimezone($chosenTimezone)->toDayDateTimeString() }}
                            ({{ $chosenTimezone }}).
                        </p>
                        <p class="mt-1 text-xs text-brand-800">
                            Nothing has been sent yet. Edits you save before then are what will go out — the message
                            is built at the moment it is sent, not now.
                        </p>

                        <form method="POST" action="{{ route('inbox.compose.unschedule', $draft) }}"
                              class="mt-3"
                              @submit.prevent="submitAfterSaving($event.target)">
                            @csrf
                            <button type="submit" class="kn-btn-secondary kn-btn-sm">Unschedule — keep it as a draft</button>
                        </form>
                    </div>
                @else
                    {{--
                        The schedule panel stays in the DOM when collapsed so its
                        values survive — but its two fields are DISABLED while it
                        is closed, and that is not cosmetic.

                        Both belong to the composer form (form="kn-compose"), so
                        the browser validates them on every submit, including
                        Send. A datetime-local holding a time before its min — or
                        a half-typed date — is invalid, and an invalid control
                        the reader cannot see makes Send a button that does
                        nothing at all: the browser refuses the submit and then
                        has nowhere to show the error (Chrome logs "An invalid
                        form control is not focusable" to a console no user
                        reads). A disabled control is barred from constraint
                        validation and is not submitted, so a closed panel is
                        genuinely closed. Opening it enables them again, and
                        Schedule validates them where they are visible.
                    --}}
                    <div x-show="showSchedule" x-cloak class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-4">
                        <h3 class="text-sm font-semibold text-ink-900">Send it later</h3>
                        <p class="mt-1 text-xs text-ink-600">
                            The draft waits as <em>scheduled</em> and goes out on its own at the time you pick. You
                            can unschedule it any time before then.
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="scheduled_at" value="Date and time" />
                                <input type="datetime-local" id="scheduled_at" name="scheduled_at" form="kn-compose"
                                       class="kn-input"
                                       value="{{ $scheduledAtValue }}"
                                       min="{{ $minLocal }}"
                                       x-ref="scheduledAt"
                                       :min="minLocal"
                                       :disabled="! showSchedule"
                                       @disabled($noMailboxes)>
                                <p class="kn-help">Read in the time zone beside it, and it has to be in the future.</p>
                                <x-input-error :messages="$errors->get('scheduled_at')" />
                            </div>

                            <div>
                                <x-input-label for="timezone" value="Time zone" />
                                <select id="timezone" name="timezone" form="kn-compose" class="kn-select"
                                        @change="retime($event.target.value)"
                                        :disabled="! showSchedule" @disabled($noMailboxes)>
                                    @foreach ($timezones as $timezone)
                                        <option value="{{ $timezone }}" @selected($timezone === $chosenTimezone)>{{ $timezone }}</option>
                                    @endforeach
                                </select>
                                <p class="kn-help">Defaults to your own time zone ({{ $chosenTimezone }}).</p>
                                <x-input-error :messages="$errors->get('timezone')" />
                            </div>
                        </div>

                        <button type="submit" form="kn-compose"
                                formaction="{{ route('inbox.compose.schedule', $draft) }}"
                                formmethod="POST"
                                class="kn-btn-secondary mt-3" @disabled($noMailboxes)>
                            Schedule it
                        </button>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" form="kn-compose"
                            formaction="{{ route('inbox.compose.send', $draft) }}"
                            formmethod="POST"
                            class="kn-btn-primary" @disabled($noMailboxes)>
                        Send now
                    </button>

                    {{--
                        A real submit as the fallback — formaction plus a
                        _method button, which Laravel reads the same way as the
                        hidden field. Alpine intercepts the click and saves over
                        XHR instead, so pressing Save does not throw the page
                        away and reload it.
                    --}}
                    <button type="submit" form="kn-compose"
                            formaction="{{ route('inbox.compose.update', $draft) }}"
                            formmethod="POST"
                            name="_method" value="PUT"
                            @click.prevent="saveNow()"
                            class="kn-btn-secondary">
                        Save draft
                    </button>

                    @unless ($isScheduled)
                        <button type="button" @click="showSchedule = ! showSchedule"
                                class="kn-btn-ghost"
                                x-text="showSchedule ? 'Cancel scheduling' : 'Schedule…'"
                                @disabled($noMailboxes)></button>
                    @endunless

                    {{--
                        No autosave first: this is the one action that is meant
                        to lose what is on screen.

                        The listener is on the wrapper because the confirmation
                        lives inside the component, on the form's own onsubmit.
                        submit bubbles, so by the time it arrives here the
                        answer is known: cancelled means the default was
                        prevented and the guard has to stay armed. Confirmed
                        means the reader has already said the draft can go, and
                        leaving `dirty` set would greet that decision with the
                        browser's own "changes you made may not be saved" —
                        a second, worse-worded dialogue asking the same
                        question.
                    --}}
                    <div class="ml-auto" @submit="if (! $event.defaultPrevented) dirty = false">
                        <x-confirm-form
                            :action="route('inbox.compose.destroy', $draft)"
                            method="DELETE"
                            label="Discard draft"
                            :message="'Discard this draft? The message and its '.($attachments->count() === 1 ? '1 attachment' : $attachments->count().' attachments').' are deleted for good.'"
                            button-class="kn-btn-danger" />
                    </div>
                </div>

                <p class="border-t border-ink-100 pt-3 text-xs text-ink-500">
                    Drafts live in this application, not on your mail server — nothing here is ever written back
                    over IMAP, so this draft will not appear in your provider's Drafts folder. When it is sent, the
                    copy is filed in this app's Sent folder straight away rather than waiting for a sync to find it.
                </p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            /**
             * The composer's Alpine component.
             *
             * Quill is NOT imported here. mountEditor() and emailSafeHtml() are
             * borrowed from the block builder, which holds the app's only
             * dynamic import of Quill — so this screen shares that lazily
             * loaded chunk instead of pulling a second copy of the editor into
             * the page, and the two editors cannot drift apart.
             */
            window.knInboxComposer = function (config) {
                var builder = typeof window.knBlockBuilder === 'function' ? window.knBlockBuilder({}) : null;

                return {
                    body: config.body || '',
                    raw: false,
                    editorBroken: builder === null,
                    caretPlaced: false,

                    showCc: !! config.showCc,
                    showBcc: !! config.showBcc,

                    // Open when the last attempt to schedule was rejected —
                    // otherwise the error is rendered inside a collapsed panel
                    // and "Schedule it" looks like a button that does nothing.
                    showSchedule: !! config.showSchedule,

                    saving: false,
                    dirty: false,
                    savedAt: '',
                    saveError: '',
                    signature: '',
                    timer: null,

                    minLocal: config.minLocal,

                    /*
                     * mountEditor() addresses a block setting by key. The
                     * composer has exactly one field, so these two adapt it —
                     * and lastField is the property it clears when the editor
                     * takes focus.
                     */
                    lastField: null,

                    settingValue: function () {
                        return this.body;
                    },

                    setSetting: function (key, value) {
                        this.body = value;
                        this.touch();
                    },

                    emailSafeHtml: builder ? builder.emailSafeHtml : function (html) { return html; },

                    init: function () {
                        var self = this;

                        this.serialise();
                        this.signature = this.snapshot();

                        if (this.editorBroken) {
                            this.raw = true;
                        }

                        this.timer = setInterval(function () { self.autosave(); }, 20000);

                        // A draft lost to a stray click is the worst outcome
                        // here, so leaving with an unsaved change is confirmed.
                        window.addEventListener('beforeunload', function (event) {
                            if (! self.dirty) return;
                            event.preventDefault();
                            event.returnValue = '';
                        });
                    },

                    destroy: function () {
                        clearInterval(this.timer);
                    },

                    /**
                     * Enter in a one-line field does nothing.
                     *
                     * This form has no submit button of its own — Send, Save
                     * and Schedule all sit outside it and reach back with
                     * form="kn-compose". They are still the form's buttons as
                     * far as the browser is concerned, so pressing Enter in a
                     * field triggers implicit submission, which fires the
                     * form's DEFAULT button: the first one in document order,
                     * which is "Schedule it" inside the collapsed schedule
                     * panel. Enter in the subject line is not a request to
                     * schedule a message from a panel the reader cannot even
                     * see. The editor and the raw HTML view are untouched —
                     * Enter is a new line there and the event only reaches
                     * here after bubbling out of them.
                     */
                    guardEnter: function (event) {
                        var el = event.target;

                        if (el && (el.tagName === 'INPUT' || el.tagName === 'SELECT')) {
                            event.preventDefault();
                        }
                    },

                    /* ------------------------------------------------ editor */

                    startEditor: async function (el) {
                        if (! builder) {
                            this.editorBroken = true;
                            this.raw = true;
                            return;
                        }

                        try {
                            await builder.mountEditor.call(this, el, 'body');
                        } catch (error) {
                            // The chunk did not load. Raw HTML still edits,
                            // saves and sends, so say so rather than showing an
                            // empty box that swallows what is typed into it.
                            this.editorBroken = true;
                            this.raw = true;
                            return;
                        }

                        if (! this.caretPlaced) {
                            this.caretPlaced = true;
                            this.caretAboveQuote(el);
                        }
                    },

                    /**
                     * Quill leaves the caret at the end of whatever it pasted.
                     * On a reply that is the wrong end — the cursor belongs on
                     * the first line, above the quote, where the answer is
                     * actually written.
                     */
                    caretAboveQuote: function (el) {
                        var root = el.querySelector('.ql-editor');
                        if (! root) return;

                        var first = root.firstElementChild || root;

                        try {
                            root.focus({ preventScroll: true });

                            var range = document.createRange();
                            range.setStart(first, 0);
                            range.collapse(true);

                            var selection = window.getSelection();
                            selection.removeAllRanges();
                            selection.addRange(range);
                        } catch (error) {
                            // Placing the caret is a courtesy, never a
                            // requirement — the editor works without it.
                        }
                    },

                    /* ------------------------------------------- cc and bcc */

                    openField: function (flag, ref) {
                        var self = this;

                        this[flag] = true;

                        // $nextTick alone is too early: x-show has queued the
                        // display change but the browser has not painted it,
                        // and focus() on a still-hidden element does nothing.
                        // One frame later it lands. Measured, not guessed.
                        this.$nextTick(function () {
                            requestAnimationFrame(function () {
                                if (self.$refs[ref]) self.$refs[ref].focus();
                            });
                        });
                    },

                    closeField: function (flag, ref) {
                        if (this.$refs[ref] && this.$refs[ref].value.trim() !== ''
                            && ! window.confirm('Remove this line? The addresses on it are cleared.')) {
                            return;
                        }

                        if (this.$refs[ref]) this.$refs[ref].value = '';

                        this[flag] = false;
                        this.touch();
                    },

                    /* --------------------------------------------- schedule */

                    /**
                     * "Now" read in the SELECTED zone. Server-rendered it is
                     * right for the zone the page loaded with, but the select
                     * can change that zone, and moving to a zone ahead of it
                     * would let a time the controller rejects as past through
                     * the browser — abort_if($when->isPast(), 422) is an error
                     * page, not a field error.
                     */
                    retime: function (zone) {
                        try {
                            var parts = new Intl.DateTimeFormat('en-GB', {
                                timeZone: zone,
                                hourCycle: 'h23',
                                year: 'numeric', month: '2-digit', day: '2-digit',
                                hour: '2-digit', minute: '2-digit'
                            }).formatToParts(new Date(config.serverNow))
                              .reduce(function (carry, part) { carry[part.type] = part.value; return carry; }, {});

                            this.minLocal = parts.year + '-' + parts.month + '-' + parts.day
                                + 'T' + parts.hour + ':' + parts.minute;
                        } catch (error) {
                            // An identifier this browser does not know: keep the
                            // server's value rather than replacing it with a
                            // wrong one.
                            return;
                        }

                        // A minimum that has moved past the chosen time would
                        // make the field invalid, and an invalid field blocks
                        // Send as well as Schedule. So the time moves with it.
                        var field = this.$refs.scheduledAt;

                        if (field && field.value && field.value < this.minLocal) {
                            field.value = this.minLocal;
                        }
                    },

                    /* ---------------------------------------------- autosave */

                    serialise: function () {
                        if (this.$refs.bodyField) this.$refs.bodyField.value = this.body;
                        this.dirty = false;
                    },

                    payload: function () {
                        var form = this.$refs.form;

                        var read = function (name) {
                            var el = form ? form.elements[name] : null;
                            return el && typeof el.value === 'string' ? el.value : '';
                        };

                        return {
                            mailbox_id: read('mailbox_id'),
                            to: read('to'),
                            cc: this.showCc ? read('cc') : '',
                            bcc: this.showBcc ? read('bcc') : '',
                            subject: read('subject'),
                            body_html: this.body
                        };
                    },

                    snapshot: function () {
                        return JSON.stringify(this.payload());
                    },

                    /** Keeps the hidden field and the dirty flag honest. */
                    touch: function () {
                        if (this.$refs.bodyField) this.$refs.bodyField.value = this.body;
                        this.dirty = this.snapshot() !== this.signature;
                    },

                    /**
                     * PUTs the draft. A real PUT over fetch, so there is no
                     * method spoofing to get wrong, and the controller answers
                     * JSON to an XHR — this must never navigate the page.
                     */
                    autosave: async function (force) {
                        if (this.saving) return false;

                        var snap = this.snapshot();

                        if (! force && snap === this.signature) return true;

                        this.saving = true;
                        this.saveError = '';

                        try {
                            var meta = document.querySelector('meta[name=csrf-token]');
                            var response;

                            try {
                                response = await fetch(config.updateUrl, {
                                    method: 'PUT',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': meta ? meta.content : '',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: snap
                                });
                            } catch (networkError) {
                                // Never show the browser's own wording here —
                                // "Failed to fetch" tells the person writing an
                                // email nothing they can do anything about.
                                this.saveError = 'The server could not be reached, so this draft was not saved. '
                                    + 'Check the connection and press Try again — nothing on screen has been lost.';

                                return false;
                            }

                            if (! response.ok) {
                                this.saveError = await this.failureMessage(response);

                                return false;
                            }

                            this.signature = snap;
                            this.dirty = false;
                            this.savedAt = new Date().toLocaleTimeString();

                            return true;
                        } finally {
                            this.saving = false;
                        }
                    },

                    failureMessage: async function (response) {
                        if (response.status === 419) {
                            return 'Your session has expired, so nothing can be saved until you sign in again. '
                                + 'Copy anything you cannot lose, then reload this page.';
                        }

                        if (response.status === 403) {
                            return 'You no longer have permission to write in this inbox, so this draft cannot be saved.';
                        }

                        if (response.status === 404) {
                            return 'This draft no longer exists — it may have been sent or discarded somewhere else.';
                        }

                        if (response.status === 422) {
                            var body = await response.json().catch(function () { return null; });

                            return body && body.message
                                ? 'The draft was rejected: ' + body.message
                                : 'The draft was rejected by the server.';
                        }

                        return 'The draft could not be saved (HTTP ' + response.status + '). Press Try again.';
                    },

                    saveNow: async function () {
                        var ok = await this.autosave(true);

                        this.toast(ok ? 'Draft saved.' : this.saveError, ok ? 'success' : 'error');
                    },

                    /**
                     * Upload, remove and unschedule all POST and come back on a
                     * redirect, which rebuilds this page from the database. So
                     * the draft is saved first — otherwise attaching a file
                     * would quietly discard everything typed since the last
                     * autosave.
                     */
                    submitAfterSaving: async function (form, question) {
                        if (question && ! window.confirm(question)) {
                            return;
                        }

                        if (this.snapshot() !== this.signature) {
                            var saved = await this.autosave();

                            if (! saved && ! window.confirm(
                                'The draft could not be saved just now:\n\n' + this.saveError
                                + '\n\nCarry on anyway? Everything typed since the last save will be lost.'
                            )) {
                                return;
                            }
                        }

                        // The reload is deliberate from here on.
                        this.dirty = false;
                        form.submit();
                    },

                    /* ------------------------------------------ attachments */

                    checkFile: function (event) {
                        var file = event.target.files && event.target.files[0];

                        if (! file) return;

                        if (file.size > config.maxBytes) {
                            event.target.value = '';
                            this.toast('"' + file.name + '" is larger than the ' + config.maxLabel
                                + ' limit, so it was not attached.', 'error');
                        }
                    },

                    toast: function (message, type) {
                        if (! message) return;

                        window.dispatchEvent(new CustomEvent('toast', {
                            detail: { type: type || 'info', message: message }
                        }));
                    }
                };
            };
        </script>
    @endpush
</x-app-layout>
