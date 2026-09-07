<x-app-layout>
    <x-slot name="header">Map columns</x-slot>

    @php
        /*
         * Seed for the Alpine state. old() is read out of the raw array rather
         * than with dot notation, because a CSV header is free text and may
         * legitimately contain a dot ("user.email"), which dot notation would
         * misread as a nested key.
         */
        $oldMapping = is_array(old('mapping')) ? old('mapping') : [];

        $mapState = [];
        foreach ($headers as $header) {
            $mapState[$header] = array_key_exists($header, $oldMapping)
                ? (string) $oldMapping[$header]
                : ($guessed[$header] ?? 'ignore');
        }

        // Field keys are what the form posts; these labels are what the live
        // Alpine warnings show, so a duplicate never reads as "first_name".
        $targetLabels = $targets;
        foreach ($customFields as $customField) {
            $targetLabels['custom:'.$customField->key] = $customField->name;
        }

        $options = $import->options ?? [];
        $duplicatesChoice = old('duplicates', $options['duplicates'] ?? 'skip');
        $statusChoice = old('status', $options['status'] ?? 'active');
        $consentChoice = old('consent_status', $options['consent_status'] ?? 'unknown');
        $sourceValue = old('source', $options['source'] ?? '');

        $selectedLists = array_map('strval', (array) old('list_ids', $import->list_ids ?? []));
        $selectedTags = array_map('strval', (array) old('tag_ids', $import->tag_ids ?? []));

        // Tag colours are user input, so only a real hex reaches the style attribute.
        $swatch = fn ($color) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? $color : '#64748b';

        $steps = [
            1 => ['label' => 'Upload the file', 'hint' => $import->original_name],
            2 => ['label' => 'Map the columns', 'hint' => count($headers).' '.\Illuminate\Support\Str::plural('column', count($headers)).' found'],
            3 => ['label' => 'Review and import', 'hint' => 'Watch it run'],
        ];
        $currentStep = 2;
    @endphp

    <x-page-header title="Map your columns"
                   :subtitle="'Step 2 of 3 — tell us what each column in '.$import->original_name.' holds. Nothing is written to your contacts until you start the import on the next screen.'"
                   :back="route('imports.index')">
        <x-slot name="actions">
            <a href="{{ route('imports.create') }}" class="kn-btn-secondary">Upload a different file</a>
            @permission('contacts.import')
                <x-confirm-form :action="route('imports.destroy', $import)"
                                label="Discard"
                                button-class="kn-btn-ghost"
                                :message="'Discard this upload? The file is deleted and nothing is imported.'" />
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------ step indicator --}}
    <ol class="mb-6 grid gap-3 sm:grid-cols-3">
        @foreach ($steps as $number => $step)
            @php $state = $number === $currentStep ? 'current' : ($number < $currentStep ? 'done' : 'todo'); @endphp
            <li class="flex items-center gap-3 rounded-lg border px-4 py-3
                       {{ $state === 'current' ? 'border-brand-200 bg-brand-50' : 'border-ink-200 bg-white' }}">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold
                             {{ $state === 'current' ? 'bg-brand-600 text-white' : ($state === 'done' ? 'bg-emerald-100 text-emerald-700' : 'bg-ink-100 text-ink-500') }}">
                    {{ $state === 'done' ? '✓' : $number }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium {{ $state === 'current' ? 'text-brand-800' : 'text-ink-700' }}">
                        {{ $step['label'] }}
                    </span>
                    <span class="block truncate text-xs {{ $state === 'current' ? 'text-brand-700' : 'text-ink-500' }}"
                          title="{{ $step['hint'] }}">
                        {{ $step['hint'] }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>

    <form method="POST" action="{{ route('imports.map.save', $import) }}" class="space-y-6"
          x-data="{
              mapping: @js((object) $mapState),
              labels: @js((object) $targetLabels),
              labelFor(target) { return this.labels[target] || target },
              emailColumn() {
                  const keys = Object.keys(this.mapping);
                  for (let i = 0; i !== keys.length; i++) {
                      if (this.mapping[keys[i]] === 'email') { return keys[i] }
                  }
                  return '';
              },
              emailMapped() { return this.emailColumn() !== '' },
              mappedCount() { return Object.values(this.mapping).filter(t => t !== 'ignore').length },
              duplicateTargets() {
                  const seen = [];
                  const dupes = [];
                  Object.values(this.mapping).forEach(t => {
                      if (t === 'ignore') { return }
                      if (seen.indexOf(t) === -1) { seen.push(t); return }
                      if (dupes.indexOf(t) === -1) { dupes.push(t) }
                  });
                  return dupes;
              }
          }">
        @csrf

        {{-- ------------------------------------------------------ column mapping --}}
        <div class="kn-card overflow-hidden">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Column mapping</h3>
                <span class="text-xs text-ink-500">
                    <span x-text="mappedCount()">{{ count(array_filter($mapState, fn ($t) => $t !== 'ignore')) }}</span>
                    of {{ count($headers) }} columns mapped
                </span>
            </div>

            {{-- Live guard: the server rejects a mapping with no email column,
                 but the user should never have to submit to find that out. --}}
            <div class="px-5 pt-4">
                <div x-show="!emailMapped()" x-cloak
                     class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    <p>
                        <span class="font-medium">No column is mapped to the email address yet.</span>
                        Every contact needs one, so pick the column that holds email addresses below before you can continue.
                    </p>
                </div>

                <div x-show="emailMapped()" x-cloak
                     class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <p>
                        Email addresses will be read from the column
                        <span class="font-semibold" x-text="emailColumn()"></span>.
                    </p>
                </div>

                <div x-show="duplicateTargets().length !== 0" x-cloak
                     class="mt-3 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    <p>
                        More than one column is pointed at the same field
                        (<span class="font-semibold" x-text="duplicateTargets().map(t => labelFor(t)).join(', ')"></span>).
                        Only the first of them is used — change one of the duplicates if that is not what you want.
                    </p>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            <th class="min-w-[10rem]">Column in your file</th>
                            <th class="min-w-[10rem]">First value</th>
                            <th class="min-w-[16rem]">Import it as</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($headers as $header)
                            @php $firstValue = $sample[0][$header] ?? null; @endphp
                            <tr>
                                <td>
                                    <span class="block max-w-[14rem] truncate font-medium text-ink-900" title="{{ $header }}">
                                        {{ $header }}
                                    </span>
                                </td>

                                <td>
                                    @if (filled($firstValue))
                                        <span class="block max-w-[16rem] truncate text-ink-600" title="{{ $firstValue }}">
                                            {{ $firstValue }}
                                        </span>
                                    @else
                                        <span class="text-ink-400">empty</span>
                                    @endif
                                </td>

                                <td>
                                    <select name="mapping[{{ $header }}]"
                                            x-model="mapping[@js($header)]"
                                            aria-label="Import the column {{ $header }} as"
                                            class="kn-select">
                                        <option value="ignore" @selected($mapState[$header] === 'ignore')>Ignore this column</option>

                                        <optgroup label="Contact fields">
                                            @foreach ($targets as $key => $label)
                                                <option value="{{ $key }}" @selected($mapState[$header] === $key)>{{ $label }}</option>
                                            @endforeach
                                        </optgroup>

                                        @if ($customFields->isNotEmpty())
                                            <optgroup label="Custom fields">
                                                @foreach ($customFields as $field)
                                                    <option value="custom:{{ $field->key }}"
                                                            @selected($mapState[$header] === 'custom:'.$field->key)>
                                                        {{ $field->name }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    </select>

                                    <span x-show="mapping[@js($header)] === 'email'" x-cloak
                                          class="kn-badge-green mt-1.5">Used as the contact's email address</span>
                                    <span x-show="mapping[@js($header)] === 'ignore'" x-cloak
                                          class="kn-badge-gray mt-1.5">Not imported</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-input-error :messages="$errors->get('mapping')" class="px-5 pb-4" />
        </div>

        {{-- ---------------------------------------------------------- options --}}
        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Import options</h3></div>

            <div class="space-y-6 p-5">
                {{-- duplicates --}}
                <fieldset>
                    <legend class="kn-label">When an address is already in your account</legend>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 hover:bg-ink-50">
                            <input type="radio" name="duplicates" value="skip" class="kn-checkbox mt-0.5 rounded-full"
                                   @checked($duplicatesChoice === 'skip')>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink-900">Skip the existing contact</span>
                                <span class="mt-0.5 block text-xs text-ink-500">
                                    Leave what you already have exactly as it is. The row is counted as a duplicate
                                    and nothing about that contact changes.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 px-4 py-3 hover:bg-ink-50">
                            <input type="radio" name="duplicates" value="update" class="kn-checkbox mt-0.5 rounded-full"
                                   @checked($duplicatesChoice === 'update')>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink-900">Update the existing contact</span>
                                <span class="mt-0.5 block text-xs text-ink-500">
                                    Fills in the fields your file supplies. A blank cell in the CSV never wipes a
                                    value you already have, and the contact's status and consent are left untouched.
                                </span>
                            </span>
                        </label>
                    </div>

                    <x-input-error :messages="$errors->get('duplicates')" />
                </fieldset>

                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <x-input-label for="status" value="Status for new contacts" />
                        <select id="status" name="status" class="kn-select">
                            <option value="active" @selected($statusChoice === 'active')>Active — ready to receive campaigns</option>
                            <option value="pending" @selected($statusChoice === 'pending')>Pending — not mailed until confirmed</option>
                        </select>
                        <p class="kn-help">Only affects contacts created by this import.</p>
                        <x-input-error :messages="$errors->get('status')" />
                    </div>

                    <div>
                        <x-input-label for="consent_status" value="How was consent given?" />
                        <select id="consent_status" name="consent_status" class="kn-select">
                            <option value="explicit" @selected($consentChoice === 'explicit')>Explicit — they opted in themselves</option>
                            <option value="implied" @selected($consentChoice === 'implied')>Implied — existing customers or contacts</option>
                            <option value="unknown" @selected($consentChoice === 'unknown')>Unknown — source is not recorded</option>
                        </select>
                        <p class="kn-help">Recorded against every contact for your own compliance records.</p>
                        <x-input-error :messages="$errors->get('consent_status')" />
                    </div>

                    <div>
                        <x-input-label for="source" value="Source label" />
                        <x-text-input id="source" name="source" :value="$sourceValue" maxlength="100"
                                      placeholder="import" />
                        <p class="kn-help">Shows on each contact and can be filtered on later. Defaults to "import".</p>
                        <x-input-error :messages="$errors->get('source')" />
                    </div>
                </div>

                {{-- lists --}}
                <div>
                    <p class="kn-label">Add every imported contact to these lists</p>

                    @if ($lists->isEmpty())
                        <p class="text-sm text-ink-500">
                            You have no lists yet. Contacts will still be imported — you can group them later.
                            @permission('contacts.create')
                                <a href="{{ route('lists.create') }}" class="font-medium text-brand-600 hover:text-brand-700">Create a list</a>.
                            @endpermission
                        </p>
                    @else
                        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($lists as $list)
                                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                                    <input type="checkbox" name="list_ids[]" value="{{ $list->id }}" class="kn-checkbox"
                                           @checked(in_array((string) $list->id, $selectedLists, true))>
                                    <span class="min-w-0 truncate text-sm text-ink-800">{{ $list->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <x-input-error :messages="$errors->get('list_ids')" />
                    <x-input-error :messages="\Illuminate\Support\Arr::flatten($errors->get('list_ids.*'))" />
                </div>

                {{-- tags --}}
                <div>
                    <p class="kn-label">Tag every imported contact</p>

                    @if ($tags->isEmpty())
                        <p class="text-sm text-ink-500">
                            No tags exist yet. Tags are optional — you can add them to these contacts afterwards.
                            @permission('contacts.view')
                                <a href="{{ route('tags.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Manage tags</a>.
                            @endpermission
                        </p>
                    @else
                        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($tags as $tag)
                                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                                    <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" class="kn-checkbox"
                                           @checked(in_array((string) $tag->id, $selectedTags, true))>
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $swatch($tag->color) }}"></span>
                                    <span class="min-w-0 truncate text-sm text-ink-800">{{ $tag->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <x-input-error :messages="$errors->get('tag_ids')" />
                    <x-input-error :messages="\Illuminate\Support\Arr::flatten($errors->get('tag_ids.*'))" />
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-ink-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-ink-500" x-show="!emailMapped()" x-cloak>
                    Pick an email column above to continue.
                </p>
                <p class="text-xs text-ink-500" x-show="emailMapped()" x-cloak>
                    You will see a summary and can still change your mind before anything is imported.
                </p>

                <div class="flex shrink-0 items-center gap-3 sm:ml-auto">
                    <a href="{{ route('imports.index') }}" class="kn-btn-secondary">Cancel</a>
                    <button type="submit" class="kn-btn-primary" :disabled="!emailMapped()">
                        Save mapping and review
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------- file preview --}}
    <div class="kn-card mt-6 overflow-hidden">
        <div class="kn-card-header">
            <h3 class="text-sm font-semibold text-ink-900">File preview</h3>
            <span class="text-xs text-ink-500">
                First {{ count($sample) }} of {{ number_format((int) $import->total_rows) }}
                {{ \Illuminate\Support\Str::plural('row', (int) $import->total_rows) }}
            </span>
        </div>

        @if (empty($sample))
            <x-empty-state title="No data rows found"
                           message="The file has a header row but no rows underneath it. Upload a file with at least one contact in it.">
                <x-slot name="action">
                    <a href="{{ route('imports.create') }}" class="kn-btn-primary">Upload a different file</a>
                </x-slot>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="kn-table">
                    <thead>
                        <tr>
                            @foreach ($headers as $header)
                                <th class="whitespace-nowrap">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sample as $row)
                            <tr>
                                @foreach ($headers as $header)
                                    <td class="max-w-[14rem] truncate" title="{{ $row[$header] ?? '' }}">
                                        {{ filled($row[$header] ?? null) ? $row[$header] : '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                Values look shifted or squashed into one column? The file may use a different separator or
                encoding — re-save it as a UTF-8 CSV and upload it again.
            </p>
        @endif
    </div>
</x-app-layout>
