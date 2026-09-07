<x-app-layout>
    <x-slot name="header">Tags</x-slot>

    @php
        $q = trim((string) ($filters['q'] ?? ''));

        /*
         | When a create or an update fails validation Laravel bounces back to
         | this same screen with one shared error bag. The hidden _tag_id on the
         | inline edit form tells us which form to re-open and where the errors
         | and old() values belong, so a failed edit never poisons the create
         | card (and the other way round).
         */
        $editingTagId = (int) old('_tag_id');
        $creating = $errors->any() && $editingTagId === 0;

        $newName = $creating ? old_text('name') : '';
        $newSlug = $creating ? old_text('slug') : '';
        $newDescription = $creating ? old_text('description') : '';
        $newColor = $creating ? old_text('color', '#2563eb') : '#2563eb';

        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $newColor)) {
            $newColor = '#2563eb';
        }

        $subtitle = $q !== ''
            ? number_format($tags->total()).' tag'.($tags->total() === 1 ? '' : 's').' matching "'.$q.'"'
            : number_format($tags->total()).' tag'.($tags->total() === 1 ? '' : 's').' · labels you apply to contacts in bulk';
    @endphp

    <x-page-header title="Tags" :subtitle="$subtitle">
        <x-slot name="actions">
            @permission('contacts.view')
                <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">Contacts</a>
            @endpermission
        </x-slot>
    </x-page-header>

    <div class="grid gap-6 @permission('contacts.create') lg:grid-cols-3 @endpermission">

        {{-- ------------------------------------------------------- tag list --}}
        <div class="space-y-5 @permission('contacts.create') lg:col-span-2 @endpermission">

            <form method="GET" action="{{ route('tags.index') }}" class="kn-card">
                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                    <div class="flex-1">
                        <label for="q" class="sr-only">Search tags</label>
                        <x-text-input id="q" name="q" :value="$q" placeholder="Search tags by name" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="kn-btn-primary shrink-0">Search</button>
                        @if ($q !== '')
                            <a href="{{ route('tags.index') }}" class="kn-btn-secondary shrink-0">Clear</a>
                        @endif
                    </div>
                </div>
            </form>

            <div class="kn-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="kn-table">
                        <thead>
                            <tr>
                                <th>Tag</th>
                                <th>Slug</th>
                                <th>Description</th>
                                <th>Contacts</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>

                        @forelse ($tags as $tag)
                            @php
                                // The colour is user data, so it is validated here as well as in the
                                // request before it ever reaches a style attribute.
                                $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tag->color)
                                    ? $tag->color
                                    : '#64748b';

                                $isEditingRow = $editingTagId === $tag->id;
                                $rowName = $isEditingRow ? old_text('name') : (string) $tag->name;
                                $rowSlug = $isEditingRow ? old_text('slug') : (string) $tag->slug;
                                $rowDescription = $isEditingRow ? old_text('description') : (string) $tag->description;
                                $rowColor = $isEditingRow ? old_text('color', $color) : $color;

                                if (! preg_match('/^#[0-9a-fA-F]{6}$/', $rowColor)) {
                                    $rowColor = $color;
                                }

                                $deleteMessage = 'Delete the tag "'.$tag->name.'"? It is removed from every contact that carries it. The contacts themselves are kept.';
                            @endphp

                            <tbody x-data="{ editing: {{ $isEditingRow ? 'true' : 'false' }}, color: @js($rowColor) }">
                                <tr>
                                    <td>
                                        <span class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                              style="background-color: {{ $color }}1a; color: {{ $color }}; --tw-ring-color: {{ $color }}59">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full" style="background-color: {{ $color }}"></span>
                                            <span class="truncate">{{ $tag->name }}</span>
                                        </span>
                                    </td>
                                    <td>
                                        <code class="rounded bg-ink-100 px-1.5 py-0.5 text-xs text-ink-600">{{ $tag->slug }}</code>
                                    </td>
                                    <td class="text-ink-600">
                                        <span class="block max-w-xs truncate" title="{{ $tag->description }}">{{ $tag->description ?: '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <a href="{{ route('tags.show', $tag) }}" class="font-medium text-brand-600 hover:text-brand-700">
                                            {{ number_format($tag->subscribers_count) }}
                                        </a>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <a href="{{ route('tags.show', $tag) }}" class="kn-btn-secondary kn-btn-sm">View</a>

                                            @permission('contacts.update')
                                                <button type="button" class="kn-btn-ghost kn-btn-sm"
                                                        @click="editing = ! editing"
                                                        x-text="editing ? 'Close' : 'Edit'">Edit</button>
                                            @endpermission

                                            @permission('contacts.delete')
                                                <x-confirm-form :action="route('tags.destroy', $tag)"
                                                                label="Delete"
                                                                :message="$deleteMessage" />
                                            @endpermission
                                        </div>
                                    </td>
                                </tr>

                                @permission('contacts.update')
                                    <tr x-show="editing" x-cloak>
                                        <td colspan="5" class="bg-ink-50/70">
                                            <form method="POST" action="{{ route('tags.update', $tag) }}" class="space-y-4">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="_tag_id" value="{{ $tag->id }}">

                                                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                                    <div>
                                                        <x-input-label :for="'name_'.$tag->id" value="Name" />
                                                        <x-text-input :id="'name_'.$tag->id" name="name" :value="$rowName" maxlength="100" required />
                                                        <x-input-error :messages="$isEditingRow ? $errors->get('name') : []" />
                                                    </div>

                                                    <div>
                                                        <x-input-label :for="'slug_'.$tag->id" value="Slug" />
                                                        <x-text-input :id="'slug_'.$tag->id" name="slug" :value="$rowSlug" maxlength="100" required />
                                                        <x-input-error :messages="$isEditingRow ? $errors->get('slug') : []" />
                                                    </div>

                                                    <div>
                                                        <x-input-label :for="'color_'.$tag->id" value="Colour" />
                                                        <div class="flex items-center gap-2">
                                                            <input type="color" x-model="color" aria-label="Pick a colour"
                                                                   class="h-9 w-10 shrink-0 cursor-pointer rounded-lg border border-ink-300 bg-white p-1">
                                                            <x-text-input :id="'color_'.$tag->id" name="color" :value="$rowColor"
                                                                          x-model="color" maxlength="7" required />
                                                        </div>
                                                        <x-input-error :messages="$isEditingRow ? $errors->get('color') : []" />
                                                    </div>

                                                    <div>
                                                        <x-input-label :for="'description_'.$tag->id" value="Description" />
                                                        <x-text-input :id="'description_'.$tag->id" name="description" :value="$rowDescription" maxlength="255" />
                                                        <x-input-error :messages="$isEditingRow ? $errors->get('description') : []" />
                                                    </div>
                                                </div>

                                                <div class="flex items-center justify-end gap-2">
                                                    <button type="button" class="kn-btn-secondary kn-btn-sm" @click="editing = false">Cancel</button>
                                                    <button type="submit" class="kn-btn-primary kn-btn-sm">Save tag</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endpermission
                            </tbody>
                        @empty
                            <tbody>
                                <tr>
                                    <td colspan="5">
                                        @if ($q !== '')
                                            <x-empty-state title="No tag matches this search"
                                                           message="Nothing here is named like that. Clear the search to see every tag.">
                                                <x-slot name="action">
                                                    <a href="{{ route('tags.index') }}" class="kn-btn-secondary">Clear search</a>
                                                </x-slot>
                                            </x-empty-state>
                                        @else
                                            <x-empty-state title="No tags yet"
                                                           message="Tags are free-form labels. Create one here, then apply it to contacts in bulk from the contacts screen.">
                                                @permission('contacts.view')
                                                    <x-slot name="action">
                                                        <a href="{{ route('subscribers.index') }}" class="kn-btn-secondary">Go to contacts</a>
                                                    </x-slot>
                                                @endpermission
                                            </x-empty-state>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        @endforelse
                    </table>
                </div>

                @if ($tags->hasPages())
                    <div class="border-t border-ink-100 px-5 py-3">{{ $tags->links() }}</div>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------ create tag --}}
        @permission('contacts.create')
            <div>
                <form method="POST" action="{{ route('tags.store') }}" class="kn-card"
                      x-data="{ name: @js($newName), slug: @js($newSlug), touched: {{ $newSlug !== '' ? 'true' : 'false' }}, color: @js($newColor), slugify(value) { return String(value).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100); } }">
                    @csrf

                    <div class="kn-card-header">
                        <h3 class="text-sm font-semibold text-ink-900">New tag</h3>
                    </div>

                    <div class="space-y-4 p-5">
                        <div>
                            <x-input-label for="new_name" value="Name" />
                            <x-text-input id="new_name" name="name" :value="$newName" maxlength="100" required
                                          placeholder="e.g. VIP customers"
                                          x-model="name"
                                          x-on:input="if (! touched) slug = slugify($event.target.value)" />
                            <x-input-error :messages="$creating ? $errors->get('name') : []" />
                        </div>

                        <div>
                            <x-input-label for="new_slug" value="Slug" />
                            <x-text-input id="new_slug" name="slug" :value="$newSlug" maxlength="100"
                                          placeholder="vip-customers"
                                          x-model="slug"
                                          x-on:input="touched = true" />
                            <p class="kn-help">Filled in from the name; edit it if you need a different key. Letters, numbers, dashes and underscores only.</p>
                            <x-input-error :messages="$creating ? $errors->get('slug') : []" />
                        </div>

                        <div>
                            <x-input-label for="new_color" value="Colour" />
                            <div class="flex items-center gap-2">
                                <input type="color" x-model="color" aria-label="Pick a colour"
                                       class="h-9 w-10 shrink-0 cursor-pointer rounded-lg border border-ink-300 bg-white p-1">
                                <x-text-input id="new_color" name="color" :value="$newColor" maxlength="7" required
                                              x-model="color" />
                            </div>
                            <p class="kn-help">Six-digit hex, for example #2563eb.</p>
                            <x-input-error :messages="$creating ? $errors->get('color') : []" />
                        </div>

                        <div>
                            <x-input-label for="new_description" value="Description" />
                            <textarea id="new_description" name="description" rows="2" maxlength="255" class="kn-textarea"
                                      placeholder="What does this tag mean?">{{ $newDescription }}</textarea>
                            <x-input-error :messages="$creating ? $errors->get('description') : []" />
                        </div>

                        <div>
                            <p class="kn-stat-label">Preview</p>
                            <span class="mt-1.5 inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                  :style="{ backgroundColor: color + '1a', color: color, '--tw-ring-color': color + '59' }">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: color }"></span>
                                <span class="truncate" x-text="name || 'Tag name'">Tag name</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-ink-100 px-5 py-3">
                        <button type="submit" class="kn-btn-primary">Create tag</button>
                    </div>
                </form>

                @permission('contacts.view')
                    <p class="mt-3 text-xs text-ink-500">
                        Apply a tag to people from the
                        <a href="{{ route('subscribers.index') }}" class="text-brand-600 hover:text-brand-700">contacts</a>
                        screen: tick the rows you want, then pick the tag in the selection toolbar.
                    </p>
                @endpermission
            </div>
        @endpermission
    </div>
</x-app-layout>
