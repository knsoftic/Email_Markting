@php
    /**
     * The campaign replies queue.
     *
     * Everything on this screen comes from what CampaignReplyController@index
     * passes: $threads (a paginator of EmailThread with `campaign` eager
     * loaded), $filters, $statuses, $counts and $campaigns. No relation is
     * touched that was not loaded — `subscriber` is NOT loaded, so this screen
     * never mentions it; lazy loading is prevented outside production, and a
     * list that only breaks on a developer's machine is worse than one that
     * never had the field.
     *
     * ── This is a queue, not a list ─────────────────────────────────────────
     * The controller sorts 'new' to the top and then by recency, so the row a
     * person is waiting on is the first thing on the page. The markup keeps
     * that promise: a 'new' row carries a brand bar, a tinted background and a
     * bolder subject, so "what still needs answering" survives a glance at a
     * screen with forty rows on it.
     *
     * ── What this screen may claim about a match ────────────────────────────
     * ReplyMatcher works three ways: In-Reply-To/References against the
     * Message-IDs we minted, a per-recipient token that came back in the
     * address, and — only when neither is present — the sender's address plus a
     * matching subject inside a 30-day window. The first two are certain, the
     * third is a judgement. Which one linked a given row is NOT stored on the
     * row, so this screen must not print a confidence badge per conversation;
     * inventing one would be worse than saying nothing. What it does instead is
     * state plainly, once, that the fallback exists and can be wrong, and point
     * at where a wrong match is undone (the conversation itself, which carries
     * the unlink control).
     */

    $counts = is_array($counts ?? null) ? $counts : [];

    $filterQ = (string) ($filters['q'] ?? '');
    $filterStatus = (string) ($filters['status'] ?? '');
    $filterCampaign = (string) ($filters['campaign'] ?? '');
    $hasFilters = $filterQ !== '' || $filterStatus !== '' || $filterCampaign !== '';

    // Everything except the status, so a chip keeps the search and the campaign.
    $keepQuery = [];
    if ($filterQ !== '') {
        $keepQuery['q'] = $filterQ;
    }
    if ($filterCampaign !== '') {
        $keepQuery['campaign'] = $filterCampaign;
    }

    $totalThreads = (int) ($counts['all'] ?? 0);
    $newCount = (int) ($counts['new'] ?? 0);

    $statusLabels = [
        'new' => 'New',
        'read' => 'Read',
        'replied' => 'Replied',
        'closed' => 'Closed',
    ];

    // Literal tone classes, handed to <x-status-badge> as a map so the four
    // reply states never look different here than they do inside a conversation.
    $statusTones = [
        'new' => 'kn-badge-blue',
        'read' => 'kn-badge-gray',
        'replied' => 'kn-badge-green',
        'closed' => 'kn-badge-gray',
    ];

    // Full literal class lists — never assembled from fragments, so Tailwind
    // still sees every class it has to compile.
    $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
    $chipOn = 'bg-brand-600 text-white ring-brand-600';
    $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
    $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
    $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

    // The 'new' row treatment, and the neutral version of each class so every
    // row keeps the same geometry.
    $rowNew = 'bg-brand-50/60';
    $rowPlain = '';
    $barNew = 'mt-0.5 h-8 w-1 shrink-0 rounded-full bg-brand-500';
    $barPlain = 'mt-0.5 h-8 w-1 shrink-0 rounded-full bg-transparent';

    $canViewCampaigns = (bool) auth()->user()?->hasPermission('campaigns.view');

    /**
     * The campaign select only carries the 50 most recent campaigns that have
     * replies. If the current filter names one outside that window, the option
     * is added back by hand — otherwise submitting the search would silently
     * drop the campaign filter and show the operator a different result set
     * than the one they asked for.
     */
    $campaignOptions = $campaigns->map(fn ($campaign) => [
        'id' => (string) $campaign->id,
        'name' => (string) $campaign->name,
    ])->all();

    if ($filterCampaign !== '' && ! collect($campaignOptions)->contains(fn ($o) => $o['id'] === $filterCampaign)) {
        // Name it from a row on this page where we can; fall back to the id.
        $named = $threads->first(fn ($thread) => (string) $thread->campaign_id === $filterCampaign)?->campaign?->name;

        array_unshift($campaignOptions, [
            'id' => $filterCampaign,
            'name' => $named ?: 'Campaign #'.$filterCampaign,
        ]);
    }

    /**
     * ?page=999 on a queue that has 30 conversations is not an empty queue, and
     * saying "no replies yet" under a subtitle counting 30 of them — with the
     * pager still sitting below — is the screen contradicting itself.
     */
    $pastTheEnd = $threads->isEmpty() && $threads->currentPage() > 1;

    $subtitle = $totalThreads === 0
        ? 'No campaign replies have been matched yet'
        : ($newCount > 0
            ? number_format($newCount).' '.\Illuminate\Support\Str::plural('conversation', $newCount)
                .' still '.($newCount === 1 ? 'needs' : 'need').' an answer · '
                .number_format($totalThreads).' in total'
            : 'Nothing is waiting for an answer · '
                .number_format($totalThreads).' '.\Illuminate\Support\Str::plural('conversation', $totalThreads).' in total');

    // Truthful because it is read off the matcher itself rather than typed here.
    $addressWindowDays = \App\Services\Inbox\ReplyMatcher::ADDRESS_WINDOW_DAYS;

    $rematchMessage = 'Re-run reply matching over the most recent incoming messages that are not already linked to a campaign?'
        ."\n\n"
        .'This only reads those messages and links the ones that match. Nothing is sent, edited, moved or deleted, and a reply that is already linked is left exactly as it is.';

    /**
     * Which state changes make sense from where a conversation already is.
     * Offering "mark as read" for a row somebody is about to open, or "close"
     * on something already closed, is busywork dressed up as a control.
     */
    $actionsFor = function (?string $status) {
        return match ($status) {
            'new' => [['closed', 'Close', 'Takes it out of the queue. It stays searchable and comes back as new if they write again.']],
            'read' => [
                ['replied', 'Mark replied', 'Use this if you answered from another mail client — replying from the inbox marks the conversation automatically.'],
                ['closed', 'Close', 'Takes it out of the queue. It stays searchable and comes back as new if they write again.'],
            ],
            'replied' => [['closed', 'Close', 'Takes it out of the queue. It stays searchable and comes back as new if they write again.']],
            // "Reopen" puts the row back in the NEW group, but the queue then
            // orders that group by recency — an old conversation reopened today
            // lands wherever its last message puts it, not at the very top.
            'closed' => [['new', 'Reopen', 'Puts it back in the queue as new, in date order with everything else waiting for an answer.']],
            default => [['new', 'Add to the queue', 'Files this conversation as new so it appears with everything else waiting for an answer.']],
        };
    };
