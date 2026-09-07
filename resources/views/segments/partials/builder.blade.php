{{--
    Segment rule builder.

    Included by segments/create and segments/edit, from INSIDE the form: it
    renders the hidden "rules" input (a JSON string, which SegmentRequest
    decodes) and the match_type radios, so the parent form needs nothing else.

    The initial state is built in the single-line PHP blocks below and handed
    to Alpine on one line — a multi-line array literal inside an HTML attribute
    breaks the Blade parser. Nothing in this comment may name a Blade directive
    either: raw PHP blocks are extracted before comments are stripped, so a
    directive name written here would swallow the top of the file.
--}}

@php $builderRules = old('rules', $segment->rules ?? []); @endphp
@php $builderRules = is_string($builderRules) ? (json_decode($builderRules, true) ?: []) : (array) $builderRules; @endphp
@php $builderState = ['rules' => array_values(array_filter($builderRules, 'is_array')), 'matchType' => old('match_type', $segment->match_type ?: 'all')]; @endphp
@php $builderGroups = collect($fields)->groupBy('group'); @endphp
@php $builderKnown = collect($fields)->keyBy('key'); @endphp
@php $builderDropped = collect($builderState['rules'])->reject(fn ($r) => is_string($r['field'] ?? null) && $builderKnown->has($r['field']) && in_array($r['operator'] ?? null, $builderKnown[$r['field']]['operators'] ?? [], true))->count(); @endphp

