@props(['title', 'message' => null])

<div {{ $attributes->merge(['class' => 'px-6 py-14 text-center']) }}>
    <div class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-ink-100 text-ink-400">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5v9a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25v-9m16.5 0A2.25 2.25 0 0 0 18 5.25H6A2.25 2.25 0 0 0 3.75 7.5m16.5 0h-16.5M9 11.25h6"/>
        </svg>
    </div>
    <p class="mt-3 text-sm font-medium text-ink-800">{{ $title }}</p>
    @if ($message)
        <p class="mx-auto mt-1 max-w-sm text-sm text-ink-500">{{ $message }}</p>
    @endif
    @isset($action)
        <div class="mt-4 flex justify-center">{{ $action }}</div>
    @endisset
</div>
