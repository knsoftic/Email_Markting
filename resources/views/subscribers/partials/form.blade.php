@php
    /**
     * Shared create/edit body. The opening form tag, the CSRF field and the
     * method spoof live in create.blade.php / edit.blade.php; this partial
     * owns the fields only.
     */
    $statusLabels = [
        'active' => 'Active — may receive marketing email',
        'pending' => 'Pending — waiting for confirmation',
        'unsubscribed' => 'Unsubscribed — opted out',
        'bounced' => 'Bounced — delivery failed',
        'blocked' => 'Blocked — never contact',
    ];

    $consentLabels = [
        'explicit' => 'Explicit — they actively opted in',
        'implied' => 'Implied — existing customer or contact',
        'unknown' => 'Unknown — no consent record',
    ];

    $chosenLists = array_map('intval', (array) old('list_ids', $selectedLists));
    $chosenTags = array_map('intval', (array) old('tag_ids', $selectedTags));

    // Tag colours are user input, so only a real hex reaches the style attribute.
    $swatch = fn ($color) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? $color : '#64748b';
@endphp

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        {{-- --------------------------------------------------------- identity --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Contact details</h3>
            </div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="email" value="Email address" />
                    <x-text-input id="email" name="email" type="email" required autocomplete="off"
                                  :value="old('email', $subscriber->email)" placeholder="name@example.com" />
                    <p class="kn-help">Must be unique inside your account.</p>
                    <x-input-error :messages="$errors->get('email')" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="name" value="Full name" />
                    <x-text-input id="name" name="name" :value="old('name', $subscriber->name)" placeholder="Jane Doe" />
                    <p class="kn-help">Leave the two fields below blank and we will split this name for you.</p>
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="first_name" value="First name" />
                    <x-text-input id="first_name" name="first_name" :value="old('first_name', $subscriber->first_name)" />
                    <x-input-error :messages="$errors->get('first_name')" />
                </div>

                <div>
                    <x-input-label for="last_name" value="Last name" />
                    <x-text-input id="last_name" name="last_name" :value="old('last_name', $subscriber->last_name)" />
                    <x-input-error :messages="$errors->get('last_name')" />
                </div>

                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input id="phone" name="phone" :value="old('phone', $subscriber->phone)" placeholder="+387 61 000 000" />
                    <x-input-error :messages="$errors->get('phone')" />
                </div>

                <div>
                    <x-input-label for="company" value="Company" />
                    <x-text-input id="company" name="company" :value="old('company', $subscriber->company)" />
                    <x-input-error :messages="$errors->get('company')" />
                </div>

                <div>
                    <x-input-label for="country" value="Country" />
                    <x-text-input id="country" name="country" :value="old('country', $subscriber->country)" placeholder="Bosnia and Herzegovina" />
                    <x-input-error :messages="$errors->get('country')" />
                </div>

                <div>
                    <x-input-label for="city" value="City" />
                    <x-text-input id="city" name="city" :value="old('city', $subscriber->city)" />
                    <x-input-error :messages="$errors->get('city')" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="timezone" value="Timezone" />
                    <select id="timezone" name="timezone" class="kn-select">
                        <option value="">Not set</option>
                        @foreach (\DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $subscriber->timezone) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    <p class="kn-help">Used when a campaign is scheduled in the recipient's local time.</p>
                    <x-input-error :messages="$errors->get('timezone')" />
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------- custom fields --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Custom fields</h3>
                @permission('contacts.view')
                    <a href="{{ route('custom-fields.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">Manage fields</a>
                @endpermission
            </div>

            @if ($customFields->isEmpty())
                <x-empty-state title="No custom fields defined"
                               message="Custom fields let you store anything else you know about a contact and merge it into an email." >
                    <x-slot name="action">
                        @permission('contacts.view')
                            <a href="{{ route('custom-fields.index') }}" class="kn-btn-secondary">Create a custom field</a>
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @else
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    @foreach ($customFields as $field)
                        @php
                            $inputId = 'custom_'.$field->key;
                            $inputName = 'custom['.$field->key.']';
                            $errorKey = 'custom.'.$field->key;
                            $current = old($errorKey, $subscriber->customValue($field->key) ?? $field->default_value);
                            // Built in PHP: a literal {{ }} pair written in Blade would be parsed as an echo.
                            $mergeTag = '{{ custom.'.$field->key.' }}';
                        @endphp

                        <div class="{{ $field->type === 'boolean' ? 'sm:col-span-2' : '' }}">
                            @if ($field->type === 'boolean')
                                <label for="{{ $inputId }}" class="flex items-start gap-3 rounded-lg border border-ink-200 p-3">
                                    <input type="hidden" name="{{ $inputName }}" value="0">
                                    <input id="{{ $inputId }}" type="checkbox" name="{{ $inputName }}" value="1"
                                           class="kn-checkbox mt-0.5" @checked((string) $current === '1')>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-800">{{ $field->name }}</span>
                                        <span class="block text-xs text-ink-500">Key: {{ $field->key }}</span>
                                    </span>
                                </label>
                            @else
                                <x-input-label :for="$inputId" :value="$field->name.($field->is_required ? ' *' : '')" />

                                @if ($field->type === 'select')
                                    <select id="{{ $inputId }}" name="{{ $inputName }}" class="kn-select" @required($field->is_required)>
                                        <option value="">Not set</option>
                                        @foreach ((array) ($field->options ?? []) as $option)
                                            <option value="{{ $option }}" @selected((string) $current === (string) $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field->type === 'number')
                                    <x-text-input :id="$inputId" :name="$inputName" type="number" step="any"
                                                  :value="$current" :required="$field->is_required" />
                                @elseif ($field->type === 'date')
                                    <x-text-input :id="$inputId" :name="$inputName" type="date"
                                                  :value="$current" :required="$field->is_required" />
                                @elseif ($field->type === 'url')
                                    <x-text-input :id="$inputId" :name="$inputName" type="url" placeholder="https://"
                                                  :value="$current" :required="$field->is_required" />
                                @else
                                    <x-text-input :id="$inputId" :name="$inputName" type="text" maxlength="500"
                                                  :value="$current" :required="$field->is_required" />
                                @endif

                                <p class="kn-help">Merge tag: <code class="text-ink-700">{{ $mergeTag }}</code></p>
                            @endif

                            <x-input-error :messages="$errors->get($errorKey)" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------------ notes --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Notes</h3>
                <span class="text-xs text-ink-500">Internal only — never sent to the contact</span>
            </div>
            <div class="p-5">
                <x-input-label for="notes" value="Notes" class="sr-only" />
                <textarea id="notes" name="notes" rows="4" maxlength="2000" class="kn-textarea"
                          placeholder="Anything your team should know about this contact.">{{ old('notes', $subscriber->notes) }}</textarea>
                <x-input-error :messages="$errors->get('notes')" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ sidebar --}}
    <div class="space-y-6">

        {{-- Status & consent --}}
        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Status &amp; consent</h3></div>
            <div class="space-y-5 p-5">
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="kn-select" required>
                        @foreach ($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $subscriber->status ?? 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="kn-help">Only active contacts that are not suppressed receive marketing email.</p>
                    <x-input-error :messages="$errors->get('status')" />
                </div>

                <div>
                    <x-input-label for="consent_status" value="Consent" />
                    <select id="consent_status" name="consent_status" class="kn-select" required>
                        @foreach ($consentLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('consent_status', $subscriber->consent_status ?? 'unknown') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="kn-help">Choosing “Explicit” stamps the date and IP of this change on the contact.</p>
                    <x-input-error :messages="$errors->get('consent_status')" />
                </div>

                <div>
                    <x-input-label for="source" value="Source" />
                    <x-text-input id="source" name="source" :value="old('source', $subscriber->source)"
                                  maxlength="100" placeholder="manual" />
                    <p class="kn-help">Where this contact came from. Defaults to “manual”.</p>
                    <x-input-error :messages="$errors->get('source')" />
                </div>

                @if ($subscriber->exists && $subscriber->consent_at)
                    <div class="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600">
                        Consent recorded {{ $subscriber->consent_at->format('d M Y H:i') }}
                        @if ($subscriber->consent_ip)
                            from {{ $subscriber->consent_ip }}
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Lists --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Lists</h3>
                <span class="text-xs text-ink-500">{{ $lists->count() }} available</span>
            </div>

            @if ($lists->isEmpty())
                <x-empty-state title="No lists yet" message="Lists group contacts so a campaign can target them.">
                    <x-slot name="action">
                        @permission('contacts.create')
                            <a href="{{ route('lists.create') }}" class="kn-btn-secondary">Create a list</a>
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @else
                <div class="max-h-64 space-y-2 overflow-y-auto p-5">
                    @foreach ($lists as $list)
                        <label class="flex items-center gap-3 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                            <input type="checkbox" name="list_ids[]" value="{{ $list->id }}" class="kn-checkbox"
                                   @checked(in_array($list->id, $chosenLists, true))>
                            <span class="min-w-0 truncate text-sm text-ink-800">{{ $list->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <x-input-error :messages="$errors->get('list_ids')" class="px-5 pb-4" />
        </div>

        {{-- Tags --}}
        <div class="kn-card">
            <div class="kn-card-header">
                <h3 class="text-sm font-semibold text-ink-900">Tags</h3>
                <span class="text-xs text-ink-500">{{ $tags->count() }} available</span>
            </div>

            @if ($tags->isEmpty())
                <x-empty-state title="No tags yet" message="Tags are free-form labels you can filter and segment on.">
                    <x-slot name="action">
                        @permission('contacts.view')
                            <a href="{{ route('tags.index') }}" class="kn-btn-secondary">Manage tags</a>
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @else
                <div class="max-h-64 space-y-2 overflow-y-auto p-5">
                    @foreach ($tags as $tag)
                        <label class="flex items-center gap-3 rounded-lg border border-ink-200 px-3 py-2 hover:bg-ink-50">
                            <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" class="kn-checkbox"
                                   @checked(in_array($tag->id, $chosenTags, true))>
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $swatch($tag->color) }}"></span>
                            <span class="min-w-0 truncate text-sm text-ink-800">{{ $tag->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <x-input-error :messages="$errors->get('tag_ids')" class="px-5 pb-4" />
        </div>
    </div>
</div>

<div class="mt-6 flex items-center justify-end gap-3">
    <a href="{{ $subscriber->exists ? route('subscribers.show', $subscriber) : route('subscribers.index') }}"
       class="kn-btn-secondary">Cancel</a>
    <x-primary-button>{{ $subscriber->exists ? 'Save contact' : 'Add contact' }}</x-primary-button>
</div>