<div x-data="knSegmentBuilder(@js($builderState), @js($fields), @js(route('segments.preview')))" class="grid gap-6 lg:grid-cols-5">

    {{-- ------------------------------------------------------- conditions --}}
    <div class="kn-card lg:col-span-3">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Conditions</h3>
            <span class="text-xs text-ink-500">
                <span x-text="rules.length"></span> condition<span x-show="rules.length !== 1">s</span>
            </span>
        </div>

        <div class="space-y-4 p-5">
            {{-- Match type. Real radios, so the value posts with the form. --}}
            <div class="flex flex-wrap gap-2">
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition"
                       :class="matchType === 'all' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600 hover:bg-ink-50'">
                    <input type="radio" name="match_type" value="all" x-model="matchType" class="kn-checkbox rounded-full">
                    Match ALL conditions
                </label>

                <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition"
                       :class="matchType === 'any' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-600 hover:bg-ink-50'">
                    <input type="radio" name="match_type" value="any" x-model="matchType" class="kn-checkbox rounded-full">
                    Match ANY condition
                </label>
            </div>

            <p class="kn-help">
                <span x-show="matchType === 'all'">A contact must satisfy every condition below to be in this segment.</span>
                <span x-show="matchType === 'any'" x-cloak>A contact only has to satisfy one of the conditions below.</span>
            </p>

            <x-input-error :messages="$errors->get('match_type')" />

            {{--
                A stored rule whose filter or condition no longer exists is
                dropped when the builder loads. It was already being ignored
                when the segment ran, so nobody's membership changes — but
                saving writes the loss to the record, so say it out loud.
            --}}
            @if ($builderDropped > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-800">
                        {{ $builderDropped === 1
                            ? '1 saved condition could not be loaded'
                            : $builderDropped.' saved conditions could not be loaded' }}
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        The filter it named, or the condition it used, is no longer available — a deleted
                        custom field is the usual cause — so it is not listed below. It was already being
                        ignored whenever this segment ran, so nobody moves in or out of the segment; saving
                        this form removes it from the record for good.
                    </p>
                </div>
            @endif

            {{-- Rows --}}
            <template x-for="(rule, index) in rules" :key="rule._id">
                <div class="rounded-lg border border-ink-200 bg-ink-50/60 p-3">
                    <div class="mb-2 flex items-center justify-between gap-2" x-show="index > 0" x-cloak>
                        <span class="kn-badge-gray" x-text="matchType === 'all' ? 'AND' : 'OR'"></span>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-12">
                        {{-- Field --}}
                        <div class="sm:col-span-4">
                            <label class="sr-only" :for="'rule-field-' + rule._id">Filter</label>
                            <select class="kn-select" :id="'rule-field-' + rule._id"
                                    x-model="rule.field" @change="onFieldChange(index)">
                                @foreach ($builderGroups as $groupName => $groupFields)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach ($groupFields as $field)
                                            <option value="{{ $field['key'] }}">{{ $field['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        {{-- Operator --}}
                        <div class="sm:col-span-3">
                            <label class="sr-only" :for="'rule-op-' + rule._id">Condition</label>
                            <select class="kn-select" :id="'rule-op-' + rule._id"
                                    x-model="rule.operator" @change="onOperatorChange(index)"
                                    x-init="$nextTick(() => syncOperatorSelect(rule, $el))">
                                <template x-for="op in operatorsFor(rule.field)" :key="op">
                                    <option :value="op" x-text="operatorLabel(op)"></option>
                                </template>
                            </select>
                        </div>

                        {{-- Value: shape follows the field's input type and the operator --}}
                        <div class="sm:col-span-4">
                            <template x-if="inputFor(rule) === 'text'">
                                <input type="text" class="kn-input" maxlength="191"
                                       x-model="rule.value" placeholder="Value to match"
                                       :aria-label="labelFor(rule.field) + ' value'">
                            </template>

                            <template x-if="inputFor(rule) === 'select'">
                                <select class="kn-select" x-model="rule.value"
                                        :aria-label="labelFor(rule.field) + ' value'"
                                        x-init="$nextTick(() => syncValueSelect(rule, $el))">
                                    <template x-for="option in optionsFor(rule)" :key="option.value">
                                        <option :value="option.value" x-text="option.label"></option>
                                    </template>
                                </select>
                            </template>

                            <template x-if="inputFor(rule) === 'date'">
                                <input type="date" class="kn-input" x-model="rule.value"
                                       :aria-label="labelFor(rule.field) + ' date'">
                            </template>

                            <template x-if="inputFor(rule) === 'days'">
                                <div class="flex items-center gap-2">
                                    <input type="number" min="0" max="3650" step="1" class="kn-input"
                                           x-model.number="rule.value"
                                           :aria-label="labelFor(rule.field) + ' number of days'">
                                    <span class="shrink-0 text-xs font-medium text-ink-500">days</span>
                                </div>
                            </template>

                            <template x-if="inputFor(rule) === 'none'">
                                <p class="flex h-full items-center text-xs text-ink-500">No value needed.</p>
                            </template>
                        </div>

                        {{-- Remove --}}
                        <div class="flex items-start justify-end sm:col-span-1">
                            <button type="button" class="kn-btn-ghost kn-btn-sm text-red-600 hover:bg-red-50 hover:text-red-700"
                                    @click="removeRule(index)"
                                    :aria-label="'Remove condition ' + (index + 1)">
                                Remove
                            </button>
                        </div>
                    </div>

                    <p class="mt-2 text-xs text-ink-500" x-show="describeRule(rule)" x-cloak x-text="describeRule(rule)"></p>
                </div>
            </template>

            {{-- No conditions --}}
            <div x-show="rules.length === 0" x-cloak
                 class="rounded-lg border border-dashed border-ink-300 px-4 py-6 text-center">
                <p class="text-sm font-medium text-ink-700">No conditions yet</p>
                <p class="mx-auto mt-1 max-w-sm text-xs text-ink-500">
                    A segment with no conditions matches every contact in your account. Add at least one
                    condition before saving.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="kn-btn-secondary kn-btn-sm" @click="addRule()">Add condition</button>
                <button type="button" class="kn-btn-ghost kn-btn-sm" @click="clearRules()" x-show="rules.length > 0" x-cloak>
                    Remove all
                </button>
            </div>

            {{-- What actually posts. SegmentRequest accepts this JSON string. --}}
            <input type="hidden" name="rules" :value="rulesJson">

            <x-input-error :messages="$errors->get('rules')" />
            {{-- Nested rules.N.* errors, named by the row they belong to. --}}
            @foreach ($errors->keys() as $errorKey)
                @if (str_starts_with($errorKey, 'rules.'))
                    @php $ruleRow = (int) (explode('.', $errorKey)[1] ?? 0) + 1; @endphp
                    <p class="kn-error">Condition {{ $ruleRow }}: {{ $errors->first($errorKey) }}</p>
                @endif
            @endforeach

            <noscript>
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    The rule builder needs JavaScript. Turn it on to add or change conditions.
                </p>
            </noscript>
        </div>
    </div>

    {{-- ---------------------------------------------------------- preview --}}
    <div class="lg:col-span-2">
        <div class="kn-card lg:sticky lg:top-6">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Live preview</h3>
                <button type="button" class="kn-btn-ghost kn-btn-sm" @click="refresh()" :disabled="loading">
                    <span x-show="! loading">Refresh</span>
                    <span x-show="loading" x-cloak>Counting…</span>
                </button>
            </div>

            <div class="p-5">
                <p class="kn-stat-label">Contacts matched</p>

                <div class="mt-1 h-9">
                    <div x-show="loading" x-cloak class="kn-skeleton h-8 w-28"></div>
                    <p x-show="! loading" class="text-3xl font-semibold text-ink-900"
                       x-text="count === null ? '—' : count.toLocaleString()"></p>
                </div>

                <p class="kn-help">
                    Counts every contact matching the conditions, including unsubscribed and bounced ones.
                    <span x-show="appliedCount > 0" x-cloak>
                        <span x-text="appliedCount"></span> condition<span x-show="appliedCount !== 1">s</span> applied.
                    </span>
                </p>

                {{-- A silently dropped rule would give a wrong count, so say so. --}}
                <div x-show="ignored.length > 0" x-cloak
                     class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-800">
                        <span x-text="ignored.length"></span> condition<span x-show="ignored.length !== 1">s</span> ignored
                    </p>
                    <p class="mt-1 text-xs text-amber-700">
                        These were dropped before counting — the number above does not include them.
                        The filter or its condition is no longer available. Fix or remove them before saving.
                    </p>
                    <ul class="mt-2 space-y-1">
                        <template x-for="(dropped, i) in ignored" :key="i">
                            <li class="font-mono text-xs text-amber-900" x-text="dropped"></li>
                        </template>
                    </ul>
                </div>

                <div x-show="error !== ''" x-cloak
                     class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-xs font-medium text-red-700" x-text="error"></p>
                </div>

                <div x-show="! loading && count === 0 && error === ''" x-cloak
                     class="mt-4 rounded-lg border border-dashed border-ink-300 px-4 py-5 text-center">
                    <p class="text-sm font-medium text-ink-700">Nothing matches yet</p>
                    <p class="mt-1 text-xs text-ink-500">Loosen a condition, or switch to “Match ANY condition”.</p>
                </div>

                <div x-show="sample.length > 0" x-cloak class="mt-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Sample of the newest matches</p>
                    <div class="overflow-x-auto">
                        <table class="kn-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Country</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="row in sample" :key="row.id">
                                    <tr>
                                        <td class="max-w-[12rem] truncate" x-text="row.email"></td>
                                        <td class="max-w-[9rem] truncate" x-text="row.name || '—'"></td>
                                        <td><span :class="statusClass(row.status)" x-text="statusLabel(row.status)"></span></td>
                                        <td class="whitespace-nowrap" x-text="row.country || '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            @verbatim
            /**
             * Alpine component behind the segment rule builder.
             *
             * Defined as a classic script so it exists before Alpine.start()
             * runs from the deferred module bundle.
             */
            window.knSegmentBuilder = (state, fields, previewUrl) => ({
                fields: fields,
                previewUrl: previewUrl,
                rules: [],
                matchType: state.matchType === 'any' ? 'any' : 'all',
                count: null,
                appliedCount: 0,
                loading: false,
                ignored: [],
                sample: [],
                error: '',
                uid: 0,
                timer: null,

                operatorLabels: {
                    is: 'is',
                    is_not: 'is not',
                    in: 'is one of',
                    contains: 'contains',
                    not_contains: 'does not contain',
                    starts_with: 'starts with',
                    ends_with: 'ends with',
                    is_set: 'has a value',
                    is_not_set: 'is empty',
                    before: 'is before',
                    after: 'is after',
                    on: 'is on',
                    in_last_days: 'in the last',
                    not_in_last_days: 'not in the last',
                },

                init() {
                    this.rules = (state.rules || [])
                        .filter((rule) => rule && this.field(rule.field))
                        .map((rule) => this.seedRule(rule));

                    if (this.rules.length === 0) {
                        this.addRule();
                    }

                    this.$watch('rules', () => this.schedule());
                    this.$watch('matchType', () => this.schedule());

                    this.refresh();
                },

                // ------------------------------------------------ descriptors

                field(key) {
                    return this.fields.find((entry) => entry.key === key) || null;
                },

                labelFor(key) {
                    const descriptor = this.field(key);

                    return descriptor ? descriptor.label : key;
                },

                operatorsFor(key) {
                    const descriptor = this.field(key);

                    return descriptor ? descriptor.operators : [];
                },

                operatorLabel(operator) {
                    return this.operatorLabels[operator] || String(operator).replace(/_/g, ' ');
                },

                optionsFor(rule) {
                    const descriptor = this.field(rule.field);

                    return descriptor && Array.isArray(descriptor.options) ? descriptor.options : [];
                },

                choiceInputs() {
                    return ['select', 'list', 'tag', 'campaign', 'boolean'];
                },

                /** Which value control this rule needs right now. */
                inputFor(rule) {
                    const descriptor = this.field(rule.field);

                    if (!descriptor) return 'text';
                    if (rule.operator === 'is_set' || rule.operator === 'is_not_set') return 'none';

                    if (descriptor.input === 'date') {
                        return this.isDayOperator(rule.operator) ? 'days' : 'date';
                    }

                    return this.choiceInputs().includes(descriptor.input) ? 'select' : 'text';
                },

                isDayOperator(operator) {
                    return operator === 'in_last_days' || operator === 'not_in_last_days';
                },

                // ------------------------------------------------------ rules

                seedRule(rule) {
                    const seeded = {
                        _id: ++this.uid,
                        field: rule.field,
                        operator: this.operatorsFor(rule.field).includes(rule.operator)
                            ? rule.operator
                            : (this.operatorsFor(rule.field)[0] || ''),
                        value: rule.value === undefined ? '' : rule.value,
                    };

                    // Option values are strings, so a numeric id from an older
                    // save must be normalised or the select shows the wrong row.
                    if (this.inputFor(seeded) === 'select' && seeded.value !== null) {
                        seeded.value = String(seeded.value);
                    }

                    if (this.inputFor(seeded) === 'none') {
                        seeded.value = null;
                    }

                    return seeded;
                },

                defaultValue(descriptor, operator) {
                    if (!descriptor) return '';
                    if (operator === 'is_set' || operator === 'is_not_set') return null;

                    if (descriptor.input === 'date' && this.isDayOperator(operator)) {
                        return 30;
                    }

                    if (this.choiceInputs().includes(descriptor.input)) {
                        return descriptor.options && descriptor.options.length
                            ? String(descriptor.options[0].value)
                            : '';
                    }

                    return '';
                },

                addRule() {
                    const descriptor = this.fields[0];

                    if (!descriptor) return;

                    const operator = descriptor.operators[0] || '';

                    this.rules.push({
                        _id: ++this.uid,
                        field: descriptor.key,
                        operator: operator,
                        value: this.defaultValue(descriptor, operator),
                    });
                },

                removeRule(index) {
                    this.rules.splice(index, 1);
                },

                clearRules() {
                    this.rules = [];
                },

                /** Changing the field resets the operator and the value. */
                onFieldChange(index) {
                    const rule = this.rules[index];
                    const descriptor = this.field(rule.field);

                    rule.operator = descriptor ? (descriptor.operators[0] || '') : '';
                    rule.value = this.defaultValue(descriptor, rule.operator);
                },

                onOperatorChange(index) {
                    const rule = this.rules[index];

                    rule.value = this.defaultValue(this.field(rule.field), rule.operator);
                },

                /**
                 * A <select> whose <option> list is built by x-for can be
                 * painted after x-model has already applied the value, which
                 * would leave the control blank while the model held a real
                 * choice. These run on the next tick and settle the two.
                 */
                syncOperatorSelect(rule, el) {
                    if (this.operatorsFor(rule.field).includes(rule.operator)) {
                        el.value = rule.operator;
                    } else {
                        rule.operator = el.value;
                    }
                },

                syncValueSelect(rule, el) {
                    const match = this.optionsFor(rule)
                        .find((option) => String(option.value) === String(rule.value));

                    if (match) {
                        el.value = String(match.value);
                    } else {
                        // Nothing stored (or the stored choice is gone): adopt
                        // whatever the browser is showing so the two agree.
                        rule.value = el.value;
                    }
                },

                describeRule(rule) {
                    const descriptor = this.field(rule.field);

                    if (!descriptor || !rule.operator) return '';

                    const shape = this.inputFor(rule);

                    if (shape === 'none') {
                        return descriptor.label + ' ' + this.operatorLabel(rule.operator);
                    }

                    if (rule.value === null || rule.value === '') return '';

                    let shown = rule.value;

                    if (shape === 'select') {
                        const option = this.optionsFor(rule).find((entry) => String(entry.value) === String(rule.value));
                        shown = option ? option.label : rule.value;
                    }

                    if (shape === 'days') {
                        shown = shown + ' days';
                    }

                    return descriptor.label + ' ' + this.operatorLabel(rule.operator) + ' ' + shown;
                },

                // ---------------------------------------------------- payload

                get payloadRules() {
                    return this.rules
                        .filter((rule) => rule.field && rule.operator)
                        .map((rule) => ({
                            field: rule.field,
                            operator: rule.operator,
                            value: this.inputFor(rule) === 'none' ? null : rule.value,
                        }));
                },

                get rulesJson() {
                    return JSON.stringify(this.payloadRules);
                },

                // ---------------------------------------------------- preview

                schedule() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.refresh(), 400);
                },

                csrfToken() {
                    const meta = document.querySelector('meta[name="csrf-token"]');

                    return meta ? meta.getAttribute('content') : '';
                },

                async refresh() {
                    clearTimeout(this.timer);
                    this.loading = true;
                    this.error = '';

                    try {
                        const response = await fetch(this.previewUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrfToken(),
                            },
                            body: JSON.stringify({
                                match_type: this.matchType,
                                rules: this.payloadRules,
                            }),
                        });

                        if (!response.ok) {
                            throw new Error('Preview failed with status ' + response.status);
                        }

                        const data = await response.json();

                        this.count = data.count;
                        this.appliedCount = data.rules_applied || 0;
                        this.ignored = data.rules_ignored || [];
                        this.sample = data.sample || [];
                    } catch (failure) {
                        this.count = null;
                        this.appliedCount = 0;
                        this.ignored = [];
                        this.sample = [];
                        this.error = 'Could not refresh the preview. Check your connection, then press Refresh.';
                    } finally {
                        this.loading = false;
                    }
                },

                statusClass(status) {
                    const tones = {
                        active: 'kn-badge-green',
                        pending: 'kn-badge-amber',
                        unsubscribed: 'kn-badge-gray',
                        bounced: 'kn-badge-amber',
                        blocked: 'kn-badge-red',
                    };

                    return tones[status] || 'kn-badge-gray';
                },

                statusLabel(status) {
                    const text = String(status || '');

                    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '—';
                },
            });
            @endverbatim
        </script>
    @endpush
@endonce
