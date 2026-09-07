<x-app-layout>
    <x-slot name="header">{{ $step->exists ? 'Edit step' : 'Add a step' }}</x-slot>

    @php
        $isNew = ! $step->exists;
        $type = $step->type;
        $meta = $stepTypes[$type] ?? ['label' => $type, 'summary' => '', 'runs' => ''];

        $action = $isNew
            ? route('automations.steps.store', $automation)
            : route('automations.steps.update', [$automation, $step]);
        $method = $isNew ? 'POST' : 'PUT';
        $saveLabel = $isNew ? 'Add step' : 'Save step';

        $posted = session()->hasOldInput();

        /*
         * A rejected save must not throw the email away. The controller rebuilds
         * the document from the database, so on a validation failure the blocks
         * the user had just edited would silently revert. old('blocks') holds
         * exactly what the builder serialised, so it wins when it is there —
         * and both shapes are accepted, because nothing forces the JSON one.
         */
        $decodeOld = function (string $key) {
            $value = old($key);

            return is_array($value) ? $value : json_decode((string) $value, true);
        };

        $oldBlocks = $decodeOld('blocks');
        $oldSettings = $decodeOld('settings');

        if (is_array($oldBlocks) && $oldBlocks !== []) {
            $document = [
                'settings' => is_array($oldSettings) ? $oldSettings : ($document['settings'] ?? []),
                'blocks' => $oldBlocks,
            ];
        }

        $label = old_text('label', (string) ($step->label ?? ''));
        $subject = old_text('subject', (string) ($config['subject'] ?? ($fromTemplate?->subject ?? '')));

        $amount = old_text('amount', (string) ($config['amount'] ?? 1));
        $unit = old_text('unit', (string) ($config['unit'] ?? 'days'));

        $check = old_text('check', (string) ($config['check'] ?? 'has_tag'));
        $ifFalse = old_text('if_false', (string) ($config['if_false'] ?? 'end'));

        $tagId = old_text('tag_id', (string) ($config['tag_id'] ?? ''));
        $listId = old_text('list_id', (string) ($config['list_id'] ?? ''));
        $fromListId = old_text('from_list_id', (string) ($config['from_list_id'] ?? ''));
        $campaignId = old_text('campaign_id', (string) ($config['campaign_id'] ?? ''));

        $unitLabels = [
            'minutes' => 'Minutes',
            'hours' => 'Hours',
            'days' => 'Days',
            'weeks' => 'Weeks',
        ];

        $checkLabels = [
            'has_tag' => 'Has a tag',
            'on_list' => 'Is on a list',
            'opened_campaign' => 'Opened a campaign',
            'clicked_campaign' => 'Clicked a link in a campaign',
        ];

        $templateNames = $templates->mapWithKeys(fn ($t) => [(string) $t->id => $t->name]);
        $templateDocuments = $canLoadTemplate
            ? $templates->mapWithKeys(fn ($t) => [(string) $t->id => route('templates.document', $t->id)])
            : collect();

        $subtitle = $isNew
            ? 'It is added at the end of the sequence. Reorder it afterwards if it belongs somewhere else.'
            : 'Step '.$step->position.' of this automation.';
    @endphp

    <x-page-header :title="($isNew ? 'Add a step: ' : 'Edit step: ').$meta['label']"
                   :subtitle="$subtitle"
                   :back="route('automations.show', $automation)">
        <x-slot name="actions">
            <span class="text-xs text-ink-500">{{ $automation->name }}</span>
        </x-slot>
    </x-page-header>

    {{-- --------------------------------------------------------- type picker --}}
    @if ($isNew)
        <div class="kn-card mb-5">
            <div class="kn-card-header">
                <h2 class="text-sm font-semibold text-ink-900">Step type</h2>
            </div>
            <div class="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($stepTypes as $value => $option)
                    <a href="{{ route('automations.steps.create', ['automation' => $automation, 'type' => $value]) }}"
                       class="rounded-lg border px-3 py-2 text-left transition {{ $type === $value
                           ? 'border-brand-300 bg-brand-50'
                           : 'border-ink-200 hover:border-brand-200 hover:bg-ink-50' }}">
                        <span class="block text-sm font-medium text-ink-900">{{ $option['label'] }}</span>
                        <span class="block text-xs text-ink-500">{{ $option['summary'] }}</span>
                    </a>
                @endforeach
            </div>
            <div class="border-t border-ink-100 px-4 py-2.5 text-xs text-ink-500">
                Choosing a different type reloads this form with that type's settings. Nothing is saved until you
                press {{ $saveLabel }}.
            </div>
        </div>
    @endif

    {{-- --------------------------------------------------- what this step does --}}
    <div class="kn-card mb-5">
        <div class="kn-card-body">
            <p class="text-sm font-semibold text-ink-900">{{ $meta['label'] }}</p>
            <p class="mt-1 text-sm text-ink-600">{{ $meta['summary'] }}</p>
            <p class="mt-1 text-sm text-ink-600">{{ $meta['runs'] }}</p>

            @unless ($isNew)
                <p class="mt-2 text-xs text-ink-500">
                    A step's type cannot be changed here — every type stores a different shape of settings, and
                    swapping one for another would leave the old settings behind for the runner to read. Delete this
                    step and add the one you want instead.
                </p>
            @endunless

            @if ($stranded > 0)
                <p class="mt-2 text-xs text-amber-700">
                    {{ number_format($stranded) }} contact(s) are parked on this step right now. They pick up whatever
                    is saved here when the runner next reaches them — the change is not applied retroactively to
                    anybody who has already passed it.
                </p>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="kn-card mb-5 border-red-200">
            <div class="kn-card-body">
                <p class="text-sm font-semibold text-red-800">This step was not saved</p>
                <p class="mt-1 text-sm text-ink-600">Nothing was lost. Fix the points below and save again.</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- ================================================== send_email: builder --}}
    @if ($type === 'send_email')
        <x-block-builder :document="$document"
                         :block-types="$blockTypes"
                         :tokens="$tokens"
                         :action="$action"
                         :method="$method"
                         :save-label="$saveLabel">

            <x-slot name="actions">
                <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary kn-btn-sm">Cancel</a>
            </x-slot>

            <x-slot name="meta">
                <input type="hidden" name="type" value="send_email">

                <div class="kn-card"
                     x-data="{
                         template: '',
                         templateDocumentUrls: @js($templateDocuments),
                         templateNames: @js($templateNames),
                     }">
                    <div class="kn-card-body grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="label" class="kn-label">Step name <span class="font-normal text-ink-400">(optional)</span></label>
                            <x-text-input id="label" name="label" type="text" maxlength="191"
                                          :value="$label" placeholder="Welcome email" />
                            <p class="kn-help">Internal only — it names this step in the builder and in error messages.</p>
                            <x-input-error :messages="$errors->get('label')" />
                        </div>

                        <div>
                            <label for="subject" class="kn-label">Subject line</label>
                            <x-text-input id="subject" name="subject" type="text" required maxlength="255"
                                          :value="$subject" placeholder="Welcome aboard" />
                            <p class="kn-help">Placeholders work here too, so the subject can carry a first name.</p>
                            <x-input-error :messages="$errors->get('subject')" />
                        </div>

                        @if ($canLoadTemplate && $templates->isNotEmpty())
                            <div class="sm:col-span-2">
                                <label for="template_picker" class="kn-label">
                                    Start from a saved template <span class="font-normal text-ink-400">(optional)</span>
                                </label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select id="template_picker" class="kn-select max-w-xs" x-model="template">
                                        <option value="">Choose a template…</option>
                                        @foreach ($templates as $template)
                                            <option value="{{ $template->id }}">
                                                {{ $template->name }}@if ($template->is_system) (built in)@endif
                                            </option>
                                        @endforeach
                                    </select>

                                    {{-- Replaces the whole document, and says so before it does:
                                         merging two block documents produces something neither
                                         the template author nor this user asked for. --}}
                                    <button type="button"
                                            x-show="templateDocumentUrls[template]"
                                            :disabled="loadingTemplate"
                                            @click="applyTemplate(templateDocumentUrls[template], templateNames[template])"
                                            class="kn-btn-secondary kn-btn-sm">
                                        <span x-show="! loadingTemplate">Use this template's content</span>
                                        <span x-show="loadingTemplate" x-cloak>Loading…</span>
                                    </button>
                                </div>
                                <p class="kn-help">
                                    The step takes its own copy of the blocks. Editing the template later does not
                                    change this step, and editing here does not change the template.
                                </p>
                            </div>
                        @endif

                        @if ($fromTemplate)
                            <div class="sm:col-span-2 rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                                This step currently takes its content from the template
                                <span class="font-medium text-ink-800">{{ $fromTemplate->name }}</span>. Its blocks are
                                loaded into the builder above; saving here gives the step its own copy and stops it
                                following that template.
                            </div>
                        @endif

                        <div class="sm:col-span-2 rounded-lg bg-ink-50 px-4 py-3 text-xs text-ink-600">
                            <p class="font-medium text-ink-800">About the unsubscribe warning</p>
                            <p class="mt-1">
                                The strip above is shared with the campaign editor, so it talks about campaigns. An
                                automation email is not refused for having no footer block — the runner always adds
                                the one-click List-Unsubscribe headers — but the visible link is what most readers
                                actually use, so keep the footer block unless you have a reason not to.
                            </p>

                            @unless ($canPreview)
                                <p class="mt-2 text-amber-700">
                                    Your role cannot use the shared preview endpoint, so the live preview on the right
                                    will report an error. The email itself saves and sends normally.
                                </p>
                            @endunless
                        </div>
                    </div>
                </div>
            </x-slot>
        </x-block-builder>

    {{-- ================================================ everything else: form --}}
    @else
        <form method="POST" action="{{ $action }}" class="space-y-5"
              x-data="{ check: @js($check) }">
            @csrf
            @unless ($isNew)
                @method('PUT')
            @endunless
            <input type="hidden" name="type" value="{{ $type }}">

            <div class="kn-card">
                <div class="kn-card-body grid gap-4">

                    <div>
                        <label for="label" class="kn-label">Step name <span class="font-normal text-ink-400">(optional)</span></label>
                        <x-text-input id="label" name="label" type="text" maxlength="191"
                                      :value="$label" placeholder="{{ $meta['label'] }}" />
                        <p class="kn-help">Internal only — it names this step in the builder and in error messages.</p>
                        <x-input-error :messages="$errors->get('label')" />
                    </div>

                    {{-- ------------------------------------------------- wait --}}
                    @if ($type === 'wait')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="amount" class="kn-label">How long</label>
                                <x-text-input id="amount" name="amount" type="number" min="1" max="525600"
                                              required :value="$amount" />
                                <x-input-error :messages="$errors->get('amount')" />
                            </div>

                            <div>
                                <label for="unit" class="kn-label">Unit</label>
                                <select id="unit" name="unit" class="kn-select" required>
                                    @foreach ($unitLabels as $value => $text)
                                        <option value="{{ $value }}" @selected($unit === $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('unit')" />
                            </div>
                        </div>

                        <div class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                            <p class="font-medium text-ink-800">Waits are capped at about a year</p>
                            <p class="mt-1">
                                Whatever unit you choose, the resume time is clamped to roughly 12 months — 525,600
                                minutes, 8,760 hours, 52 weeks or 365 days. The column that stores it cannot hold a
                                date past 2038, and a wait that silently produced an impossible date would either
                                fail to save or come back as a date that is already due, which would mail somebody
                                immediately instead of next year.
                            </p>
                            <p class="mt-1">
                                The wait starts when the contact reaches this step, not when they enter the
                                automation. The runner checks for due contacts once a minute, so a wait can finish up
                                to a minute later than the exact time.
                            </p>
                        </div>
                    @endif

                    {{-- -------------------------------------------- condition --}}
                    @if ($type === 'condition')
                        <div>
                            <label for="check" class="kn-label">What to check</label>
                            <select id="check" name="check" class="kn-select" required x-model="check">
                                @foreach ($checkLabels as $value => $text)
                                    <option value="{{ $value }}" @selected($check === $value)>{{ $text }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('check')" />
                        </div>

                        {{-- Only the field belonging to the selected check is in
                             the document, so a stale id from another check cannot
                             be posted alongside it. --}}
                        <template x-if="check === 'has_tag'">
                            <div>
                                <label for="tag_id" class="kn-label">Tag</label>
                                <select id="tag_id" name="tag_id" class="kn-select" required>
                                    <option value="">Choose a tag</option>
                                    @foreach ($tags as $tag)
                                        <option value="{{ $tag->id }}" @selected($tagId === (string) $tag->id)>{{ $tag->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tag_id')" />
                            </div>
                        </template>

                        <template x-if="check === 'on_list'">
                            <div>
                                <label for="list_id_cond" class="kn-label">List</label>
                                <select id="list_id_cond" name="list_id" class="kn-select" required>
                                    <option value="">Choose a list</option>
                                    @foreach ($lists as $list)
                                        <option value="{{ $list->id }}" @selected($listId === (string) $list->id)>{{ $list->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('list_id')" />
                            </div>
                        </template>

                        <template x-if="check === 'opened_campaign' || check === 'clicked_campaign'">
                            <div>
                                <label for="campaign_id" class="kn-label">Campaign</label>
                                <select id="campaign_id" name="campaign_id" class="kn-select">
                                    <option value="">Any campaign</option>
                                    @foreach ($campaigns as $campaign)
                                        <option value="{{ $campaign->id }}" @selected($campaignId === (string) $campaign->id)>
                                            {{ $campaign->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="kn-help">
                                    Left on "any campaign", the check passes if the contact has ever opened or clicked
                                    anything you have sent.
                                </p>
                                <x-input-error :messages="$errors->get('campaign_id')" />
                            </div>
                        </template>

                        <div>
                            <span class="kn-label">If the answer is no</span>

                            <label class="mt-1 flex items-start gap-2.5 rounded-lg border border-ink-200 px-3 py-2.5 text-sm text-ink-700">
                                <input type="radio" name="if_false" value="end" class="kn-checkbox mt-0.5"
                                       @checked($ifFalse !== 'skip')>
                                <span>
                                    <span class="font-medium text-ink-900">End the run</span>
                                    <span class="block text-xs text-ink-500">
                                        The contact stops here and their run is marked completed. Nothing after this
                                        step ever reaches them.
                                    </span>
                                </span>
                            </label>

                            <label class="mt-2 flex items-start gap-2.5 rounded-lg border border-ink-200 px-3 py-2.5 text-sm text-ink-700">
                                <input type="radio" name="if_false" value="skip" class="kn-checkbox mt-0.5"
                                       @checked($ifFalse === 'skip')>
                                <span>
                                    <span class="font-medium text-ink-900">Skip the next step</span>
                                    <span class="block text-xs text-ink-500">
                                        Exactly one step is skipped — the one immediately after this — and the run
                                        carries on from the one after that. This is the only branch a numbered list
                                        of steps can express; there is no second path.
                                    </span>
                                </span>
                            </label>

                            <x-input-error :messages="$errors->get('if_false')" />
                        </div>

                        <div class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                            A check the runner cannot evaluate — a tag or list that has since been deleted — counts as
                            <span class="font-medium">no</span>, never as yes. That is the safe answer: the run stops
                            rather than mailing on a condition nobody can work out.
                        </div>
                    @endif

                    {{-- ---------------------------------- add_tag / remove_tag --}}
                    @if (in_array($type, ['add_tag', 'remove_tag'], true))
                        <div>
                            <label for="tag_id" class="kn-label">Tag</label>
                            <select id="tag_id" name="tag_id" class="kn-select" required>
                                <option value="">Choose a tag</option>
                                @foreach ($tags as $tag)
                                    <option value="{{ $tag->id }}" @selected($tagId === (string) $tag->id)>{{ $tag->name }}</option>
                                @endforeach
                            </select>
                            @if ($tags->isEmpty())
                                <p class="kn-help">There are no tags in this account yet. Create one before adding this step.</p>
                            @endif
                            <p class="kn-help">
                                @if ($type === 'add_tag')
                                    Adding a tag the contact already has changes nothing and does not re-fire a
                                    "tag added" trigger.
                                @else
                                    Removing a tag the contact does not have changes nothing.
                                @endif
                                If the tag is deleted later, the runner steps over this and carries on.
                            </p>
                            <x-input-error :messages="$errors->get('tag_id')" />
                        </div>
                    @endif

                    {{-- -------------------------------------------- move_list --}}
                    @if ($type === 'move_list')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="from_list_id" class="kn-label">
                                    Remove from <span class="font-normal text-ink-400">(optional)</span>
                                </label>
                                <select id="from_list_id" name="from_list_id" class="kn-select">
                                    <option value="">Leave every list they are on</option>
                                    @foreach ($lists as $list)
                                        <option value="{{ $list->id }}" @selected($fromListId === (string) $list->id)>{{ $list->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('from_list_id')" />
                            </div>

                            <div>
                                <label for="list_id" class="kn-label">Add to</label>
                                <select id="list_id" name="list_id" class="kn-select" required>
                                    <option value="">Choose a list</option>
                                    @foreach ($lists as $list)
                                        <option value="{{ $list->id }}" @selected($listId === (string) $list->id)>{{ $list->name }}</option>
                                    @endforeach
                                </select>
                                @if ($lists->isEmpty())
                                    <p class="kn-help">There are no lists in this account yet. Create one before adding this step.</p>
                                @endif
                                <x-input-error :messages="$errors->get('list_id')" />
                            </div>
                        </div>

                        <div class="rounded-lg bg-ink-50 px-4 py-3 text-sm text-ink-600">
                            Adding to a list the contact is already on does not duplicate them. If the destination
                            list has been deleted the runner steps over the whole step — nobody is moved, and nobody
                            is removed from the old list either.
                        </div>
                    @endif

                    {{-- ------------------------------------------ unsubscribe --}}
                    @if ($type === 'unsubscribe')
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <p class="font-medium">This step has nothing to configure, and it ends the run.</p>
                            <p class="mt-1">
                                The contact is suppressed for this whole account — not just for this automation — so
                                no campaign and no other automation will mail them again either. The run stops here
                                and is marked completed; any steps after this one are never reached by anybody.
                            </p>
                            <p class="mt-1">
                                Undoing it means removing the suppression by hand from the contacts module.
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ route('automations.show', $automation) }}" class="kn-btn-secondary">Cancel</a>
                <button type="submit" class="kn-btn-primary">{{ $saveLabel }}</button>
            </div>
        </form>
    @endif
</x-app-layout>
