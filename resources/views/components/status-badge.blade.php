@props(['status', 'map' => null])

@php
    /**
     * Shared status pill. Subscriber, campaign, import and suppression states
     * all render through here so one status never looks different on two
     * screens.
     */
    $tones = $map ?? [
        'active' => 'kn-badge-green',
        'completed' => 'kn-badge-green',
        'sent' => 'kn-badge-green',
        'pending' => 'kn-badge-amber',
        'processing' => 'kn-badge-blue',
        'mapping' => 'kn-badge-blue',
        'scheduled' => 'kn-badge-blue',
        'queued' => 'kn-badge-blue',
        'sending' => 'kn-badge-blue',
        'draft' => 'kn-badge-gray',
        'paused' => 'kn-badge-amber',
        'unsubscribed' => 'kn-badge-gray',
        'bounced' => 'kn-badge-amber',
        'blocked' => 'kn-badge-red',
        'failed' => 'kn-badge-red',
        'cancelled' => 'kn-badge-gray',
        'suspended' => 'kn-badge-red',
    ];

    $class = $tones[$status] ?? 'kn-badge-gray';
    $label = ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>{{ $label }}</span>
