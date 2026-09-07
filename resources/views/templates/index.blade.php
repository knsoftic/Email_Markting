<x-app-layout>
    <x-slot name="header">Email templates</x-slot>

    @php
        /**
         * The controller does not hand the index a category list — it only
         * exists on the builder screen — so the canonical set is repeated here
         * and the currently filtered value is folded in. That way the select
         * always shows what is actually being filtered on, even for a category
         * saved before this list changed.
         */
        $categories = ['general', 'welcome', 'newsletter', 'promotion', 'product',
            'course', 'event', 'discount', 'announcement', 'follow-up'];

        $activeCategory = (string) ($filters['category'] ?? '');
        if (filled($activeCategory) && ! in_array($activeCategory, $categories, true)) {
            $categories[] = $activeCategory;
        }

        $isFiltered = filled($filters['q'] ?? null) || filled($activeCategory);

        // 0 means the capability is switched off, NULL means unlimited.
        $atLimit = $templateLimit !== null && $templatesUsed >= $templateLimit;

        // Every "add" control below leads to a controller that calls
        // assertMayAdd(), so a button shown while either check fails would
        // simply throw. Decided once, used everywhere.
        $canAdd = $builderAllowed && ! $atLimit;

        $subtitle = $templateLimit === null
            ? number_format($templatesUsed).' of your own '.\Illuminate\Support\Str::plural('template', $templatesUsed).' · your plan sets no limit'
            : number_format($templatesUsed).' of '.number_format($templateLimit).' own '.\Illuminate\Support\Str::plural('template', $templateLimit).' used';

        $usagePercent = ($templateLimit === null || $templateLimit <= 0)
            ? 0
            : min(100, (int) round($templatesUsed / $templateLimit * 100));

        $prettyCategory = fn (?string $value) => filled($value)
            ? ucfirst(str_replace('-', ' ', $value))
            : 'Uncategorised';
    @endphp

    <x-page-header title="Email templates" :subtitle="$subtitle">
        <x-slot name="actions">
            @permission('campaigns.create')
                <a href="{{ route('campaigns.create') }}" class="kn-btn-secondary">New campaign</a>
            @endpermission

            @permission('templates.create')
                @if (! $builderAllowed)
                    <span class="kn-badge-amber">The template builder is not in your plan</span>
                @elseif ($atLimit)
                    <span class="kn-badge-amber">Template limit reached</span>
                @else
                    <a href="{{ route('templates.create') }}" class="kn-btn-primary">New template</a>
                @endif
            @endpermission
        </x-slot>
    </x-page-header>

    {{-- ------------------------------------------------------- plan gating --}}
    @if (! $builderAllowed)
        <div class="kn-card mb-5 border-amber-200">
            <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-ink-900">The template builder is not included in your plan</p>
                    {{--
                        Only assertMayAdd() checks allow_template_builder, and only create(), store() and
                        duplicate() call it. edit(), update() and preview() do not, and the Edit buttons
                        below are shown and do work — so this must not claim editing is switched off.
                    --}}
                    <p class="mt-1 text-sm text-ink-600">
                        Creating and duplicating templates is switched off — ask your account owner to upgrade
                        to turn it back on. Everything already saved still works: you can open, preview and edit
                        the templates below, and a campaign can still be built from one of them.
                    </p>
                </div>
                @permission('campaigns.create')
                    <a href="{{ route('campaigns.create') }}" class="kn-btn-secondary shrink-0">Build a campaign instead</a>
                @endpermission
            </div>
        </div>
    @elseif ($atLimit)
        <div class="kn-card mb-5 border-amber-200">
            <div class="px-5 py-4">
                <p class="text-sm font-semibold text-ink-900">
                    You have used all {{ number_format((int) $templateLimit) }}
                    {{ \Illuminate\Support\Str::plural('template', (int) $templateLimit) }} on your plan
                </p>
                <p class="mt-1 text-sm text-ink-600">
                    Delete a template you no longer send, or upgrade the plan, before creating or duplicating
                    another one. Nothing already saved stops working.
                </p>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-ink-200">
                    <div class="h-2 rounded-full bg-red-500" style="width: {{ $usagePercent }}%"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('templates.index') }}" class="kn-card mb-5">
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-input-label for="q" value="Search" />
                <x-text-input id="q" name="q" :value="$filters['q'] ?? ''" placeholder="Search templates by name" />
            </div>

            <div>
                <x-input-label for="category" value="Category" />
                <select id="category" name="category" class="kn-select">
                    <option value="">Any category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($activeCategory === $category)>
                            {{ $prettyCategory($category) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                @if ($isFiltered)
                    <a href="{{ route('templates.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($templates->isEmpty())
        <div class="kn-card">
            @if ($isFiltered)
                <x-empty-state title="No templates match these filters"
                               message="Nothing here matches that name or category. Try a shorter search term, or look across every category.">
                    <x-slot name="action">
                        <a href="{{ route('templates.index') }}" class="kn-btn-secondary">Clear filters</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-empty-state title="No templates yet"
                               message="A template is a reusable email design — build it once with the block editor, then start any campaign from it instead of laying the message out again.">
                    <x-slot name="action">
                        @permission('templates.create')
                            @if ($canAdd)
                                <a href="{{ route('templates.create') }}" class="kn-btn-primary">Build your first template</a>
                            @elseif (! $builderAllowed)
                                <span class="kn-badge-amber">The template builder is not in your plan</span>
                            @else
                                <span class="kn-badge-amber">Template limit reached</span>
                            @endif
                        @endpermission
                    </x-slot>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">
                {{ $isFiltered ? 'Matching templates' : 'All templates' }}
            </h2>
            {{-- total() is the count AFTER the filters, so the "including the
                 system ones" claim is only true on the unfiltered list. --}}
            <span class="text-xs text-ink-500">
                {{ number_format($templates->total()) }} {{ \Illuminate\Support\Str::plural('template', $templates->total()) }}
                {{ $isFiltered
                    ? ($templates->total() === 1 ? 'matches' : 'match')
                    : 'available, including the ones KN Softic provides' }}
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($templates as $template)
                @php
                    $previewUrl = route('templates.preview', $template);
                    $isSystem = (bool) $template->is_system;

                    // A system template is read-only for everyone here: edit()
                    // and destroy() both abort on it, so only Duplicate is offered.
                    $canEdit = ! $isSystem && (bool) auth()->user()?->hasPermission('templates.update');
                    $canDelete = ! $isSystem && (bool) auth()->user()?->hasPermission('templates.delete');

                    $titleUrl = $canEdit ? route('templates.edit', $template) : $previewUrl;
                @endphp

                <div class="kn-card flex flex-col overflow-hidden">

                    {{--
                        Thumbnail. The rendered email is served standalone and
                        embedded fully sandboxed — no scripts, no forms, no
                        access to this page — and lazily, so a page of 24 only
                        fetches the frames that come into view.

                        The card underneath is not decoration: a fully sandboxed
                        frame has an opaque origin, and some browsers (and
                        privacy extensions) refuse to load one at all. When that
                        happens the frame paints nothing, and without this layer
                        the card would be a blank white box that reads as broken.
                        Painted first, covered by the frame when the frame works.
                    --}}
                    <a href="{{ $previewUrl }}" target="_blank" rel="noopener"
                       class="group relative block h-40 shrink-0 overflow-hidden border-b border-ink-200/70 bg-ink-50"
                       title="Open the full preview of {{ $template->name }} in a new tab">

                        <span class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-4 text-center">
                            <span class="grid h-9 w-9 place-items-center rounded-full bg-white text-ink-400 shadow-card">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/>
                                </svg>
                            </span>
                            <span class="line-clamp-2 text-[11px] font-medium text-ink-500">{{ $template->name }}</span>
                        </span>

                        <iframe src="{{ $previewUrl }}"
                                sandbox=""
                                loading="lazy"
                                tabindex="-1"
                                aria-hidden="true"
                                title="{{ $template->name }}"
                                class="pointer-events-none absolute left-0 top-0 border-0"
                                style="width: 660px; height: 900px; transform: scale(0.46); transform-origin: top left;"></iframe>

                        <span class="absolute inset-0 transition group-hover:bg-brand-600/5"></span>
                        <span class="sr-only">Open a full preview of {{ $template->name }}</span>
                    </a>

                    <div class="flex-1 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ $titleUrl }}"
                               @unless ($canEdit) target="_blank" rel="noopener" @endunless
                               class="block min-w-0 truncate text-sm font-semibold text-ink-900 hover:text-brand-600">
                                {{ $template->name }}
                            </a>

                            @if ($isSystem)
                                <span class="kn-badge-blue shrink-0" title="Provided by KN Softic and read-only — duplicate it to make changes">
                                    System
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 truncate text-xs text-ink-500" title="{{ $template->subject }}">
                            {{ filled($template->subject) ? 'Subject: '.$template->subject : 'No subject line saved' }}
                        </p>

                        <p class="mt-2 line-clamp-2 text-sm text-ink-500">
                            {{ filled($template->description) ? $template->description : 'No description' }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="kn-badge-gray">{{ $prettyCategory($template->category) }}</span>
                            <span class="text-xs text-ink-500"
                                  title="{{ $template->updated_at?->format('D, d M Y H:i') ?? 'Never saved' }}">
                                Updated {{ $template->updated_at?->diffForHumans() ?? 'never' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-1.5 border-t border-ink-100 px-5 py-3">
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="kn-btn-secondary kn-btn-sm">
                            Preview
                        </a>

                        @if ($canEdit)
                            <a href="{{ route('templates.edit', $template) }}" class="kn-btn-ghost kn-btn-sm">Edit</a>
                        @endif

                        @permission('templates.create')
                            @if ($canAdd)
                                <form method="POST" action="{{ route('templates.duplicate', $template) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="kn-btn-ghost kn-btn-sm">Duplicate</button>
                                </form>
                            @endif
                        @endpermission

                        @if ($canDelete)
                            <x-confirm-form :action="route('templates.destroy', $template)"
                                            label="Delete"
                                            :message="'Delete the template '.$template->name.'? Campaigns already built from it keep their content — only the reusable design is removed.'" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($templates->hasPages())
            <div class="mt-6">
                <div class="border-t border-ink-100 px-5 py-3">{{ $templates->links() }}</div>
            </div>
        @endif
    @endif
</x-app-layout>
