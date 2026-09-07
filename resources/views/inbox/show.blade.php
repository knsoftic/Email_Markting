<x-app-layout>
    <x-slot name="header">{{ $email->subject ?: '(No subject)' }}</x-slot>

    @php
        /**
         * Reading one message.
         *
         * ── $body is never in this document ─────────────────────────────────
         * The controller hands over an already-sanitised body, and this screen
         * still does not print it. It is loaded from inbox.body into a
         * sandboxed frame, which is the second of the three layers protecting
         * the reader (sanitiser, sandbox, CSP on the frame document). Putting
         * it in this DOM — even escaped, even "just to measure it" — would
         * throw the second layer away.
         *
         * Relations: the controller loaded attachments, mailbox and thread.
         * Nothing else is touched, because lazy loading is prevented outside
         * production and a reading screen that only breaks on a developer's
         * machine is worse than one that never had the field.
         */

        $subject = $email->subject ?: '(No subject)';

        $folder = (string) $email->folder_type;

        $folderRoutes = [
            'inbox' => 'inbox.index',
            'sent' => 'inbox.sent',
            'drafts' => 'inbox.drafts',
            'spam' => 'inbox.spam',
            'trash' => 'inbox.trash',
            'archive' => 'inbox.archive',
        ];

        $folderLabels = [
            'inbox' => 'Inbox',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'spam' => 'Spam',
            'trash' => 'Trash',
            'archive' => 'Archive',
        ];

        $folderLabel = $folderLabels[$folder] ?? 'Inbox';
        $folderUrl = route($folderRoutes[$folder] ?? 'inbox.index');
        $folderCounts = $counts[$folder] ?? ['total' => 0, 'unread' => 0];

        $isOutgoing = $email->direction === 'outgoing';

        // Received mail is dated by when it arrived, sent mail by when it went.
        // created_at is the last resort so a locally composed draft still has a
        // date rather than an em dash.
        $when = $email->received_at ?? $email->sent_at ?? $email->created_at;
        $whenLabel = $email->received_at ? 'Received' : ($email->sent_at ? 'Sent' : 'Created');

        /**
         * to/cc/bcc are json. They are normally [{name, email}] but a row
         * written by an earlier sync can hold bare strings, so both shapes are
         * accepted rather than assumed.
         */
        $addresses = function ($list) {
            return collect($list ?? [])
                ->map(function ($item) {
                    $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
                    $mail = is_array($item) ? trim((string) ($item['email'] ?? '')) : trim((string) $item);

                    return $name !== '' && $mail !== '' ? $name.' <'.$mail.'>' : ($mail ?: $name);
                })
                ->filter()
                ->values();
        };

        $to = $addresses($email->to);
        $cc = $addresses($email->cc);
        $bcc = $addresses($email->bcc);

        // Bcc is only ever populated on something this account sent; showing it
        // on a received message would be inventing a field.
        $recipientGroups = array_filter([
            'To' => $to,
            'Cc' => $cc,
            'Bcc' => $bcc,
        ], fn ($group) => $group->isNotEmpty());

        // "Reply all" that goes to exactly the same person as "Reply" is two
        // buttons doing one thing, so it is only offered when it differs.
        $replyAllIsDifferent = ($to->count() + $cc->count()) > 1;

        $attachments = $email->attachments;
        $files = $attachments->where('is_inline', false)->values();
        $embedded = $attachments->where('is_inline', true)->values();

        $human = function ($bytes) {
            $bytes = (int) $bytes;
            $units = ['B', 'KB', 'MB', 'GB'];
            $i = 0;

            while ($bytes >= 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }

            return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
        };

        $bodyUrl = route('inbox.body', ['email' => $email->id] + ($showingRemote ? ['images' => 'show'] : []));
        $showImagesUrl = route('inbox.show', ['email' => $email->id, 'images' => 'show']);
        $hideImagesUrl = route('inbox.show', ['email' => $email->id]);

        $hasBody = trim($body) !== '';

        // Where this message can go from where it is. Every key is an action
        // InboxService::bulk() actually understands — a move that is not in its
        // match() would be a button that reports "That action is not available".
        $moves = match ($folder) {
            'inbox' => ['archive' => 'Archive', 'spam' => 'Mark as spam', 'trash' => 'Move to Trash'],
            'archive' => ['inbox' => 'Move back to Inbox', 'spam' => 'Mark as spam', 'trash' => 'Move to Trash'],
            'spam' => ['inbox' => 'Not spam — move to Inbox', 'trash' => 'Move to Trash'],
            'trash' => ['inbox' => 'Move back to Inbox', 'archive' => 'Move to Archive'],
            'sent' => ['archive' => 'Archive', 'trash' => 'Move to Trash'],
            'drafts' => ['trash' => 'Move to Trash'],
            default => [],
        };

        // Later-phase screens are linked only if they are actually mounted.
        $campaignLink = $email->campaign_id
            && \Illuminate\Support\Facades\Route::has('campaigns.show')
            && auth()->user()?->hasPermission('campaigns.view')
                ? route('campaigns.show', $email->campaign_id)
                : null;

        $repliesLink = \Illuminate\Support\Facades\Route::has('campaign-replies.index')
            ? route('campaign-replies.index')
            : null;

        // Linked only when the reader may actually open it — a link straight
        // into a 403 is worse than plain text.
        $mailboxLink = $email->mailbox
            && \Illuminate\Support\Facades\Route::has('mailboxes.show')
            && auth()->user()?->hasPermission('mailboxes.view')
                ? route('mailboxes.show', $email->mailbox->id)
                : null;

        $subtitleParts = array_filter([
            $email->fromDisplay() ?: null,
            $when?->format('D, d M Y H:i'),
            'In '.$folderLabel,
        ]);
    @endphp

    {{-- ============================================================ header --}}
    <x-page-header :title="$subject"
                   :subtitle="implode(' · ', $subtitleParts)"
                   :back="$folderUrl">
        <x-slot name="actions">
            <span class="kn-badge-gray">In {{ $folderLabel }}</span>

            @if ($email->is_campaign_reply)
                <span class="kn-badge-blue">Campaign reply</span>
            @endif

            @if ($email->is_draft)
                <x-status-badge status="draft" />
            @endif

            @if ($email->send_status === 'scheduled' && $email->scheduled_at)
                <x-status-badge status="scheduled" />
            @endif

            @if ($email->send_status === 'failed')
                <x-status-badge status="failed" />
            @endif

            @if ($email->is_important)
                <span class="kn-badge-amber">Important</span>
            @endif

            {{-- Opening a message marks it read before this renders, so in
                 practice this chip is only seen if something else set the flag
                 back. It is here so the state is never shown incorrectly. --}}
            @unless ($email->is_read)
                <span class="kn-badge-amber">Unread</span>
            @endunless

            <form method="POST" action="{{ route('inbox.star', $email) }}" class="inline">
                @csrf
                <button type="submit" class="kn-btn-secondary"
                        aria-pressed="{{ $email->is_starred ? 'true' : 'false' }}"
                        title="{{ $email->is_starred ? 'Remove the star from this message' : 'Star this message' }}">
                    <svg class="h-4 w-4 {{ $email->is_starred ? 'text-amber-500' : 'text-ink-400' }}"
                         fill="{{ $email->is_starred ? 'currentColor' : 'none' }}"
                         stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.12 5.11 5.52.44c.5.04.7.66.32.99l-4.2 3.6 1.28 5.39a.56.56 0 0 1-.84.6L12 16.72l-4.72 2.91a.56.56 0 0 1-.84-.6l1.28-5.39-4.2-3.6a.56.56 0 0 1 .32-.99l5.52-.44 2.12-5.11Z"/>
                    </svg>
                    {{ $email->is_starred ? 'Starred' : 'Star' }}
                </button>
            </form>

            @if ($email->is_draft)
                @permission('inbox.send')
                    <a href="{{ route('inbox.compose.edit', $email) }}" class="kn-btn-primary">Continue editing</a>
                @endpermission
            @else
                @permission('inbox.send')
                    <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'reply']) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-primary">Reply</button>
                    </form>

                    @if ($replyAllIsDifferent)
                        <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'reply_all']) }}" class="inline">
                            @csrf
                            <button type="submit" class="kn-btn-secondary">Reply all</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'forward']) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary">Forward</button>
                    </form>
                @endpermission
            @endif
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------- send failed ------ --}}
    @if ($email->send_status === 'failed')
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-800">This message was not sent.</p>
            @if (filled($email->error))
                <p class="mt-1 break-words text-xs text-red-700">{{ $email->error }}</p>
            @else
                <p class="mt-1 text-xs text-red-700">
                    No reason was recorded against the message. The sending account's own screen keeps the
                    connection errors.
                </p>
            @endif
        </div>
    @elseif ($email->send_status === 'scheduled' && $email->scheduled_at)
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
            <p class="text-sm text-brand-800">
                <span class="font-semibold">Scheduled to send</span>
                on {{ $email->scheduled_at->format('D, d M Y H:i') }}
                ({{ $email->scheduled_at->diffForHumans() }}). Nothing is built until it goes.
            </p>
            {{-- The route 404s on anything that is not still a draft, so the
                 button is only offered while that is true. --}}
            @if ($email->is_draft)
                @permission('inbox.send')
                    <form method="POST" action="{{ route('inbox.compose.unschedule', $email) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary kn-btn-sm">Cancel the schedule</button>
                    </form>
                @endpermission
            @endif
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ======================================================== left --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- --------------------------------------- who and when ------ --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">
                        {{ $isOutgoing ? 'Sent from this account' : 'Sender and recipients' }}
                    </h3>
                    <span class="text-xs text-ink-500">{{ $isOutgoing ? 'Outgoing' : 'Incoming' }}</span>
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <div class="min-w-0 sm:col-span-2">
                        <dt class="kn-stat-label">From</dt>
                        <dd class="mt-0.5 break-words text-sm text-ink-800">
                            <span class="font-medium text-ink-900">{{ $email->fromDisplay() ?: '—' }}</span>
                            @if ($email->from_email && $email->from_name)
                                <span class="text-ink-500">&lt;{{ $email->from_email }}&gt;</span>
                            @endif
                        </dd>
                    </div>

                    @foreach ($recipientGroups as $label => $group)
                        @php
                            $shown = $group->take(3);
                            $rest = $group->slice(3);
                        @endphp

                        <div class="min-w-0 sm:col-span-2" @if ($rest->isNotEmpty()) x-data="{ open: false }" @endif>
                            <dt class="kn-stat-label">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">
                                {{ $shown->implode(', ') }}@if ($rest->isNotEmpty())<span x-show="open" x-cloak>, {{ $rest->implode(', ') }}</span>@endif

                                @if ($rest->isNotEmpty())
                                    <button type="button" @click="open = ! open"
                                            :aria-expanded="open ? 'true' : 'false'"
                                            class="ml-1 text-xs font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-900"
                                            x-text="open ? 'Show fewer' : @js('and '.$rest->count().' more')">and {{ $rest->count() }} more</button>
                                @endif

                                @if ($label === 'Bcc')
                                    <span class="mt-1 block text-xs text-ink-500">
                                        Blind copies. Nobody on the To or Cc line was shown these addresses.
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endforeach

                    @if (filled($email->reply_to) && $email->reply_to !== $email->from_email)
                        <div class="min-w-0 sm:col-span-2">
                            <dt class="kn-stat-label">Reply-To</dt>
                            <dd class="mt-0.5 break-words text-sm text-ink-800">
                                {{ $email->reply_to }}
                                <span class="mt-1 block text-xs text-ink-500">
                                    A reply goes here, not to the From address.
                                </span>
                            </dd>
                        </div>
                    @endif

                    <div class="min-w-0">
                        <dt class="kn-stat-label">{{ $whenLabel }}</dt>
                        <dd class="mt-0.5 text-sm text-ink-800">
                            {{ $when?->format('D, d M Y H:i') ?? '—' }}
                            @if ($when)
                                <span class="text-ink-500">({{ $when->diffForHumans() }})</span>
                            @endif
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt class="kn-stat-label">Mailbox</dt>
                        <dd class="mt-0.5 break-words text-sm text-ink-800">
                            @if ($email->mailbox)
                                @if ($mailboxLink)
                                    <a href="{{ $mailboxLink }}" class="font-medium text-brand-700 hover:text-brand-900">{{ $email->mailbox->name }}</a>
                                @else
                                    <span class="font-medium">{{ $email->mailbox->name }}</span>
                                @endif
                                <span class="block text-xs text-ink-500">{{ $email->mailbox->email }}</span>
                            @else
                                Not linked to a mailbox
                                <span class="block text-xs text-ink-500">
                                    Written here rather than fetched from a mail server.
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div class="min-w-0">
                        <dt class="kn-stat-label">Folder</dt>
                        <dd class="mt-0.5 text-sm text-ink-800">
                            <a href="{{ $folderUrl }}" class="font-medium text-brand-700 hover:text-brand-900">{{ $folderLabel }}</a>
                            <span class="block text-xs text-ink-500">
                                {{ number_format((int) $folderCounts['total']) }}
                                {{ \Illuminate\Support\Str::plural('message', (int) $folderCounts['total']) }} in it,
                                {{ number_format((int) $folderCounts['unread']) }} unread
                            </span>
                        </dd>
                    </div>

                    @if ((int) $email->size > 0)
                        <div class="min-w-0">
                            <dt class="kn-stat-label">Size</dt>
                            <dd class="mt-0.5 text-sm text-ink-800">{{ $human($email->size) }}</dd>
                        </div>
                    @endif

                    @if ($email->is_campaign_reply)
                        <div class="min-w-0 sm:col-span-2">
                            <dt class="kn-stat-label">Campaign</dt>
                            <dd class="mt-0.5 text-sm text-ink-800">
                                This arrived as a reply to a campaign that went out from this account.
                                <span class="mt-1 flex flex-wrap gap-3 text-xs">
                                    @if ($campaignLink)
                                        <a href="{{ $campaignLink }}" class="font-semibold text-brand-700 hover:text-brand-900">Open the campaign</a>
                                    @endif
                                    @if ($repliesLink)
                                        <a href="{{ $repliesLink }}" class="font-semibold text-brand-700 hover:text-brand-900">All campaign replies</a>
                                    @endif
                                </span>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- --------------------------------------- remote images ----- --}}
            @if ($blockedImages > 0 && ! $showingRemote)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-800">
                        {{ $blockedImages === 1
                            ? '1 remote image in this message is not being loaded.'
                            : number_format($blockedImages).' remote images in this message are not being loaded.' }}
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        A remote image is fetched from the sender's own server the moment it is displayed. That
                        request tells them the message was opened, when it was opened, and the IP address it was
                        opened from. This application sends tracking pixels of its own, which is exactly how it
                        knows. So they stay blocked until you ask for them.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <a href="{{ $showImagesUrl }}" class="kn-btn-secondary kn-btn-sm">Show images for this message</a>
                        <span class="text-xs text-amber-700">
                            The choice is not remembered — it applies to this message, this time.
                        </span>
                    </div>
                </div>
            @elseif ($showingRemote && $hasRemoteImages)
                <div class="rounded-lg border border-ink-200 bg-ink-50 px-4 py-3">
                    <p class="text-sm font-semibold text-ink-800">Remote images are being loaded for this message.</p>
                    <p class="mt-1 text-xs text-ink-600">
                        The sender's server has been contacted for them, so it can now tell that the message was
                        opened and see the IP address it was opened from. Nothing was remembered: the next time this
                        message is opened they will be blocked again.
                    </p>
                    <div class="mt-3">
                        <a href="{{ $hideImagesUrl }}" class="kn-btn-secondary kn-btn-sm">Block them again</a>
                    </div>
                </div>
            @endif

            {{-- ------------------------------------------------ the body -- --}}
            <div class="kn-card overflow-hidden">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Message</h3>
                    <span class="text-xs text-ink-500">Shown in a sandboxed frame</span>
                </div>

                @if ($hasBody)
                    <div class="bg-ink-100 p-3">
                        {{--
                            The body is a stranger's HTML. It is loaded from its
                            own URL into a frame with an empty sandbox — opaque
                            origin, no scripts, no forms, no access to this page
                            — and that document carries its own CSP.
                        --}}
                        <iframe sandbox="" title="Message body" loading="lazy"
                                src="{{ $bodyUrl }}"
                                class="h-[42rem] w-full rounded-lg border border-ink-200 bg-white"></iframe>
                    </div>

                    <div class="border-t border-ink-100 px-5 py-4">
                        <p class="text-xs text-ink-500">
                            The frame has a fixed height and its own scrollbar. A sandboxed frame cannot report its
                            height to this page, so it is not sized to fit the message — a long message scrolls
                            inside it. Links in the message open in the frame itself; the browser's Back button
                            brings the message back.
                        </p>
                    </div>
                @else
                    <x-empty-state title="This message has no body."
                                   message="Nothing arrived in either the HTML or the plain-text part. Anything the message carried is in the attachments below." />
                @endif
            </div>

            {{-- ---------------------------------------- attachments ------ --}}
            @if ($files->isNotEmpty() || $embedded->isNotEmpty())
                <div class="kn-card overflow-hidden">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Attachments</h3>
                        <span class="text-xs text-ink-500">
                            {{ number_format($files->count()) }}
                            {{ \Illuminate\Support\Str::plural('file', $files->count()) }}@if ($embedded->isNotEmpty()) · {{ number_format($embedded->count()) }} embedded @endif
                            · {{ $human($attachments->sum('size')) }} in total
                        </span>
                    </div>

                    @if ($files->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="kn-table">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($files as $file)
                                        @php $onDisk = $file->exists(); @endphp
                                        <tr>
                                            <td class="max-w-xs break-words font-medium text-ink-900">{{ $file->name }}</td>
                                            <td class="text-xs text-ink-500">{{ $file->mime_type ?: 'Unknown type' }}</td>
                                            <td class="whitespace-nowrap">{{ $file->humanSize() }}</td>
                                            <td class="text-right">
                                                @if ($onDisk)
                                                    <a href="{{ route('inbox.attachment', ['email' => $email->id, 'attachment' => $file->id]) }}"
                                                       class="kn-btn-secondary kn-btn-sm">Download</a>
                                                @else
                                                    <span class="kn-badge-gray">File missing from storage</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($embedded->isNotEmpty())
                        <div class="border-t border-ink-100 px-5 py-4">
                            <p class="text-xs font-semibold text-ink-700">
                                {{ $embedded->count() === 1
                                    ? '1 embedded image'
                                    : number_format($embedded->count()).' embedded images' }}
                            </p>
                            <p class="mt-1 text-xs text-ink-500">
                                Part of the message above — the body refers to them, so they are shown there rather
                                than listed as files the sender attached. They came with the message, so displaying
                                them tells the sender nothing.
                            </p>
                            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-600">
                                @foreach ($embedded as $image)
                                    <li class="break-words">
                                        {{ $image->name }} ({{ $image->humanSize() }})
                                        @if ($image->exists())
                                            ·
                                            <a href="{{ route('inbox.attachment', ['email' => $email->id, 'attachment' => $image->id]) }}"
                                               class="font-semibold text-brand-700 hover:text-brand-900">Download</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="border-t border-ink-100 px-5 py-4">
                        <p class="text-xs text-ink-500">
                            A download is always served as a file, never as the type the sender claimed — an
                            "invoice.html" that ran as a page would be running inside this session.
                        </p>
                    </div>
                </div>
            @endif

            {{-- ------------------------------------- the rest of the thread --}}
            @if ($thread->isNotEmpty())
                <div class="kn-card overflow-hidden">
                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">Rest of this conversation</h3>
                        <span class="text-xs text-ink-500">
                            {{ number_format($thread->count()) }} other
                            {{ \Illuminate\Support\Str::plural('message', $thread->count()) }} · oldest first
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Message</th>
                                    <th>From</th>
                                    <th>When</th>
                                    <th class="text-right">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($thread as $message)
                                    @php
                                        $messageWhen = $message->received_at ?? $message->sent_at;
                                        $messageOutgoing = $message->direction === 'outgoing';
                                    @endphp
                                    <tr>
                                        <td class="max-w-sm">
                                            <a href="{{ route('inbox.show', $message->id) }}"
                                               class="font-medium text-ink-900 hover:text-brand-700">
                                                {{ $message->subject ?: '(No subject)' }}
                                            </a>
                                            <span class="mt-1 flex flex-wrap gap-1.5">
                                                <span class="{{ $messageOutgoing ? 'kn-badge-blue' : 'kn-badge-gray' }}">
                                                    {{ $messageOutgoing ? 'Sent from here' : 'Received' }}
                                                </span>
                                                @unless ($message->is_read)
                                                    <span class="kn-badge-amber">Unread</span>
                                                @endunless
                                            </span>
                                        </td>
                                        <td class="break-words text-xs text-ink-600">
                                            {{ $message->from_name ?: ($message->from_email ?: '—') }}
                                        </td>
                                        <td class="whitespace-nowrap text-xs text-ink-600">
                                            {{ $messageWhen?->format('d M Y H:i') ?? '—' }}
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('inbox.show', $message->id) }}" class="kn-btn-ghost kn-btn-sm">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ======================================================= right --}}
        <div class="space-y-6">

            {{-- ------------------------------------------------- actions -- --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Actions</h3>
                    <span class="text-xs text-ink-500">This copy only</span>
                </div>

                <div class="kn-card-body space-y-4">
                    @if ($email->is_draft)
                        @permission('inbox.send')
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('inbox.compose.edit', $email) }}" class="kn-btn-primary kn-btn-sm">Continue editing</a>

                                <x-confirm-form :action="route('inbox.compose.destroy', $email)"
                                                method="DELETE"
                                                label="Discard draft"
                                                message="Discard this draft? It is deleted along with anything attached to it, and it cannot be brought back." />
                            </div>
                            <p class="text-xs text-ink-500">
                                This is a draft. It has not been sent, and nobody on the To line has seen it.
                            </p>
                        @endpermission
                    @else
                        @permission('inbox.send')
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'reply']) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="kn-btn-primary kn-btn-sm">Reply</button>
                                </form>

                                @if ($replyAllIsDifferent)
                                    <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'reply_all']) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="kn-btn-secondary kn-btn-sm">Reply all</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('inbox.compose.respond', ['email' => $email->id, 'mode' => 'forward']) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="kn-btn-secondary kn-btn-sm">Forward</button>
                                </form>
                            </div>
                            <p class="text-xs text-ink-500">
                                A reply, reply-all or forward opens as a saved draft first, so an attachment has
                                somewhere to belong and a half-written message survives a closed tab.
                            </p>
                        @endpermission
                    @endif

                    {{-- ------------------------------------------- read state --}}
                    <div class="border-t border-ink-100 pt-4">
                        <form method="POST" action="{{ route('inbox.read', $email) }}" class="inline">
                            @csrf
                            <button type="submit" class="kn-btn-secondary kn-btn-sm">
                                {{ $email->is_read ? 'Mark as unread' : 'Mark as read' }}
                            </button>
                        </form>
                        @if ($email->is_read)
                            <p class="mt-2 text-xs text-ink-500">
                                This takes you back to {{ $folderLabel }}. Opening a message marks it read, so
                                staying on this screen would undo it before you saw it happen.
                            </p>
                        @endif
                    </div>

                    {{-- ----------------------------------------------- moves --}}
                    @if ($moves !== [])
                        <div class="border-t border-ink-100 pt-4">
                            <p class="kn-stat-label mb-2">Move it</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($moves as $action => $label)
                                    <form method="POST" action="{{ route('inbox.bulk') }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $action }}">
                                        <input type="hidden" name="ids[]" value="{{ $email->id }}">
                                        <button type="submit" class="kn-btn-secondary kn-btn-sm">{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- --------------------------------- only inside the trash --}}
                    @if ($folder === 'trash')
                        <div class="border-t border-ink-100 pt-4">
                            <p class="kn-stat-label mb-2">Delete for good</p>
                            <p class="mb-2 text-xs text-ink-500">
                                Offered here because the message is already in the Trash. It removes the message and
                                its attachments from this account, and it cannot be undone.
                            </p>

                            <x-confirm-form :action="route('inbox.bulk')"
                                            method="POST"
                                            label="Delete for good"
                                            :message="'Delete '.($email->subject ?: 'this message').' for good? It is removed from this account along with anything attached to it, and it cannot be brought back. The copy on the mail server is not touched.'">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="ids[]" value="{{ $email->id }}">
                            </x-confirm-form>
                        </div>
                    @endif

                    <p class="border-t border-ink-100 pt-4 text-xs text-ink-500">
                        These actions change this account's copy of the message. This application never writes back
                        over IMAP, so starring it, moving it or deleting it here leaves the message exactly where it
                        is on the mail server.
                    </p>
                </div>
            </div>

            {{-- --------------------------------------------- how it renders --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">How this message is displayed</h3>
                </div>
                <div class="kn-card-body space-y-2 text-xs text-ink-500">
                    <p>
                        The body was written by whoever sent it, so it is not trusted. It is cleaned against an
                        allow-list of tags, then loaded into a frame of its own with scripting switched off and no
                        access to this page, and that frame is served with a policy that permits nothing it did not
                        already have.
                    </p>
                    <p>
                        Remote images are held back on every message, every time, unless you ask for them on the
                        message you are reading.
                    </p>
                    @if ($embedded->isNotEmpty())
                        <p>
                            The {{ $embedded->count() === 1 ? 'image embedded in' : 'images embedded in' }} this
                            message {{ $embedded->count() === 1 ? 'is' : 'are' }} served from here, not from the
                            sender — they arrived with the message.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
