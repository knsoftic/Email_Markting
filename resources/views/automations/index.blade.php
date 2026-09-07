<x-app-layout>
    <x-slot name="header">Automations</x-slot>

    @php
        /**
         * Status chips come from the grouped counts the controller passes, so a
         * status only appears once something is actually in it. "All" is the sum
         * of the same collection rather than a second query.
         */
        $search = (string) ($filters['q'] ?? '');
        $activeStatus = (string) ($filters['status'] ?? '');
        $activeTrigger = (string) ($filters['trigger'] ?? '');
        $hasFilters = $search !== '' || $activeStatus !== '' || $activeTrigger !== '';

        // Everything except the status, so a chip keeps the current search.
        $keepQuery = array_filter([
            'q' => $search !== '' ? $search : null,
            'trigger' => $activeTrigger !== '' ? $activeTrigger : null,
        ]);

        $total = (int) $statusCounts->sum();

        $statusNames = [
            'draft' => 'Drafts',
            'active' => 'Active',
            'paused' => 'Paused',
            'completed' => 'Completed',
        ];

        // Full literal class lists — never assembled from fragments, so Tailwind
        // still sees every class it has to compile.
        $chipBase = 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition';
        $chipOn = 'bg-brand-600 text-white ring-brand-600';
        $chipOff = 'bg-white text-ink-600 ring-ink-200 hover:bg-ink-50 hover:text-ink-900';
        $chipCountOn = 'rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-semibold text-white';
        $chipCountOff = 'rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-600';

        $canAdd = $automationsAllowed
            && ($automationLimit === null || $automationsUsed < (int) $automationLimit);

        $subtitle = $total === 1
            ? '1 automation in this account'
            : number_format($total).' automations in this account';
    @endphp

    <x-page-header title="Automations" :subtitle="$subtitle">
        <x-slot name="actions">
            @permission('automation.manage')
                @if ($canAdd)
                    <a href="{{ route('automations.create') }}" class="kn-btn-primary">New automation</a>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- --------------------------------------------------------- plan state --}}
    @unless ($automationsAllowed)
        <div class="kn-card mb-5 border-amber-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">Automations are not part of this plan</p>
                <p class="mt-1 text-sm text-ink-600">
                    Existing automations are still listed and can be paused or deleted, but new ones cannot be
                    created and none can be activated until the plan includes automations.
                </p>
            </div>
        </div>
    @elseif ($automationLimit !== null && $automationsUsed >= (int) $automationLimit)
        <div class="kn-card mb-5 border-amber-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-ink-900">
                    You are using all {{ number_format((int) $automationLimit) }} automation(s) your plan allows
                </p>
                <p class="mt-1 text-sm text-ink-600">
                    Delete one you no longer need, or upgrade the plan, to create another. The ones you already have
                    keep running.
                </p>
            </div>
        </div>
    @endunless

    {{-- ------------------------------------------------------- status chips --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('automations.index', $keepQuery) }}"
           class="{{ $chipBase }} {{ $activeStatus === '' ? $chipOn : $chipOff }}">
            All
            <span class="{{ $activeStatus === '' ? $chipCountOn : $chipCountOff }}">{{ number_format($total) }}</span>
        </a>

        @foreach ($statusNames as $status => $label)
            @continue (! $statusCounts->has($status))
            @php $isOn = $activeStatus === $status; @endphp

            <a href="{{ route('automations.index', array_merge($keepQuery, ['status' => $status])) }}"
               class="{{ $chipBase }} {{ $isOn ? $chipOn : $chipOff }}">
                {{ $label }}
                <span class="{{ $isOn ? $chipCountOn : $chipCountOff }}">
                    {{ number_format((int) $statusCounts[$status]) }}
                </span>
            </a>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ filters --}}
    <form method="GET" action="{{ route('automations.index') }}" class="kn-card mb-5">
        @if ($activeStatus !== '')
            <input type="hidden" name="status" value="{{ $activeStatus }}">
        @endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-text-input name="q" :value="$search" placeholder="Search by name or description" />
            </div>

            <div>
                <select name="trigger" class="kn-select">
                    <option value="">Any trigger</option>
                    @foreach ($triggerLabels as $value => $meta)
                        <option value="{{ $value }}" @selected($activeTrigger === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Filter</button>
                @if ($hasFilters)
                    <a href="{{ route('automations.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- -------------------------------------------------------------- table --}}
    <div class="kn-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="kn-table">
                <thead>
                    <tr>
                        <th>Automation</th>
                        <th class="min-w-[14rem]">Starts when</th>
                        <th>Status</th>
                        <th>Steps</th>
                        <th>Entered</th>
                        <th>Completed</th>
                        <th>Emails sent</th>
                        <th>Last activity</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($automations as $automation)
                        @php
                            $trigger = $triggers[$automation->id] ?? ['summary' => 'Unknown trigger', 'detail' => ''];
                            $reasons = $blockers[$automation->id] ?? [];
                            $steps = (int) $automation->steps_count;
                            $entered = (int) $automation->entered_count;
                            $completed = (int) $automation->completed_count;

                            // withMax() returns the raw column value, and a row
                            // with no runs returns null.
                            $lastRun = $automation->runs_max_updated_at
                                ? \Illuminate\Support\Carbon::parse($automation->runs_max_updated_at)
                                : null;

                            $canActivate = in_array($automation->status, ['draft', 'paused', 'completed'], true)
                                && $automationsAllowed
                                && $reasons === [];
                        @endphp

                        <tr>
                            {{-- name --}}
                            <td>
                                <div class="min-w-0 max-w-xs">
                                    <a href="{{ route('automations.show', $automation) }}"
                                       class="block truncate font-medium text-ink-900 hover:text-brand-600">
                                        {{ $automation->name }}
                                    </a>
                                    @if ($automation->description)
                                        <span class="block truncate text-xs text-ink-500">{{ $automation->description }}</span>
                                    @endif
                                </div>
                            </td>

                            {{-- trigger, in plain words --}}
                            <td>
                                <span class="block text-sm text-ink-800">{{ $trigger['summary'] }}</span>
                                <span class="block max-w-xs text-xs text-ink-500">{{ $trigger['detail'] }}</span>
                            </td>

                            {{-- status --}}
                            <td>
                                <x-status-badge :status="$automation->status" />
                                @if ($reasons !== [])
                                    <a href="{{ route('automations.show', $automation) }}"
                                       class="mt-1 block text-[11px] font-medium text-amber-700 hover:text-amber-900">
                                        {{ count($reasons) }} thing(s) to fix
                                    </a>
                                @endif
                            </td>

                            {{-- steps --}}
                            <td class="whitespace-nowrap">
                                @if ($steps === 0)
                                    <span class="text-xs text-ink-400">None yet</span>
                                @else
                                    <span class="font-medium text-ink-900">{{ $steps }}</span>
                                    <span class="block text-xs text-ink-500">
                                        {{ (int) $automation->send_steps_count }} send email
                                    </span>
                                @endif
                            </td>

                            {{-- entered --}}
                            <td class="whitespace-nowrap font-medium text-ink-900">{{ number_format($entered) }}</td>

                            {{-- completed --}}
                            <td class="whitespace-nowrap">
                                @if ($entered === 0)
                                    {{-- A percentage of nobody is not zero per cent, it is nothing at all. --}}
                                    <span class="text-ink-400">—</span>
                                @else
                                    <span class="font-medium text-ink-900">{{ number_format($completed) }}</span>
                                    <span class="block text-xs text-ink-500">
                                        {{ (int) round($completed / max(1, $entered) * 100) }}% of entries
                                    </span>
                                @endif
                            </td>

                            {{-- emails sent --}}
                            <td class="whitespace-nowrap">
                                @if ((int) $automation->send_steps_count === 0)
                                    <span class="text-xs text-ink-400" title="This automation has no email steps">
                                        Sends none
                                    </span>
                                @else
                                    <span class="font-medium text-ink-900">{{ number_format((int) $automation->emails_sent) }}</span>
                                @endif
                            </td>

                            {{-- last activity --}}
                            <td class="whitespace-nowrap">
                                @if ($lastRun)
                                    <span class="text-xs text-ink-600" title="{{ $lastRun->format('D, d M Y H:i') }}">
                                        {{ $lastRun->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-xs text-ink-400">Nobody has entered it</span>
                                @endif
                            </td>

                            {{-- actions --}}
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a href="{{ route('automations.show', $automation) }}" class="kn-btn-ghost kn-btn-sm">Open</a>

                                    @permission('automation.view')
                                        <a href="{{ route('automations.runs', $automation) }}" class="kn-btn-ghost kn-btn-sm">Activity</a>
                                    @endpermission

                                    @permission('automation.manage')
                                        @if ($automation->status === 'active')
                                            <form method="POST" action="{{ route('automations.pause', $automation) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="kn-btn-secondary kn-btn-sm">Pause</button>
                                            </form>
                                        @elseif ($canActivate)
                                            {{-- The controller runs a deeper check than a list
                                                 screen can (every step's subject and content) and
                                                 sends you to the automation with the reasons if it
                                                 finds anything, so this never silently no-ops. --}}
                                            <form method="POST" action="{{ route('automations.activate', $automation) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="kn-btn-primary kn-btn-sm">Activate</button>
                                            </form>
                                        @else
                                            <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary kn-btn-sm">
                                                Fix before activating
                                            </a>
                                        @endif

                                        @if ($canAdd)
                                            <form method="POST" action="{{ route('automations.duplicate', $automation) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="kn-btn-ghost kn-btn-sm">Duplicate</button>
                                            </form>
                                        @endif

                                        <x-confirm-form :action="route('automations.destroy', $automation)"
                                                        label="Delete"
                                                        :message="'Delete '.$automation->name.'? Contacts part-way through it are stopped where they are. Email it already sent stays in the logs.'" />
                                    @endpermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                @if ($hasFilters)
                                    <x-empty-state title="No automations match this search"
                                                   message="Nothing here matches that name, status or trigger.">
                                        <x-slot name="action">
                                            <a href="{{ route('automations.index') }}" class="kn-btn-secondary">Clear filters</a>
                                        </x-slot>
                                    </x-empty-state>
                                @else
                                    <x-empty-state title="No automations yet"
                                                   message="An automation watches for something happening — a tag, a list, an open — and then walks that one contact through a sequence of steps at its own pace. Nothing is sent until you activate it.">
                                        <x-slot name="action">
                                            @permission('automation.manage')
                                                @if ($canAdd)
                                                    <a href="{{ route('automations.create') }}" class="kn-btn-primary">Create automation</a>
                                                @endif
                                            @endpermission
                                        </x-slot>
                                    </x-empty-state>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($automations->hasPages())
            <div class="border-t border-ink-100 px-5 py-3">{{ $automations->links() }}</div>
        @endif
    </div>
</x-app-layout>
