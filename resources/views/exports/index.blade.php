<x-app-layout>
    <x-slot name="header">Export contacts</x-slot>

    @php
        /**
         * The screen is one POST form: the browser downloads the CSV straight
         * from the response, so there is no fetch() and no progress polling.
         */
        $hasSelection = ! empty($selectedIds);

        // Arriving from a bulk selection pre-chooses "Selected contacts".
        // The value is echoed into an Alpine expression, which is evaluated as
        // JavaScript, so it is whitelisted rather than merely escaped.
        //
        // A source whose radio is absent (no incoming selection) or disabled
        // (no lists / tags / segments yet) can never post a value, so it must
        // never be the default: the form would bounce on "source is required"
        // with nothing on screen ticked to explain why. "all" always exists,
        // so the fallback is always reachable.
        $availableSources = array_values(array_filter(
            ['all', 'selected', 'list', 'tag', 'segment'],
            fn ($source) => match ($source) {
                'selected' => $hasSelection,
                'list' => $lists->isNotEmpty(),
                'tag' => $tags->isNotEmpty(),
                'segment' => $segments->isNotEmpty(),
                default => true,
            }
        ));

        $defaultSource = in_array(old('source'), $availableSources, true)
            ? old('source')
            : ($hasSelection ? 'selected' : 'all');

        // Standard columns start ticked, custom fields start unticked; a failed
        // validation pass restores exactly what the user had.
        $oldColumns = old('columns');
        $checkedColumns = is_array($oldColumns) ? $oldColumns : array_keys($columns);

        $oldLists = array_map('intval', (array) old('list_ids', []));
        $oldTags = array_map('intval', (array) old('tag_ids', []));

        $columnTotal = count($columns) + count($customFields);
    @endphp

    <x-page-header title="Export contacts"
                   :subtitle="number_format($total).' contacts in this account · exported as UTF-8 CSV'"
                   :back="route('subscribers.index')">
        <x-slot name="actions">
            @permission('contacts.export')
                <a href="{{ route('suppressions.export') }}" class="kn-btn-ghost">Export suppressions</a>
            @endpermission
            @permission('contacts.import')
                <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Imports</a>
            @endpermission
            <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">All contacts</a>
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------------ stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Contacts"
                     :value="number_format($total)"
                     meta="Everything in this account"
                     :href="route('subscribers.index')" />
        <x-stat-card label="Lists"
                     :value="number_format($lists->count())"
                     :meta="$lists->isEmpty() ? 'No lists yet' : 'Export one or many at once'"
                     :href="route('lists.index')" />
        <x-stat-card label="Tags"
                     :value="number_format($tags->count())"
                     :meta="$tags->isEmpty() ? 'No tags yet' : 'Export by tag'"
                     :href="route('tags.index')" />
        <x-stat-card label="Segments"
                     :value="number_format($segments->count())"
                     :meta="$segments->isEmpty() ? 'No segments yet' : 'Rule-based audiences'"
                     :href="route('segments.index')" />
    </div>

    @if ($total === 0 && ! $hasSelection)
        <div class="kn-card">
            <x-empty-state title="Nothing to export yet"
                           message="There are no contacts in this account. Add one by hand or import a CSV, then come back to export.">
                <x-slot name="action">
                    @permission('contacts.create')
                        <a href="{{ route('subscribers.create') }}" class="kn-btn-primary">Add contact</a>
                    @endpermission
                    @permission('contacts.import')
                        <a href="{{ route('imports.create') }}" class="kn-btn-secondary">Import a CSV</a>
                    @endpermission
                </x-slot>
            </x-empty-state>
        </div>
    @else
        <form method="POST"
              action="{{ route('exports.download') }}"
              x-data="{
                  source: '{{ $defaultSource }}',
                  mailableOnly: {{ old('mailable_only') ? 'true' : 'false' }},
                  chosen: 0,
                  sourceLabels: { all: 'All contacts', selected: 'Selected contacts', list: 'Chosen lists', tag: 'Chosen tags', segment: 'A segment' },
                  boxes() { return Array.from(this.$refs.columns.querySelectorAll('input[type=checkbox]')) },
                  setAll(on) { this.boxes().forEach(b => { b.checked = on }); this.count() },
                  count() { this.chosen = this.boxes().filter(b => b.checked).length },
              }"
              x-init="$nextTick(() => count())">
            @csrf

            <div class="grid items-start gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">

                    {{-- ------------------------------------------------ source --}}
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h3 class="text-sm font-semibold text-ink-900">Who do you want to export?</h3>
                            <span class="text-xs text-ink-500">Pick one</span>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-3 sm:grid-cols-2">

                                @if ($hasSelection)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                                           :class="source === 'selected' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-ink-200 bg-white hover:border-ink-300'">
                                        <input type="radio" name="source" value="selected" x-model="source"
                                               @checked($defaultSource === 'selected')
                                               class="kn-checkbox mt-0.5 rounded-full">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-ink-900">Selected contacts</span>
                                            <span class="block text-xs text-ink-500">
                                                {{ number_format(count($selectedIds)) }} contact{{ count($selectedIds) === 1 ? '' : 's' }} you ticked on the contacts screen
                                            </span>
                                        </span>
                                    </label>
                                @endif

                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                                       :class="source === 'all' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-ink-200 bg-white hover:border-ink-300'">
                                    <input type="radio" name="source" value="all" x-model="source"
                                           @checked($defaultSource === 'all')
                                           class="kn-checkbox mt-0.5 rounded-full">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">All contacts</span>
                                        <span class="block text-xs text-ink-500">{{ number_format($total) }} contacts in this account</span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $lists->isEmpty() ? 'cursor-not-allowed opacity-60' : '' }}"
                                       :class="source === 'list' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-ink-200 bg-white hover:border-ink-300'">
                                    <input type="radio" name="source" value="list" x-model="source"
                                           @checked($defaultSource === 'list') @disabled($lists->isEmpty())
                                           class="kn-checkbox mt-0.5 rounded-full">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">A list</span>
                                        <span class="block text-xs text-ink-500">
                                            {{ $lists->isEmpty() ? 'No lists yet' : 'Everyone on one or more lists' }}
                                        </span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $tags->isEmpty() ? 'cursor-not-allowed opacity-60' : '' }}"
                                       :class="source === 'tag' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-ink-200 bg-white hover:border-ink-300'">
                                    <input type="radio" name="source" value="tag" x-model="source"
                                           @checked($defaultSource === 'tag') @disabled($tags->isEmpty())
                                           class="kn-checkbox mt-0.5 rounded-full">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">A tag</span>
                                        <span class="block text-xs text-ink-500">
                                            {{ $tags->isEmpty() ? 'No tags yet' : 'Everyone carrying one or more tags' }}
                                        </span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $segments->isEmpty() ? 'cursor-not-allowed opacity-60' : '' }}"
                                       :class="source === 'segment' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-ink-200 bg-white hover:border-ink-300'">
                                    <input type="radio" name="source" value="segment" x-model="source"
                                           @checked($defaultSource === 'segment') @disabled($segments->isEmpty())
                                           class="kn-checkbox mt-0.5 rounded-full">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">A segment</span>
                                        <span class="block text-xs text-ink-500">
                                            {{ $segments->isEmpty() ? 'No segments yet' : 'Everyone matching a saved rule set' }}
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <x-input-error :messages="$errors->get('source')" class="mt-3" />

                            @if ($hasSelection)
                                {{-- Disabled inputs are not submitted, so the ids only travel
                                     when "Selected contacts" is the chosen source. --}}
                                @foreach ($selectedIds as $selectedId)
                                    <input type="hidden" name="ids[]" value="{{ $selectedId }}"
                                           :disabled="source !== 'selected'">
                                @endforeach

                                <div x-show="source === 'selected'" x-cloak
                                     class="mt-4 rounded-lg border border-ink-200 bg-ink-50 px-4 py-3">
                                    <p class="text-sm text-ink-700">
                                        {{ number_format(count($selectedIds)) }} contact{{ count($selectedIds) === 1 ? '' : 's' }} carried over from your selection.
                                    </p>
                                    <p class="kn-help">
                                        Need a different set?
                                        <a href="{{ route('subscribers.index') }}" class="text-brand-600 hover:text-brand-700">Go back to contacts</a>
                                        and tick the ones you want.
                                    </p>
                                </div>
                                <x-input-error :messages="$errors->get('ids')" class="mt-2" />
                            @endif

                            {{-- lists --}}
                            <div x-show="source === 'list'" x-cloak class="mt-4">
                                @if ($lists->isEmpty())
                                    <div class="rounded-lg border border-ink-200">
                                        <x-empty-state title="No lists yet"
                                                       message="Create a list and add contacts to it, then you can export the list on its own.">
                                            <x-slot name="action">
                                                @permission('contacts.create')
                                                    <a href="{{ route('lists.create') }}" class="kn-btn-primary">Create a list</a>
                                                @endpermission
                                            </x-slot>
                                        </x-empty-state>
                                    </div>
                                @else
                                    <x-input-label for="list_ids" value="Lists to export" />
                                    <select id="list_ids" name="list_ids[]" multiple size="8" class="kn-select"
                                            :disabled="source !== 'list'" :required="source === 'list'">
                                        @foreach ($lists as $list)
                                            <option value="{{ $list->id }}" @selected(in_array($list->id, $oldLists, true))>
                                                {{ $list->name }} ({{ number_format((int) $list->total_count) }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="kn-help">Hold Ctrl (Cmd on a Mac) to pick several. A contact on two chosen lists is exported once.</p>
                                    <x-input-error :messages="$errors->get('list_ids')" />
                                    <x-input-error :messages="$errors->get('list_ids.*')" />
                                @endif
                            </div>

                            {{-- tags --}}
                            <div x-show="source === 'tag'" x-cloak class="mt-4">
                                @if ($tags->isEmpty())
                                    <div class="rounded-lg border border-ink-200">
                                        <x-empty-state title="No tags yet"
                                                       message="Tags label contacts across lists. Add one from a contact or from the tags screen, then export by tag.">
                                            <x-slot name="action">
                                                @permission('contacts.view')
                                                    <a href="{{ route('tags.index') }}" class="kn-btn-secondary">Manage tags</a>
                                                @endpermission
                                            </x-slot>
                                        </x-empty-state>
                                    </div>
                                @else
                                    <x-input-label for="tag_ids" value="Tags to export" />
                                    <select id="tag_ids" name="tag_ids[]" multiple size="8" class="kn-select"
                                            :disabled="source !== 'tag'" :required="source === 'tag'">
                                        @foreach ($tags as $tag)
                                            <option value="{{ $tag->id }}" @selected(in_array($tag->id, $oldTags, true))>
                                                {{ $tag->name }} ({{ number_format((int) $tag->subscribers_count) }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="kn-help">Contacts carrying any of the chosen tags are included, each one only once.</p>
                                    <x-input-error :messages="$errors->get('tag_ids')" />
                                    <x-input-error :messages="$errors->get('tag_ids.*')" />
                                @endif
                            </div>

                            {{-- segment --}}
                            <div x-show="source === 'segment'" x-cloak class="mt-4">
                                @if ($segments->isEmpty())
                                    <div class="rounded-lg border border-ink-200">
                                        <x-empty-state title="No segments yet"
                                                       message="A segment is a saved set of rules, such as everyone in Berlin who opened an email this month.">
                                            <x-slot name="action">
                                                @permission('contacts.create')
                                                    <a href="{{ route('segments.create') }}" class="kn-btn-primary">Create a segment</a>
                                                @endpermission
                                            </x-slot>
                                        </x-empty-state>
                                    </div>
                                @else
                                    <x-input-label for="segment_id" value="Segment to export" />
                                    <select id="segment_id" name="segment_id" class="kn-select sm:max-w-md"
                                            :disabled="source !== 'segment'" :required="source === 'segment'">
                                        <option value="">Choose a segment</option>
                                        @foreach ($segments as $segment)
                                            <option value="{{ $segment->id }}" @selected((int) old('segment_id') === $segment->id)>
                                                {{ $segment->name }} ({{ number_format((int) $segment->cached_count) }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="kn-help">The rules are re-run at export time, so the file matches the segment as it stands right now.</p>
                                    <x-input-error :messages="$errors->get('segment_id')" />
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- ----------------------------------------------- filters --}}
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h3 class="text-sm font-semibold text-ink-900">Narrow it down</h3>
                            <span class="text-xs text-ink-500">Optional</span>
                        </div>

                        <div class="grid gap-5 p-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="status" value="Status" />
                                <select id="status" name="status" class="kn-select">
                                    <option value="">Any status</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected(old('status') === $status)>
                                            {{ ucfirst(str_replace('_', ' ', $status)) }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="kn-help">Leave on "Any status" to export every contact in the chosen source.</p>
                                <x-input-error :messages="$errors->get('status')" />
                            </div>

                            <div>
                                <span class="kn-label">Deliverability</span>
                                <label class="flex items-start gap-3 rounded-lg border border-ink-200 px-3 py-2.5">
                                    {{-- The unchecked box still posts a value, so the server always
                                         sees an explicit true or false. --}}
                                    <input type="hidden" name="mailable_only" value="0">
                                    <input type="checkbox" id="mailable_only" name="mailable_only" value="1"
                                           x-model="mailableOnly" class="kn-checkbox mt-0.5">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">Only contacts we may email</span>
                                        <span class="block text-xs text-ink-500">
                                            Keeps active contacts only, and drops any address on the suppression list.
                                        </span>
                                    </span>
                                </label>
                                <x-input-error :messages="$errors->get('mailable_only')" />
                            </div>
                        </div>
                    </div>

                    {{-- ----------------------------------------------- columns --}}
                    <div class="kn-card">
                        <div class="kn-card-header">
                            <h3 class="text-sm font-semibold text-ink-900">Columns</h3>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" class="kn-btn-secondary kn-btn-sm" @click="setAll(true)">Select all</button>
                                <button type="button" class="kn-btn-ghost kn-btn-sm" @click="setAll(false)">Clear</button>
                            </div>
                        </div>

                        <div class="p-5" x-ref="columns">
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($columns as $key => $label)
                                    <label class="flex items-center gap-2.5 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                                        <input type="checkbox" name="columns[]" value="{{ $key }}"
                                               @checked(in_array($key, $checkedColumns, true))
                                               @change="count()" class="kn-checkbox">
                                        <span class="min-w-0 truncate text-sm text-ink-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @if (count($customFields) > 0)
                                <p class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-ink-500">Custom fields</p>
                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($customFields as $fieldKey => $fieldLabel)
                                        <label class="flex items-center gap-2.5 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                                            <input type="checkbox" name="columns[]" value="custom:{{ $fieldKey }}"
                                                   @checked(in_array('custom:'.$fieldKey, $checkedColumns, true))
                                                   @change="count()" class="kn-checkbox">
                                            <span class="min-w-0 truncate text-sm text-ink-700">{{ $fieldLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="kn-help">
                                    Custom fields start switched off.
                                    @permission('contacts.view')
                                        <a href="{{ route('custom-fields.index') }}" class="text-brand-600 hover:text-brand-700">Manage custom fields</a>.
                                    @endpermission
                                </p>
                            @endif

                            <x-input-error :messages="$errors->get('columns')" class="mt-3" />
                            <x-input-error :messages="$errors->get('columns.*')" />
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------- summary --}}
                <div class="space-y-6">
                    <div class="kn-card lg:sticky lg:top-6">
                        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Your export</h3></div>

                        <div class="space-y-3 p-5 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-ink-600">Source</span>
                                <span class="font-medium text-ink-900" x-text="sourceLabels[source]"></span>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <span class="text-ink-600">Columns</span>
                                <span class="font-medium text-ink-900">
                                    <span x-text="chosen"></span> of {{ number_format($columnTotal) }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <span class="text-ink-600">Format</span>
                                <span class="kn-badge-blue">CSV · UTF-8</span>
                            </div>

                            <p class="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600"
                               x-show="mailableOnly" x-cloak>
                                Only active contacts are written. Pending, unsubscribed, bounced and blocked
                                contacts, and every suppressed address, are left out of this file.
                            </p>

                            <p class="text-xs text-ink-500">
                                The file carries a byte-order mark, so Excel opens accented names correctly instead of showing mojibake.
                            </p>
                        </div>

                        <div class="border-t border-ink-100 p-5">
                            <button type="submit" class="kn-btn-primary w-full" :disabled="chosen === 0">Download CSV</button>
                            <p class="kn-help text-center">
                                The file streams straight to your browser — a large export may take a moment before the download starts.
                            </p>
                            <p class="mt-2 text-center text-xs font-medium text-amber-600" x-show="chosen === 0" x-cloak>
                                Pick at least one column first.
                            </p>
                        </div>
                    </div>

                    <div class="kn-card">
                        <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Good to know</h3></div>
                        <ul class="space-y-2.5 p-5 text-xs text-ink-600">
                            <li>Lists and tags export as one comma-separated cell per contact.</li>
                            <li>Dates are written in full as YYYY-MM-DD HH:MM:SS in UTC.</li>
                            <li>Every export is written to the activity log with the source and column count.</li>
                            <li>Suppressed addresses have their own file on the
                                <a href="{{ route('suppressions.index') }}" class="text-brand-600 hover:text-brand-700">suppressions screen</a>.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </form>
    @endif
</x-app-layout>
