@props(['groups', 'selected' => [], 'disabled' => false])

{{--
    Shared permission picker used by both the platform role editor and the
    account-side role editor, so the two can never drift apart.
--}}
<div x-data="{
        toggleGroup(group, state) {
            this.$refs[group]?.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = state });
        }
     }"
     class="divide-y divide-ink-100">

    @foreach ($groups as $group => $permissions)
        <div class="p-5">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold capitalize text-ink-900">{{ str_replace('_', ' ', $group) }}</h4>

                @unless ($disabled)
                    <div class="flex gap-2 text-xs">
                        <button type="button" class="text-brand-600 hover:text-brand-700"
                                @click="toggleGroup('{{ $group }}', true)">Select all</button>
                        <span class="text-ink-300">·</span>
                        <button type="button" class="text-ink-500 hover:text-ink-700"
                                @click="toggleGroup('{{ $group }}', false)">Clear</button>
                    </div>
                @endunless
            </div>

            <div x-ref="{{ $group }}" class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($permissions as $permission)
                    <label class="flex items-start gap-2.5 rounded-lg border border-ink-200 px-3 py-2 {{ $disabled ? 'opacity-60' : 'cursor-pointer hover:bg-ink-50' }}">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                               class="kn-checkbox mt-0.5"
                               @checked(in_array($permission->id, $selected, true))
                               @disabled($disabled)>
                        <span class="min-w-0">
                            <span class="block text-sm text-ink-800">{{ $permission->name }}</span>
                            <span class="block text-xs text-ink-400">{{ $permission->slug }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
