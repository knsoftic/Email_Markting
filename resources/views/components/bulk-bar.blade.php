@props(['action', 'method' => 'POST', 'countLabel' => 'selected'])

{{--
    Bulk-action toolbar for a checkbox table.

    Usage: wrap the table AND this component in one
    x-data="knBulkSelect()" element. The table's row checkboxes carry
    name="ids[]" and @change="sync()"; the header checkbox uses
    x-model="all" @change="toggleAll()". This component renders the bar that
    appears once something is ticked, and submits the ticked ids as a real
    form POST — no fake buttons.
--}}
<div x-show="selected.length > 0" x-cloak x-transition
     class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5">

    <span class="text-sm font-medium text-brand-800">
        <span x-text="selected.length"></span> {{ $countLabel }}
    </span>

    <form method="POST" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
        @csrf
        @if (strtoupper($method) !== 'POST')
            @method($method)
        @endif

        {{-- The live selection is mirrored into real inputs on submit. --}}
        <template x-for="id in selected" :key="id">
            <input type="hidden" name="ids[]" :value="id">
        </template>

        {{ $slot }}
    </form>

    <button type="button" @click="clearAll()" class="kn-btn-ghost kn-btn-sm ml-auto">Clear selection</button>
</div>
