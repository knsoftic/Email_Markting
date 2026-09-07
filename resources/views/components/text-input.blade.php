@props(['disabled' => false])

@php
    /**
     * A text input can only hold a scalar.
     *
     * old() hands back whatever was posted, so a field submitted as name[]=x
     * comes back as an array — and the attribute bag then calls trim() on it,
     * which is a fatal on the redisplay of the very form that rejected the
     * input. Every form in the app shares this component, so the guard belongs
     * here rather than at forty call sites.
     */
    $value = $attributes->get('value');

    if ($value !== null && ! is_scalar($value)) {
        $attributes = $attributes->except('value');
    }
@endphp

<input @disabled($disabled) {{ $attributes->merge(['class' => 'kn-input']) }}>
