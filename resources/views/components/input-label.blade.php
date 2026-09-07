@props(['value' => null])

<label {{ $attributes->merge(['class' => 'kn-label']) }}>
    {{ $value ?? $slot }}
</label>
