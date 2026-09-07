<x-app-layout>
    <x-slot name="header">{{ $automation->name }}</x-slot>

    @php
        $steps = $automation->steps;
        $stepIds = $steps->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $count = count($stepIds);

        $entered = (int) $automation->entered_count;
        $completed = (int) $automation->completed_count;
        $failed = (int) ($runCounts['failed'] ?? 0);
        $cancelled = (int) ($runCounts['cancelled'] ?? 0);

        $canActivate = $blockers === [] && in_array($automation->status, ['draft', 'paused', 'completed'], true);

        $unitWords = [
            'minutes' => 'minute(s)',
            'hours' => 'hour(s)',
            'days' => 'day(s)',
            'weeks' => 'week(s)',
        ];

        $checkWords = [
            'has_tag' => 'has the tag',
            'on_list' => 'is on the list',
            'opened_campaign' => 'opened',
            'clicked_campaign' => 'clicked a link in',
        ];

        /**
         * One step's configuration, said out loud. Nothing here invents a value
         * the config does not hold — a missing name is reported as missing, not
         * hidden behind a bare id.
         */
        $describe = function ($step) use ($names, $unitWords, $checkWords) {
            $c = (array) ($step->config ?? []);

            $nameOf = function (string $group, $id, string $noun) use ($names) {
                $id = (int) $id;

                if ($id === 0) {
                    return null;
                }

                return $names[$group]->has($id) ? '"'.$names[$group][$id].'"' : 'a deleted '.$noun;
            };

            return match ($step->type) {
                'send_email' => trim((string) ($c['subject'] ?? '')) !== ''
                    ? 'Subject: '.$c['subject']
                    : 'No subject line set.',

                'wait' => 'Wait '.max(1, (int) ($c['amount'] ?? 0)).' '
                    .($unitWords[$c['unit'] ?? 'days'] ?? 'day(s)').' before the next step.',

                'condition' => 'Continue only if the contact '
                    .($checkWords[$c['check'] ?? ''] ?? 'matches an unknown check').' '
                    .(match ($c['check'] ?? '') {
                        'has_tag' => $nameOf('tags', $c['tag_id'] ?? 0, 'tag') ?? 'no tag chosen',
                        'on_list' => $nameOf('lists', $c['list_id'] ?? 0, 'list') ?? 'no list chosen',
                        'opened_campaign', 'clicked_campaign' => $nameOf('campaigns', $c['campaign_id'] ?? 0, 'campaign') ?? 'any campaign',
                        default => '',
                    })
                    .'. If not, '
                    .(($c['if_false'] ?? 'end') === 'skip'
                        ? 'skip the one step that follows and carry on after it.'
                        : 'the run ends here and is marked completed.'),

                'add_tag' => 'Add the tag '.($nameOf('tags', $c['tag_id'] ?? 0, 'tag') ?? 'no tag chosen').'.',

                'remove_tag' => 'Remove the tag '.($nameOf('tags', $c['tag_id'] ?? 0, 'tag') ?? 'no tag chosen').'.',

                'move_list' => 'Add to '.($nameOf('lists', $c['list_id'] ?? 0, 'list') ?? 'no list chosen')
                    .(($from = $nameOf('lists', $c['from_list_id'] ?? 0, 'list')) ? ', removing them from '.$from : '')
                    .'.',

                'unsubscribe' => 'Opt the contact out of all email from this account. The run ends here.',

                default => 'This step type is not one the runner knows, so it is stepped over.',
            };
        };
    @endphp

    <x-page-header :title="$automation->name"
                   :subtitle="$automation->description ?: $trigger['detail']"
                   :back="route('automations.index')">
        <x-slot name="actions">
            <x-status-badge :status="$automation->status" />

            <a href="{{ route('automations.runs', $automation) }}" class="kn-btn-ghost">Activity</a>

            @permission('automation.manage')
                <a href="{{ route('automations.edit', $automation) }}" class="kn-btn-secondary">Settings</a>

                @if ($automation->status === 'active')
                    <form method="POST" action="{{ route('automations.pause', $automation) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-secondary">Pause</button>
                    </form>
                @elseif ($canActivate)
                    <form method="POST" action="{{ route('automations.activate', $automation) }}" class="inline">
                        @csrf
                        <button type="submit" class="kn-btn-primary">Activate</button>
                    </form>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------- what starts it --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-wide text-ink-400">Starts when</p>
                <p class="mt-0.5 text-sm font-medium text-ink-900">{{ $trigger['summary'] }}</p>
                <p class="mt-0.5 text-sm text-ink-600">{{ $trigger['detail'] }}</p>
            </div>

            <div class="text-right text-xs text-ink-500">
                <p>
                    Sends as
                    <span class="font-medium text-ink-700">
                        {{ $automation->from_email ?: ($automation->smtpAccount?->from_email ?: 'the chosen SMTP account’s own address') }}
                    </span>
                </p>
                <p class="mt-0.5">
                    {{ $automation->smtpAccount?->name ?: 'SMTP account picked automatically at send time' }}
                </p>
            </div>
        </div>
    </div>

    {{-- ----------------------------------------------------------- blockers --}}
    @if ($blockers !== [])
        <div class="kn-card mb-5 border-amber-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">
                    This cannot be activated yet
                </p>
                <p class="mt-1 text-sm text-ink-600">
                    Each of these would leave the automation showing "Active" while doing nothing at all, so it is
                    refused rather than allowed to look like it works.
                </p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-amber-800">
                    @foreach ($blockers as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($warnings !== [])
        <div class="kn-card mb-5">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">Worth knowing</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-ink-600">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- -------------------------------------------------------------- stats --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-stat-card label="Entered" :value="number_format($entered)"
                     :meta="$entered === 0 ? 'Nobody has been entered yet' : 'Total enrolments recorded'" />

        <x-stat-card label="In progress" :value="number_format($liveRuns)"
                     :meta="$liveRuns === 0 ? 'Nobody is part-way through' : 'Waiting or running right now'"
                     :href="route('automations.runs', ['automation' => $automation, 'status' => 'live'])" />

        <x-stat-card label="Completed" :value="number_format($completed)"
                     :meta="$entered === 0 ? 'Nothing to measure yet' : (int) round($completed / max(1, $entered) * 100).'% of entries'" />

        <x-stat-card label="Failed" :value="number_format($failed)"
                     :tone="$failed > 0 ? 'danger' : 'default'"
                     :meta="$failed === 0 ? 'No run has failed' : 'Each one shows its reason'"
                     :href="route('automations.runs', ['automation' => $automation, 'status' => 'failed'])" />

        <x-stat-card label="Emails sent"
                     :value="$steps->where('type', 'send_email')->isEmpty() ? '—' : number_format((int) $automation->emails_sent)"
                     :meta="$steps->where('type', 'send_email')->isEmpty() ? 'There are no email steps' : 'Counted by the runner as each one goes out'" />
    </div>

    @if ($cancelled > 0)
        <p class="mb-5 text-xs text-ink-500">
            {{ number_format($cancelled) }} run(s) were cancelled — an opt-out part-way through, a hard bounce, or a
            contact who was deleted. <a href="{{ route('automations.runs', ['automation' => $automation, 'status' => 'cancelled']) }}"
               class="font-medium text-brand-600 hover:text-brand-700">See why</a>.
        </p>
    @endif

    {{-- -------------------------------------------------------------- steps --}}
    <div class="kn-card">
        <div class="kn-card-header flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-ink-900">Steps</h2>
                <p class="mt-0.5 text-xs text-ink-500">
                    Run in order, one after another. A contact enters at step 1 and stops at the last one.
                </p>
            </div>

            @permission('automation.manage')
                <a href="{{ route('automations.steps.create', $automation) }}" class="kn-btn-primary kn-btn-sm">Add a step</a>
            @endpermission
        </div>

        @if ($count === 0)
            <x-empty-state title="No steps yet"
                           message="An automation with no steps enters nobody — the enroller refuses it outright. Add the first step to give it something to do.">
                <x-slot name="action">
                    @permission('automation.manage')
                        <a href="{{ route('automations.steps.create', $automation) }}" class="kn-btn-primary">Add the first step</a>
                    @endpermission
                </x-slot>
            </x-empty-state>
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($steps as $index => $step)
                    @php
                        $meta = $stepTypes[$step->type] ?? ['label' => $step->type, 'summary' => '', 'runs' => ''];
                        $notes = $stepNotes[$step->id] ?? [];
                        $here = (int) ($runsPerStep[$step->id] ?? 0);

                        // The full order with this step moved one place. Built
                        // here so the form posts a complete sequence rather than
                        // an instruction the server has to interpret.
                        $up = $stepIds;
                        if ($index > 0) {
                            [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]];
                        }

                        $down = $stepIds;
                        if ($index < $count - 1) {
                            [$down[$index], $down[$index + 1]] = [$down[$index + 1], $down[$index]];
                        }
                    @endphp

                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600">
                                {{ $step->position }}
                            </span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink-900">
                                    {{ $step->label ?: $meta['label'] }}
                                </p>
                                <span class="kn-badge-gray">{{ $meta['label'] }}</span>

                                @if ($step->type === 'send_email' && (int) $step->sent_count > 0)
                                    <span class="kn-badge-green">{{ number_format((int) $step->sent_count) }} sent</span>
                                @endif

                                @if ($here > 0)
                                    <span class="kn-badge-amber" title="Contacts parked on this step right now">
                                        {{ number_format($here) }} here now
                                    </span>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-ink-600">{{ $describe($step) }}</p>
                            <p class="mt-0.5 text-xs text-ink-500">{{ $meta['runs'] }}</p>

                            @if ($notes !== [])
                                <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-amber-700">
                                    @foreach ($notes as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        @permission('automation.manage')
                            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                @if ($index > 0)
                                    <form method="POST" action="{{ route('automations.steps.reorder', $automation) }}" class="inline">
                                        @csrf
                                        @foreach ($up as $id)
                                            <input type="hidden" name="order[]" value="{{ $id }}">
                                        @endforeach
                                        <button type="submit" class="kn-btn-ghost kn-btn-sm" title="Move up">Up</button>
                                    </form>
                                @endif

                                @if ($index < $count - 1)
                                    <form method="POST" action="{{ route('automations.steps.reorder', $automation) }}" class="inline">
                                        @csrf
                                        @foreach ($down as $id)
                                            <input type="hidden" name="order[]" value="{{ $id }}">
                                        @endforeach
                                        <button type="submit" class="kn-btn-ghost kn-btn-sm" title="Move down">Down</button>
                                    </form>
                                @endif

                                <a href="{{ route('automations.steps.edit', [$automation, $step]) }}"
                                   class="kn-btn-secondary kn-btn-sm">Edit</a>

                                <x-confirm-form :action="route('automations.steps.destroy', [$automation, $step])"
                                                label="Delete"
                                                :message="$here === 0
                                                    ? 'Delete step '.$step->position.'? The remaining steps are renumbered.'
                                                    : 'Delete step '.$step->position.'? '.number_format($here).' contact(s) are waiting on it. Their run is marked completed on the next pass and they will not receive the steps after it.'" />
                            </div>
                        @endpermission
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                The last step ends the run. There is no loop back to the start — re-entry is a setting on the
                automation, triggered by a new event, not something a step can do.
            </div>
        @endif
    </div>

    {{-- ---------------------------------------------------- add a step, typed --}}
    @permission('automation.manage')
        <div class="kn-card mt-5">
            <div class="kn-card-header">
                <h2 class="text-sm font-semibold text-ink-900">What a step can be</h2>
            </div>

            <div class="grid gap-2 p-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($stepTypes as $type => $meta)
                    <a href="{{ route('automations.steps.create', ['automation' => $automation, 'type' => $type]) }}"
                       class="rounded-lg border border-ink-200 px-3 py-2.5 transition hover:border-brand-300 hover:bg-brand-50">
                        <p class="text-sm font-medium text-ink-900">{{ $meta['label'] }}</p>
                        <p class="mt-0.5 text-xs text-ink-600">{{ $meta['summary'] }}</p>
                        <p class="mt-0.5 text-[11px] text-ink-500">{{ $meta['runs'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    @endpermission

    {{-- ------------------------------------------------------------- danger --}}
    @permission('automation.manage')
        <div class="kn-card mt-5 border-red-200">
            <div class="kn-card-body flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-ink-900">Delete this automation</p>
                    <p class="mt-1 text-sm text-ink-600">
                        @if ($liveRuns === 0)
                            Nobody is part-way through it. Email it already sent stays in the logs.
                        @else
                            {{ number_format($liveRuns) }} contact(s) are part-way through. Deleting stops them where
                            they are and records why on each one — they do not receive the rest of the sequence.
                            Email already sent stays in the logs.
                        @endif
                    </p>
                </div>

                <x-confirm-form :action="route('automations.destroy', $automation)"
                                label="Delete automation"
                                button-class="kn-btn-danger"
                                :message="'Delete '.$automation->name.'? '.($liveRuns === 0
                                    ? 'This cannot be undone.'
                                    : number_format($liveRuns).' contact(s) part-way through it will be stopped. This cannot be undone.')" />
            </div>
        </div>
    @endpermission
</x-app-layout>
