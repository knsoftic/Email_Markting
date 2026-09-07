@php
    /**
     * The inbox shell and message list.
     *
     * Everything on this screen comes from what InboxController@render passes:
     * $view, $emails (a paginator with `mailbox` eager-loaded), $counts,
     * $mailboxes and $filters. No relation is touched that was not loaded —
     * lazy loading is prevented outside production, and a list screen that only
     * breaks on a developer's machine is worse than one that never had the
     * field.
     *
     * ── These folders are ours ──────────────────────────────────────────────
     * `folder_type` is the type the sync assigned to OUR copy of the message.
     * Nothing on this page is written back over IMAP: starring, archiving and
     * deleting change what this account sees here and nothing on the mail
     * server. The note under the filters says exactly that, because a mail
     * screen that stays quiet about it is letting the user assume otherwise.
     */

    $counts = is_array($counts ?? null) ? $counts : [];

    // route() by name, never by string-building — an unknown $view falls back
    // to the inbox rather than throwing on a missing route.
    $folderRoutes = [
        'inbox' => 'inbox.index',
        'starred' => 'inbox.starred',
        'drafts' => 'inbox.drafts',
        'sent' => 'inbox.sent',
        'archive' => 'inbox.archive',
        'spam' => 'inbox.spam',
        'trash' => 'inbox.trash',
    ];

    $currentRoute = $folderRoutes[$view] ?? 'inbox.index';

    // Heroicons outline path data. Path data is not a class name, so building
    // this array costs Tailwind nothing.
    $folderIcons = [
        'inbox' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
        'starred' => 'M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z',
        'drafts' => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125',
        'sent' => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
        'archive' => 'm20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
        'spam' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
        'trash' => 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    ];

    $paperclipPath = 'm18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13';

    $folderLabels = [
        'inbox' => 'Inbox',
        'starred' => 'Starred',
        'drafts' => 'Drafts',
        'sent' => 'Sent',
        'archive' => 'Archive',
        'spam' => 'Spam',
        'trash' => 'Trash',
    ];

    $folderLabel = $folderLabels[$view] ?? 'Inbox';

    // Full literal class lists — never assembled from fragments, so Tailwind
    // still sees every class it has to compile.
    $railBase = 'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition';
    $railOn = 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200';
    $railOff = 'text-ink-600 hover:bg-ink-100 hover:text-ink-900';
    $railUnread = 'ml-auto shrink-0 rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-bold text-white';
    $railTotal = 'ml-auto shrink-0 text-[11px] font-medium text-ink-400';

    $user = auth()->user();
    $canSend = (bool) $user?->hasPermission('inbox.send');
    $canDelete = (bool) $user?->hasPermission('inbox.delete');

    // Sent and Drafts are lists of things this account wrote, so "from: me" on
    // every row tells the reader nothing. Those two show the recipient.
    $isOutgoingView = in_array($view, ['sent', 'drafts'], true);

    $dateHeading = match ($view) {
        'drafts' => 'Saved',
        'sent' => 'Sent',
        default => 'Received',
    };

    $filterQ = (string) ($filters['q'] ?? '');
    $filterUnread = ($filters['unread'] ?? '') === '1';
    $filterAttachments = ($filters['attachments'] ?? '') === '1';
    $filterMailboxId = (int) ($filters['mailbox'] ?? 0);
    $hasFilters = $filterQ !== '' || $filterUnread || $filterAttachments || $filterMailboxId > 0;

    // ?page=999 on a folder that has 30 messages is not an empty folder, and
    // saying "the inbox is empty" under a subtitle reading "30 messages" — with
    // the pager still sitting below it — is the screen contradicting itself.
    $pastTheEnd = $emails->isEmpty() && $emails->currentPage() > 1;

    $folderTotal = (int) ($counts[$view]['total'] ?? 0);
    $folderUnread = (int) ($counts[$view]['unread'] ?? 0);
    $trashTotal = (int) ($counts['trash']['total'] ?? 0);

    $subtitle = $hasFilters
        ? number_format($emails->total()).' '.\Illuminate\Support\Str::plural('message', $emails->total()).' match these filters'
        : ($folderTotal === 0
            ? 'Nothing in this folder'
            : number_format($folderTotal).' '.\Illuminate\Support\Str::plural('message', $folderTotal)
                .($folderUnread > 0 ? ' · '.number_format($folderUnread).' unread' : ''));

    /**
     * ComposeController::create() 422s when it cannot resolve a mailbox, so the
     * button posts a concrete id taken from the list the controller passed —
     * the current filter when it is real, otherwise the first mailbox. That is
     * also why the button is not rendered at all with no mailboxes connected.
     */
    $composeMailboxId = $mailboxes->firstWhere('id', $filterMailboxId)?->id ?? $mailboxes->first()?->id;

    /**
     * Which bulk actions make sense HERE. InboxService::bulk() understands
     * read, unread, star, unstar, important, unimportant, trash, spam,
     * archive, inbox and delete — but "Move to Trash" inside the Trash and
     * "Delete for good" outside it are both nonsense, and 'delete' is refused
     * anywhere but the Trash, so neither is offered where it would not work.
     */
    $bulkOptions = match ($view) {
        'inbox' => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
            ['star', 'Add star'], ['unstar', 'Remove star'],
            ['important', 'Flag as important'], ['unimportant', 'Remove the important flag'],
            ['archive', 'Archive'], ['spam', 'Mark as spam'],
        ],
        'starred' => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
            ['unstar', 'Remove star'],
            ['important', 'Flag as important'], ['unimportant', 'Remove the important flag'],
            ['archive', 'Archive'], ['spam', 'Mark as spam'],
        ],
        'drafts' => [
            ['star', 'Add star'], ['unstar', 'Remove star'],
        ],
        'sent' => [
            ['star', 'Add star'], ['unstar', 'Remove star'],
            ['important', 'Flag as important'], ['unimportant', 'Remove the important flag'],
            ['archive', 'Archive'],
        ],
        'archive' => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
            ['star', 'Add star'], ['unstar', 'Remove star'],
            ['inbox', 'Move back to the Inbox'], ['spam', 'Mark as spam'],
        ],
        'spam' => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
            ['star', 'Add star'], ['unstar', 'Remove star'],
            ['inbox', 'Not spam — move to the Inbox'],
        ],
        'trash' => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
            ['star', 'Add star'], ['unstar', 'Remove star'],
            ['inbox', 'Restore to the Inbox'],
        ],
        default => [
            ['read', 'Mark as read'], ['unread', 'Mark as unread'],
        ],
    };

    if ($canDelete) {
        $bulkOptions[] = $view === 'trash'
            ? ['delete', 'Delete for good']
            : ['trash', 'Move to Trash'];
    }

    $defaultBulkAction = $bulkOptions[0][0] ?? 'read';

    /**
     * An empty Drafts is not an empty Inbox, and saying "no messages" in both
     * places tells the reader nothing about why.
     */
    $emptyCopy = match ($view) {
        'starred' => [
            'title' => 'Nothing is starred',
            'message' => 'A star is a private marker on this account’s copy of a message — it is never sent to the mail server. Star anything from a list row or from the message itself to find it again here.',
        ],
        'drafts' => [
            'title' => 'No drafts waiting',
            'message' => 'A draft appears here the moment you start writing, including a reply you never finished. Nothing in this folder has been sent to anyone.',
        ],
        'sent' => [
            'title' => 'Nothing sent from here yet',
            'message' => 'Messages sent from this screen are listed here. Mail you sent from another email client shows up only after its Sent folder has been synced.',
        ],
        'archive' => [
            'title' => 'Nothing archived',
            'message' => 'Archiving takes a message out of the Inbox without deleting it. Anything you archive stays here and stays searchable.',
        ],
        'spam' => [
            'title' => 'No spam here',
            'message' => 'Messages the sync filed as spam land in this folder. Nothing has been filed that way, and nothing here is deleted automatically.',
        ],
        'trash' => [
            'title' => 'The trash is empty',
            'message' => 'Messages you delete are kept here until the trash is emptied, so a mistake can be undone. Nothing is waiting.',
        ],
        default => [
            'title' => 'The inbox is empty',
            'message' => 'Received mail appears here after a mailbox sync. This screen reads the copy already stored in KN Softic — it does not fetch from the mail server itself.',
        ],
    };

    /**
     * Which timestamp a row is actually about. Drafts and sent mail are ordered
     * by when we last touched them (InboxService::query() does the same), so
     * showing received_at — which is null on both — would print an em dash on
     * every row.
     */
    $stampOf = function ($email) use ($isOutgoingView) {
        return $isOutgoingView
            ? ($email->sent_at ?? $email->updated_at)
            : ($email->received_at ?? $email->created_at);
    };

    // Time for today, "6 Sep" inside this year, "6 Sep 2025" before that.
    $shortDate = function ($when) {
        if (! $when) {
            return '—';
        }

        return $when->isToday()
            ? $when->format('H:i')
            : ($when->year === now()->year ? $when->format('j M') : $when->format('j M Y'));
    };

    /**
     * A one-line excerpt, as text and only ever as text. `preview` is written
     * by the sync; the body is a fallback, stripped of markup before anything
     * else touches it, because a list row must never render a stranger's HTML.
     *
     * The rule itself lives in IncomingHtmlSanitizer::excerpt(), not here. It
     * used to exist in both places, and the copy in the service still carried
     * the strip_tags() flaw this one had been fixed for — two implementations
     * of one rule is how a fixed one drifts back to broken.
     */
    $excerptOf = fn ($email) => app(\App\Services\Inbox\IncomingHtmlSanitizer::class)->excerpt($email, 2000);

    // Only worth a line under each sender when there is more than one mailbox
    // to tell apart.
    $showMailboxLine = $mailboxes->count() > 1;