@endphp

<x-app-layout>
    <x-slot name="header">Campaign Replies</x-slot>

    <x-page-header title="Campaign replies" :subtitle="$subtitle">
        <x-slot name="actions">
            <a href="{{ route('inbox.index') }}" class="kn-btn-ghost">Inbox</a>

            <x-confirm-form :action="route('campaign-replies.rematch')"
                            method="POST"
                            label="Check for missed replies"
                            :message="$rematchMessage"
                            button-class="kn-btn-secondary" />
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ status chips --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('campaign-replies.index', $keepQuery) }}"
           class="{{ $chipBase }} {{ $filterStatus === '' ? $chipOn : $chipOff }}"
           @if ($filterStatus === '') aria-current="page" @endif>
            All
            <span class="{{ $filterStatus === '' ? $chipCountOn : $chipCountOff }}">{{ number_format($totalThreads) }}</span>
        </a>

        @foreach ($statuses as $status)
            @php
                $isOn = $filterStatus === $status;
                $statusCount = (int) ($counts[$status] ?? 0);
            @endphp

            <a href="{{ route('campaign-replies.index', array_merge($keepQuery, ['status' => $status])) }}"
               class="{{ $chipBase }} {{ $isOn ? $chipOn : $chipOff }}"
               @if ($isOn) aria-current="page" @endif>
                {{ $statusLabels[$status] ?? ucfirst($status) }}
                <span class="{{ $isOn ? $chipCountOn : $chipCountOff }}">{{ number_format($statusCount) }}</span>
            </a>
        @endforeach
    </div>

    {{-- ----------------------------------------------------------- filters --}}
    {{-- One GET form, so a filter set survives pagination: the paginator was
         built withQueryString(). The chip's status rides along as a hidden
         field so searching does not throw you back to "All". --}}
    <form method="GET" action="{{ route('campaign-replies.index') }}" class="kn-card mb-4">
        @if ($filterStatus !== '')
            <input type="hidden" name="status" value="{{ $filterStatus }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-input-label for="q" value="Search replies" />
                <x-text-input id="q" name="q" :value="$filterQ"
                              placeholder="Subject, sender address or preview text" />
            </div>

            <div>
                <x-input-label for="campaign" value="Campaign" />
                <select id="campaign" name="campaign" class="kn-select">
                    <option value="">Every campaign</option>
                    @foreach ($campaignOptions as $option)
                        <option value="{{ $option['id'] }}" @selected($filterCampaign === $option['id'])>
                            {{ $option['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Apply</button>
                @if ($hasFilters)
                    <a href="{{ route('campaign-replies.index') }}" class="kn-btn-ghost shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- The honest line about how a message ended up on this screen. --}}
    <p class="mb-4 text-xs text-ink-500">
        Replies are matched to a campaign from the reply headers the mail carries — In-Reply-To and References against the
        message IDs KN Softic minted, or a per-recipient token that came back in the address. Both identify the recipient
        exactly. When a mail client strips those, matching falls back on the sender’s address plus a matching subject within
        {{ $addressWindowDays }} days, and that fallback is a judgement: it can occasionally file a message under the wrong
        campaign. Open a conversation to unlink one that does not belong.
    </p>

    @if ($filterStatus === 'closed')
        <p class="mb-4 text-xs text-ink-500">
            Closing is not deleting. A closed conversation keeps every message, stays searchable, and returns to the queue as
            new if the person writes again.
        </p>
    @endif

    {{-- -------------------------------------------------------------- rows --}}
    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th class="min-w-[16rem]">Conversation</th>
                        <th class="min-w-[11rem]">Answers campaign</th>
                        <th class="w-28">Messages</th>
                        <th class="w-28">Status</th>
                        <th class="w-32">Last message</th>
                        <th class="w-56 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($threads as $thread)
                        @php
                            // Anything outside the four known states is shown as
                            // exactly that rather than being quietly relabelled.
                            $rowStatus = in_array($thread->reply_status, $statuses, true)
                                ? $thread->reply_status
                                : null;

                            $isNew = $rowStatus === 'new';

                            $subject = trim((string) $thread->subject) !== ''
                                ? $thread->subject
                                : '(no subject)';

                            $messages = (int) $thread->messages_count;
                            $unread = (int) $thread->unread_count;
                            $when = $thread->last_message_at;

                            $campaign = $thread->campaign;
                            $href = route('campaign-replies.show', $thread);
                        @endphp

                        <tr class="{{ $isNew ? $rowNew : $rowPlain }}">
                            {{-- subject; the bar and the weight are what make a
                                 waiting conversation findable at a glance --}}
                            <td>
                                <div class="flex items-start gap-3">
                                    <span class="{{ $isNew ? $barNew : $barPlain }}" aria-hidden="true"></span>

                                    <div class="min-w-0 max-w-md">
                                        <a href="{{ $href }}"
                                           class="block truncate {{ $isNew ? 'font-semibold text-ink-900' : 'font-medium text-ink-800' }} hover:text-brand-600"
                                           title="{{ $subject }}">
                                            {{ $subject }}
                                        </a>

                                        <span class="mt-0.5 block text-xs text-ink-500">
                                            @if ($isNew)
                                                Waiting for an answer
                                            @elseif ($messages > 1)
                                                {{ number_format($messages) }} messages in this conversation
                                            @else
                                                One message in this conversation
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            </td>

                            {{-- the campaign this conversation answers --}}
                            <td>
                                @if ($campaign)
                                    <div class="min-w-0 max-w-[14rem]">
                                        @if ($canViewCampaigns)
                                            <a href="{{ route('campaigns.show', $campaign) }}"
                                               class="block truncate text-ink-700 hover:text-brand-600"
                                               title="{{ $campaign->name }}">
                                                {{ $campaign->name }}
                                            </a>
                                        @else
                                            <span class="block truncate text-ink-700" title="{{ $campaign->name }}">
                                                {{ $campaign->name }}
                                            </span>
                                        @endif

                                        @if (trim((string) $campaign->subject) !== '')
                                            <span class="mt-0.5 block truncate text-xs text-ink-400"
                                                  title="{{ $campaign->subject }}">
                                                {{ $campaign->subject }}
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-ink-400"
                                          title="The campaign this conversation was linked to is no longer available on this account.">
                                        No longer available
                                    </span>
                                @endif
                            </td>

                            {{-- message and unread counts --}}
                            <td class="whitespace-nowrap">
                                <span class="text-ink-700">{{ number_format($messages) }}</span>

                                @if ($unread > 0)
                                    <span class="ml-1.5 rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-semibold text-white"
                                          title="{{ number_format($unread) }} unread {{ \Illuminate\Support\Str::plural('message', $unread) }} in this conversation">
                                        {{ $unread > 99 ? '99+' : number_format($unread) }} unread
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if ($rowStatus)
                                    <x-status-badge :status="$rowStatus" :map="$statusTones" />
                                @else
                                    <span class="kn-badge-gray"
                                          title="No reply state has been recorded for this conversation.">Unsorted</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-xs text-ink-500"
                                title="{{ $when?->format('D, d M Y H:i') ?? 'No date recorded' }}">
                                {{ $when?->diffForHumans() ?? '—' }}
                            </td>

                            {{-- open, plus the state changes that make sense here --}}
                            <td class="text-right">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    @foreach ($actionsFor($rowStatus) as [$target, $label, $explain])
                                        <form method="POST" action="{{ route('campaign-replies.status', $thread) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $target }}">
                                            <button type="submit" class="kn-btn-ghost kn-btn-sm" title="{{ $explain }}">
                                                {{ $label }}
                                            </button>
                                        </form>
                                    @endforeach

                                    <a href="{{ $href }}" class="kn-btn-secondary kn-btn-sm">Open</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @if ($pastTheEnd)
                                    <x-empty-state title="Nothing on this page"
                                                   message="This page number is past the end of the queue. The conversations are on the earlier pages.">
                                        <x-slot name="action">
                                            <a href="{{ $threads->url(1) }}" class="kn-btn-secondary">Back to the first page</a>
                                        </x-slot>
                                    </x-empty-state>
                                @elseif ($hasFilters)
                                    <x-empty-state title="No replies match these filters"
                                                   message="Nothing in the queue matches that search, status and campaign together. Widen it, or clear the filters to see every reply.">
                                        <x-slot name="action">
                                            <a href="{{ route('campaign-replies.index') }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No campaign replies yet"
                                                   message="A reply lands here when incoming mail is synced and matched to a campaign you sent. If replies arrived before matching was switched on, they can still be picked up.">
                                        <x-slot name="action">
                                            <div class="flex flex-wrap items-center justify-center gap-2">
                                                <x-confirm-form :action="route('campaign-replies.rematch')"
                                                                method="POST"
                                                                label="Check for missed replies"
                                                                :message="$rematchMessage"
                                                                button-class="kn-btn-primary" />

                                                <a href="{{ route('inbox.index') }}" class="kn-btn-secondary">Go to the inbox</a>
                                            </div>
                                        </x-slot>
                                    </x-empty-state>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($threads->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $threads->links() }}</div>
        @endif
    </div>
</x-app-layout>
