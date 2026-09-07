@extends('public.layout', [
    'heading' => 'Preview link',
    'subheading' => 'This is not a real unsubscribe link',
])

@section('title', 'Preview link')

@section('content')
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <p class="font-medium">Nothing happened, and nothing will.</p>
        <p class="mt-1">
            You opened the unsubscribe link from a preview or a test send. It has no contact behind it, so there is
            nothing to unsubscribe.
        </p>
    </div>

    <p class="mt-4 text-sm leading-relaxed text-ink-600">
        In a real campaign this link is unique to each recipient and signed, so it can only ever unsubscribe the
        person it was sent to.
    </p>
@endsection
