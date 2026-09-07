<x-app-layout>
    <x-slot name="header">Search</x-slot>

    @php
        /**
         * Whole literal class strings only — never assembled from fragments,
         * so Tailwind's scanner sees every class this page can render.
         */
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        $rowLink = 'flex items-start gap-3 border-b border-ink-100 px-4 py-3 last:border-b-0 hover:bg-ink-50 sm:px-5';

        $found = collect($counts)->sum();
        $hasTerm = mb_strlen($term) >= $minTerm;
        $hasDates = ($filters['from'] ?? null) || ($filters['to'] ?? null);

        // Every group's row is one link. Where the record has its own screen the
        // link goes there; where it does not, it goes to that module's list
        // filtered by this term, which is the nearest honest destination.
        $routeFor = function (string $group, $row) use ($term) {
            return match ($group) {
                'contacts' => \Illuminate\Support\Facades\Route::has('subscribers.edit')
                    ? route('subscribers.edit', $row->id) : route('subscribers.index', ['q' => $term]),
                'campaigns' => route('campaigns.show', $row->id),
                'messages' => route('inbox.show', $row->id),
                'logs' => route('logs.show', $row->id),
                'templates' => route('templates.index', ['q' => $term]),
                'automations' => route('automations.show', $row->id),
                'lists' => route('lists.show', $row->id),
                'segments' => route('segments.index', ['q' => $term]),
                'tags' => route('tags.index', ['q' => $term]),
                'suppressions' => route('suppressions.index', ['q' => $term]),
                default => route('search.index', ['q' => $term]),
            };
        };
    @endphp

    <x-page-header
        title="Search"
        :subtitle="$hasTerm
            ? ($found === 0
                ? 'Nothing matched “'.$term.'”'
                : number_format($found).' '.\Illuminate\Support\Str::plural('match', $found).' for “'.$term.'”')
            : 'Look across contacts, campaigns, messages and the email log at once'" />

    {{-- --------------------------------------------------------- the form --}}
    <form method="GET" action="{{ route('search.index') }}" class="kn-card mb-5 p-4 sm:p-5">
        @if ($only)
            <input type="hidden" name="in" value="{{ $only }}">
        @endif

        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto]">
            <div>
                <x-input-label for="q" value="Search for" />
                <x-text-input id="q" name="q" type="search" class="mt-1 block w-full"
                              :value="$filters['q']" autofocus
                              placeholder="An address, a name, a subject line…" />
            </div>

            <div>
                <x-input-label for="from" value="From" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block"
                              :value="$filters['from']" />
            </div>

            <div>
                <x-input-label for="to" value="To" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block"
                              :value="$filters['to']" />
            </div>

            <div class="flex items-end gap-2">
                <x-primary-button type="submit">Search</x-primary-button>

                @if ($hasTerm || $hasDates)
                    <a href="{{ route('search.index') }}"
                       class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-ink-600 hover:text-ink-900">
                        Clear
                    </a>
                @endif
            </div>
        </div>

        <p class="mt-3 text-xs text-ink-500">
            Dates are read in {{ $zone }}, this account's own timezone, so a day means your day.
            @if (in_array('messages', $searched, true))
                Received mail is matched on when it arrived; everything else on when it was created.
            @else
                Records are matched on when they were created.
            @endif
        </p>
    </form>

    {{-- ------------------------------------------------------ nothing yet --}}
    @if (! $hasTerm)
        <x-empty-state
            title="Type at least {{ $minTerm }} characters"
            message="A one-letter search would read every record this account has to return a list nobody could use, so it is not run." />

    {{-- --------------------------------------------------------- no matches --}}
    @elseif ($found === 0)
        <x-empty-state
            title="Nothing matched “{{ $term }}”"
            message="This looks at the fields listed below. It does not read message bodies or attachments — that content is not indexed, and searching it would mean reading every message every time." />

        <div class="kn-card mt-4 p-4 text-sm text-ink-600 sm:p-5">
            <p class="mb-2 font-medium text-ink-900">What was searched</p>
            <ul class="space-y-1">
                @foreach ($searched as $key)
                    <li><span class="font-medium text-ink-800">{{ $groups[$key]['label'] }}</span>
                        — {{ $fieldNotes[$key] }}</li>
                @endforeach
            </ul>

            @if ($skipped !== [])
                <p class="mt-3 text-xs text-ink-500">
                    {{ count($skipped) }}
                    {{ \Illuminate\Support\Str::plural('area', count($skipped)) }}
                    {{ count($skipped) === 1 ? 'was' : 'were' }} not searched, because your role cannot open
                    {{ count($skipped) === 1 ? 'it' : 'them' }}.
                </p>
            @endif
        </div>

    {{-- ------------------------------------------------------------ results --}}
    @else
        @if (count($searched) > 1 || $only)
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <a href="{{ route('search.index', array_filter(['q' => $term, 'from' => $filters['from'], 'to' => $filters['to']])) }}"
                   class="{{ $chipBase }} {{ $only === null ? $chipOn : $chipOff }}">
                    Everything
                    <span class="{{ $only === null ? $chipCountOn : $chipCountOff }}">{{ number_format($found) }}</span>
                </a>

                @foreach ($groups as $key => $group)
                    @continue (! in_array($key, $searched, true))
                    @continue (($counts[$key] ?? 0) === 0 && $only !== $key)

                    <a href="{{ route('search.index', array_filter(['q' => $term, 'in' => $key, 'from' => $filters['from'], 'to' => $filters['to']])) }}"
                       class="{{ $chipBase }} {{ $only === $key ? $chipOn : $chipOff }}">
                        {{ $group['label'] }}
                        <span class="{{ $only === $key ? $chipCountOn : $chipCountOff }}">
                            {{ number_format($counts[$key] ?? 0) }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="space-y-5">
            @foreach ($results as $key => $rows)
                <section class="kn-card overflow-hidden">
                    <header class="flex items-center justify-between border-b border-ink-100 px-4 py-3 sm:px-5">
                        <h2 class="text-sm font-semibold text-ink-900">{{ $groups[$key]['label'] }}</h2>

                        @if ($rows->count() >= $perGroup && $only !== $key)
                            <a href="{{ route('search.index', array_filter(['q' => $term, 'in' => $key, 'from' => $filters['from'], 'to' => $filters['to']])) }}"
                               class="text-xs font-medium text-brand-700 hover:text-brand-800">
                                More {{ \Illuminate\Support\Str::plural($groups[$key]['noun'], 2) }}
                            </a>
                        @endif
                    </header>

                    @foreach ($rows as $row)
                        <a href="{{ $routeFor($key, $row) }}" class="{{ $rowLink }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-900">
                                    @switch ($key)
                                        @case ('contacts') {{ $row->email }} @break
                                        @case ('messages') {{ $row->subject ?: '(no subject)' }} @break
                                        @case ('logs') {{ $row->recipient_email }} @break
                                        @case ('suppressions') {{ $row->email }} @break
                                        @default {{ $row->name }}
                                    @endswitch
                                </p>

                                <p class="mt-0.5 truncate text-xs text-ink-500">
                                    @switch ($key)
                                        @case ('contacts')
                                            {{ trim($row->name ?: trim(($row->first_name ?? '').' '.($row->last_name ?? ''))) ?: 'No name recorded' }}
                                            @if ($row->company) · {{ $row->company }} @endif
                                            @break
                                        @case ('campaigns') {{ $row->subject ?: 'No subject line yet' }} @break
                                        @case ('messages')
                                            {{ $row->from_name ?: $row->from_email ?: 'Unknown sender' }}
                                            @break
                                        @case ('logs') {{ $row->subject ?: 'No subject recorded' }} @break
                                        @case ('templates') {{ $row->subject ?: 'No subject line' }} @break
                                        @case ('automations') {{ $row->description ?: 'No description' }} @break
                                        @case ('lists') {{ $row->description ?: number_format((int) $row->active_count).' active' }} @break
                                        @case ('tags') {{ number_format((int) $row->subscribers_count) }} contact(s) @break
                                        @case ('suppressions') {{ str_replace('_', ' ', (string) $row->reason) }} @break
                                        @default &nbsp;
                                    @endswitch
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                @isset ($row->status)
                                    <x-status-badge :status="$row->status" />
                                @endisset

                                <p class="mt-1 text-[11px] text-ink-400">
                                    {{ optional($key === 'messages' ? ($row->received_at ?? $row->created_at) : $row->created_at)
                                        ?->timezone($zone)?->format('j M Y') ?? '—' }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </section>
            @endforeach
        </div>

        @if ($skipped !== [])
            <p class="mt-4 text-xs text-ink-500">
                {{ count($skipped) }}
                {{ \Illuminate\Support\Str::plural('area', count($skipped)) }}
                {{ count($skipped) === 1 ? 'was' : 'were' }} not searched, because your role cannot open
                {{ count($skipped) === 1 ? 'it' : 'them' }}.
            </p>
        @endif
    @endif
</x-app-layout>
