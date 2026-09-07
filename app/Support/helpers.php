<?php

use Illuminate\Support\Arr;

if (! function_exists('old_text')) {
    /**
     * old(), for a field that can only hold a single line of text.
     *
     * old() returns whatever was posted. A text field submitted as name[]=x
     * comes back as an array, and every way a view then uses it — casting it,
     * interpolating it, handing it to an attribute bag — is a fatal on the
     * redisplay of the very form that rejected the input. That is the one
     * moment the user's unsaved work exists only in that response, so a 500
     * there loses it.
     *
     * Anything that is not a scalar is not a value the field could have held,
     * so it is treated as nothing given.
     */
    function old_text(string $key, mixed $default = ''): string
    {
        $value = old($key);

        if (! is_scalar($value)) {
            $value = is_scalar($default) ? $default : '';
        }

        return (string) $value;
    }
}

if (! function_exists('old_list')) {
    /**
     * old(), for a field that is genuinely a list of values — a checkbox group
     * such as audience[lists][].
     *
     * The mirror of old_text(): here a scalar is the malformed case, and the
     * values are flattened to scalars so a nested array cannot reach a
     * comparison that expects an id.
     *
     * @return array<int, scalar>
     */
    function old_list(string $key, mixed $default = []): array
    {
        $value = old($key, $default);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(Arr::flatten($value), 'is_scalar'));
    }
}
