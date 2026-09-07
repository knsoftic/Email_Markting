@props([
    'document',
    'blockTypes',
    'tokens',
    'action',
    'method' => 'POST',
    'saveLabel' => 'Save',
])

{{--
    The email builder, shared by the template editor and the campaign editor.

    One <form> wraps everything so the document and the surrounding fields save
    together — a template whose blocks saved but whose name did not would be a
    silent data loss. The document itself travels as JSON in two hidden inputs,
    filled by serialise() on submit; both form requests accept either JSON or
    plain arrays, so this stays a real form post.

    Slots:
      $meta    — the parent's own fields (name, subject, audience …)
      $actions — extra buttons beside Save
      $aside   — extra panels under the block list
--}}

<form method="POST" action="{{ $action }}"
      x-data="knBlockBuilder({
          document: @js($document),
          blockTypes: @js($blockTypes),
          tokens: @js($tokens),
          compileUrl: @js(route('templates.compile')),
      })"
      @focusin="rememberField($event)"
      @submit="serialise()"
      class="space-y-5">

    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    {{-- The document. Written by serialise() the moment before submit. --}}
    <input type="hidden" name="blocks" x-ref="blocksField">
    <input type="hidden" name="settings" x-ref="settingsField">

    {{-- ------------------------------------------------------- top bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-200 bg-white px-4 py-3">
        <div class="flex flex-wrap items-center gap-3 text-xs text-ink-500">
            <span x-show="compiling" x-cloak class="flex items-center gap-1.5 text-brand-600">
                <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z"/>
                </svg>
                Building preview…
            </span>

            <span x-show="! compiling" x-cloak>
                <span x-text="doc.blocks.length"></span> block(s) · <span x-text="sizeLabel()"></span>
            </span>

            <span x-show="dirty" x-cloak class="kn-badge-amber">Unsaved changes</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{ $actions ?? '' }}
            <button type="submit" class="kn-btn-primary">{{ $saveLabel }}</button>
        </div>
    </div>

    {{-- --------------------------------------------------- warning strip --}}
    <template x-if="compileError">
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" x-text="compileError"></div>
    </template>

    <template x-if="unknownTokens.length > 0">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">
                Unknown placeholder<span x-show="unknownTokens.length > 1">s</span>:
                <span x-text="unknownTokens.map(t => '@{{' + t + '}}').join(', ')"></span>
            </p>
            <p class="mt-0.5 text-xs">
                Nothing will fill these, so recipients would see the raw text. Check the spelling against the
                placeholder list, or remove them.
            </p>
        </div>
    </template>

    <template x-if="gmailClip">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            This email is over 102&nbsp;KB. Gmail clips messages that large behind “View entire message”, which
            usually hides the unsubscribe link. Shorten the content or move part of it to a landing page.
        </div>
    </template>

    <template x-if="! hasFooter()">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            There is no footer block, so this email has no unsubscribe link. A campaign cannot be sent without one.
        </div>
    </template>

    {{ $meta ?? '' }}

    {{-- --------------------------------------------------------- columns --}}
    <div class="grid gap-4 xl:grid-cols-12">

        {{-- ============================================ 1. blocks + palette --}}
        <div class="space-y-4 xl:col-span-3">

            <div class="kn-card">
                <div class="kn-card-header flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink-900">Content</h2>
                    <span class="text-xs text-ink-500" x-text="doc.blocks.length + ' block(s)'"></span>
                </div>

                <div class="max-h-[26rem] overflow-y-auto p-2">
                    <template x-if="doc.blocks.length === 0">
                        <p class="px-2 py-6 text-center text-sm text-ink-500">
                            No blocks yet. Add one from the palette below.
                        </p>
                    </template>

                    <template x-for="(block, index) in doc.blocks" :key="block.id">
                        <div draggable="true"
                             @dragstart="dragStart(index, $event)"
                             @dragover.prevent="dragOver(index)"
                             @dragend="dragEnd()"
                             @click="select(index)"
                             class="group mb-1.5 cursor-pointer rounded-lg border px-2.5 py-2 transition"
                             :class="selected === index
                                 ? 'border-brand-300 bg-brand-50'
                                 : 'border-transparent hover:border-ink-200 hover:bg-ink-50'">

                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 grid h-6 w-8 shrink-0 place-items-center rounded bg-ink-100 text-[10px] font-semibold text-ink-600"
                                      x-text="iconOf(block)"></span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-semibold text-ink-800" x-text="labelOf(block)"></p>
                                    <p class="truncate text-[11px] text-ink-500" x-text="summaryOf(block)"></p>
                                </div>
                            </div>

                            <div class="mt-1.5 flex items-center gap-1 opacity-0 transition group-hover:opacity-100"
                                 :class="selected === index ? 'opacity-100' : ''">
                                <button type="button" @click.stop="move(index, -1)" :disabled="index === 0"
                                        class="rounded p-1 text-ink-500 hover:bg-white hover:text-ink-800 disabled:opacity-30"
                                        title="Move up" aria-label="Move up">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/>
                                    </svg>
                                </button>
                                <button type="button" @click.stop="move(index, 1)" :disabled="index === doc.blocks.length - 1"
                                        class="rounded p-1 text-ink-500 hover:bg-white hover:text-ink-800 disabled:opacity-30"
                                        title="Move down" aria-label="Move down">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                </button>
                                <button type="button" @click.stop="duplicate(index)"
                                        class="rounded p-1 text-ink-500 hover:bg-white hover:text-ink-800"
                                        title="Duplicate" aria-label="Duplicate">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0h1.5"/>
                                    </svg>
                                </button>
                                <button type="button" @click.stop="remove(index)"
                                        class="ml-auto rounded p-1 text-ink-400 hover:bg-red-50 hover:text-red-600"
                                        title="Remove" aria-label="Remove">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- palette --}}
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Add a block</h2>
                </div>
                <div class="grid grid-cols-3 gap-1.5 p-2">
                    <template x-for="type in types" :key="type.type">
                        <button type="button" @click="add(type.type)"
                                class="flex flex-col items-center gap-1 rounded-lg border border-ink-200 px-1 py-2.5 text-center transition hover:border-brand-300 hover:bg-brand-50">
                            <span class="text-xs font-semibold text-ink-600" x-text="type.icon"></span>
                            <span class="text-[10px] leading-tight text-ink-600" x-text="type.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{ $aside ?? '' }}
        </div>

        {{-- =============================================== 2. block editor --}}
        <div class="space-y-4 xl:col-span-4">

            <div class="kn-card">
                <div class="kn-card-header flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink-900">
                        <span x-text="current() ? labelOf(current()) + ' settings' : 'Block settings'"></span>
                    </h2>
                    <span x-show="current()" x-cloak class="text-[11px] text-ink-400"
                          x-text="'#' + (selected + 1)"></span>
                </div>

                <div class="kn-card-body space-y-4">
                    <template x-if="! current()">
                        <p class="py-6 text-center text-sm text-ink-500">
                            Select a block on the left to edit it.
                        </p>
                    </template>

                    <template x-if="current()">
                        <div class="space-y-4">
                            <template x-for="field in fieldsOf(current())" :key="(current()?.id || '') + ':' + field.key">
                                <div>
                                    <label class="kn-label" x-text="field.label"></label>

                                    {{-- text --}}
                                    <template x-if="field.type === 'text'">
                                        <input type="text" class="kn-input"
                                               x-model="doc.blocks[selected].settings[field.key]">
                                    </template>

                                    {{-- url / image --}}
                                    <template x-if="field.type === 'url' || field.type === 'image'">
                                        <div class="space-y-1.5">
                                            <input type="url" class="kn-input" placeholder="https://…"
                                                   x-model="doc.blocks[selected].settings[field.key]">
                                            <template x-if="field.type === 'image' && doc.blocks[selected].settings[field.key]">
                                                <img :src="doc.blocks[selected].settings[field.key]" alt=""
                                                     class="max-h-24 rounded border border-ink-200 bg-ink-50 object-contain p-1">
                                            </template>
                                            <p class="kn-help" x-show="field.type === 'image'">
                                                Images must be hosted at a public https address — email clients do
                                                not load anything from your computer.
                                            </p>
                                        </div>
                                    </template>

                                    {{-- number --}}
                                    <template x-if="field.type === 'number'">
                                        <input type="number" class="kn-input" min="0"
                                               x-model.number="doc.blocks[selected].settings[field.key]">
                                    </template>

                                    {{-- select --}}
                                    <template x-if="field.type === 'select'">
                                        <select class="kn-select"
                                                x-model.number="doc.blocks[selected].settings[field.key]"
                                                @change="field.key === 'count' && syncColumns(doc.blocks[selected])">
                                            <template x-for="option in (field.options || [])" :key="option">
                                                <option :value="option" x-text="option"></option>
                                            </template>
                                        </select>
                                    </template>

                                    {{-- alignment --}}
                                    <template x-if="field.type === 'align'">
                                        <div class="flex gap-1">
                                            <template x-for="option in ['left', 'center', 'right']" :key="option">
                                                <button type="button" @click="setSetting(field.key, option)"
                                                        class="flex-1 rounded-md border px-2 py-1.5 text-xs capitalize transition"
                                                        :class="settingValue(field.key) === option
                                                            ? 'border-brand-300 bg-brand-50 text-brand-700'
                                                            : 'border-ink-200 text-ink-600 hover:bg-ink-50'"
                                                        x-text="option"></button>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- colour --}}
                                    <template x-if="field.type === 'color'">
                                        <div class="flex items-center gap-2">
                                            <input type="color" class="h-9 w-12 cursor-pointer rounded border border-ink-200 bg-white p-1"
                                                   :value="colourValue(field.key, docColour('textColor', '#1e293b'))"
                                                   @input="setSetting(field.key, $event.target.value)">
                                            <input type="text" class="kn-input flex-1 font-mono text-xs" placeholder="Inherit"
                                                   :value="settingValue(field.key)"
                                                   @input="setSetting(field.key, $event.target.value)">
                                            <button type="button" @click="setSetting(field.key, '')"
                                                    class="kn-btn-ghost kn-btn-sm shrink-0">Reset</button>
                                        </div>
                                    </template>

                                    {{--
                                        Rich text. The editor and the raw HTML view are x-if rather than
                                        x-show: Quill puts its toolbar in the DOM beside the editor, so
                                        hiding it would leave a stray toolbar behind, and coming back from
                                        the HTML view has to rebuild the editor from the edited value.
                                    --}}
                                    <template x-if="field.type === 'richtext'">
                                        <div class="space-y-1.5" x-data="{ raw: false }">
                                            <div class="flex justify-end">
                                                <button type="button" @click="raw = ! raw"
                                                        class="kn-btn-ghost kn-btn-sm"
                                                        x-text="raw ? 'Back to the editor' : 'Edit the HTML'"></button>
                                            </div>

                                            <template x-if="! raw">
                                                <div class="overflow-hidden rounded-md border border-ink-200 bg-white">
                                                    <div x-init="mountEditor($el, field.key)" style="min-height:11rem"></div>
                                                </div>
                                            </template>

                                            <template x-if="raw">
                                                <textarea rows="8" class="kn-textarea font-mono text-xs"
                                                          x-model="doc.blocks[selected].settings[field.key]"></textarea>
                                            </template>

                                            <p class="kn-help">
                                                The toolbar offers only formatting inboxes actually render. Alignment
                                                lives in the block settings above, where it compiles to something
                                                Outlook understands.
                                            </p>
                                        </div>
                                    </template>

                                    {{-- raw HTML block --}}
                                    <template x-if="field.type === 'code'">
                                        <div class="space-y-1">
                                            <textarea rows="8" class="kn-textarea font-mono text-xs"
                                                      x-model="doc.blocks[selected].settings[field.key]"></textarea>
                                            <p class="kn-help">
                                                Pasted as-is, then cleaned: scripts, iframes, embedded stylesheets and
                                                anything else no inbox will run are stripped when the email is built.
                                            </p>
                                        </div>
                                    </template>

                                    {{-- columns repeater --}}
                                    <template x-if="field.type === 'columns'">
                                        <div class="space-y-2">
                                            <template x-for="(column, ci) in (doc.blocks[selected].settings.columns || [])" :key="ci">
                                                <div>
                                                    <p class="mb-1 text-[11px] font-medium text-ink-500"
                                                       x-text="'Column ' + (ci + 1)"></p>
                                                    <textarea rows="3" class="kn-textarea text-xs"
                                                              x-model="doc.blocks[selected].settings.columns[ci].html"></textarea>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- social repeater --}}
                                    <template x-if="field.type === 'social'">
                                        <div class="space-y-2">
                                            <template x-for="(link, li) in (doc.blocks[selected].settings.links || [])" :key="li">
                                                <div class="flex items-center gap-1.5">
                                                    <select class="kn-select w-32 text-xs"
                                                            x-model="doc.blocks[selected].settings.links[li].network">
                                                        @foreach (['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok', 'whatsapp'] as $network)
                                                            <option value="{{ $network }}">{{ ucfirst($network) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="url" class="kn-input flex-1 text-xs" placeholder="https://…"
                                                           x-model="doc.blocks[selected].settings.links[li].href">
                                                    <button type="button" @click="removeSocial(doc.blocks[selected], li)"
                                                            class="shrink-0 rounded p-1.5 text-ink-400 hover:bg-red-50 hover:text-red-600"
                                                            aria-label="Remove network">
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </template>

                                            <button type="button" @click="addSocial(doc.blocks[selected])"
                                                    class="kn-btn-secondary kn-btn-sm">Add network</button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- The footer's opt-out is not an editable option. --}}
                            <template x-if="current().type === 'footer'">
                                <p class="rounded-md bg-ink-50 px-3 py-2 text-xs text-ink-600">
                                    The unsubscribe and preferences links are added automatically and cannot be
                                    turned off — every marketing email needs a working opt-out.
                                </p>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ---------------------------------------------- placeholders --}}
            <div class="kn-card" x-data="{ open: false }">
                <button type="button" @click="open = ! open"
                        class="kn-card-header flex w-full items-center justify-between text-left">
                    <h2 class="text-sm font-semibold text-ink-900">Personalisation</h2>
                    <span class="text-xs text-ink-500" x-text="open ? 'Hide' : 'Show'"></span>
                </button>

                <div x-show="open" x-cloak class="kn-card-body space-y-3">
                    <p class="kn-help">
                        Click a placeholder to drop it into the field you were last typing in. Give a fallback with
                        a pipe — <code class="rounded bg-ink-100 px-1">@{{first_name|there}}</code> — so a
                        contact with no first name still reads properly.
                    </p>

                    <template x-for="(group, name) in tokenGroups()" :key="name">
                        <div>
                            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-400" x-text="name"></p>
                            <div class="flex flex-wrap gap-1">
                                <template x-for="token in group" :key="token.token">
                                    <button type="button" @click="insertToken(token.token)"
                                            class="rounded border border-ink-200 bg-white px-1.5 py-1 font-mono text-[11px] text-ink-600 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                                            :title="token.label"
                                            x-text="token.token"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ------------------------------------------- document design --}}
            {{--
                @invalid: the width box has min/max/step, so it can hold a value
                the browser refuses to submit. A control inside a collapsed
                panel is display:none and therefore not focusable, and the
                browser then aborts the submit and reports nothing at all —
                Save would simply look broken. Opening the panel puts the
                offending field back in front of the author. invalid does not
                bubble, hence the capture phase.
            --}}
            <div class="kn-card" x-data="{ open: false }" @invalid.capture="open = true">
                <button type="button" @click="open = ! open"
                        class="kn-card-header flex w-full items-center justify-between text-left">
                    <h2 class="text-sm font-semibold text-ink-900">Email design</h2>
                    <span class="text-xs text-ink-500" x-text="open ? 'Hide' : 'Show'"></span>
                </button>

                <div x-show="open" x-cloak class="kn-card-body grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="kn-label">Preheader</label>
                        <input type="text" class="kn-input" x-model="doc.settings.preheader"
                               placeholder="The line shown after the subject in the inbox">
                        <p class="kn-help">Hidden inside the email, but most inbox lists show it beside the subject.</p>
                    </div>

                    <div>
                        <label class="kn-label">Content width (px)</label>
                        <input type="number" min="320" max="900" step="10" class="kn-input" x-model.number="doc.settings.width">
                    </div>

                    <div>
                        <label class="kn-label">Font</label>
                        <select class="kn-select" x-model="doc.settings.fontFamily">
                            @foreach ([
                                'Arial, Helvetica, sans-serif' => 'Arial',
                                'Helvetica, Arial, sans-serif' => 'Helvetica',
                                'Georgia, Times New Roman, serif' => 'Georgia',
                                'Tahoma, Verdana, sans-serif' => 'Tahoma',
                                'Verdana, Geneva, sans-serif' => 'Verdana',
                                'Trebuchet MS, Helvetica, sans-serif' => 'Trebuchet MS',
                                'Courier New, Courier, monospace' => 'Courier New',
                            ] as $stack => $label)
                                <option value="{{ $stack }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="kn-help">Only fonts installed on the reader's device render — web fonts are ignored by most clients.</p>
                    </div>

                    @foreach ([
                        'backgroundColor' => 'Page background',
                        'contentBackground' => 'Content background',
                        'textColor' => 'Text',
                        'mutedColor' => 'Muted text',
                        'linkColor' => 'Links',
                    ] as $key => $label)
                        <div>
                            <label class="kn-label">{{ $label }}</label>
                            <div class="flex items-center gap-2">
                                <input type="color" class="h-9 w-12 cursor-pointer rounded border border-ink-200 bg-white p-1"
                                       :value="docColour('{{ $key }}', '{{ \App\Services\Campaigns\BlockCatalogue::DOCUMENT_DEFAULTS[$key] }}')"
                                       @input="doc.settings.{{ $key }} = $event.target.value">
                                <input type="text" class="kn-input flex-1 font-mono text-xs"
                                       x-model="doc.settings.{{ $key }}">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ==================================================== 3. preview --}}
        <div class="xl:col-span-5">
            <div class="kn-card xl:sticky xl:top-4">
                <div class="kn-card-header flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1">
                        @foreach (['preview' => 'Preview', 'html' => 'HTML', 'text' => 'Plain text'] as $key => $label)
                            <button type="button" @click="view = '{{ $key }}'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                                    :class="view === '{{ $key }}'
                                        ? 'bg-brand-50 text-brand-700'
                                        : 'text-ink-500 hover:bg-ink-100'">{{ $label }}</button>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-1" x-show="view === 'preview'">
                        <button type="button" @click="device = 'desktop'"
                                class="rounded-md px-2 py-1 text-xs transition"
                                :class="device === 'desktop' ? 'bg-ink-100 text-ink-800' : 'text-ink-500 hover:bg-ink-50'"
                                title="Desktop width">Desktop</button>
                        <button type="button" @click="device = 'mobile'"
                                class="rounded-md px-2 py-1 text-xs transition"
                                :class="device === 'mobile' ? 'bg-ink-100 text-ink-800' : 'text-ink-500 hover:bg-ink-50'"
                                title="Mobile width">Mobile</button>
                        <button type="button" @click="compile()" class="kn-btn-ghost kn-btn-sm" :disabled="compiling">
                            Refresh
                        </button>
                    </div>
                </div>

                <div class="bg-ink-100 p-3">
                    {{-- The email renders inside a sandboxed frame: no scripts,
                         no forms, no access to this page. --}}
                    <div x-show="view === 'preview'" class="mx-auto transition-all"
                         :style="device === 'mobile' ? 'max-width:375px' : 'max-width:100%'">
                        <iframe x-ref="frame" sandbox="" title="Email preview" loading="lazy"
                                class="h-[34rem] w-full rounded-lg border border-ink-200 bg-white"></iframe>
                    </div>

                    <div x-show="view === 'html'" x-cloak>
                        <pre class="h-[34rem] overflow-auto rounded-lg border border-ink-200 bg-white p-3 text-[11px] leading-relaxed text-ink-700"><code x-text="previewHtml"></code></pre>
                    </div>

                    <div x-show="view === 'text'" x-cloak>
                        <pre class="h-[34rem] overflow-auto whitespace-pre-wrap rounded-lg border border-ink-200 bg-white p-3 text-xs leading-relaxed text-ink-700" x-text="plainText"></pre>
                    </div>
                </div>

                {{-- Which sentence is true depends on whether the account has a
                     contact to fill the placeholders with, so the server says
                     so and neither claim is shown before it has. --}}
                <div class="kn-card-body border-t border-ink-100 text-xs text-ink-500">
                    <span x-show="sampled !== false">
                        Filled with a real contact from your list, so this is what a recipient would see.
                    </span>
                    <span x-show="sampled === false" x-cloak>
                        There are no contacts yet, so every placeholder is showing its fallback text. Once you
                        import contacts the preview fills with a real one.
                    </span>
                    The plain-text part goes out alongside the HTML for clients that cannot show it.
                </div>
            </div>
        </div>
    </div>
</form>
