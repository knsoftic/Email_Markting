@props(['label', 'value', 'meta' => null, 'tone' => 'default', 'href' => null])

@php
    $tones = [
        'default' => 'text-ink-900',
        'positive' => 'text-emerald-600',
        'warning' => 'text-amber-600',
        'danger' => 'text-red-600',
    ];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'kn-stat block']) }}>
    <p class="kn-stat-label">{{ $label }}</p>
    <p class="kn-stat-value {{ $tones[$tone] ?? $tones['default'] }}">{{ $value }}</p>
    @if ($meta)
        <p class="mt-1 text-xs text-ink-500">{{ $meta }}</p>
    @endif
</{{ $tag }}>
