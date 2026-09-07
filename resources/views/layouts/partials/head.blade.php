@php
    /**
     * Converts the admin-saved brand colour into the RGB triplets the Tailwind
     * `brand` palette reads, and derives a darker hover shade from it, so the
     * whole UI follows the branding settings with no rebuild.
     */
    $toRgb = static function (?string $hex, string $fallback): string {
        $hex = ltrim((string) $hex, '#');

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }

        return implode(' ', [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))]);
    };

    $shade = static function (?string $hex, float $factor, string $fallback): string {
        $clean = ltrim((string) $hex, '#');

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $clean)) {
            return $fallback;
        }

        $parts = [];
        foreach ([0, 2, 4] as $offset) {
            $parts[] = (int) max(0, min(255, round(hexdec(substr($clean, $offset, 2)) * $factor)));
        }

        return implode(' ', $parts);
    };

    $primary = $brand['primary_color'] ?? '#1d4ed8';
    $accent = $brand['accent_color'] ?? '#0ea5e9';
    $favicon = $brand['favicon_path'] ?? '';
    $hasFavicon = $favicon && \Illuminate\Support\Facades\Storage::disk('public')->exists($favicon);
@endphp

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>@yield('title', $title ?? $brand['company_name'])</title>
<meta name="description" content="{{ $brand['tagline'] }}">

@if ($hasFavicon)
    <link rel="icon" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($favicon) }}">
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">

<style>
    :root {
        --brand-400: {{ $shade($primary, 1.35, '96 165 250') }};
        --brand-500: {{ $shade($primary, 1.15, '59 130 246') }};
        --brand-600: {{ $toRgb($primary, '29 78 216') }};
        --brand-700: {{ $shade($primary, 0.85, '30 64 175') }};
        --brand-800: {{ $shade($primary, 0.7, '30 58 138') }};
        --accent-500: {{ $toRgb($accent, '14 165 233') }};
        --accent-600: {{ $shade($accent, 0.85, '2 132 199') }};
    }
</style>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('head')
