@php
    /**
     * The small round icon that fronts every notification, in the bell and on
     * the page alike.
     *
     * Expects $icon (campaign|split|smtp|mailbox|plan) and $level
     * (success|info|warning|danger). Both come from the stored payload, so
     * both are looked up in a map with a fallback rather than trusted: a row
     * written by an older release must still render.
     *
     * Every class below is a whole literal string. Nothing here is assembled
     * from fragments — Tailwind's scanner reads this file as text, and a class
     * it cannot see is a class that does not exist in the stylesheet.
     */
    $tones = [
        'success' => 'bg-emerald-50 text-emerald-600',
        'info' => 'bg-brand-50 text-brand-600',
        'warning' => 'bg-amber-50 text-amber-600',
        'danger' => 'bg-red-50 text-red-600',
    ];

    $paths = [
        // paper plane — a campaign
        'campaign' => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
        // branching arrows — a split test
        'split' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5A3.75 3.75 0 0 0 20.25 12M16.5 3 21 7.5m0 0L16.5 12M21 7.5H7.5A3.75 3.75 0 0 0 3.75 11.25',
        // server stack — an SMTP account
        'smtp' => 'M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.7 5.1a3 3 0 0 1 2.4-1.2h7.8a3 3 0 0 1 2.4 1.2l2.55 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3M15 18.75h.008v.008H15v-.008Z',
        // envelope — a mailbox
        'mailbox' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        // gauge — a plan allowance
        'plan' => 'M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z',
    ];

    $tone = $tones[$level ?? 'info'] ?? $tones['info'];
    $path = $paths[$icon ?? 'plan'] ?? $paths['plan'];
@endphp

<span class="grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $tone }}" aria-hidden="true">
    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
    </svg>
</span>
