@php
    $flashes = array_filter([
        'success' => session('success') ?: session('status'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ]);

    $styles = [
        'success' => ['bg-emerald-50 border-emerald-200 text-emerald-800', 'm4.5 12.75 6 6 9-13.5'],
        'error' => ['bg-red-50 border-red-200 text-red-800', 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z'],
        'warning' => ['bg-amber-50 border-amber-200 text-amber-800', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z'],
        'info' => ['bg-brand-50 border-brand-200 text-brand-800', 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z'],
    ];
@endphp

@foreach ($flashes as $type => $message)
    <div x-data="{ show: true }" x-show="show" x-transition
         class="mb-4 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ $styles[$type][0] }}">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $styles[$type][1] }}"/>
        </svg>
        <p class="flex-1">{{ $message }}</p>
        <button type="button" @click="show = false" class="shrink-0 opacity-60 hover:opacity-100" aria-label="Dismiss">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
@endforeach
