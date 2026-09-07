<x-app-layout>
    <x-slot name="header">Activity</x-slot>

    @php
        /**
         * Whole literal class strings only — never assembled from fragments,
         * so Tailwind's scanner sees every class this page can render.
         */
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';

        $hasFilters = ($filters['q'] ?? null) || $area || ($filters['user'] ?? null)
            || ($filters['from'] ?? null) || ($filters['to'] ?? null);

        // What each row was: the part before the dot names the area, the part
        // after it names the act. Shown as a quiet label rather than the raw
        // event string, which reads like a database column because it is one.
        $verbOf = function (string $event): string {
            $tail = str_contains($event, '.') ? substr($event, strpos($event, '.') + 1) : $event;

            return ucfirst(str_replace(['_', '.'], [' ', ' '], $tail));
        };
    @endphp

    <x-page-header
        title="Activity"
        :subtitle="$logs->total() === 0
            ? ($hasFilters ? 'Nothing matches these filters' : 'Nothing has been recorded yet')
            : number_format($logs->total()).' '.\Illuminate\Support\Str::plural('entry', $logs->total())" />

    {{-- --------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('activity.index') }}" class="kn-card mb-4 p-4 sm:p-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_auto_auto_auto]">
            <div>
                <x-input-label for="q" value="Search" />
                <x-text-input id="q" name="q" type="search" class="mt-1 block w-full"
                              :value="$filters['q']" placeholder="A name, a campaign, an address…" />
            </div>

            <div>
                <x-input-label for="user" value="Done by" />
                <select id="user" name="user"
                        class="mt-1 block w-full rounded-md border-ink-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Anyone</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((int) ($filters['user'] ?? 0) === $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="from" value="From" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block" :value="$filters['from']" />
            </div>

            <div>
                <x-input-label for="to" value="To" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block" :value="$filters['to']" />
            </div>

            <div class="flex items-end gap-2">
                @if ($area)
                    <input type="hidden" name="area" value="{{ $area }}">
                @endif

                <x-primary-button type="submit">Filter</x-primary-button>

                @if ($hasFilters)
                    <a href="{{ route('activity.index') }}"
                       class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-ink-600 hover:text-ink-900">
                        Clear
                    </a>
                @endif
            </div>
        </div>

        <p class="mt-3 text-xs text-ink-500">
            Dates are read in {{ $zone }}, this account's own timezone. Entries are kept for as long as
            the account exists — nothing here can be edited or removed, which is what makes it worth reading.
        </p>
    </form>

    {{-- ------------------------------------------------------ area chips --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('activity.index', array_filter([
                'q' => $filters['q'], 'user' => $filters['user'],
                'from' => $filters['from'], 'to' => $filters['to'],
           ])) }}"
           class="{{ $chipBase }} {{ $area === null ? $chipOn : $chipOff }}">
            Everything
        </a>

        @foreach ($areas as $key => $label)
            <a href="{{ route('activity.index', array_filter([
                    'area' => $key, 'q' => $filters['q'], 'user' => $filters['user'],
                    'from' => $filters['from'], 'to' => $filters['to'],
               ])) }}"
               class="{{ $chipBase }} {{ $area === $key ? $chipOn : $chipOff }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ list --}}
    @if ($logs->isEmpty())
        <x-empty-state
            :title="$hasFilters ? 'Nothing matches these filters' : 'Nothing has been recorded yet'"
            :message="$hasFilters
                ? 'Try a wider date range, or clear the filters to see everything this account has done.'
                : 'Every change made in this account is recorded here — who did it, when, and from where.'" />
    @else
        <div class="kn-card overflow-hidden">
            @foreach ($logs as $log)
                <div class="flex flex-wrap items-start gap-x-4 gap-y-1 border-b border-ink-100 px-4 py-3 last:border-b-0 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink-900">{{ $log->description }}</p>

                        <p class="mt-0.5 text-xs text-ink-500">
                            <span class="font-medium text-ink-700">
                                @if ($log->user)
                                    {{ $log->user->name }}
                                @elseif (str_starts_with((string) $log->event, 'admin.'))
                                    KN Softic
                                @else
                                    {{-- The actor is genuinely unknown: a scheduled
                                         job, or somebody whose account has since been
                                         deleted. Saying so beats inventing a name. --}}
                                    The system
                                @endif
                            </span>
                            · {{ $verbOf((string) $log->event) }}
                            @if ($log->ip)
                                · from {{ $log->ip }}
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-xs text-ink-600">
                            {{ $log->created_at?->timezone($zone)?->format('j M Y, H:i') ?? '—' }}
                        </p>
                        <p class="text-[11px] text-ink-400">
                            {{ $log->created_at?->diffForHumans() ?? '' }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</x-app-layout>