@endphp

<x-app-layout>
    <x-slot name="header">{{ $folderLabel }}</x-slot>

    <x-page-header :title="$folderLabel" :subtitle="$subtitle">
        <x-slot name="actions">
            @permission('mailboxes.view')
                <a href="{{ route('mailboxes.index') }}" class="kn-btn-secondary">Mailboxes</a>
            @endpermission
        </x-slot>
    </x-page-header>

    {{--
        The rail is a column beside the list on large screens and a scrolling
        row above it on small ones. It never disappears: the folder you are in,
        and what is waiting in the others, is the one thing this screen cannot
        do without.
    --}}
    <div class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start">

        {{-- ------------------------------------------------------ folder rail --}}
        <div class="space-y-3">
            @permission('inbox.send')
                @if ($composeMailboxId)
                    <form method="POST" action="{{ route('inbox.compose.create') }}">
                        @csrf
                        <input type="hidden" name="mailbox" value="{{ $composeMailboxId }}">
                        <button type="submit" class="kn-btn-primary w-full">Write a message</button>
                    </form>
                @else
                    {{-- No mailbox, so no "from" address. The composer would refuse
                         to open; a button that 422s is worse than an honest line. --}}
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                        No mailbox is connected, so there is nothing to send from.
                        @permission('mailboxes.manage')
                            <a href="{{ route('mailboxes.create') }}" class="font-semibold underline hover:no-underline">Connect one</a>.
                        @endpermission
                    </div>
                @endif
            @endpermission

            <nav aria-label="Mail folders" class="kn-card overflow-hidden">
                <div class="flex gap-1 overflow-x-auto p-2 lg:flex-col lg:gap-0.5 lg:overflow-x-visible">
                    @foreach ($folderLabels as $key => $label)
                        @php
                            $isCurrent = $view === $key;
                            $total = (int) ($counts[$key]['total'] ?? 0);
                            $unread = (int) ($counts[$key]['unread'] ?? 0);
                        @endphp

                        <a href="{{ route($folderRoutes[$key]) }}"
                           class="{{ $railBase }} {{ $isCurrent ? $railOn : $railOff }}"
                           @if ($isCurrent) aria-current="page" @endif
                           title="{{ $unread > 0
                                ? number_format($unread).' unread of '.number_format($total)
                                : number_format($total).' '.\Illuminate\Support\Str::plural('message', $total) }}">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $folderIcons[$key] }}"/>
                            </svg>

                            <span class="truncate">{{ $label }}</span>

                            @if ($unread > 0)
                                <span class="{{ $railUnread }}">{{ $unread > 999 ? '999+' : number_format($unread) }}</span>
                            @elseif ($total > 0)
                                <span class="{{ $railTotal }}">{{ $total > 999 ? '999+' : number_format($total) }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </nav>

            {{-- Emptying the trash is the one destructive control in the inbox, so
                 it lives only inside the Trash, only when there is something to
                 remove, and only behind a confirmation. --}}
            @if ($view === 'trash' && $trashTotal > 0)
                @permission('inbox.delete')
                    <div class="kn-card p-3">
                        <p class="text-xs text-ink-600">
                            Emptying the trash removes all
                            {{ number_format($trashTotal) }} {{ \Illuminate\Support\Str::plural('message', $trashTotal) }}
                            in the trash <strong>and every file attached to them</strong> from KN Softic’s storage —
                            everything, not only what a filter is showing below. They cannot be brought back.
                        </p>
                        <div class="mt-2">
                            <x-confirm-form :action="route('inbox.empty-trash')"
                                            method="POST"
                                            label="Empty trash"
                                            :message="'Permanently delete '.number_format($trashTotal).' message(s) and their attachments? They cannot be brought back.'" />
                        </div>
                    </div>
                @endpermission
            @endif
        </div>

        {{-- ------------------------------------------------------- list column --}}
        <div class="min-w-0 space-y-4">

            {{-- One GET form, so a filter set survives pagination: the paginator
                 was built withQueryString(). --}}
            <form method="GET" action="{{ route($currentRoute) }}" class="kn-card">
                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <x-input-label for="q" value="Search this folder" />
                        <x-text-input id="q" name="q" :value="$filterQ"
                                      placeholder="Subject, sender or preview text" />
                    </div>

                    @if ($mailboxes->isNotEmpty())
                        <div>
                            <x-input-label for="mailbox" value="Mailbox" />
                            <select id="mailbox" name="mailbox" class="kn-select">
                                <option value="">Every mailbox</option>
                                @foreach ($mailboxes as $box)
                                    <option value="{{ $box->id }}" @selected($filterMailboxId === (int) $box->id)>
                                        {{ $box->name }} — {{ $box->email }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="flex flex-col justify-end gap-1.5">
                        <label class="inline-flex items-center gap-2 text-sm text-ink-700">
                            <input type="checkbox" name="unread" value="1" class="kn-checkbox" @checked($filterUnread)>
                            Unread only
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-ink-700">
                            <input type="checkbox" name="attachments" value="1" class="kn-checkbox" @checked($filterAttachments)>
                            Has an attachment
                        </label>
                    </div>

                    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                        <button type="submit" class="kn-btn-primary">Apply filters</button>
                        @if ($hasFilters)
                            <a href="{{ route($currentRoute) }}" class="kn-btn-ghost">Clear filters</a>
                        @endif
                    </div>
                </div>
            </form>

            {{-- The honest line. Nothing here is written back over IMAP. --}}
            <p class="text-xs text-ink-500">
                This is KN Softic’s own copy of your mail: starring, moving, archiving and deleting here change what you see on
                this screen and are never written back to the mail server.
            </p>

            {{-- ------------------------------------------- bulk bar + message list --}}
            <div x-data="knBulkSelect()">
                <x-bulk-bar :action="route('inbox.bulk')" count-label="messages selected">
                    <div x-data="{ bulkAction: @js($defaultBulkAction) }" class="flex flex-wrap items-center gap-2">
                        <select name="action" x-model="bulkAction" class="kn-select w-auto min-w-[13rem] py-1.5 text-xs">
                            @foreach ($bulkOptions as [$actionValue, $actionLabel])
                                <option value="{{ $actionValue }}">{{ $actionLabel }}</option>
                            @endforeach
                        </select>

                        <button type="submit" class="kn-btn-primary kn-btn-sm"
                                @click="if (bulkAction === 'delete' && ! confirm('Delete ' + selected.length + ' message(s) for good? The messages and their attachments are removed from KN Softic and cannot be brought back.')) $event.preventDefault()">
                            Apply
                        </button>
                    </div>
                </x-bulk-bar>

                <div class="kn-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th class="w-10">
                                        <input type="checkbox" class="kn-checkbox" x-model="all" @change="toggleAll()"
                                               aria-label="Select every message on this page">
                                    </th>
                                    <th class="w-10"><span class="sr-only">Star</span></th>
                                    <th class="min-w-[9rem]">{{ $isOutgoingView ? 'To' : 'From' }}</th>
                                    <th>Subject</th>
                                    <th class="w-24 text-right">{{ $dateHeading }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($emails as $email)
                                    @php
                                        $unreadRow = ! $email->is_read;

                                        $who = $isOutgoingView
                                            ? ($email->recipientLine() ?: 'No recipients yet')
                                            : ($email->fromDisplay() ?: 'Unknown sender');

                                        $subject = trim((string) $email->subject) !== ''
                                            ? $email->subject
                                            : '(no subject)';

                                        $excerpt = $excerptOf($email);
                                        $when = $stampOf($email);

                                        // A draft opens in the composer; anything else opens in
                                        // the reader. Sending a draft to the reader would show a
                                        // half-written message with no way to finish it.
                                        $href = ($view === 'drafts' && $email->is_draft && $canSend)
                                            ? route('inbox.compose.edit', $email)
                                            : route('inbox.show', $email);

                                        $attachmentCount = (int) $email->attachments_count;
                                    @endphp

                                    <tr class="{{ $unreadRow ? 'bg-brand-50/50' : '' }}">
                                        <td>
                                            <input type="checkbox" class="kn-checkbox"
                                                   data-row-id="{{ $email->id }}" @change="sync()"
                                                   aria-label="Select “{{ $subject }}”">
                                        </td>

                                        {{-- A star is a real POST, not a link that changes state. --}}
                                        <td>
                                            <form method="POST" action="{{ route('inbox.star', $email) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded p-1 {{ $email->is_starred ? 'text-amber-500 hover:text-amber-600' : 'text-ink-300 hover:text-amber-500' }}"
                                                        title="{{ $email->is_starred ? 'Remove the star from this message' : 'Star this message' }}"
                                                        aria-label="{{ $email->is_starred ? 'Remove star' : 'Add star' }}">
                                                    <svg class="h-4 w-4" fill="{{ $email->is_starred ? 'currentColor' : 'none' }}"
                                                         stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $folderIcons['starred'] }}"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>

                                        {{-- sender, or recipient in Sent and Drafts --}}
                                        <td>
                                            <div class="flex min-w-0 max-w-[15rem] items-center gap-2">
                                                @if ($unreadRow)
                                                    <span class="h-2 w-2 shrink-0 rounded-full bg-brand-600" title="Unread"></span>
                                                @endif
                                                <a href="{{ $href }}"
                                                   class="truncate {{ $unreadRow ? 'font-semibold text-ink-900' : 'text-ink-700' }} hover:text-brand-600"
                                                   title="{{ $isOutgoingView ? 'To: '.$who : $who.' <'.$email->from_email.'>' }}">
                                                    {{ $who }}
                                                </a>
                                            </div>

                                            @if ($showMailboxLine && $email->mailbox)
                                                <span class="mt-0.5 block max-w-[15rem] truncate text-[11px] text-ink-400">
                                                    {{ $email->mailbox->name }}
                                                </span>
                                            @endif
                                        </td>

                                        {{-- subject + excerpt --}}
                                        <td>
                                            <a href="{{ $href }}" class="block min-w-0">
                                                <span class="flex min-w-0 items-center gap-1.5">
                                                    @if ($email->has_attachments)
                                                        <svg class="h-3.5 w-3.5 shrink-0 text-ink-400" fill="none" stroke="currentColor"
                                                             stroke-width="1.6" viewBox="0 0 24 24"
                                                             role="img"
                                                             aria-label="{{ $attachmentCount > 0
                                                                ? $attachmentCount.' '.\Illuminate\Support\Str::plural('attachment', $attachmentCount)
                                                                : 'Has an attachment' }}">
                                                            <title>{{ $attachmentCount > 0
                                                                ? $attachmentCount.' '.\Illuminate\Support\Str::plural('attachment', $attachmentCount)
                                                                : 'Has an attachment' }}</title>
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $paperclipPath }}"/>
                                                        </svg>
                                                    @endif

                                                    <span class="truncate {{ $unreadRow ? 'font-semibold text-ink-900' : 'text-ink-800' }}">
                                                        {{ $subject }}
                                                    </span>
                                                </span>

                                                @if ($excerpt !== '')
                                                    <span class="mt-0.5 block truncate text-xs text-ink-500">
                                                        {{ \Illuminate\Support\Str::limit($excerpt, 120) }}
                                                    </span>
                                                @endif
                                            </a>

                                            @if ($email->is_important || $email->is_campaign_reply || $email->send_status === 'scheduled' || $email->send_status === 'failed')
                                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                                    @if ($email->send_status === 'failed')
                                                        <span class="kn-badge-red" title="{{ $email->error ?: 'The last send attempt failed.' }}">Send failed</span>
                                                    @endif

                                                    @if ($email->send_status === 'scheduled')
                                                        <span class="kn-badge-blue"
                                                              title="{{ $email->scheduled_at?->format('D, d M Y H:i') }}">
                                                            Goes out {{ $email->scheduled_at?->diffForHumans() ?? 'later' }}
                                                        </span>
                                                    @endif

                                                    @if ($email->is_campaign_reply)
                                                        <span class="kn-badge-gray">Campaign reply</span>
                                                    @endif

                                                    @if ($email->is_important)
                                                        <span class="kn-badge-amber">Important</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-right text-xs {{ $unreadRow ? 'font-semibold text-ink-700' : 'text-ink-500' }}"
                                            title="{{ $when?->format('D, d M Y H:i') ?? 'No date recorded' }}">
                                            {{ $shortDate($when) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
                                            @if ($pastTheEnd)
                                                <x-empty-state title="Nothing on this page"
                                                               message="This page number is past the end of the folder. The messages are on the earlier pages.">
                                                    <x-slot name="action">
                                                        <a href="{{ $emails->url(1) }}" class="kn-btn-secondary">Back to the first page</a>
                                                    </x-slot>
                                                </x-empty-state>
                                            @elseif ($hasFilters)
                                                <x-empty-state title="No messages match these filters"
                                                               message="Nothing in this folder matches that search. Widen it, or clear the filters to see the whole folder.">
                                                    <x-slot name="action">
                                                        <a href="{{ route($currentRoute) }}" class="kn-btn-secondary">Clear filters</a>
                                                    </x-slot>
                                                </x-empty-state>
                                            @else
                                                <x-empty-state :title="$emptyCopy['title']" :message="$emptyCopy['message']">
                                                    <x-slot name="action">
                                                        @if ($view === 'drafts' || $view === 'sent')
                                                            @permission('inbox.send')
                                                                @if ($composeMailboxId)
                                                                    <form method="POST" action="{{ route('inbox.compose.create') }}">
                                                                        @csrf
                                                                        <input type="hidden" name="mailbox" value="{{ $composeMailboxId }}">
                                                                        <button type="submit" class="kn-btn-primary">Write a message</button>
                                                                    </form>
                                                                @endif
                                                            @endpermission
                                                        @elseif ($view === 'inbox')
                                                            @permission('mailboxes.view')
                                                                <a href="{{ route('mailboxes.index') }}" class="kn-btn-secondary">Check the mailboxes</a>
                                                            @endpermission
                                                        @else
                                                            <a href="{{ route('inbox.index') }}" class="kn-btn-secondary">Back to the Inbox</a>
                                                        @endif
                                                    </x-slot>
                                                </x-empty-state>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($emails->hasPages())
                        <div class="border-t border-ink-100 px-5 py-3">{{ $emails->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
