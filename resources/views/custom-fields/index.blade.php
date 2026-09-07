<x-app-layout>
    <x-slot name="header">Custom fields</x-slot>

    @php
        $typeLabels = [
            'text' => 'Text',
            'number' => 'Number',
            'date' => 'Date',
            'select' => 'Dropdown',
            'boolean' => 'Yes / no',
            'url' => 'URL',
        ];

        /*
         * Every row edits inline, so a failed update comes back to this same
         * screen. The forms post a hidden _row (ignored by the form request,
         * which only reads validated() keys) purely so we know which row to
         * re-open and where the error messages belong. No _row means the error
         * came from the create card below the table.
         */
        $editingId = old('_row');
        $createHasErrors = $editingId === null;

        $newType = $createHasErrors ? old('type', 'text') : 'text';
        $newOptions = $createHasErrors
            ? array_values(array_filter((array) old('options', []), 'is_string'))
            : [];
        $newOptions = $newOptions ?: [''];

        $requiredCount = $fields->where('is_required', true)->count();
        $selectCount = $fields->where('type', 'select')->count();

        /*
         * $errors->get('options.*') returns one array of messages per matching
         * key, so it has to be flattened before <x-input-error> can loop it —
         * handing the component nested arrays fatals inside htmlspecialchars().
         */
        $optionErrors = \Illuminate\Support\Arr::flatten($errors->get('options.*'));
    @endphp

    <x-page-header title="Custom fields"
                   subtitle="Extra data you keep on every contact, on top of the built-in name and email."
                   :back="route('subscribers.index')">
        <x-slot name="actions">
            @permission('contacts.import')
                <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Imports</a>
            @endpermission
        </x-slot>
    </x-page-header>

    @unless ($allowed)
        <div class="kn-card mb-6 border-amber-200">
            <div class="kn-card-body sm:flex sm:items-start sm:gap-4">
                <div class="mb-3 grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600 sm:mb-0">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94 2.7 17.06A1.5 1.5 0 0 0 4 19.5h16a1.5 1.5 0 0 0 1.3-2.44L13.66 3.94a1.5 1.5 0 0 0-2.62 0Z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-ink-900">Custom fields are not part of your current plan</h3>
                    <p class="mt-1 text-sm text-ink-600">
                        New fields cannot be added until your plan includes custom subscriber fields. Ask your
                        account owner to upgrade the subscription, or contact support to have the feature enabled
                        on this account.
                    </p>
                    @if ($fields->isNotEmpty())
                        <p class="mt-2 text-sm text-ink-600">
                            The {{ $fields->count() }} field{{ $fields->count() === 1 ? '' : 's' }} already defined below
                            keep working: their values stay on your contacts and are still shown on the contact form.
                            You can still rename or remove them.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endunless

    @if ($fields->isNotEmpty())
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-stat-card label="Fields defined" :value="number_format($fields->count())" meta="Stored on every contact" />
            <x-stat-card label="Required" :value="number_format($requiredCount)"
                         meta="Must be filled in on the contact form" />
            <x-stat-card label="Dropdowns" :value="number_format($selectCount)" meta="Fields with a fixed option list" />
        </div>
    @endif

    <div class="mb-6 rounded-xl border border-brand-100 bg-brand-50 px-5 py-4">
        <p class="text-sm font-semibold text-ink-900">How custom fields work</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink-600">
            <li>Each field's value is stored per contact, so every subscriber can carry its own answer.</li>
            <li>The <span class="font-medium text-ink-700">key</span> — not the label — is what you map CSV columns onto when importing, and what personalisation reads when building an email.</li>
            <li>Renaming a key moves the values already stored on your contacts across to the new key.</li>
            <li>Required fields must be filled in whenever a contact is added or edited here; contacts imported or created before the field existed keep whatever they already have.</li>
        </ul>
    </div>

    <div class="kn-card mb-6 overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">Your fields</h3>
            <span class="text-xs text-ink-500">Lowest order number first</span>
        </div>

        @if ($fields->isEmpty())
            @php
                $emptyMessage = $allowed
                    ? 'Add your first field with the form below — a company name, a plan tier, a renewal date, anything you want to keep against a contact.'
                    : 'Your plan does not include custom subscriber fields, so there is nothing to define here yet.';
            @endphp
            <x-empty-state title="No custom fields yet" :message="$emptyMessage" />
        @else
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Key</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Options</th>
                            <th>Default</th>
                            <th>Order</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>

                    @foreach ($fields as $field)
                        @php
                            $isEditing = $editingId !== null && (string) $editingId === (string) $field->id;
                            $fieldOptions = array_values(array_filter((array) ($field->options ?? []), 'is_string'));

                            $rowType = $isEditing ? old('type', $field->type) : $field->type;
                            $rowName = $isEditing ? old('name', $field->name) : $field->name;
                            $rowKey = $isEditing ? old('key', $field->key) : $field->key;
                            $rowDefault = $isEditing ? old('default_value', $field->default_value) : $field->default_value;
                            $rowSort = $isEditing ? old('sort_order', $field->sort_order) : $field->sort_order;
                            $rowRequired = $isEditing ? (bool) old('is_required', $field->is_required) : (bool) $field->is_required;

                            $rowOptions = $isEditing
                                ? array_values(array_filter((array) old('options', []), 'is_string'))
                                : $fieldOptions;
                            $rowOptions = $rowOptions ?: [''];

                            $optionSummary = $field->type === 'select' ? implode(', ', $fieldOptions) : '';

                            $deleteMessage = 'Delete the field "'.$field->name.'"? Its stored value is removed from every contact in this account and cannot be recovered.';
                        @endphp

                        <tbody x-data="{ open: {{ $isEditing ? 'true' : 'false' }}, type: @js($rowType), options: @js($rowOptions) }">
                            <tr :class="{ 'bg-ink-50': open }">
                                <td class="font-medium text-ink-900">{{ $field->name }}</td>

                                <td><span class="kn-badge-gray font-mono">{{ $field->key }}</span></td>

                                <td class="whitespace-nowrap">{{ $typeLabels[$field->type] ?? ucfirst($field->type) }}</td>

                                <td>
                                    @if ($field->is_required)
                                        <span class="kn-badge-amber">Required</span>
                                    @else
                                        <span class="kn-badge-gray">Optional</span>
                                    @endif
                                </td>

                                <td class="text-ink-600">
                                    @if ($optionSummary !== '')
                                        <span class="block max-w-xs truncate" title="{{ $optionSummary }}">{{ $optionSummary }}</span>
                                    @else
                                        <span class="text-ink-400">—</span>
                                    @endif
                                </td>

                                <td class="text-ink-600">
                                    @if (filled($field->default_value))
                                        <span class="block max-w-xs truncate" title="{{ $field->default_value }}">{{ $field->default_value }}</span>
                                    @else
                                        <span class="text-ink-400">—</span>
                                    @endif
                                </td>

                                <td class="text-xs text-ink-500">{{ $field->sort_order }}</td>

                                <td class="text-right">
                                    @permission('contacts.update', 'contacts.delete')
                                        <div class="flex justify-end gap-1.5">
                                            @permission('contacts.update')
                                                <button type="button"
                                                        class="kn-btn-secondary kn-btn-sm"
                                                        @click="open = ! open"
                                                        :aria-expanded="open ? 'true' : 'false'"
                                                        aria-controls="field-editor-{{ $field->id }}">
                                                    <span x-text="open ? 'Close' : 'Edit'">{{ $isEditing ? 'Close' : 'Edit' }}</span>
                                                </button>
                                            @endpermission

                                            @permission('contacts.delete')
                                                <x-confirm-form :action="route('custom-fields.destroy', $field)"
                                                                label="Delete"
                                                                :message="$deleteMessage" />
                                            @endpermission
                                        </div>
                                    @else
                                        <span class="text-xs text-ink-400">View only</span>
                                    @endpermission
                                </td>
                            </tr>

                            @permission('contacts.update')
                                <tr x-show="open" x-cloak id="field-editor-{{ $field->id }}">
                                    <td colspan="8" class="bg-ink-50 p-0">
                                        <form method="POST" action="{{ route('custom-fields.update', $field) }}" class="p-5">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="_row" value="{{ $field->id }}">

                                            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                                <div>
                                                    <x-input-label :for="'name-'.$field->id" value="Label" />
                                                    <x-text-input :id="'name-'.$field->id" name="name" :value="$rowName" maxlength="100" required />
                                                    <p class="kn-help">Shown on the contact form and in your reports.</p>
                                                    <x-input-error :messages="$isEditing ? $errors->get('name') : []" />
                                                </div>

                                                <div>
                                                    <x-input-label :for="'key-'.$field->id" value="Key" />
                                                    <x-text-input :id="'key-'.$field->id" name="key" :value="$rowKey" maxlength="64" class="font-mono" />
                                                    <p class="kn-help">
                                                        Must start with a letter and contain only lowercase letters, numbers and
                                                        underscores. Renaming it moves the stored values across every contact.
                                                    </p>
                                                    <x-input-error :messages="$isEditing ? $errors->get('key') : []" />
                                                </div>

                                                <div>
                                                    <x-input-label :for="'type-'.$field->id" value="Type" />
                                                    <select id="type-{{ $field->id }}" name="type" class="kn-select" x-model="type">
                                                        @foreach ($typeLabels as $value => $label)
                                                            <option value="{{ $value }}" @selected($rowType === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$isEditing ? $errors->get('type') : []" />
                                                </div>

                                                <template x-if="type === 'select'">
                                                    <div class="sm:col-span-2 lg:col-span-3">
                                                        <span class="kn-label">Dropdown options</span>
                                                        <div class="space-y-2">
                                                            <template x-for="(option, index) in options" :key="index">
                                                                <div class="flex items-center gap-2">
                                                                    <input type="text"
                                                                           class="kn-input"
                                                                           maxlength="100"
                                                                           placeholder="Option label"
                                                                           :name="'options[' + index + ']'"
                                                                           x-model="options[index]">
                                                                    <button type="button"
                                                                            class="kn-btn-ghost kn-btn-sm shrink-0"
                                                                            x-show="options.length > 1"
                                                                            @click="options.splice(index, 1)">Remove</button>
                                                                </div>
                                                            </template>
                                                        </div>
                                                        <button type="button" class="kn-btn-secondary kn-btn-sm mt-2" @click="options.push('')">
                                                            Add option
                                                        </button>
                                                        <p class="kn-help">Contacts can only hold one of these values. Blank rows are dropped when you save.</p>
                                                    </div>
                                                </template>

                                                <div>
                                                    <x-input-label :for="'default-'.$field->id" value="Default value" />
                                                    <x-text-input :id="'default-'.$field->id" name="default_value" :value="$rowDefault" maxlength="255"
                                                                  x-bind:placeholder="type === 'boolean' ? '1 or 0' : (type === 'date' ? 'YYYY-MM-DD' : 'Optional')" />
                                                    <p class="kn-help">Used when a contact is created without a value for this field.</p>
                                                    <x-input-error :messages="$isEditing ? $errors->get('default_value') : []" />
                                                </div>

                                                <div>
                                                    <x-input-label :for="'sort-'.$field->id" value="Order" />
                                                    <x-text-input :id="'sort-'.$field->id" name="sort_order" type="number" min="0" max="9999" :value="$rowSort" />
                                                    <p class="kn-help">Lower numbers appear first on the contact form.</p>
                                                    <x-input-error :messages="$isEditing ? $errors->get('sort_order') : []" />
                                                </div>

                                                <div class="flex items-end">
                                                    <label class="flex items-center gap-2 pb-2 text-sm text-ink-700">
                                                        <input type="hidden" name="is_required" value="0">
                                                        <input type="checkbox" name="is_required" value="1" class="kn-checkbox" @checked($rowRequired)>
                                                        Required on the contact form
                                                    </label>
                                                </div>
                                            </div>

                                            <x-input-error class="mt-3" :messages="$isEditing ? $optionErrors : []" />

                                            <div class="mt-5 flex items-center justify-end gap-3 border-t border-ink-200 pt-4">
                                                <button type="button" class="kn-btn-secondary kn-btn-sm" @click="open = false">Cancel</button>
                                                <button type="submit" class="kn-btn-primary kn-btn-sm">Save field</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endpermission
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    @permission('contacts.update')
        @if ($allowed)
            <form method="POST" action="{{ route('custom-fields.store') }}" class="kn-card"
                  x-data="{ type: @js($newType), options: @js($newOptions) }">
                @csrf

                <div class="kn-card-header">
                    <h3 class="text-sm font-semibold text-ink-900">Add a field</h3>
                    <span class="text-xs text-ink-500">Applies to every contact in this account</span>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <x-input-label for="new_name" value="Label" />
                        <x-text-input id="new_name" name="name" :value="$createHasErrors ? old('name') : ''"
                                      maxlength="100" placeholder="e.g. Company" required />
                        <p class="kn-help">Shown on the contact form and in your reports.</p>
                        <x-input-error :messages="$createHasErrors ? $errors->get('name') : []" />
                    </div>

                    <div>
                        <x-input-label for="new_key" value="Key" />
                        <x-text-input id="new_key" name="key" :value="$createHasErrors ? old('key') : ''"
                                      maxlength="64" placeholder="e.g. company" class="font-mono" />
                        <p class="kn-help">
                            Must start with a letter and contain only lowercase letters, numbers and underscores.
                            Leave it blank and we build it from the label. This is what CSV columns are mapped onto
                            and what personalisation reads.
                        </p>
                        <x-input-error :messages="$createHasErrors ? $errors->get('key') : []" />
                    </div>

                    <div>
                        <x-input-label for="new_type" value="Type" />
                        <select id="new_type" name="type" class="kn-select" x-model="type">
                            @foreach ($typeLabels as $value => $label)
                                <option value="{{ $value }}" @selected($newType === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="kn-help">The type decides how the value is validated on the contact form.</p>
                        <x-input-error :messages="$createHasErrors ? $errors->get('type') : []" />
                    </div>

                    <template x-if="type === 'select'">
                        <div class="sm:col-span-2 lg:col-span-3">
                            <span class="kn-label">Dropdown options</span>
                            <div class="space-y-2">
                                <template x-for="(option, index) in options" :key="index">
                                    <div class="flex items-center gap-2">
                                        <input type="text"
                                               class="kn-input"
                                               maxlength="100"
                                               placeholder="Option label"
                                               :name="'options[' + index + ']'"
                                               x-model="options[index]">
                                        <button type="button"
                                                class="kn-btn-ghost kn-btn-sm shrink-0"
                                                x-show="options.length > 1"
                                                @click="options.splice(index, 1)">Remove</button>
                                    </div>
                                </template>
                            </div>
                            <button type="button" class="kn-btn-secondary kn-btn-sm mt-2" @click="options.push('')">
                                Add option
                            </button>
                            <p class="kn-help">Contacts can only hold one of these values. Blank rows are dropped when you save.</p>
                        </div>
                    </template>

                    <div>
                        <x-input-label for="new_default" value="Default value" />
                        <x-text-input id="new_default" name="default_value" :value="$createHasErrors ? old('default_value') : ''"
                                      maxlength="255"
                                      x-bind:placeholder="type === 'boolean' ? '1 or 0' : (type === 'date' ? 'YYYY-MM-DD' : 'Optional')" />
                        <p class="kn-help">Used when a contact is created without a value for this field.</p>
                        <x-input-error :messages="$createHasErrors ? $errors->get('default_value') : []" />
                    </div>

                    <div>
                        <x-input-label for="new_sort" value="Order" />
                        <x-text-input id="new_sort" name="sort_order" type="number" min="0" max="9999"
                                      :value="$createHasErrors ? old('sort_order', 0) : 0" />
                        <p class="kn-help">Lower numbers appear first on the contact form.</p>
                        <x-input-error :messages="$createHasErrors ? $errors->get('sort_order') : []" />
                    </div>

                    <div class="flex items-end">
                        <label class="flex items-center gap-2 pb-2 text-sm text-ink-700">
                            <input type="hidden" name="is_required" value="0">
                            <input type="checkbox" name="is_required" value="1" class="kn-checkbox"
                                   @checked($createHasErrors && old('is_required'))>
                            Required on the contact form
                        </label>
                    </div>
                </div>

                <x-input-error class="px-5" :messages="$createHasErrors ? $optionErrors : []" />

                <div class="flex items-center justify-between gap-4 border-t border-ink-100 px-5 py-3">
                    <p class="text-xs text-ink-500">Contacts you already have keep an empty value until it is filled in or imported.</p>
                    <button type="submit" class="kn-btn-primary">Add field</button>
                </div>
            </form>
        @endif
    @endpermission
</x-app-layout>
