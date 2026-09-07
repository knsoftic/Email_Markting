@props(['inverted' => false])

@php
    $logo = $brand['logo_path'] ?? '';
    $hasLogo = $logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($logo);
@endphp

@if ($hasLogo)
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}"
         alt="{{ $brand['company_name'] }}"
         {{ $attributes->merge(['class' => 'h-9 w-auto object-contain']) }}>
@else
    {{-- Fallback wordmark: the KN monogram plus the admin-configured name. --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-600 text-sm font-bold tracking-tight text-white">
            KN
        </span>
        <span class="text-base font-semibold tracking-tight {{ $inverted ? 'text-white' : 'text-ink-900' }}">
            {{ $brand['company_name'] }}
        </span>
    </span>
@endif
