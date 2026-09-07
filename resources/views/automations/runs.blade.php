<x-app-layout>
    <x-slot name="header">{{ $automation->name }} — activity</x-slot>

    @php
        $search = (string) ($filters['q'] ?? '');
        $activeStatus = (string) ($filters['status'] ?? '');
        $hasFilters = $search !== '' || $activeStatus !== '';

        $keepQuery = $search !== '' ? ['q' => $search] : [];
        $total = (int) $runCounts->sum();

        $statusNames = [
            'waiting' => 'Waiting',
            'running' => 'Running now',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
        ];

        // What each state means for the person in it. This screen exists to
        // answer "why did this person not get the email?", and the status on its
        // own does not answer that.
        $statusMeanings = [
            'waiting' => 'Parked until their next step is due. Nothing is wrong.',
            'running' => 'Claimed by the runner right now. A run stuck here for more than 15 minutes is reclaimed automatically.',
            'completed' => 'Reached the end of the sequence, or a condition ended it, or the step they were on was deleted.',
            'failed' => 'A step threw an error. The reason is on the row, and the run does not continue by itself.',
            'cancelled' => 'Stopped deliberately: opted out, no longer active, hard bounced, or the automation was deleted.',
            'live' => 'Part-way through the sequence — parked until their next step is due, or being advanced right now.',
        ];

        // The "In progress" card on the automation links here with status=live,
        // and it counts waiting AND running. Without this chip the screen could
        // not express the number the card had just shown, so the card said one
        // thing and the page it opened showed another.
        $liveCount = (int) ($runCounts['waiting'] ?? 0) + (int) ($runCounts['running'] ?? 0);

        // waiting and running are the same thing to a person: in progress.
        $badgeMap = [
            'waiting' => 'kn-badge-blue',
            'running' => 'kn-badge-blue',
            'completed' => 'kn-badge-green',
            'failed' => 'kn-badge-red',
            'cancelled' => 'kn-badge-gray',
        ];

        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        $subtitle = $total === 0
            ? 'Nobody has entered this automation yet.'
            : number_format($total).' contact(s) have entered it.';
    @endphp

    <x-page-header title="Activity" :subtitle="$subtitle" :back="route('automations.show', $automation)">
        <x-slot name="actions">
            <x-status-badge :status="$automation->status" />
            <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary">Steps</a>
        </x-slot>
    </x-page-header>

    @if ($automation->status !== 'active')
        <div class="kn-card mb-5 border-amber-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">
                    This automation is {{ $automation->status }}, so nothing below is moving
                </p>
                <p class="mt-1 text-sm text-ink-600">
                    The runner checks each waiting contact roughly every fifteen minutes while an automation is not
                    active, finds it stopped, and puts them back exactly where they were. Activating it again
                    continues each contact from the same step rather than restarting them.
                </p>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------- status chips --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('automations.runs', array_merge(['automation' => $automation], $keepQuery)) }}"
           class="{{ $chipBase }} {{ $activeStatus === '' ? $chipOn : $chipOff }}">
            All
            <span class="{{ $activeStatus === '' ? $chipCountOn : $chipCountOff }}">{{ number_format($total) }}</span>
        </a>

        @if ($liveCount > 0)
            <a href="{{ route('automations.runs', array_merge(['automation' => $automation, 'status' => 'live'], $keepQuery)) }}"
               class="{{ $chipBase }} {{ $activeStatus === 'live' ? $chipOn : $chipOff }}"
               title="{{ $statusMeanings['live'] }}">
                In progress
                <span class="{{ $activeStatus === 'live' ? $chipCountOn : $chipCountOff }}">
                    {{ number_format($liveCount) }}
                </span>
            </a>
        @endif

        @foreach ($statusNames as $status => $label)
            @continue (! $runCounts->has($status))
            @php $isOn = $activeStatus === $status; @endphp

            <a href="{{ route('automations.runs', array_merge(['automation' => $automation, 'status' => $status], $keepQuery)) }}"
               class="{{ $chipBase }} {{ $isOn ? $chipOn : $chipOff }}"
               title="{{ $statusMeanings[$status] }}">
                {{ $label }}
                <span class="{{ $isOn ? $chipCountOn : $chipCountOff }}">
                    {{ number_format((int) $runCounts[$status]) }}
                </span>
            </a>
        @endforeach
    </div>

    @if ($activeStatus !== '' && isset($statusMeanings[$activeStatus]))
        <p class="mb-4 text-sm text-ink-600">{{ $statusMeanings[$activeStatus] }}</p>
    @endif

    {{-- ------------------------------------------------------------ search --}}
    <form method="GET" action="{{ route('automations.runs', $automation) }}" class="kn-card mb-5">
        @if ($activeStatus !== '')
            <input type="hidden" name="status" value="{{ $activeStatus }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-3">
                <x-text-input name="q" :value="$search" placeholder="Search by email address" />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($hasFilters)
                    <a href="{{ route('automations.runs', $automation) }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------------------- table --}}
    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Where they are</th>
                        <th>Next step due</th>
                        <th>Entered</th>
                        <th class="min-w-[16rem]">Why it stopped</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        @php
                            $status = (string) $run->status;
                            $live = in_array($status, ['waiting', 'running'], true);
                            $step = $run->currentStep;
                            $done = (int) $run->steps_completed;
                        @endphp

                        <tr>
                            {{-- contact --}}
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    @if ($run->subscriber)
                                        <span class="block truncate font-medium text-ink-900">{{ $run->subscriber->email }}</span>
                                        @php $name = trim(($run->subscriber->first_name ?? '').' '.($run->subscriber->last_name ?? '')); @endphp
                                        @if ($name !== '')
                                            <span class="block truncate text-xs text-ink-500">{{ $name }}</span>
                                        @endif
                                        @if ($run->subscriber->status !== 'active')
                                            <span class="mt-0.5 inline-block text-[11px] text-amber-700">
                                                This contact is {{ $run->subscriber->status }} — no further email is sent to them.
                                            </span>
                                        @endif
                                    @else
                                        {{-- The row survives a deleted contact; the runner cancels
                                             the run the next time it looks at it. --}}
                                        <span class="text-xs text-ink-400">The contact has been deleted</span>
                                    @endif
                                </div>
                            </td>

                            {{-- status --}}
                            <td>
                                <x-status-badge :status="$status" :map="$badgeMap" />
                            </td>

                            {{-- current step --}}
                            <td>
                                @if ($step)
                                    <span class="block text-sm text-ink-800">
                                        Step {{ $step->position }}: {{ $step->label ?: ($stepTypes[$step->type]['label'] ?? $step->type) }}
                                    </span>
                                @elseif ($live)
                                    {{-- current_step_id is nullOnDelete, so this is a
                                         contact whose step was removed under them. --}}
                                    <span class="block text-sm text-amber-700">
                                        The step they were on has been deleted
                                    </span>
                                    <span class="block text-xs text-ink-500">
                                        The runner finds nothing there and marks them completed on its next pass.
                                    </span>
                                @else
                                    <span class="text-xs text-ink-400">—</span>
                                @endif

                                <span class="block text-xs text-ink-500">
                                    {{ $done }} of {{ $stepCount }} step(s) done
                                </span>
                            </td>

                            {{-- next run --}}
                            <td class="whitespace-nowrap">
                                @if (! $live)
                                    <span class="text-xs text-ink-400">Not scheduled</span>
                                @elseif ($run->next_run_at)
                                    <span class="block text-xs text-ink-600"
                                          title="{{ $run->next_run_at->format('D, d M Y H:i') }}">
                                        {{ $run->next_run_at->diffForHumans() }}
                                    </span>
                                    @if ($run->next_run_at->isPast() && $automation->status === 'active')
                                        <span class="block text-[11px] text-ink-400">Due — picked up on the next minute's tick</span>
                                    @endif
                                @else
                                    <span class="text-xs text-ink-400">No time set</span>
                                @endif
                            </td>

                            {{-- entered --}}
                            <td class="whitespace-nowrap">
                                @if ($run->started_at)
                                    <span class="text-xs text-ink-600" title="{{ $run->started_at->format('D, d M Y H:i') }}">
                                        {{ $run->started_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-xs text-ink-400">Not recorded</span>
                                @endif

                                @if ($run->completed_at)
                                    <span class="block text-[11px] text-ink-400"
                                          title="{{ $run->completed_at->format('D, d M Y H:i') }}">
                                        Ended {{ $run->completed_at->diffForHumans() }}
                                    </span>
                                @endif
                            </td>

                            {{-- reason --}}
                            <td>
                                @if ($run->last_error)
                                    <span class="block max-w-md break-words text-sm {{ $status === 'failed' ? 'text-red-700' : 'text-ink-600' }}">
                                        {{ $run->last_error }}
                                    </span>
                                @elseif ($status === 'failed')
                                    {{-- fail() always writes a reason, so a failed run
                                         without one came from somewhere else. --}}
                                    <span class="text-sm text-red-700">Failed without recording a reason.</span>
                                @elseif ($status === 'completed')
                                    <span class="text-xs text-ink-400">Finished normally</span>
                                @else
                                    <span class="text-xs text-ink-400">Nothing has gone wrong</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @if ($hasFilters)
                                    <x-empty-state title="No contacts match this search"
                                                   message="Nothing here matches that address or status.">
                                        <x-slot name="action">
                                            <a href="{{ route('automations.runs', $automation) }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="Nobody has entered this automation"
                                                   message="Contacts appear here the moment the trigger enrols them. Until then there is nothing to measure — not zero opens or zero sends, simply nobody yet.">
                                        <x-slot name="action">
                                            <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary">Back to the steps</a>
                                        </x-slot>
                                    </x-empty-state>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($runs->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $runs->links() }}</div>
        @endif
    </div>

    <p class="mt-4 text-xs text-ink-500">
        This screen is read-only. A contact's place in an automation is moved by the runner alone — editing it by
        hand from here would race the very process that owns the row.
    </p>
</x-app-layout>
