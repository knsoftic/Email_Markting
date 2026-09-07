@props(['title', 'subtitle' => null, 'back' => null])

<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-1.5 inline-flex items-center gap-1 text-xs font-medium text-ink-500 hover:text-ink-700">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/>
                </svg>
                Back
            </a>
        @endif

        <h1 class="truncate text-xl font-semibold tracking-tight text-ink-900">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
