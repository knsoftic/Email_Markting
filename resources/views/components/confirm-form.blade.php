@props([
    'action',
    'method' => 'DELETE',
    'label' => 'Delete',
    'message' => 'Are you sure? This cannot be undone.',
    'buttonClass' => 'kn-btn-danger kn-btn-sm',
])

{{--
    A real submitting form behind a confirmation dialog — never a bare link.
    Extra hidden inputs can be passed in the default slot.
--}}
<form method="POST" action="{{ $action }}" class="inline"
      onsubmit="return confirm(@js($message));">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    {{ $slot }}

    <button type="submit" class="{{ $buttonClass }}">{{ $label }}</button>
</form>
