@php
    $isNew = ! $template->exists;

    $heading = $isNew ? 'New template' : 'Edit '.$template->name;

    $subtitle = $isNew
        ? 'Build the email once here, then start any campaign from it. Nothing is saved until you press Create template.'
        : 'Changes apply to campaigns built from this template afterwards — campaigns already sent keep the content they went out with.';

    /**
     * `category` is validated as a free string (required|string|max:50), not as
     * in:<list>, so a stored value can sit outside the canonical set. Without
     * folding it in, the select would match no option, the browser would show
     * the first one instead, and pressing Save would silently rewrite the
     * category the user never touched. The index screen guards the same way.
     *
     * old() is checked for a string before it is cast: `category` failing the
     * string rule is exactly what puts an array in the old input, and casting
     * that would turn the redisplay of the error into a 500.
     */
    $oldCategory = old('category');

    $currentCategory = is_string($oldCategory) && filled($oldCategory)
        ? $oldCategory
        : (string) ($template->category ?: 'general');

    if (filled($currentCategory) && ! in_array($currentCategory, $categories, true)) {
        $categories[] = $currentCategory;
    }

    /*
     * A rejected save must not throw the email away. The controller rebuilds
     * $document from the model — the LAST SAVED version on an edit, and the
     * starter document on a create — so without this, every block the author
     * had just built is silently replaced on the way back while the message
     * above tells them only their name or category was wrong. old('blocks')
     * holds exactly what the builder serialised, so it wins when present.
     *
     * The builder posts the document as JSON in a hidden field, so old()
     * normally holds a string — but nothing forces that shape. Anything that
     * is not this form (an integration, a replayed request, a test) can post
     * blocks[] as a real array, and casting an array to string is a fatal
     * "Array to string conversion" on the one response carrying the author's
     * unsaved email. Both shapes are accepted, exactly as EmailTemplateRequest
     * accepts both. This mirrors the campaign editor.
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

    // The document travels in hidden inputs written by the builder, so a
    // validation failure on it has no field of its own to sit under. Collect
    // every blocks.* message and show them once, above the fields.
    $blockErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => $key === 'blocks' || $key === 'settings' || str_starts_with($key, 'blocks.'))
        ->flatten()
        ->unique()
        ->values();
@endphp

<x-app-layout>
    <x-slot name="header">{{ $isNew ? 'New template' : 'Edit template' }}</x-slot>

    <x-page-header :title="$heading"
                   :subtitle="$subtitle"
                   :back="route('templates.index')">
        <x-slot name="actions">
            <a href="{{ route('templates.index') }}" class="kn-btn-secondary">All templates</a>
        </x-slot>
    </x-page-header>

    @if ($template->exists)
        {{--
            Duplicate is a real POST, but the builder renders the whole page
            inside one <form> and forms cannot nest. The form lives out here and
            the button below reaches it through the HTML form= attribute.
        --}}
        <form id="template-duplicate-form" method="POST" class="hidden"
              action="{{ route('templates.duplicate', $template) }}"
              onsubmit="return confirm(@js('Duplicate '.$template->name.'? The copy is made from the last saved version — anything you have changed since is not carried over.'));">
            @csrf
        </form>
    @endif

    <x-block-builder :document="$document"
                     :block-types="$blockTypes"
                     :tokens="$tokens"
                     :action="$template->exists ? route('templates.update', $template) : route('templates.store')"
                     :method="$template->exists ? 'PUT' : 'POST'"
                     :save-label="$template->exists ? 'Save template' : 'Create template'">

        {{-- ------------------------------------------ beside the Save button --}}
        <x-slot name="actions">
            @if ($template->exists)
                {{-- templates.preview is gated on templates.view, which this
                     screen (templates.update) does not imply — offering the
                     link to a role without it would open a 403 in a new tab.
                     The campaign editor guards the same route the same way. --}}
                @permission('templates.view')
                    <a href="{{ route('templates.preview', $template) }}" target="_blank" rel="noopener"
                       class="kn-btn-secondary">Preview in new tab</a>
                @endpermission

                @permission('templates.create')
                    <button type="submit" form="template-duplicate-form" class="kn-btn-secondary">Duplicate</button>
                @endpermission
            @endif
        </x-slot>

        {{-- ----------------------------------- the template's own fields ----}}
        <x-slot name="meta">
            @if ($blockErrors->isNotEmpty())
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-sm font-semibold text-red-800">The email content could not be saved</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5 text-sm text-red-700">
                        @foreach ($blockErrors as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">Template details</h2>
                    <span class="text-xs text-ink-500">
                        @if ($template->exists)
                            Last saved {{ $template->updated_at?->diffForHumans() ?? 'just now' }}
                        @else
                            Not saved yet
                        @endif
                    </span>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Template name" />
                        <x-text-input id="name" name="name" :value="old('name', $template->name)"
                                      placeholder="e.g. Monthly newsletter" required maxlength="191" />
                        <p class="kn-help">Only you see this — it is how the template is listed and picked in the campaign editor.</p>
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div>
                        <x-input-label for="category" value="Category" />
                        <select id="category" name="category" class="kn-select" required>
                            @foreach ($categories as $category)
                                <option value="{{ $category }}" @selected($currentCategory === $category)>
                                    {{ ucfirst(str_replace('-', ' ', $category)) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="kn-help">Groups the template on the list screen so a long library stays searchable.</p>
                        <x-input-error :messages="$errors->get('category')" />
                    </div>

                    <div>
                        <x-input-label for="subject" value="Default subject line" />
                        <x-text-input id="subject" name="subject" :value="old('subject', $template->subject)"
                                      placeholder="e.g. Your October update is here" maxlength="255" />
                        <p class="kn-help">
                            A starting point — every campaign can change it. Placeholders work here too, with the
                            same fallback syntax as the body:
                            <code class="rounded bg-ink-100 px-1">@{{first_name|there}}</code>.
                        </p>
                        <x-input-error :messages="$errors->get('subject')" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <x-text-input id="description" name="description" :value="old('description', $template->description)"
                                      placeholder="What this template is for" maxlength="255" />
                        <p class="kn-help">Shown under the name on the template list, so your team knows when to reach for it.</p>
                        <x-input-error :messages="$errors->get('description')" />
                    </div>
                </div>
            </div>
        </x-slot>

        {{-- ------------------------------------- under the block palette ----}}
        <x-slot name="aside">
            <div class="kn-card">
                <div class="kn-card-header">
                    <h2 class="text-sm font-semibold text-ink-900">How this template is used</h2>
                </div>
                <div class="kn-card-body space-y-2 text-xs text-ink-600">
                    <p>
                        Saving stores both the blocks and the finished HTML, so the campaign editor and the
                        preview open instantly. A campaign takes its own copy of the content when you load this
                        template into it — editing here never changes a campaign that has already been built.
                    </p>
                    <p>
                        The preheader, width, font and colours live under <span class="font-medium text-ink-800">Email design</span>
                        in the middle column and are saved with the template.
                    </p>
                    @permission('campaigns.create')
                        <a href="{{ route('campaigns.create') }}" class="kn-btn-secondary kn-btn-sm mt-1">Start a campaign</a>
                    @endpermission
                </div>
            </div>
        </x-slot>
    </x-block-builder>
</x-app-layout>
