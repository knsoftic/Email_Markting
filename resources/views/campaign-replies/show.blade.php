<x-app-layout>
    <x-slot name="header">{{ $thread->subject ?: '(No subject)' }}</x-slot>

    @php
        /**
         * One campaign conversation, oldest message first.
         *
         * ── What this screen is allowed to touch ────────────────────────────
         * CampaignReplyController@show passes exactly four things: $thread with
         * campaign:id,name,subject,status loaded, $messages (attachments
         * loaded, oldest first), $bodies keyed by email id, and $statuses.
         * Nothing else is reached for — lazy loading is prevented outside
         * production, so $thread->subscriber or $message->campaign would break
         * on a developer's machine and nowhere else. The contact is therefore
         * read off the messages themselves, and the subscriber link is built
         * from the id on the thread row.
         *
         * ── $bodies is never printed ────────────────────────────────────────
         * The body of a reply is a stranger's HTML. It is loaded from
         * inbox.body into a frame with an empty sandbox, and this document does
         * not contain it in any form. $bodies is used here for two things only:
         * to know whether a message has a body at all, and to pick between two
         * frame heights. Its content is never echoed.
         *
         * ── Read state is deliberately not shown per message ────────────────
         * The controller marks every message read AFTER it has fetched
         * $messages, so is_read in this collection is the value from before the
         * update. Rendering an "Unread" chip from it would be showing a flag
         * the database no longer holds.
         */

        $subject = $thread->subject ?: '(No subject)';

        $campaign = $thread->campaign;   // may be null: the campaign row can be deleted
        $messages = $messages->values();
        $total = $messages->count();

        $user = auth()->user();

        // ------------------------------------------------------------ status
        // reply_status is a nullable column. A thread that was filed under a
        // campaign before it was ever put in the queue has no state at all, and
        // an empty badge would be worse than saying so.
        $current = (string) $thread->reply_status;
        $tracked = in_array($current, $statuses, true);

        $statusTones = [
            'new' => 'kn-badge-amber',
            'read' => 'kn-badge-blue',
            'replied' => 'kn-badge-green',
            'closed' => 'kn-badge-gray',
        ];

        $statusMeaning = [
            'new' => 'Nobody has dealt with this yet.',
            'read' => 'Seen, but no answer has gone back.',
            'replied' => 'Answered from this account.',
            'closed' => 'Done with. Still searchable.',
        ];

        /**
         * What each state is FOR, in the words of somebody working the queue.
         * Only the states this conversation is not already in are offered — a
         * button that sets the status it already has does nothing and says so
         * afterwards, which is worse than not being there.
         *
         * There is no "mark as read": opening this page already did that.
         * "read" appears only as a way back out of replied or closed, which is
         * why its label talks about answering rather than reading.
         */
        $statusActions = [
            'read' => [
                'label' => 'Mark as not answered',
                'class' => 'kn-btn-secondary kn-btn-sm',
                'help' => 'Seen, but nothing has gone back yet.',
            ],
            'replied' => [
                'label' => 'Mark as answered',
                'class' => 'kn-btn-primary kn-btn-sm',
                'help' => 'For an answer you sent from somewhere other than here — sending a reply from this page sets it on its own.',
            ],
            'closed' => [
                'label' => 'Close this conversation',
                'class' => 'kn-btn-secondary kn-btn-sm',
                'help' => 'Takes it out of the queue. It stays searchable, and it comes back if they write again.',
            ],
        ];

        /**
         * Only the states it is not already in — and never "new".
         *
         * CampaignReplyController@show moves a conversation out of "new" on
         * every render, and updateStatus redirects back() to this same screen.
         * A "put it back in the queue" button here would therefore write 'new',
         * bounce straight back into show(), and be rewritten to 'read' before
         * the operator saw the badge change: the flash would claim one thing
         * and the badge show another. Re-queueing lives on the queue screen,
         * where the redirect lands on a page that does not touch the state.
         */
        $offered = array_values(array_filter(
            $statuses,
            fn ($status) => $status !== $current && $status !== 'new'
        ));

        // ------------------------------------------------------------- links
        // Route::has as well as the permission: a link straight into a 403, or
        // into a route that is not mounted yet, is worse than plain text.
        $canSeeCampaign = $campaign
            && \Illuminate\Support\Facades\Route::has('campaigns.show')
            && (bool) $user?->hasPermission('campaigns.view');

        $canSeeReport = $campaign
            && \Illuminate\Support\Facades\Route::has('analytics.campaign')
            && (bool) $user?->hasPermission('analytics.view');

        $canSeeContact = $thread->subscriber_id
            && \Illuminate\Support\Facades\Route::has('subscribers.show')
            && (bool) $user?->hasPermission('contacts.view');

        // ----------------------------------------------------------- contact
        // Read off the conversation rather than the subscriber relation, which
        // was not loaded. The first message they sent carries the name and
        // address the mail server actually saw.
        $firstIncoming = $messages->firstWhere('direction', 'incoming');
        $contactName = trim((string) ($firstIncoming?->from_name ?? ''));
        $contactEmail = trim((string) ($firstIncoming?->from_email ?? ''));

        // ------------------------------------------------------------- dates
        $whenOf = fn ($message) => $message?->received_at ?? $message?->sent_at ?? $message?->created_at;

        $startedAt = $whenOf($messages->first()) ?? $thread->created_at;
        $lastAt = $thread->last_message_at ?? $whenOf($messages->last());

        $subtitleParts = array_filter([
            $campaign?->name,
            $total === 1 ? '1 message' : number_format($total).' messages',
            $lastAt ? 'last activity '.$lastAt->diffForHumans() : null,
        ]);

        /**
         * Collapsing.
         *
         * The first message and the last two stay open; anything between them
         * folds away. That only earns its keep once at least three messages
         * would be hidden, so a five-message thread gets no toggle at all.
         */
        $collapse = $total > 5;
        $hiddenFrom = 1;
        $hiddenTo = $total - 3;
        $hiddenCount = $collapse ? ($hiddenTo - $hiddenFrom + 1) : 0;

        // Full literal class lists — never assembled from fragments, so Tailwind
        // still sees every class it has to compile.
        $incomingShell = 'rounded-xl border border-ink-200/70 bg-white shadow-card';
        $outgoingShell = 'rounded-xl border border-brand-200 bg-brand-50/50 shadow-card sm:ml-12';
        $incomingHead = 'flex flex-wrap items-start justify-between gap-3 border-b border-ink-200/70 px-5 py-4';
        $outgoingHead = 'flex flex-wrap items-start justify-between gap-3 border-b border-brand-200 px-5 py-4';
        $incomingTile = 'grid h-9 w-9 shrink-0 place-items-center rounded-full bg-ink-200 text-sm font-semibold text-ink-700';
        $outgoingTile = 'grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white';
        $incomingFrame = 'bg-ink-100 p-3';
        $outgoingFrame = 'bg-brand-100/40 p-3';

        // Heroicons outline path data. Path data is not a class name, so this
        // costs Tailwind nothing.
        $arrowIn = 'M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h7.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-6 3.75 3 3m0 0 3-3m-3 3V1.5m6 9h.75a2.25 2.25 0 0 1 2.25 2.25v7.5a2.25 2.25 0 0 1-2.25 2.25h-7.5';
        $arrowOut = 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5';
    @endphp

    {{-- ============================================================ header --}}
    <x-page-header :title="$subject"
                   :subtitle="implode(' · ', $subtitleParts)"
                   :back="route('campaign-replies.index')">
        <x-slot name="actions">
            @if ($tracked)
                <x-status-badge :status="$current" :map="$statusTones" />
            @else
                <span class="kn-badge-gray" title="No reply state has been recorded on this conversation yet.">Not in the queue</span>
            @endif

            @foreach ($offered as $status)
                <form method="POST" action="{{ route('campaign-replies.status', $thread) }}" class="inline">
                    @csrf
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="{{ $statusActions[$status]['class'] }}"
                            title="{{ $statusActions[$status]['help'] }}">
                        {{ $statusActions[$status]['label'] }}
                    </button>
                </form>
            @endforeach

            @if ($canSeeCampaign)
                <a href="{{ route('campaigns.show', $campaign->id) }}" class="kn-btn-secondary kn-btn-sm">Open campaign</a>
            @endif

            @if ($canSeeReport)
                <a href="{{ route('analytics.campaign', $campaign->id) }}" class="kn-btn-secondary kn-btn-sm">Campaign report</a>
            @endif
        </x-slot>
    </x-page-header>

    {{-- =========================================================== context --}}
    <div class="kn-card mb-5">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">What this conversation is answering</h3>
            <span class="text-xs text-ink-500">
                {{ $statusMeaning[$current] ?? 'No reply state has been recorded on this conversation.' }}
            </span>
        </div>

        <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="min-w-0 sm:col-span-2">
                <dt class="kn-stat-label">Campaign</dt>
                <dd class="mt-0.5 break-words text-sm text-ink-800">
                    @if ($campaign)
                        @if ($canSeeCampaign)
                            <a href="{{ route('campaigns.show', $campaign->id) }}"
                               class="font-medium text-brand-700 hover:text-brand-900">{{ $campaign->name }}</a>
                        @else
                            <span class="font-medium text-ink-900">{{ $campaign->name }}</span>
                        @endif

                        @if ($campaign->status)
                            <x-status-badge :status="$campaign->status" class="ml-1.5 align-middle" />
                        @endif

                        <span class="mt-1 block break-words text-xs text-ink-500">
                            Subject line sent: {{ $campaign->subject ?: '(no subject line recorded)' }}
                        </span>
                    @else
                        <span class="font-medium text-ink-900">No longer available</span>
                        <span class="mt-1 block text-xs text-ink-500">
                            The campaign this conversation was filed under has been deleted. The messages below are
                            untouched and still in the inbox — only the campaign it pointed at is gone.
                        </span>
                    @endif
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="kn-stat-label">Contact</dt>
                <dd class="mt-0.5 break-words text-sm text-ink-800">
                    @if ($contactName !== '' || $contactEmail !== '')
                        @if ($canSeeContact)
                            <a href="{{ route('subscribers.show', $thread->subscriber_id) }}"
                               class="font-medium text-brand-700 hover:text-brand-900">
                                {{ $contactName !== '' ? $contactName : $contactEmail }}
                            </a>
                        @else
                            <span class="font-medium text-ink-900">{{ $contactName !== '' ? $contactName : $contactEmail }}</span>
                        @endif

                        @if ($contactName !== '' && $contactEmail !== '')
                            <span class="mt-1 block break-words text-xs text-ink-500">{{ $contactEmail }}</span>
                        @endif

                        @unless ($thread->subscriber_id)
                            <span class="mt-1 block text-xs text-ink-500">
                                Not tied to a contact record — the reply was matched to the campaign, not to a row in
                                the audience.
                            </span>
                        @endunless
                    @else
                        <span class="text-ink-500">Nothing has come in on this conversation yet.</span>
                    @endif
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="kn-stat-label">Messages</dt>
                <dd class="mt-0.5 text-sm text-ink-800">
                    {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('message', $total) }}
                    <span class="mt-1 block text-xs text-ink-500">
                        {{ number_format($messages->where('direction', 'incoming')->count()) }} in,
                        {{ number_format($messages->where('direction', 'outgoing')->count()) }} out
                    </span>
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="kn-stat-label">Started</dt>
                <dd class="mt-0.5 text-sm text-ink-800">
                    {{ $startedAt?->format('D, d M Y H:i') ?? '—' }}
                    @if ($startedAt)
                        <span class="mt-1 block text-xs text-ink-500">{{ $startedAt->diffForHumans() }}</span>
                    @endif
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="kn-stat-label">Last activity</dt>
                <dd class="mt-0.5 text-sm text-ink-800">
                    {{ $lastAt?->format('D, d M Y H:i') ?? '—' }}
                    @if ($lastAt)
                        <span class="mt-1 block text-xs text-ink-500">{{ $lastAt->diffForHumans() }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        {{-- ------------------------------------------ how this was matched --}}
        <div class="border-t border-ink-100 px-5 py-4">
            <p class="text-xs font-semibold text-ink-700">How these replies were tied to the campaign</p>
            <p class="mt-1 text-xs text-ink-500">
                First from the reply headers: In-Reply-To or References naming a Message-ID this application minted
                for one specific recipient, or a per-recipient token that came back in the address the reply was sent
                to. Either of those identifies the recipient exactly. When neither survives — plenty of mail clients
                strip them — there is a fallback: this address was mailed in the last
                {{ \App\Services\Inbox\ReplyMatcher::ADDRESS_WINDOW_DAYS }} days and the subject matches the campaign's
                subject. That last one is a judgement, and it can occasionally file a message under the wrong campaign.
            </p>
            <p class="mt-1.5 text-xs text-ink-500">
                Which of the three attributed any one message below is not stored on the row, so this screen cannot
                tell you per message — it would be inventing the answer. If a message here is not actually a reply to
                this campaign, unlink it and the campaign's reply count is corrected.
            </p>
        </div>
    </div>

    {{-- ============================================================ thread --}}
    @if ($total === 0)
        <div class="kn-card overflow-hidden">
            <x-empty-state title="This conversation has no messages."
                           message="The thread row exists but every message on it has been deleted. Nothing can be shown, and nothing is missing from the inbox." />
        </div>
    @else
        <div class="space-y-4" @if ($collapse) x-data="{ earlier: false }" @endif>

            @foreach ($messages as $index => $message)
                @php
                    $isOutgoing = $message->direction === 'outgoing';
                    $isHidden = $collapse && $index >= $hiddenFrom && $index <= $hiddenTo;

                    $when = $whenOf($message);

                    $sender = $message->fromDisplay() ?: '—';
                    $senderEmail = trim((string) $message->from_email);
                    $initial = mb_strtoupper(mb_substr($sender, 0, 1)) ?: '?';

                    $to = $message->recipientLine();

                    $attachments = $message->attachments;
                    $files = $attachments->where('is_inline', false)->values();
                    $embedded = $attachments->where('is_inline', true)->values();

                    // $bodies is read for length and emptiness only. It is never
                    // echoed: the body is served into a sandboxed frame instead.
                    $body = (string) ($bodies[$message->id] ?? '');
                    $hasBody = trim($body) !== '';

                    // A short message does not need a screen-and-a-half of frame.
                    // Both class lists are literal so Tailwind compiles them.
                    $frameHeight = mb_strlen($body) < 1500
                        ? 'h-64 w-full rounded-lg border border-ink-200 bg-white'
                        : 'h-[34rem] w-full rounded-lg border border-ink-200 bg-white';
                @endphp

                {{-- The toggle sits where the folded messages were removed from. --}}
                @if ($collapse && $index === $hiddenFrom)
                    <div class="flex items-center gap-3">
                        <span class="h-px flex-1 bg-ink-200"></span>
                        <button type="button" @click="earlier = ! earlier"
                                :aria-expanded="earlier ? 'true' : 'false'"
                                class="kn-btn-secondary kn-btn-sm"
                                x-text="earlier
                                    ? @js('Hide the '.number_format($hiddenCount).' earlier '.\Illuminate\Support\Str::plural('message', $hiddenCount))
                                    : @js(number_format($hiddenCount).' earlier '.\Illuminate\Support\Str::plural('message', $hiddenCount))">
                            {{ number_format($hiddenCount) }} earlier {{ \Illuminate\Support\Str::plural('message', $hiddenCount) }}
                        </button>
                        <span class="h-px flex-1 bg-ink-200"></span>
                    </div>
                @endif

                <article class="{{ $isOutgoing ? $outgoingShell : $incomingShell }}"
                         @if ($isHidden) x-show="earlier" x-cloak @endif>

                    {{-- --------------------------------------------- who ---- --}}
                    <div class="{{ $isOutgoing ? $outgoingHead : $incomingHead }}">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="{{ $isOutgoing ? $outgoingTile : $incomingTile }}" aria-hidden="true">{{ $initial }}</span>

                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="break-words text-sm font-semibold text-ink-900">{{ $sender }}</span>

                                    <span class="{{ $isOutgoing ? 'kn-badge-blue' : 'kn-badge-gray' }}">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="1.8"
                                             viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="{{ $isOutgoing ? $arrowOut : $arrowIn }}"/>
                                        </svg>
                                        {{ $isOutgoing ? 'Sent by us' : 'They wrote this' }}
                                    </span>

                                    @if ($message->is_campaign_reply)
                                        <span class="kn-badge-amber">Counted as a campaign reply</span>
                                    @endif
                                </p>

                                @if ($senderEmail !== '' && $senderEmail !== $sender)
                                    <p class="mt-0.5 break-words text-xs text-ink-500">{{ $senderEmail }}</p>
                                @endif

                                @if ($isOutgoing && $to !== '')
                                    <p class="mt-0.5 break-words text-xs text-ink-500">To {{ $to }}</p>
                                @endif

                                @if (filled($message->subject) && $message->subject !== $thread->subject)
                                    <p class="mt-0.5 break-words text-xs text-ink-500">Subject: {{ $message->subject }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="whitespace-nowrap text-xs text-ink-600">{{ $when?->format('D, d M Y H:i') ?? '—' }}</p>
                            @if ($when)
                                <p class="whitespace-nowrap text-xs text-ink-400">{{ $when->diffForHumans() }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- -------------------------------------------- body ---- --}}
                    @if ($hasBody)
                        <div class="{{ $isOutgoing ? $outgoingFrame : $incomingFrame }}">
                            {{--
                                A stranger's HTML. It is served from its own URL
                                into a frame with an empty sandbox — opaque
                                origin, no scripts, no forms, no reach into this
                                page — and that document carries its own CSP.
                                Nothing from $bodies is written into this DOM.
                            --}}
                            <iframe sandbox="" loading="lazy"
                                    title="Message from {{ $sender }} on {{ $when?->format('d M Y H:i') ?? 'an unknown date' }}"
                                    src="{{ route('inbox.body', ['email' => $message->id]) }}"
                                    class="{{ $frameHeight }}"></iframe>
                        </div>
                    @else
                        <div class="px-5 py-4">
                            <p class="text-sm text-ink-500">
                                This message has no body. Nothing arrived in either the HTML or the plain-text part.
                            </p>
                        </div>
                    @endif

                    {{-- ------------------------------------- attachments ---- --}}
                    @if ($files->isNotEmpty() || $embedded->isNotEmpty())
                        <div class="border-t border-ink-100 px-5 py-4">
                            @php
                                // A message can carry inline images and no
                                // attached files at all. "0 attachments" on a
                                // panel that is plainly listing something is a
                                // small lie, so the label only counts what is
                                // actually there.
                                $fileLabel = match (true) {
                                    $files->count() === 1 => '1 attachment',
                                    $files->count() > 1 => number_format($files->count()).' attachments',
                                    default => null,
                                };

                                $embeddedLabel = $embedded->isEmpty() ? null
                                    : number_format($embedded->count()).' embedded';
                            @endphp

                            <p class="kn-stat-label">
                                {{ implode(' · ', array_filter([$fileLabel, $embeddedLabel])) }}
                            </p>

                            @if ($files->isNotEmpty())
                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($files as $file)
                                        <li class="flex flex-wrap items-center gap-2 text-sm text-ink-700">
                                            <span class="break-all font-medium text-ink-900">{{ $file->name }}</span>
                                            <span class="text-xs text-ink-500">{{ $file->humanSize() }}</span>
                                            @if ($file->exists())
                                                <a href="{{ route('inbox.attachment', ['email' => $message->id, 'attachment' => $file->id]) }}"
                                                   class="kn-btn-secondary kn-btn-sm">Download</a>
                                            @else
                                                <span class="kn-badge-gray">File missing from storage</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($embedded->isNotEmpty())
                                <p class="mt-2 text-xs text-ink-500">
                                    {{ $embedded->count() === 1 ? '1 image is' : number_format($embedded->count()).' images are' }}
                                    embedded in the message above rather than attached to it, so
                                    {{ $embedded->count() === 1 ? 'it is' : 'they are' }} shown in the frame instead of
                                    listed here as files.
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- ----------------------------------------- actions ---- --}}
                    <div class="flex flex-wrap items-center gap-2 border-t border-ink-100 px-5 py-3">
                        @permission('inbox.send')
                            <form method="POST"
                                  action="{{ route('inbox.compose.respond', ['email' => $message->id, 'mode' => 'reply']) }}"
                                  class="inline">
                                @csrf
                                <button type="submit" class="kn-btn-primary kn-btn-sm">Reply</button>
                            </form>
                        @endpermission

                        <a href="{{ route('inbox.show', $message->id) }}" class="kn-btn-secondary kn-btn-sm">Open in inbox</a>

                        @if ($message->is_campaign_reply)
                            <x-confirm-form :action="route('campaign-replies.detach', ['email' => $message->id])"
                                            method="POST"
                                            label="Unlink from this campaign"
                                            button-class="kn-btn-secondary kn-btn-sm"
                                            message="Unlink this message from the campaign? The message itself is not touched — it stays in the inbox exactly where it is, and only the link to the campaign goes. The campaign's reply count drops by one if this was the only reply from this person." />
                        @endif

                        <span class="ml-auto text-xs text-ink-400">
                            Message {{ number_format($index + 1) }} of {{ number_format($total) }}
                        </span>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- ------------------------------------------------ closing notes -- --}}
        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">What the states mean</h3>
                    <span class="text-xs text-ink-500">{{ $tracked ? 'Currently '.$current : 'No state recorded' }}</span>
                </div>
                <div class="kn-card-body space-y-2 text-xs text-ink-500">
                    <p>
                        Opening this page already moved the conversation out of <span class="font-semibold text-ink-700">new</span>,
                        so there is no "mark as read" button for something you are plainly looking at.
                    </p>
                    <p>
                        For the same reason there is no way to put it back to <span class="font-semibold text-ink-700">new</span>
                        from here — reading it is what takes it out, and this page would undo the change on the way
                        back. Reopen it from the
                        <a href="{{ route('campaign-replies.index') }}" class="font-medium text-brand-700 hover:text-brand-900">replies
                        queue</a> instead. It also returns to the queue on its own if this person writes again.
                    </p>
                    @foreach ($offered as $status)
                        <p>
                            <span class="font-semibold text-ink-700">{{ $statusActions[$status]['label'] }}</span> —
                            {{ $statusActions[$status]['help'] }}
                        </p>
                    @endforeach
                </div>
            </div>

            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Closing is not deleting</h3>
                </div>
                <div class="kn-card-body space-y-2 text-xs text-ink-500">
                    <p>
                        Marking a conversation closed takes it out of the working queue and nothing else. Every
                        message stays in the inbox, the conversation stays searchable from the replies screen, and the
                        campaign's reply count is unchanged.
                    </p>
                    <p>
                        If this person writes again on the same conversation, it comes straight back as
                        <span class="font-semibold text-ink-700">new</span> — a closed conversation cannot swallow a
                        later reply.
                    </p>
                    <p>
                        Sending a reply from this page marks the conversation answered by itself, so
                        "Mark as answered" is only needed for a reply that went out from somewhere else. A reply opens
                        as a saved draft first, so an attachment has somewhere to belong and a half-written message
                        survives a closed tab.
                    </p>
                </div>
            </div>
        </div>
    @endif
</x-app-layout>
