<x-app-layout>
    <x-slot name="header">{{ $automation->exists ? $automation->name : 'New automation' }}</x-slot>

    @php
        $isNew = ! $automation->exists;
        $action = $isNew ? route('automations.store') : route('automations.update', $automation);

        /*
         * Once a save has been attempted, old() is the only truth for the
         * checkbox groups. Unticked boxes post nothing at all, so falling back
         * to the saved config there would silently re-tick every list the user
         * had just cleared, and the next save would put it back.
         */
        $posted = session()->hasOldInput();

        $trigger = old_text('trigger_type', $automation->trigger_type ?: 'subscriber_added');
        $trigger = array_key_exists($trigger, $triggerLabels) ? $trigger : 'subscriber_added';

        $tagId = old_text('tag_id', (string) ($config['tag_id'] ?? ''));
        $listId = old_text('list_id', (string) ($config['list_id'] ?? ''));
        $campaignId = old_text('campaign_id', (string) ($config['campaign_id'] ?? ''));
        $linkId = old_text('link_id', (string) ($config['link_id'] ?? ''));
        $afterHours = old_text('after_hours', (string) ($config['after_hours'] ?? 48));
        $date = old_text('date', (string) ($config['date'] ?? ''));
        $time = old_text('time', (string) ($config['time'] ?? '09:00'));

        $chosenLists = array_map('intval', $posted
            ? old_list('list_ids')
            : array_map('intval', (array) ($config['list_ids'] ?? [])));

        $selectedSmtp = (int) old_text('smtp_account_id', (string) ($automation->smtp_account_id ?? ''));
        $smtpIds = $smtpAccounts->pluck('id')->map(fn ($id) => (int) $id)->all();

        // A stored SMTP account can drop out of the candidate list (paused, over
        // its limit, unassigned). Keeping it as an option means saving this form
        // does not quietly change a choice nobody revisited.
        $orphanSmtp = $selectedSmtp > 0 && ! in_array($selectedSmtp, $smtpIds, true);

        $allowReentry = (bool) old('allow_reentry', $automation->allow_reentry);

        // Links, keyed by campaign, for the picker that follows the campaign
        // select. Built here from the route data rather than string-built in the
        // markup.
        $linksByCampaign = $links->mapWithKeys(fn ($group, $id) => [(string) $id => $group->all()]);
    @endphp

    <x-page-header :title="$isNew ? 'New automation' : 'Edit '.$automation->name"
                   subtitle="Nothing runs while you edit. An automation only starts entering contacts once it is active."
                   :back="$isNew ? route('automations.index') : route('automations.show', $automation)">
        @if (! $isNew)
            <x-slot name="actions">
                <x-status-badge :status="$automation->status" />
                <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary">Steps</a>
            </x-slot>
        @endif
    </x-page-header>

    @if ($errors->any())
        <div class="kn-card mb-5 border-red-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-red-800">This automation was not saved</p>
                <p class="mt-1 text-sm text-ink-600">Nothing was lost. Fix the points below and save again.</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" class="space-y-5"
          x-data="{
              trigger: @js($trigger),
              campaign: @js($campaignId),
              linkId: @js($linkId),
              linksByCampaign: @js($linksByCampaign),
              links() { return this.linksByCampaign[this.campaign] || []; },
          }">
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <div class="grid gap-5 lg:grid-cols-3">

            {{-- ================================================== basics --}}
            <div class="space-y-5 lg:col-span-2">
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h2 class="text-sm font-semibold text-ink-900">Basics</h2>
                    </div>

                    <div class="kn-card-body grid gap-4">
                        <div>
                            <label for="name" class="kn-label">Name</label>
                            <x-text-input id="name" name="name" type="text" required maxlength="191"
                                          :value="old_text('name', $automation->name ?? '')"
                                          placeholder="Welcome sequence" />
                            <p class="kn-help">Internal only. Contacts never see it.</p>
                            <x-input-error :messages="$errors->get('name')" />
                        </div>

                        <div>
                            <label for="description" class="kn-label">
                                Description <span class="font-normal text-ink-400">(optional)</span>
                            </label>
                            <x-text-input id="description" name="description" type="text" maxlength="255"
                                          :value="old_text('description', $automation->description ?? '')"
                                          placeholder="What this is for, so the next person knows" />
                            <x-input-error :messages="$errors->get('description')" />
                        </div>
                    </div>
                </div>

                {{-- ============================================== trigger --}}
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h2 class="text-sm font-semibold text-ink-900">What starts it</h2>
                    </div>

                    <div class="kn-card-body space-y-4">
                        <div>
                            <label for="trigger_type" class="kn-label">Trigger</label>
                            <select id="trigger_type" name="trigger_type" class="kn-select" x-model="trigger">
                                @foreach ($triggerLabels as $value => $meta)
                                    <option value="{{ $value }}" @selected($trigger === $value)>{{ $meta['label'] }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('trigger_type')" />

                            @foreach ($triggerLabels as $value => $meta)
                                <p class="kn-help" x-show="trigger === '{{ $value }}'" x-cloak>{{ $meta['help'] }}</p>
                            @endforeach
                        </div>

                        {{--
                            Each trigger's own fields live in an x-if template, so
                            the ones that do not belong to the selected trigger are
                            not in the document at all and cannot be submitted. A
                            stale tag_id left over from a trigger somebody switched
                            away from is exactly what AutomationTrigger would go on
                            matching against.
                        --}}

                        {{-- ------------------------------------ tag_added --}}
                        <template x-if="trigger === 'tag_added'">
                            <div>
                                <label for="tag_id" class="kn-label">Tag</label>
                                <select id="tag_id" name="tag_id" class="kn-select">
                                    <option value="">Any tag</option>
                                    @foreach ($tags as $tag)
                                        <option value="{{ $tag->id }}" @selected($tagId === (string) $tag->id)>
                                            {{ $tag->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="kn-help">
                                    Only a tag that is new on that contact fires this. Re-applying a tag somebody
                                    already has does nothing.
                                </p>
                                <x-input-error :messages="$errors->get('tag_id')" />
                            </div>
                        </template>

                        {{-- ---------------------------------- list_joined --}}
                        <template x-if="trigger === 'list_joined'">
                            <div>
                                <label for="list_id" class="kn-label">List</label>
                                <select id="list_id" name="list_id" class="kn-select">
                                    <option value="">Any list</option>
                                    @foreach ($lists as $list)
                                        <option value="{{ $list->id }}" @selected($listId === (string) $list->id)>
                                            {{ $list->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('list_id')" />
                            </div>
                        </template>

                        {{-- ------------------------------ subscriber_added --}}
                        <template x-if="trigger === 'subscriber_added'">
                            <div>
                                <span class="kn-label">Only when they land on these lists <span class="font-normal text-ink-400">(optional)</span></span>

                                @if ($lists->isEmpty())
                                    <p class="kn-help">There are no lists yet, so this fires for every new contact.</p>
                                @else
                                    <div class="mt-1 grid max-h-52 gap-1 overflow-y-auto rounded-lg border border-ink-200 p-2 sm:grid-cols-2">
                                        @foreach ($lists as $list)
                                            <label class="flex items-center gap-2 rounded px-2 py-1 text-sm text-ink-700 hover:bg-ink-50">
                                                <input type="checkbox" name="list_ids[]" value="{{ $list->id }}"
                                                       class="kn-checkbox"
                                                       @checked(in_array((int) $list->id, $chosenLists, true))>
                                                <span class="truncate">{{ $list->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="kn-help">Tick nothing to fire for every new contact, whatever list they land on.</p>
                                @endif

                                <x-input-error :messages="$errors->get('list_ids')" />
                            </div>
                        </template>

                        {{-- ------------------------------- campaign_opened --}}
                        <template x-if="trigger === 'campaign_opened'">
                            <div>
                                <label for="campaign_id_open" class="kn-label">Campaign</label>
                                <select id="campaign_id_open" name="campaign_id" class="kn-select">
                                    <option value="">Any campaign</option>
                                    @foreach ($campaigns as $campaign)
                                        <option value="{{ $campaign->id }}" @selected($campaignId === (string) $campaign->id)>
                                            {{ $campaign->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="kn-help">
                                    Opens are only recorded for campaigns sent with open tracking on. A campaign sent
                                    without it can never fire this.
                                </p>
                                <x-input-error :messages="$errors->get('campaign_id')" />
                            </div>
                        </template>

                        {{-- ---------------------------------- link_clicked --}}
                        <template x-if="trigger === 'link_clicked'">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="campaign_id_click" class="kn-label">Campaign</label>
                                    <select id="campaign_id_click" name="campaign_id" class="kn-select" x-model="campaign">
                                        <option value="">Any campaign</option>
                                        @foreach ($campaigns as $campaign)
                                            <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('campaign_id')" />
                                </div>

                                <div>
                                    <label for="link_id" class="kn-label">Link</label>
                                    <select id="link_id" name="link_id" class="kn-select" x-model="linkId"
                                            :disabled="campaign === ''">
                                        <option value="">Any link</option>
                                        <template x-for="link in links()" :key="link.id">
                                            <option :value="String(link.id)" x-text="link.url"
                                                    :selected="String(link.id) === linkId"></option>
                                        </template>
                                    </select>
                                    <p class="kn-help" x-show="campaign === ''" x-cloak>
                                        Pick a campaign first — a link only exists inside one.
                                    </p>
                                    <p class="kn-help" x-show="campaign !== '' && links().length === 0" x-cloak>
                                        That campaign has no tracked links recorded, so only "any link" is available
                                        and this trigger cannot fire for it.
                                    </p>
                                    <x-input-error :messages="$errors->get('link_id')" />
                                </div>
                            </div>
                        </template>

                        {{-- --------------------------- campaign_not_opened --}}
                        <template x-if="trigger === 'campaign_not_opened'">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="campaign_id_missed" class="kn-label">Campaign</label>
                                    <select id="campaign_id_missed" name="campaign_id" class="kn-select" required>
                                        <option value="">Choose a campaign</option>
                                        @foreach ($campaigns as $campaign)
                                            <option value="{{ $campaign->id }}" @selected($campaignId === (string) $campaign->id)>
                                                {{ $campaign->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="kn-help">Required. "Did not open" has to name the email that was not opened.</p>
                                    <x-input-error :messages="$errors->get('campaign_id')" />
                                </div>

                                <div>
                                    <label for="after_hours" class="kn-label">Hours to wait first</label>
                                    <x-text-input id="after_hours" name="after_hours" type="number"
                                                  min="1" max="8760" required :value="$afterHours" />
                                    <p class="kn-help">
                                        Measured from each contact's own delivery time, so everybody gets the same
                                        grace period whatever hour of the send they were in.
                                    </p>
                                    <x-input-error :messages="$errors->get('after_hours')" />
                                </div>
                            </div>
                        </template>

                        {{-- --------------------------------- specific_date --}}
                        <template x-if="trigger === 'specific_date'">
                            <div class="space-y-4">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="date" class="kn-label">Date</label>
                                        <x-text-input id="date" name="date" type="date" required :value="$date" />
                                        <x-input-error :messages="$errors->get('date')" />
                                    </div>

                                    <div>
                                        <label for="time" class="kn-label">Time</label>
                                        <x-text-input id="time" name="time" type="time" required :value="$time" />
                                        <p class="kn-help">In {{ $timezone }}, this account's time zone.</p>
                                        <x-input-error :messages="$errors->get('time')" />
                                    </div>
                                </div>

                                <div>
                                    <span class="kn-label">Lists to enter <span class="font-normal text-ink-400">(optional)</span></span>

                                    @if ($lists->isEmpty())
                                        <p class="kn-help">There are no lists yet, so every active contact would be entered.</p>
                                    @else
                                        <div class="mt-1 grid max-h-52 gap-1 overflow-y-auto rounded-lg border border-ink-200 p-2 sm:grid-cols-2">
                                            @foreach ($lists as $list)
                                                <label class="flex items-center gap-2 rounded px-2 py-1 text-sm text-ink-700 hover:bg-ink-50">
                                                    <input type="checkbox" name="list_ids[]" value="{{ $list->id }}"
                                                           class="kn-checkbox"
                                                           @checked(in_array((int) $list->id, $chosenLists, true))>
                                                    <span class="truncate">{{ $list->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <p class="kn-help">
                                            Tick nothing and every active contact in the account is entered. This one
                                            runs once and then marks itself completed.
                                        </p>
                                    @endif

                                    <x-input-error :messages="$errors->get('list_ids')" />
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- =================================================== sending --}}
            <div class="space-y-5">
                <div class="kn-card">
                    <div class="kn-card-header">
                        <h2 class="text-sm font-semibold text-ink-900">Sending</h2>
                    </div>

                    <div class="kn-card-body grid gap-4">
                        <div>
                            <label for="from_name" class="kn-label">From name</label>
                            <x-text-input id="from_name" name="from_name" type="text" maxlength="191"
                                          :value="old_text('from_name', $automation->from_name ?? '')"
                                          placeholder="KN Softic" />
                            <x-input-error :messages="$errors->get('from_name')" />
                        </div>

                        <div>
                            <label for="from_email" class="kn-label">From address</label>
                            <x-text-input id="from_email" name="from_email" type="email" maxlength="191"
                                          :value="old_text('from_email', $automation->from_email ?? '')"
                                          placeholder="hello@yourdomain.com" />
                            <p class="kn-help">
                                Leave it empty and the SMTP account's own from address is used — that is the address
                                the provider has actually authorised, so it is the one that will not be rejected.
                            </p>
                            <x-input-error :messages="$errors->get('from_email')" />
                        </div>

                        <div>
                            <label for="smtp_account_id" class="kn-label">SMTP account</label>
                            <select id="smtp_account_id" name="smtp_account_id" class="kn-select">
                                <option value="">Choose automatically</option>
                                @if ($orphanSmtp)
                                    <option value="{{ $selectedSmtp }}" selected>
                                        The account currently saved (not available right now)
                                    </option>
                                @endif
                                @foreach ($smtpAccounts as $smtp)
                                    <option value="{{ $smtp->id }}" @selected($selectedSmtp === (int) $smtp->id)>
                                        {{ $smtp->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($smtpAccounts->isEmpty())
                                <p class="kn-help">
                                    There are no SMTP accounts available to this account yet. An automation with an
                                    email step cannot be activated until there is one, or until you set a from
                                    address above.
                                </p>
                            @else
                                <p class="kn-help">Left automatic, the sender is picked at send time from what has capacity.</p>
                            @endif
                            <x-input-error :messages="$errors->get('smtp_account_id')" />
                        </div>
                    </div>
                </div>

                <div class="kn-card">
                    <div class="kn-card-header">
                        <h2 class="text-sm font-semibold text-ink-900">Re-entry</h2>
                    </div>

                    <div class="kn-card-body">
                        <label class="flex items-start gap-2.5 text-sm text-ink-700">
                            <input type="checkbox" name="allow_reentry" value="1" class="kn-checkbox mt-0.5"
                                   @checked($allowReentry)>
                            <span>
                                Let a contact enter this automation more than once
                            </span>
                        </label>

                        <p class="kn-help mt-2">
                            Off by default, and that is usually right: a welcome sequence would otherwise send again
                            every time somebody is re-imported or re-tagged.
                        </p>
                        <p class="kn-help mt-1">
                            With it on, only a contact who has <span class="font-medium">finished</span> (completed,
                            cancelled or failed) can start again. Somebody still part-way through is never restarted,
                            so nobody gets step one twice and step four never.
                        </p>

                        <x-input-error :messages="$errors->get('allow_reentry')" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <a href="{{ $isNew ? route('automations.index') : route('automations.show', $automation) }}"
               class="kn-btn-secondary">Cancel</a>
            <button type="submit" class="kn-btn-primary">
                {{ $isNew ? 'Save draft' : 'Save changes' }}
            </button>
        </div>
    </form>
</x-app-layout>
