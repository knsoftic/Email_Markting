<x-app-layout>
    <x-slot name="header">{{ $segment->name }}</x-slot>

    @php
        // Editing needs the plan feature as well as the permission, so the
        // button is only drawn when it would actually work.
        $canEditRules = \App\Support\PlanLimits::forCurrentUser()->allows('allow_segments');

        $matchLabel = $segment->match_type === 'any' ? 'Match ANY condition' : 'Match ALL conditions';
        $cached = (int) $segment->cached_count;
        $liveTotal = $matches->total();
        // Only a genuine disagreement is worth a warning — a segment that has
        // never been calculated but already agrees with the live query is fine,
        // and the "Last calculated" card already says it has never run.
        $isStale = $liveTotal !== $cached;

        // Tag colours are user input, so only a real hex reaches the style attribute.
        $swatch = fn ($color) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? $color : '#94a3b8';
    @endphp

    <x-page-header :title="$segment->name"
                   :subtitle="$segment->description ?: $matchLabel"
                   :back="route('segments.index')">
        <x-slot name="actions">
            {{--
                The export screen chooses its own source; it does not read a
                segment out of the query string, so the label promises only what
                the link actually does — it opens the export screen, where this
                segment is one of the choices.
            --}}
            @permission('contacts.export')
                <a href="{{ route('exports.index') }}" class="kn-btn-secondary"
                   title="Opens the export screen — pick “A segment”, then {{ $segment->name }}">Export contacts</a>
            @endpermission

            @permission('contacts.update')
                <form method="POST" action="{{ route('segments.recalculate', $segment) }}" class="inline">
                    @csrf
                    <button type="submit" class="kn-btn-secondary">Recalculate</button>
                </form>

                @if ($canEditRules)
                    <a href="{{ route('segments.edit', $segment) }}" class="kn-btn-primary">Edit segment</a>
                @endif
            @endpermission

            @permission('contacts.delete')
                <x-confirm-form :action="route('segments.destroy', $segment)"
                                label="Delete segment"
                                button-class="kn-btn-danger"
                                :message="'Delete the segment '.$segment->name.'? No contacts are deleted — only the saved filter is removed.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------------ stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Total matches"
                     :value="number_format($cached)"
                     :meta="'Everyone matching these conditions'" />

        <x-stat-card label="Mailable matches"
                     :value="number_format($mailableCount)"
                     meta="Can receive a campaign right now"
                     tone="positive" />

        <x-stat-card label="Last calculated"
                     :value="$segment->last_calculated_at?->diffForHumans() ?? 'Never'"
                     :meta="$segment->last_calculated_at?->format('D, d M Y H:i') ?? 'Press Recalculate to store a count'" />
    </div>

    <p class="mb-6 rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
        The mailable count excludes unsubscribed, bounced and suppressed contacts — a campaign sent to this
        segment reaches that number, not the total above.
    </p>

    {{-- ---------------------------------------------------------- summary --}}
    <div class="kn-card mb-6">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Conditions</h3>
            <span class="kn-badge-blue">{{ $matchLabel }}</span>
        </div>

        <div class="p-5">
            @if (count($summary) > 0)
                <div class="flex flex-wrap gap-2">
                    @foreach ($summary as $line)
                        <span class="kn-badge-gray">{{ $line }}</span>
                    @endforeach
                </div>
                <p class="kn-help">
                    {{ $segment->match_type === 'any'
                        ? 'A contact is in this segment if it satisfies at least one of these conditions.'
                        : 'A contact is in this segment only if it satisfies every one of these conditions.' }}
                </p>
            @else
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-800">No usable conditions</p>
                    <p class="mt-1 text-xs text-amber-700">
                        Nothing stored on this segment can be applied, so it currently matches every contact
                        in your account. Edit it and add at least one condition.
                    </p>
                </div>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------- matches --}}
    <div class="kn-card overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Matching contacts</h3>
            <span class="text-xs text-ink-500">{{ number_format($liveTotal) }} matching right now</span>
        </div>

        @if ($isStale)
            <div class="border-b border-ink-100 bg-amber-50 px-5 py-2.5">
                <p class="text-xs text-amber-800">
                    The stored count ({{ number_format($cached) }}) is out of date — {{ number_format($liveTotal) }}
                    contacts match this second. Press Recalculate to store the new number.
                </p>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Tags</th>
                        <th>Country</th>
                        <th>Added</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($matches as $match)
                        <tr>
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    <a href="{{ route('subscribers.show', $match) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $match->displayName() }}
                                    </a>
                                    <span class="block truncate text-xs text-ink-500">{{ $match->email }}</span>
                                </div>
                            </td>

                            <td><x-status-badge :status="$match->status" /></td>

                            <td>
                                @if ($match->tags->isEmpty())
                                    <span class="text-ink-400">—</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($match->tags as $tag)
                                            <span class="kn-badge-gray">
                                                <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $swatch($tag->color) }}"></span>
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-ink-600">{{ $match->country ?: '—' }}</td>

                            <td class="whitespace-nowrap text-xs text-ink-500"
                                title="{{ $match->created_at?->format('D, d M Y H:i') }}">
                                {{ $match->created_at?->format('j M Y') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-empty-state title="No contacts match this segment"
                                               message="Nothing in your account satisfies these conditions right now. Loosen a condition, or switch the segment to match any condition instead of all of them.">
                                    <x-slot name="action">
                                        <div class="flex flex-wrap items-center justify-center gap-2">
                                            @permission('contacts.update')
                                                @if ($canEditRules)
                                                    <a href="{{ route('segments.edit', $segment) }}" class="kn-btn-primary">Edit conditions</a>
                                                @endif
                                            @endpermission
                                            <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">Browse all contacts</a>
                                        </div>
                                    </x-slot>
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($matches->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $matches->links() }}</div>
        @endif
    </div>
</x-app-layout>
